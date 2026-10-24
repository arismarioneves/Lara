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
        return match ($this->provider) {
            'gemini' => $this->gemini($bytes, $mime, $language, $vocabulary),
            'mistral' => $this->mistral($bytes, $mime, $language, $vocabulary),
            default => $this->openAi($bytes, $mime, $language, $vocabulary),
        };
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

    /**
     * Mistral Voxtral: transcrição com diarização e referências de falante opcionais.
     * Quando há speaker_references, o texto retornado já identifica quem falou.
     */
    private function mistral(string $bytes, string $mime, string $language, array $vocabulary): string
    {
        $p = Config::provider('mistral');
        $ext = str_contains($mime, 'wav') ? 'wav' : 'webm';
        $fields = [
            'file' => new \CURLStringFile($bytes, "speech.$ext", $mime),
            'model' => $this->model,
            'language' => strtolower(substr($language, 0, 2)),
        ];
        // Vocabulário técnico via context bias
        if ($vocabulary) {
            $fields['context_prompt'] = implode(', ', $vocabulary);
        }
        // Diarização com referências de falante (se configuradas)
        $refs = self::speakerReferences();
        if ($refs !== null) {
            $fields['diarize'] = 'true';
            $fields['timestamp_granularities'] = 'segment';
            $fields['speaker_references'] = json_encode($refs, JSON_UNESCAPED_UNICODE);
        }
        $res = Http::postMultipart(
            $p['base_url'] . '/audio/transcriptions',
            ['Authorization' => 'Bearer ' . $p['api_key']],
            $fields,
            30
        );
        // Com diarização, concatena segmentos com identificação de falante
        if ($refs !== null && !empty($res['segments'])) {
            return self::formatDiarized($res['segments']);
        }
        return trim((string)($res['text'] ?? ''));
    }

    /**
     * Carrega referências de falante da tabela speaker_references.
     * @return array<string, string>|null mapa nome→base64 do áudio, ou null se não houver
     */
    private static function speakerReferences(): ?array
    {
        $rows = \Lara\Db::all('SELECT name, audio_path FROM speaker_references ORDER BY name');
        if (empty($rows)) {
            return null;
        }
        $refs = [];
        foreach ($rows as $row) {
            $abs = Config::storagePath($row['audio_path']);
            if (is_file($abs)) {
                $refs[$row['name']] = base64_encode((string)file_get_contents($abs));
            }
        }
        return empty($refs) ? null : $refs;
    }

    /** Formata segmentos diarizados como texto com prefixo de falante. */
    private static function formatDiarized(array $segments): string
    {
        $lines = [];
        $current = null;
        $buffer = '';
        foreach ($segments as $seg) {
            $speaker = (string)($seg['speaker_id'] ?? '');
            $text = trim((string)($seg['text'] ?? ''));
            if ($text === '') continue;
            if ($speaker !== $current) {
                if ($buffer !== '') {
                    $lines[] = $buffer;
                }
                $current = $speaker;
                $label = $speaker !== '' ? $speaker : '?';
                $buffer = "[$label] $text";
            } else {
                $buffer .= ' ' . $text;
            }
        }
        if ($buffer !== '') {
            $lines[] = $buffer;
        }
        return implode("\n", $lines);
    }
}
