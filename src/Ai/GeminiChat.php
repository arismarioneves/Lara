<?php
declare(strict_types=1);

namespace Lara\Ai;

use Lara\Config;
use Lara\Http;
use Lara\ProviderException;

/** Gemini via Interactions API (stateless: store=false, histórico enviado a cada chamada). */
final class GeminiChat implements Chat
{
    public function __construct(private string $model, private string $thinking = '')
    {
    }

    public function name(): string
    {
        return 'gemini:' . $this->model;
    }

    public function complete(string $system, array $turns, array $opts = []): string
    {
        $p = Config::provider('gemini');
        $input = [];
        foreach ($turns as $t) {
            $input[] = [
                'type' => $t['role'] === 'assistant' ? 'model_output' : 'user_input',
                'content' => [['type' => 'text', 'text' => $t['text']]],
            ];
        }
        $body = [
            'model' => $this->model,
            'store' => false,
            'system_instruction' => $system,
            'input' => $input,
        ];
        if ($this->thinking !== '') {
            $body['generation_config'] = ['thinking_level' => $this->thinking];
        }
        if (!empty($opts['json_schema'])) {
            $body['response_format'] = ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $opts['json_schema']];
        }
        $res = Http::postJson($p['base_url'] . '/interactions', ['x-goog-api-key' => $p['api_key']], $body, 45);
        return self::outputText($res);
    }

    /** Concatena os textos do último passo model_output. */
    public static function outputText(array $res): string
    {
        $text = null;
        foreach ((array)($res['steps'] ?? []) as $step) {
            if (($step['type'] ?? '') !== 'model_output') {
                continue;
            }
            $parts = [];
            foreach ((array)($step['content'] ?? []) as $c) {
                if (($c['type'] ?? '') === 'text') {
                    $parts[] = (string)$c['text'];
                }
            }
            $text = implode('', $parts);
        }
        if ($text === null) {
            throw new ProviderException('gemini: resposta sem model_output (' . ($res['status'] ?? '?') . ')');
        }
        return trim($text);
    }
}
