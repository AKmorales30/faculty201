<?php
/**
 * The one place the system talks to an AI provider (Google Gemini, REST
 * generateContent endpoint, cURL). Everything else -- the report summary,
 * reminder wording, the chatbot -- uses only the provider-neutral methods
 * below, with messages as ['role' => 'user'|'assistant', 'text' => ...] and
 * tools as ['name', 'description', 'parameters' (JSON schema)]. Switching
 * to another provider (e.g. Ollama for an offline demo) means changing
 * this class and config/ai.php only.
 *
 * Never throws and never breaks a page: on any failure (AI turned off, no
 * key, no internet, timeout, invalid key, rate limit, blocked or empty
 * answer) the methods return null, lastError() says why, and the reason is
 * written to the PHP error log. The API key goes only in the
 * x-goog-api-key request header -- never in a URL, log or error message.
 */
class GeminiService
{
    protected const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private array $config;
    private ?string $lastError = null;

    /** $config: the array from config/ai.php (ai_config() in includes/ai.php). */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** AI switched on and a key configured. */
    public function isAvailable(): bool
    {
        return !empty($this->config['ai_enabled']) && $this->apiKey() !== '';
    }

    /** Short reason the last call failed (no key or personal data in it), or null. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * One prompt in, text out.
     * $options: temperature, max_tokens, json_schema (an OpenAPI-style schema:
     * the answer is then JSON matching it, returned as a decoded array).
     * @return string|array|null
     */
    public function generate(string $system, string $prompt, array $options = [])
    {
        $body = $this->baseBody($system, [['role' => 'user', 'parts' => [['text' => $prompt]]]], $options);
        $response = $this->request($body);
        if ($response === null) { return null; }
        $text = $this->textOf($response);
        if ($text === null) { return null; }
        if (empty($options['json_schema'])) { return $text; }

        $json = json_decode($this->stripCodeFence($text), true);
        if (!is_array($json)) {
            return $this->fail('invalid_json', 'The answer was not valid JSON');
        }
        return $json;
    }

    /**
     * A conversation with function calling. $messages are the earlier turns
     * plus the new question; $tools the functions the model may ask for.
     * Whenever the model asks for one, $run_tool($name, $args) is called --
     * it must do its own permission checks and return an array -- and the
     * result is sent back, until the model answers in text (at most
     * $max_rounds tool rounds; then it must answer with what it has).
     * Returns the answer text, or null.
     */
    public function chat(string $system, array $messages, array $tools, callable $run_tool, int $max_rounds = 4, array $options = []): ?string
    {
        $contents = $this->toContents($messages);
        if (!$contents) { return $this->fail('empty', 'No message to answer'); }
        $declarations = array_map(fn($t) => [
            'name'        => $t['name'],
            'description' => $t['description'],
            'parameters'  => $t['parameters'] ?? ['type' => 'object', 'properties' => new stdClass()],
        ], $tools);

        for ($round = 0; $round <= $max_rounds; $round++) {
            $body = $this->baseBody($system, $contents, $options);
            if ($declarations) {
                $body['tools'] = [['functionDeclarations' => $declarations]];
                // The last round must be answered in words
                $body['toolConfig'] = ['functionCallingConfig' => ['mode' => $round < $max_rounds ? 'AUTO' : 'NONE']];
            }
            $response = $this->request($body);
            if ($response === null) { return null; }

            $content = $response['candidates'][0]['content'] ?? null;
            $calls = [];
            foreach ((array)($content['parts'] ?? []) as $part) {
                if (isset($part['functionCall']['name'])) { $calls[] = $part['functionCall']; }
            }
            if (!$calls) {
                return $this->textOf($response);
            }

            // The model's turn goes back exactly as received -- newer models
            // attach a thoughtSignature to it that must be returned unchanged
            $contents[] = $content;
            $results = [];
            foreach ($calls as $call) {
                $args = isset($call['args']) && is_array($call['args']) ? $call['args'] : [];
                try {
                    $result = $run_tool((string)$call['name'], $args);
                } catch (Throwable $e) {
                    error_log('AI tool ' . $call['name'] . ' failed: ' . $e->getMessage());
                    $result = ['error' => 'This information could not be loaded right now.'];
                }
                $response_part = ['name' => (string)$call['name'], 'response' => ['result' => $result]];
                if (isset($call['id'])) { $response_part['id'] = $call['id']; }
                $results[] = ['functionResponse' => $response_part];
            }
            $contents[] = ['role' => 'user', 'parts' => $results];
        }
        return $this->fail('tool_loop', 'No answer after the tool rounds');
    }

