<?php
/**
 * Anthropic Claude adapter — Messages API over raw HTTPS (POST https://api.anthropic.com/v1/messages).
 *
 * Request shape (per the Claude API reference used for this project):
 *   headers  x-api-key, anthropic-version: 2023-06-01, content-type: application/json
 *            (+ anthropic-beta: server-side-fallback-2026-07-01 when refusal fallbacks are on)
 *   body     model, max_tokens, system: [{type: text, text, cache_control: {type: ephemeral}}]   ← stable prefix, cached
 *            messages: earlier turns (plain text) + final user turn [{reference data}, {question}]
 *            output_config.effort (models that support effort), temperature (only models that still accept
 *            sampling parameters), fallbacks: "default" (Claude Opus 5 / 5.5, Fable 5.1, Sonnet 5.5), stream
 *   No `thinking` field: current models run adaptive thinking by default and effort controls its depth; thinking
 *   counts toward max_tokens, so max_tokens is sized generously. Responses are read by block type (text blocks only);
 *   stop_reason "refusal" is handled before reading content.
 */

const CHATBOT_ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
const CHATBOT_ANTHROPIC_VERSION = '2023-06-01';
const CHATBOT_ANTHROPIC_FALLBACK_BETA = 'server-side-fallback-2026-07-01';

/** What a Claude model accepts: effort, sampling (temperature), server-side refusal fallbacks ("default" form). */
function chatbot_anthropic_caps(string $model): array
{
    $m = strtolower(trim($model));
    $caps = ['effort' => true, 'sampling' => false, 'fallbacks' => false];
    if (str_starts_with($m, 'claude-3') || str_starts_with($m, 'claude-2') || str_starts_with($m, 'claude-instant')) {
        return ['effort' => false, 'sampling' => true, 'fallbacks' => false];
    }
    if (!preg_match('/^claude-(opus|sonnet|haiku|fable|mythos)-(\d+)(?:-(\d{1,2}))?(?:-\d{8})?$/', $m, $mm)) {
        return $caps; // unknown / future id: no sampling params (newer models reject them), effort on
    }
    [$family, $major, $minor] = [$mm[1], (int) $mm[2], isset($mm[3]) ? (int) $mm[3] : 0];
    $v = $major + $minor / 10;
    if (in_array($family, ['fable', 'mythos'], true)) {
        return ['effort' => true, 'sampling' => false, 'fallbacks' => $family === 'fable' && $v >= 5.1];
    }
    $caps['sampling'] = $v < 4.7;
    $caps['effort'] = match ($family) {
        'opus' => $v >= 4.5,
        'sonnet' => $v >= 4.6,
        'haiku' => $v >= 5,
        default => true,
    };
    $caps['fallbacks'] = ($family === 'opus' && $v >= 5) || ($family === 'sonnet' && $v >= 5.5);
    return $caps;
}

/**
 * Build the HTTP request.
 * @param array $r model, key, system, context, history [{role, content}], question, max_tokens, temperature, effort, stream, fallbacks
 * @return array{url: string, headers: array, payload: array}
 */
function chatbot_anthropic_request(array $r): array
{
    $caps = chatbot_anthropic_caps($r['model']);
    $messages = [];
    foreach ($r['history'] ?? [] as $m) {
        $messages[] = ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => (string) $m['content']];
    }
    $final = [];
    if (trim((string) ($r['context'] ?? '')) !== '') {
        $final[] = ['type' => 'text', 'text' => $r['context']];
    }
    $final[] = ['type' => 'text', 'text' => (string) $r['question']];
    $messages[] = ['role' => 'user', 'content' => $final];

    $payload = [
        'model' => $r['model'],
        'max_tokens' => max(256, (int) ($r['max_tokens'] ?? 16000)),
        'system' => [['type' => 'text', 'text' => (string) $r['system'], 'cache_control' => ['type' => 'ephemeral']]],
        'messages' => $messages,
    ];
    if ($caps['effort'] && !empty($r['effort'])) {
        $payload['output_config'] = ['effort' => $r['effort']];
    }
    if ($caps['sampling'] && isset($r['temperature']) && $r['temperature'] !== null && $r['temperature'] !== '') {
        $payload['temperature'] = max(0.0, min(1.0, (float) $r['temperature']));
    }
    $headers = ['x-api-key' => (string) $r['key'], 'anthropic-version' => CHATBOT_ANTHROPIC_VERSION];
    if ($caps['fallbacks'] && ($r['fallbacks'] ?? true)) {
        $payload['fallbacks'] = 'default';
        $headers['anthropic-beta'] = CHATBOT_ANTHROPIC_FALLBACK_BETA;
    }
    if (!empty($r['stream'])) {
        $payload['stream'] = true;
    }
    return ['url' => CHATBOT_ANTHROPIC_URL, 'headers' => $headers, 'payload' => $payload];
}

