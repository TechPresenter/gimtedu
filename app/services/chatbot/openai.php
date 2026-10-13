<?php
/**
 * OpenAI adapter — Chat Completions API over raw HTTPS (POST https://api.openai.com/v1/chat/completions).
 *
 *   headers  Authorization: Bearer <key>
 *   body     model, messages [system, …history, user(reference data + question)], max_completion_tokens,
 *            temperature (non-reasoning models only) | reasoning_effort (GPT-5 / o-series), stream + stream_options
 * Prompt caching is automatic on OpenAI for long identical prefixes (the static system prompt comes first).
 */

const CHATBOT_OPENAI_URL = 'https://api.openai.com/v1/chat/completions';

/** Reasoning models (gpt-5*, o1/o3/o4…) reject custom temperature and take reasoning_effort instead. */
function chatbot_openai_is_reasoning(string $model): bool
{
    $m = strtolower($model);
    return (bool) preg_match('/^(gpt-5|o\d)/', $m) && !str_contains($m, '-chat');
}

function chatbot_openai_request(array $r): array
{
    $messages = [['role' => 'system', 'content' => (string) $r['system']]];
    foreach ($r['history'] ?? [] as $m) {
        $messages[] = ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => (string) $m['content']];
    }
    $context = trim((string) ($r['context'] ?? ''));
    $messages[] = ['role' => 'user', 'content' => ($context !== '' ? $context . "\n\n" : '') . (string) $r['question']];
    $payload = [
        'model' => $r['model'],
        'messages' => $messages,
        'max_completion_tokens' => max(256, (int) ($r['max_tokens'] ?? 16000)),
    ];
    if (chatbot_openai_is_reasoning($r['model'])) {
        $effort = (string) ($r['effort'] ?? 'low');
        $payload['reasoning_effort'] = in_array($effort, ['minimal', 'low', 'medium', 'high'], true) ? $effort : 'low';
    } elseif (isset($r['temperature']) && $r['temperature'] !== null && $r['temperature'] !== '') {
        $payload['temperature'] = max(0.0, min(2.0, (float) $r['temperature']));
    }
    if (!empty($r['stream'])) {
        $payload['stream'] = true;
        $payload['stream_options'] = ['include_usage' => true];
    }
    return ['url' => CHATBOT_OPENAI_URL, 'headers' => ['Authorization' => 'Bearer ' . $r['key']], 'payload' => $payload];
}

function chatbot_openai_parse(int $status, string $body): array
{
    $j = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($j) || isset($j['error'])) {
        $msg = is_array($j) ? (string) ($j['error']['message'] ?? '') : '';
        $code = is_array($j) ? (string) ($j['error']['code'] ?? $j['error']['type'] ?? '') : '';
        $type = match (true) {
            in_array($code, ['invalid_api_key', 'invalid_authentication'], true) => 'auth',
            $code === 'insufficient_quota' => 'billing',
            $code === 'model_not_found' => 'not_found',
            default => chatbot_http_error_type($status, 0),
        };
        return chatbot_provider_error($status, $msg !== '' ? $msg : ('HTTP ' . $status), $type);
    }
    $choice = $j['choices'][0] ?? [];
    $text = (string) ($choice['message']['content'] ?? '');
    $finish = (string) ($choice['finish_reason'] ?? '');
    $refusal = !empty($choice['message']['refusal']) || $finish === 'content_filter';
    $u = $j['usage'] ?? [];
    return [
        'ok' => true, 'text' => $text, 'model' => $j['model'] ?? null, 'stop' => $finish, 'refusal' => $refusal, 'truncated' => $finish === 'length',
        'usage' => ['input' => (int) ($u['prompt_tokens'] ?? 0), 'output' => (int) ($u['completion_tokens'] ?? 0), 'cached' => (int) ($u['prompt_tokens_details']['cached_tokens'] ?? 0)],
        'error' => null, 'error_type' => null, 'status' => $status,
    ];
}

function chatbot_openai_stream_event(string $event, string $data, array &$state): string
{
    if (trim($data) === '[DONE]') {
        return '';
    }
    $j = json_decode($data, true);
    if (!is_array($j)) {
        return '';
    }
    if (isset($j['error'])) {
        $state['error'] = (string) ($j['error']['message'] ?? 'Stream error');
        $state['error_type'] = 'server';
        return '';
    }
    $state['model'] ??= $j['model'] ?? null;
    if (!empty($j['usage'])) {
        $state['usage']['input'] = (int) ($j['usage']['prompt_tokens'] ?? 0);
        $state['usage']['output'] = (int) ($j['usage']['completion_tokens'] ?? 0);
        $state['usage']['cached'] = (int) ($j['usage']['prompt_tokens_details']['cached_tokens'] ?? 0);
    }
    $choice = $j['choices'][0] ?? null;
    if (!$choice) {
        return '';
    }
    if (!empty($choice['finish_reason'])) {
        $state['stop'] = (string) $choice['finish_reason'];
    }
    $t = (string) ($choice['delta']['content'] ?? '');
    $state['text'] .= $t;
    return $t;
}
