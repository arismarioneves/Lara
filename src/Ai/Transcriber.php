<?php
declare(strict_types=1);

namespace Lara\Ai;

use Lara\Config;
use Lara\Http;

/**
 * Transcrição online (switch "qualidade"). Devolve apenas o texto: o restante do fluxo
 * não sabe se veio daqui ou do Whisper local — a origem fica só na coluna transcript_source.
 */
final class Transcriber
{
    public function __construct(private string $provider, private string $model)
    {
    }

    public function name(): string
    {
        return $this->provider . ':' . $this->model;
    }

    /** @param list<string> $vocabulary nomes próprios para enviesar o reconhecimento */
    public function transcribe(string $bytes, string $mime, string $language, array $vocabulary = []): string
    {
        return $this->provider === 'gemini'
            ? $this->gemini($bytes, $mime, $language, $vocabulary)
            : $this->openAi($bytes, $mime, $language, $vocabulary);
    }

    private function gemini(string $bytes, string $mime, string $language, array $vocabulary): string
    {
        $p = Config::provider('gemini');
        $audio = ['type' => 'audio', 'data' => base64_encode($bytes), 'mime_type' => $mime];
        $body = ['model' => $this->model, 'store' => false];
        if (str_contains($this->model, 'transcribe')) {
            $tc = ['language_codes' => [$language], 'mode' => 'smart'];
            if ($vocabulary) {
                $tc['custom_vocabulary'] = array_values($vocabulary);
            }
            $body['input'] = [$audio];
            $body['generation_config'] = ['transcription_config' => $tc];
        } else {
            // Modelo multimodal genérico: transcrição por instrução
            $body['input'] = [[
                'type' => 'user_input',
                'content' => [
                    $audio,
                    ['type' => 'text', 'text' => "Transcreva literalmente a fala deste áudio ($language). "
                        . 'Responda somente com a transcrição. Se não houver fala inteligível, responda vazio.'],
                ],
            ]];
        }
        $res = Http::postJson($p['base_url'] . '/interactions', ['x-goog-api-key' => $p['api_key']], $body, 30);
        return GeminiChat::outputText($res);
    }

    private function openAi(string $bytes, string $mime, string $language, array $vocabulary): string
    {
        $p = Config::provider($this->provider);
        $ext = str_contains($mime, 'wav') ? 'wav' : 'webm';
        $fields = [
            'file' => new \CURLStringFile($bytes, "speech.$ext", $mime),
            'model' => $this->model,
            'language' => strtolower(substr($language, 0, 2)),
            'response_format' => 'json',
        ];
        if ($vocabulary) {
            $fields['prompt'] = implode(', ', $vocabulary) . '.';
        }
        $res = Http::postMultipart($p['base_url'] . '/audio/transcriptions', ['Authorization' => 'Bearer ' . $p['api_key']], $fields, 30);
        return trim((string)($res['text'] ?? ''));
    }
}