/** Error result shared by the adapters. */
function chatbot_provider_error(int $status, string $message, string $type, int $errno = 0): array
{
    return ['ok' => false, 'text' => '', 'model' => null, 'stop' => null, 'refusal' => false, 'truncated' => false,
        'usage' => ['input' => 0, 'output' => 0, 'cached' => 0], 'error' => $message, 'error_type' => $type, 'status' => $status, 'errno' => $errno];
}

/** Parse a non-streaming Messages API response. */
function chatbot_anthropic_parse(int $status, string $body): array
{
    $j = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($j) || ($j['type'] ?? '') === 'error') {
        $msg = is_array($j) ? (string) ($j['error']['message'] ?? '') : '';
        $etype = is_array($j) ? (string) ($j['error']['type'] ?? '') : '';
        $type = match ($etype) {
            'authentication_error', 'permission_error' => 'auth',
            'billing_error' => 'billing',
            'not_found_error' => 'not_found',
            'rate_limit_error' => 'rate_limit',
            'overloaded_error' => 'overloaded',
            'api_error' => 'server',
            'request_too_large' => 'too_large',
            'invalid_request_error' => 'bad_request',
            default => chatbot_http_error_type($status, 0),
        };
        return chatbot_provider_error($status, $msg !== '' ? $msg : ('HTTP ' . $status . ($body === '' ? ' (empty response)' : '')), $type);
    }
    $text = '';
    foreach ($j['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= (string) ($block['text'] ?? '');
        }
    }
    $u = $j['usage'] ?? [];
    $stop = (string) ($j['stop_reason'] ?? '');
    return [
        'ok' => true, 'text' => $text, 'model' => $j['model'] ?? null, 'stop' => $stop, 'refusal' => $stop === 'refusal', 'truncated' => $stop === 'max_tokens',
        'usage' => [
            'input' => (int) ($u['input_tokens'] ?? 0) + (int) ($u['cache_creation_input_tokens'] ?? 0) + (int) ($u['cache_read_input_tokens'] ?? 0),
            'output' => (int) ($u['output_tokens'] ?? 0),
            'cached' => (int) ($u['cache_read_input_tokens'] ?? 0),
        ],
        'error' => null, 'error_type' => null, 'status' => $status,
    ];
}

/**
 * Streaming event reducer: updates $state (text, model, stop, usage, error) and returns the text delta (or '').
 * Events: message_start, content_block_start/delta/stop, message_delta, message_stop, ping, error.
 */
function chatbot_anthropic_stream_event(string $event, string $data, array &$state): string
{
    $j = json_decode($data, true);
    if (!is_array($j)) {
        return '';
    }
    $type = $j['type'] ?? $event;
    switch ($type) {
        case 'message_start':
            $u = $j['message']['usage'] ?? [];
            $state['model'] = $j['message']['model'] ?? null;
            $state['usage']['input'] = (int) ($u['input_tokens'] ?? 0) + (int) ($u['cache_creation_input_tokens'] ?? 0) + (int) ($u['cache_read_input_tokens'] ?? 0);
            $state['usage']['cached'] = (int) ($u['cache_read_input_tokens'] ?? 0);
            return '';
        case 'content_block_delta':
            if (($j['delta']['type'] ?? '') === 'text_delta') {
                $t = (string) ($j['delta']['text'] ?? '');
                $state['text'] .= $t;
                return $t;
            }
            return '';
        case 'message_delta':
            if (isset($j['delta']['stop_reason'])) {
                $state['stop'] = (string) $j['delta']['stop_reason'];
            }
            if (isset($j['usage']['output_tokens'])) {
                $state['usage']['output'] = (int) $j['usage']['output_tokens'];
            }
            return '';
        case 'error':
            $state['error'] = (string) ($j['error']['message'] ?? 'Stream error');
            $state['error_type'] = ($j['error']['type'] ?? '') === 'overloaded_error' ? 'overloaded' : 'server';
            return '';
    }
    return '';
}

/** True when a 400 is caused by the optional fallback beta (header not enabled for the org / field rejected). */
function chatbot_anthropic_beta_rejected(array $res): bool
{
    return ($res['status'] ?? 0) === 400 && preg_match('/anthropic-beta|fallbacks|server-side-fallback/i', (string) $res['error']);
}
