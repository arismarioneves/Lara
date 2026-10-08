<?php
declare(strict_types=1);

namespace Lara\Ai;

use Lara\Config;
use Lara\Http;
use Lara\ProviderException;

/** Qualquer API compatível com /chat/completions (OpenRouter, Groq, OpenAI, Ollama). */
final class OpenAiChat implements Chat
{
    /** @param string $thinking nível de raciocínio; aplicado só no OpenRouter (`reasoning.effort`), que o normaliza entre modelos */
    public function __construct(private string $provider, private string $model, private string $thinking = '')
    {
    }

    public function name(): string
    {
        return $this->provider . ':' . $this->model;
    }

    public function complete(string $system, array $turns, array $opts = []): string
    {
        $p = Config::provider($this->provider);
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($turns as $t) {
            $messages[] = ['role' => $t['role'], 'content' => $t['text']];
        }
        $body = ['model' => $this->model, 'messages' => $messages];
        if ($this->thinking !== '' && $this->provider === 'openrouter') {
            $body['reasoning'] = ['effort' => $this->thinking];
        }
        if (!empty($opts['json_schema'])) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'decision', 'strict' => true, 'schema' => $opts['json_schema']],
            ];
        }
        $headers = ['HTTP-Referer' => 'https://github.com/arismarioneves/lara', 'X-Title' => 'Lara'];
        if ($p['api_key'] !== '') {
            $headers['Authorization'] = 'Bearer ' . $p['api_key'];
        }
        $res = Http::postJson($p['base_url'] . '/chat/completions', $headers, $body, 45);
        $content = $res['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new ProviderException($this->provider . ': resposta sem conteúdo');
        }
        return trim($content);
    }
}
