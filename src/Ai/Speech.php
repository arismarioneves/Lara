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
        'openrouter' => ['model' => 'google/gemini-3.8-flash-lite-tts', 'voice' => 'Kore'],
        'elevenlabs' => ['model' => 'eleven_flash_v2_5', 'voice' => ''],
        'mistral' => ['model' => 'voxtral-mini-tts-3-0', 'voice' => 'voice_480'],
    ];

    private string $model;
    private string $voice;

    /** @param list<array{id: string, name: string}> $voices vozes do modelo (do assistente de configuração) */
    public function __construct(private string $provider, string $model, string $voice, private string $style = '', array $voices = [])
    {
        if (!isset(self::DEFAULTS[$provider])) {
            throw new \InvalidArgumentException("Provedor de voz sem síntese no servidor: $provider");
        }
        $this->model = $model !== '' ? $model : self::DEFAULTS[$provider]['model'];
        if ($voice === '') {
            // Sem escolha: voz padrão do provedor, se o modelo a oferecer; senão a primeira da lista
            $ids = array_column($voices, 'id');
            $voice = self::DEFAULTS[$provider]['voice'];
            if ($ids && !in_array($voice, $ids, true)) {
                $voice = $ids[0];
            }
        }
        $this->voice = $voice;
    }

    /** @return array{bytes: string, ext: string} */
    public function synthesize(string $text): array
    {
        return match ($this->provider) {
            'gemini' => $this->gemini($text),
            'openai', 'openrouter' => $this->openAiCompatible($text),
            'elevenlabs' => $this->elevenLabs($text),
            'mistral' => $this->mistral($text),
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

    /**
     * /audio/speech no formato OpenAI (OpenAI e OpenRouter). No OpenRouter o formato aceito por todos
     * os modelos é PCM (os Gemini TTS rejeitam mp3): 24 kHz, 16 bits, mono, empacotado em WAV.
     */
    private function openAiCompatible(string $text): array
    {
        $p = Config::provider($this->provider);
        $pcm = $this->provider === 'openrouter';
        $body = ['model' => $this->model, 'voice' => $this->voice, 'input' => $text, 'response_format' => $pcm ? 'pcm' : 'mp3'];
        if ($this->style !== '') {
            $body['instructions'] = 'Fale em português do Brasil. Estilo: ' . $this->style;
        }
        $res = Http::postForBinary($p['base_url'] . '/audio/speech', ['Authorization' => 'Bearer ' . $p['api_key']], $body);
        if (!$pcm || str_starts_with($res['type'], 'audio/mpeg')) {
            return ['bytes' => $res['body'], 'ext' => 'mp3'];
        }
        return ['bytes' => str_starts_with($res['body'], 'RIFF') ? $res['body'] : self::wav($res['body'], 24000), 'ext' => 'wav'];
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

    /**
     * Mistral Voxtral TTS: POST JSON, resposta é JSON com audio_data em base64.
     * Diferente do formato OpenAI (que retorna bytes crus).
     */
    private function mistral(string $text): array
    {
        $p = Config::provider('mistral');
        $body = [
            'model' => $this->model,
            'input' => $text,
            'voice_id' => $this->voice,
            'response_format' => 'wav',
        ];
        $res = Http::postJson(
            $p['base_url'] . '/audio/speech',
            ['Authorization' => 'Bearer ' . $p['api_key']],
            $body,
            60
        );
        $data = (string)($res['audio_data'] ?? '');
        if ($data === '') {
            throw new ProviderException('mistral tts: resposta sem áudio');
        }
        $bytes = base64_decode($data, true);
        if ($bytes === false) {
            throw new ProviderException('mistral tts: áudio inválido');
        }
        return ['bytes' => $bytes, 'ext' => 'wav'];
    }

    public static function wav(string $pcm, int $rate, int $channels = 1, int $bits = 16): string
    {
        $byteRate = $rate * $channels * intdiv($bits, 8);
        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, $channels, $rate, $byteRate, $channels * intdiv($bits, 8), $bits)
            . 'data' . pack('V', strlen($pcm)) . $pcm;
    }
}
