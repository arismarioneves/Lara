<?php
declare(strict_types=1);

namespace Lara\Ai;

use Lara\Config;
use Lara\Http;
use Lara\ProviderException;

/** Síntese de voz no servidor. O provedor "browser" é resolvido no cliente (speechSynthesis). */
final class Speech
{
    public const DEFAULTS = [
        'gemini' => ['model' => 'gemini-3.8-flash-lite-tts', 'voice' => 'Kore'],
        'openai' => ['model' => 'gpt-4o-mini-tts', 'voice' => 'coral'],
        'elevenlabs' => ['model' => 'eleven_flash_v2_5', 'voice' => ''],
    ];

    private string $model;
    private string $voice;

    public function __construct(private string $provider, string $model, string $voice, private string $style = '')
    {
        if (!isset(self::DEFAULTS[$provider])) {
            throw new \InvalidArgumentException("Provedor de voz sem síntese no servidor: $provider");
        }
        $this->model = $model !== '' ? $model : self::DEFAULTS[$provider]['model'];
        $this->voice = $voice !== '' ? $voice : self::DEFAULTS[$provider]['voice'];
    }

    /** @return array{bytes: string, ext: string} */
    public function synthesize(string $text): array
    {
        return match ($this->provider) {
            'gemini' => $this->gemini($text),
            'openai' => $this->openAi($text),
            'elevenlabs' => $this->elevenLabs($text),
        };
    }

    private function gemini(string $text): array
    {
        $p = Config::provider('gemini');
        $content = ['type' => 'text', 'text' => $text];
        if ($this->style !== '') {
            $content['annotations'] = [['type' => 'speech_metadata', 'style' => $this->style]];
        }
        $res = Http::postJson($p['base_url'] . '/interactions', ['x-goog-api-key' => $p['api_key']], [
            'model' => $this->model,
            'store' => false,
            'input' => [['type' => 'user_input', 'content' => [$content]]],
            'response_format' => ['type' => 'audio'],
            'generation_config' => ['speech_config' => [['voice' => $this->voice]]],
        ], 60);
        $data = null;
        foreach ((array)($res['steps'] ?? []) as $step) {
            foreach ((array)($step['content'] ?? []) as $c) {
                if (($c['type'] ?? '') === 'audio' && isset($c['data'])) {
                    $data = $c['data'];
                }
            }
        }
        if ($data === null) {
            throw new ProviderException('gemini tts: resposta sem áudio');
        }
        $bytes = base64_decode($data, true);
        if ($bytes === false) {
            throw new ProviderException('gemini tts: áudio inválido');
        }
        // A saída padrão é WAV; se vier PCM cru (24 kHz, 16 bits, mono), empacota em WAV.
        return ['bytes' => str_starts_with($bytes, 'RIFF') ? $bytes : self::wav($bytes, 24000), 'ext' => 'wav'];
    }

    private function openAi(string $text): array
    {
        $p = Config::provider('openai');
        $body = ['model' => $this->model, 'voice' => $this->voice, 'input' => $text, 'response_format' => 'mp3'];
        if ($this->style !== '') {
            $body['instructions'] = 'Fale em português do Brasil. Estilo: ' . $this->style;
        }
        $res = Http::postForBinary($p['base_url'] . '/audio/speech', ['Authorization' => 'Bearer ' . $p['api_key']], $body);
        return ['bytes' => $res['body'], 'ext' => 'mp3'];
    }

    private function elevenLabs(string $text): array
    {
        if ($this->voice === '') {
            throw new \InvalidArgumentException('ElevenLabs: informe o voice ID na configuração de voz');
        }
        $p = Config::provider('elevenlabs');
        $url = $p['base_url'] . '/text-to-speech/' . rawurlencode($this->voice) . '?output_format=mp3_44100_128';
        $body = ['text' => $text, 'model_id' => $this->model];
        // Só os modelos v2.5 (flash/turbo) aceitam forçar idioma; os demais rejeitam o campo
        if (str_contains($this->model, 'v2_5')) {
            $body['language_code'] = 'pt';
        }
        $res = Http::postForBinary($url, ['xi-api-key' => $p['api_key'], 'Accept' => 'audio/mpeg'], $body);
        return ['bytes' => $res['body'], 'ext' => 'mp3'];
    }

    public static function wav(string $pcm, int $rate, int $channels = 1, int $bits = 16): string
    {
        $byteRate = $rate * $channels * intdiv($bits, 8);
        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, $channels, $rate, $byteRate, $channels * intdiv($bits, 8), $bits)
            . 'data' . pack('V', strlen($pcm)) . $pcm;
    }
}
