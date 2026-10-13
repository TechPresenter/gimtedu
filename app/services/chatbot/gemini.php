<?php
/**
 * Google Gemini adapter — Generative Language API generateContent over raw HTTPS.
 *
 *   POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 *        (streaming: :streamGenerateContent?alt=sse)
 *   headers  x-goog-api-key: <key>
 *   body     systemInstruction {parts:[{text}]}, contents [{role: user|model, parts:[{text}]}],
 *            generationConfig {maxOutputTokens, temperature, thinkingConfig.thinkingBudget = 0 on 2.5 Flash models}
 * Implicit context caching applies automatically on Gemini 2.5+ for repeated prefixes.
 */

const CHATBOT_GEMINI_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

function chatbot_gemini_model_id(string $model): string
{
    $m = preg_replace('#^models/#', '', trim($model));
    return preg_match('/^[A-Za-z0-9._\-]{2,80}$/', $m) ? $m : 'gemini-2.5-flash';
}

function chatbot_gemini_request(array $r): array
{
    $model = chatbot_gemini_model_id($r['model']);
    $contents = [];
    foreach ($r['history'] ?? [] as $m) {
        $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => (string) $m['content']]]];
    }
    $parts = [];
    if (trim((string) ($r['context'] ?? '')) !== '') {
        $parts[] = ['text' => $r['context']];
    }
    $parts[] = ['text' => (string) $r['question']];
    $contents[] = ['role' => 'user', 'parts' => $parts];
    $gen = ['maxOutputTokens' => max(256, (int) ($r['max_tokens'] ?? 16000))];
    if (isset($r['temperature']) && $r['temperature'] !== null && $r['temperature'] !== '') {
        $gen['temperature'] = max(0.0, min(2.0, (float) $r['temperature']));
    }
    if (preg_match('/^gemini-2\.5-flash/', $model)) {
        $gen['thinkingConfig'] = ['thinkingBudget' => 0]; // fast answers for chat; Flash models allow turning thinking off
    }
    $payload = ['systemInstruction' => ['parts' => [['text' => (string) $r['system']]]], 'contents' => $contents, 'generationConfig' => $gen];
    $url = CHATBOT_GEMINI_BASE . rawurlencode($model) . (!empty($r['stream']) ? ':streamGenerateContent?alt=sse' : ':generateContent');
    return ['url' => $url, 'headers' => ['x-goog-api-key' => (string) $r['key']], 'payload' => $payload];
}

/** Shared reader for a GenerateContentResponse (full or streamed chunk). */
function chatbot_gemini_read(array $j): array
{
    $cand = $j['candidates'][0] ?? [];
    $text = '';
    foreach ($cand['content']['parts'] ?? [] as $part) {
        if (empty($part['thought']) && isset($part['text'])) {
            $text .= (string) $part['text'];
        }
    }
    $finish = (string) ($cand['finishReason'] ?? '');
    $blocked = !empty($j['promptFeedback']['blockReason']) || in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION'], true);
    $u = $j['usageMetadata'] ?? [];
    return [
        'text' => $text, 'finish' => $finish, 'blocked' => $blocked, 'model' => $j['modelVersion'] ?? null,
        'usage' => ['input' => (int) ($u['promptTokenCount'] ?? 0), 'output' => (int) ($u['candidatesTokenCount'] ?? 0) + (int) ($u['thoughtsTokenCount'] ?? 0), 'cached' => (int) ($u['cachedContentTokenCount'] ?? 0)],
        'has_usage' => !empty($u),
    ];
}

function chatbot_gemini_parse(int $status, string $body): array
{
    $j = json_decode($body, true);
    if (isset($j[0]) && is_array($j[0])) {
        $j = $j[0]; // array form of a streamed response
    }
    if ($status < 200 || $status >= 300 || !is_array($j) || isset($j['error'])) {
        $msg = is_array($j) ? (string) ($j['error']['message'] ?? '') : '';
        $st = is_array($j) ? (string) ($j['error']['status'] ?? '') : '';
        $type = match (true) {
            stripos($msg, 'api key') !== false || in_array($st, ['UNAUTHENTICATED', 'PERMISSION_DENIED'], true) => 'auth',
            $st === 'NOT_FOUND' => 'not_found',
            $st === 'RESOURCE_EXHAUSTED' => 'rate_limit',
            $st === 'UNAVAILABLE' => 'overloaded',
            default => chatbot_http_error_type($status, 0),
        };
        return chatbot_provider_error($status, $msg !== '' ? $msg : ('HTTP ' . $status), $type);
    }
    $r = chatbot_gemini_read($j);
    return [
        'ok' => true, 'text' => $r['text'], 'model' => $r['model'], 'stop' => $r['finish'], 'refusal' => $r['blocked'] && trim($r['text']) === '', 'truncated' => $r['finish'] === 'MAX_TOKENS',
        'usage' => $r['usage'], 'error' => null, 'error_type' => null, 'status' => $status,
    ];
}

function chatbot_gemini_stream_event(string $event, string $data, array &$state): string
{
    $j = json_decode($data, true);
    if (!is_array($j)) {
        return '';
    }
    if (isset($j['error'])) {
        $state['error'] = (string) ($j['error']['message'] ?? 'Stream error');
        $state['error_type'] = 'server';
        return '';
    }
    $r = chatbot_gemini_read($j);
    $state['model'] ??= $r['model'];
    if ($r['finish'] !== '') {
        $state['stop'] = $r['finish'];
    }
    if ($r['has_usage']) {
        $state['usage'] = $r['usage'];
    }
    if ($r['blocked'] && $r['text'] === '') {
        $state['refusal'] = true;
    }
    $state['text'] .= $r['text'];
    return $r['text'];
}