    // -----------------------------------------------------------------
    // Gemini specifics
    // -----------------------------------------------------------------

    private function apiKey(): string
    {
        return trim((string)($this->config['gemini_api_key'] ?? ''));
    }

    private function baseBody(string $system, array $contents, array $options): array
    {
        $body = ['contents' => $contents];
        if ($system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        $gen = [];
        if (isset($options['temperature'])) { $gen['temperature'] = (float)$options['temperature']; }
        if (isset($options['max_tokens']))  { $gen['maxOutputTokens'] = (int)$options['max_tokens']; }
        if (!empty($options['json_schema'])) {
            $gen['responseMimeType'] = 'application/json';
            $gen['responseSchema'] = $options['json_schema'];
        }
        if ($gen) { $body['generationConfig'] = $gen; }
        return $body;
    }

    /** Neutral messages -> Gemini contents; consecutive turns of one role are merged (Gemini expects them to alternate). */
    private function toContents(array $messages): array
    {
        $contents = [];
        foreach ($messages as $m) {
            $text = trim((string)($m['text'] ?? ''));
            if ($text === '') { continue; }
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $last = count($contents) - 1;
            if ($last >= 0 && $contents[$last]['role'] === $role) {
                $contents[$last]['parts'][0]['text'] .= "\n\n" . $text;
            } else {
                $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
            }
        }
        // The conversation must start with the user (context cut-off can leave an answer first)
        while ($contents && $contents[0]['role'] !== 'user') { array_shift($contents); }
        return $contents;
    }

    /** The answer's text (thought summaries left out), or null with the reason. */
    private function textOf(array $response): ?string
    {
        $candidate = $response['candidates'][0] ?? null;
        $text = '';
        foreach ((array)($candidate['content']['parts'] ?? []) as $part) {
            if (isset($part['text']) && empty($part['thought'])) { $text .= $part['text']; }
        }
        $text = trim($text);
        if ($text !== '') { return $text; }
        $reason = $candidate['finishReason'] ?? ($response['promptFeedback']['blockReason'] ?? 'no candidates');
        return $this->fail('empty', 'Empty answer (' . $reason . ')');
    }

    private function stripCodeFence(string $text): string
    {
        return preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text));
    }

    /** POST to generateContent; the decoded response, or null (lastError set, logged). */
    private function request(array $body): ?array
    {
        $this->lastError = null;
        if (empty($this->config['ai_enabled'])) { return $this->fail('disabled', 'AI features are turned off'); }
        if ($this->apiKey() === '') { return $this->fail('no_key', 'No Gemini API key configured'); }
        if (!function_exists('curl_init')) { return $this->fail('no_curl', 'The PHP cURL extension is not enabled'); }

        $model = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($this->config['gemini_model'] ?? ''));
        $ch = curl_init(sprintf(static::ENDPOINT, rawurlencode($model)));
        $options = [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $this->apiKey()],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_CONNECTTIMEOUT => max(1, (int)($this->config['connect_timeout'] ?? 5)),
            CURLOPT_TIMEOUT        => max(3, (int)($this->config['request_timeout'] ?? 25)),
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if (!empty($this->config['ca_bundle'])) { $options[CURLOPT_CAINFO] = $this->config['ca_bundle']; }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno) {
            $code = match ($errno) {
                CURLE_OPERATION_TIMEDOUT => 'timeout',
                CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT => 'no_connection',
                default => 'network',
            };
            return $this->fail($code, 'cURL error ' . $errno . ': ' . $curl_error);
        }
        $json = json_decode((string)$raw, true);
        if ($status !== 200) {
            $message = is_array($json) ? (string)($json['error']['message'] ?? '') : '';
            $code = match (true) {
                $status === 429 => 'rate_limited',
                $status === 400 && stripos($message, 'api key') !== false, $status === 401, $status === 403 => 'invalid_key',
                $status === 404 => 'model_not_found',
                $status >= 500 => 'server_error',
                default => 'http_' . $status,
            };
            return $this->fail($code, 'HTTP ' . $status . ($message !== '' ? ': ' . $message : ''));
        }
        if (!is_array($json)) {
            return $this->fail('invalid_response', 'Response was not JSON');
        }
        return $json;
    }

    /** Record the failure and return null. $detail must not contain the key or personal data. */
    private function fail(string $code, string $detail): mixed
    {
        $detail = str_replace($this->apiKey() !== '' ? $this->apiKey() : "\0", '[key]', $detail);   // belt and braces
        $this->lastError = $code . ': ' . mb_substr($detail, 0, 220);
        error_log('Gemini: ' . $this->lastError);
        return null;
    }
}
