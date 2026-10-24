<?php
declare(strict_types=1);

namespace Lara\Setup;

use Lara\Http;
use Lara\ProviderException;

/**
 * "Testar" de um provedor: valida a chave e lista os modelos por papel.
 * Cada modelo: {id, label?, efforts?, voices?}.
 */
final class Probe
{
    public const GEMINI_VOICES = [
        'Kore', 'Aoede', 'Leda', 'Zephyr', 'Sulafat', 'Achernar', 'Despina', 'Callirrhoe', 'Vindemiatrix',
        'Laomedeia', 'Autonoe', 'Erinome', 'Pulcherrima', 'Achird', 'Sadachbia', 'Gacrux', 'Schedar',
        'Umbriel', 'Algieba', 'Puck', 'Charon', 'Fenrir', 'Orus', 'Enceladus', 'Iapetus', 'Algenib',
        'Rasalgethi', 'Alnilam', 'Zubenelgenubi', 'Sadaltager',
    ];
    public const OPENAI_VOICES = ['coral', 'nova', 'shimmer', 'sage', 'alloy', 'ash', 'ballad', 'echo', 'fable', 'onyx', 'verse'];
    public const MISTRAL_VOICES = [
        ['id' => 'voice_480', 'name' => 'Gabriela (pt)'],
        ['id' => 'voice_478', 'name' => 'Sheila (en-US)'],
        ['id' => 'voice_447', 'name' => 'Margaret (en-US)'],
        ['id' => 'voice_485', 'name' => 'Rob (en-US)'],
        ['id' => 'voice_3150', 'name' => 'James (en-US)'],
        ['id' => 'voice_1787', 'name' => 'Mary (en-UK)'],
        ['id' => 'voice_1786', 'name' => 'Sky (en-UK)'],
        ['id' => 'voice_592', 'name' => 'Marie (fr)'],
        ['id' => 'voice_483', 'name' => 'Elena (es)'],
        ['id' => 'voice_481', 'name' => 'Heidi (de)'],
        ['id' => 'voice_482', 'name' => 'Lorenzo (it)'],
    ];

    /** @return array{models: array{chat: list<array>, stt: list<array>, tts: list<array>, decisions: list<array>}} */
    public static function run(string $provider, string $key, string $baseUrl): array
    {
        $base = rtrim($baseUrl, '/');
        if ($base === '') {
            throw new \InvalidArgumentException('Informe o endereço do servidor');
        }
        try {
            $models = match ($provider) {
                'openrouter' => self::openRouter($base, $key),
                'gemini' => self::gemini($base, $key),
                'typesafe' => self::typesafe($base, $key),
                'groq', 'openai', 'ollama' => self::openAiLike($provider, $base, $key),
                'elevenlabs' => self::elevenLabs($base, $key),
                'mistral' => self::mistral($base, $key),
                default => throw new \InvalidArgumentException("Provedor desconhecido: $provider"),
            };
        } catch (ProviderException $e) {
            throw new ProviderException(self::friendly($e->getMessage()));
        }
        $models += ['chat' => [], 'stt' => [], 'tts' => [], 'decisions' => []];
        foreach ($models as &$list) {
            usort($list, static fn($a, $b) => strcmp($a['id'], $b['id']));
        }
        return ['models' => $models];
    }

    private static function openRouter(string $base, string $key): array
    {
        $auth = ['Authorization' => 'Bearer ' . $key];
        Http::getJson("$base/key", $auth); // 401 se a chave for inválida
        $out = ['chat' => [], 'stt' => [], 'tts' => [], 'decisions' => []];
        foreach ((array)(Http::getJson("$base/models", $auth)['data'] ?? []) as $m) {
            $outMod = (array)($m['architecture']['output_modalities'] ?? ['text']);
            if (!in_array('text', $outMod, true) || in_array('image', $outMod, true) || str_ends_with((string)$m['id'], ':batch')) {
                continue;
            }
            $item = ['id' => (string)$m['id'], 'label' => (string)($m['name'] ?? $m['id'])];
            $price = self::pricePerMillion($m['pricing'] ?? []);
            if ($price !== null) {
                $item['label'] .= " · $price";
            }
            if (isset($m['reasoning']['supported_efforts'])) {
                $efforts = array_values(array_intersect(Catalog::THINKING, (array)$m['reasoning']['supported_efforts']));
                $item['efforts'] = empty($m['reasoning']['mandatory']) ? array_merge([''], $efforts) : $efforts;
            }
            $out['chat'][] = $item;
        }
        foreach ((array)(Http::getJson("$base/models?output_modalities=transcription", $auth)['data'] ?? []) as $m) {
            $out['stt'][] = ['id' => (string)$m['id'], 'label' => (string)($m['name'] ?? $m['id'])];
        }
        foreach ((array)(Http::getJson("$base/models?output_modalities=speech", $auth)['data'] ?? []) as $m) {
            $voices = array_map(static fn($v) => ['id' => (string)$v, 'name' => (string)$v], (array)($m['supported_voices'] ?? []));
            $out['tts'][] = ['id' => (string)$m['id'], 'label' => (string)($m['name'] ?? $m['id']), 'voices' => $voices];
        }
        // Modelos System One (Jev e outros), servidos em /systemone
        foreach ((array)(Http::getJson("$base/models?output_modalities=decisions", $auth)['data'] ?? []) as $m) {
            $item = ['id' => (string)$m['id'], 'label' => (string)($m['name'] ?? $m['id'])];
            $price = self::pricePerMillion($m['pricing'] ?? []);
            if ($price !== null) {
                $item['label'] .= " · $price";
            }
            $out['decisions'][] = $item;
        }
        return $out;
    }

    private static function gemini(string $base, string $key): array
    {
        $res = Http::getJson("$base/models?pageSize=1000", ['x-goog-api-key' => $key]);
        $voices = array_map(static fn($v) => ['id' => $v, 'name' => $v], self::GEMINI_VOICES);
        $out = ['chat' => [], 'stt' => [], 'tts' => []];
        foreach ((array)($res['models'] ?? []) as $m) {
            $id = preg_replace('#^models/#', '', (string)($m['name'] ?? ''));
            $label = (string)($m['displayName'] ?? $id);
            if (str_contains($id, '-tts')) {
                $out['tts'][] = ['id' => $id, 'label' => $label, 'voices' => $voices];
            } elseif (str_contains($id, 'transcribe') && !str_contains($id, 'live')) {
                $out['stt'][] = ['id' => $id, 'label' => $label];
            } elseif (str_starts_with($id, 'gemini-') && !preg_match('/image|transcribe|live|embedding|robotics|omni|native-audio|computer-use|translate/', $id)) {
                $out['chat'][] = ['id' => $id, 'label' => $label];
            }
        }
        return $out;
    }

    private static function typesafe(string $base, string $key): array
    {
        $res = Http::postJson("$base/systemone", ['Authorization' => 'Bearer ' . $key], [
            'state' => 'Teste de conexão da Lara.',
            'model' => 'jev-latest',
            'questions' => ['ok' => ['type' => 'noul', 'instructions' => 'O texto é um teste de conexão?']],
        ], 15);
        $served = (string)($res['model'] ?? 'jev');
        return ['decisions' => [['id' => 'jev-latest', 'label' => "Jev (hoje: $served)"]]];
    }

    private static function openAiLike(string $provider, string $base, string $key): array
    {
        $res = Http::getJson("$base/models", $key !== '' ? ['Authorization' => 'Bearer ' . $key] : []);
        $voices = array_map(static fn($v) => ['id' => $v, 'name' => $v], self::OPENAI_VOICES);
        $out = ['chat' => [], 'stt' => [], 'tts' => []];
        foreach ((array)($res['data'] ?? []) as $m) {
            $id = (string)($m['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (preg_match('/whisper|transcribe/', $id)) {
                $out['stt'][] = ['id' => $id];
            } elseif (str_contains($id, 'tts')) {
                if ($provider === 'openai') {
                    $out['tts'][] = ['id' => $id, 'voices' => $voices];
                }
            } elseif ($provider !== 'openai' || (preg_match('/^(gpt-|o\d|chatgpt)/', $id) && !preg_match('/audio|realtime|image|search|instruct|codex/', $id))) {
                if (!preg_match('/embed|guard|moderation/', $id)) {
                    $out['chat'][] = ['id' => $id];
                }
            }
        }
        return $out;
    }

    private static function elevenLabs(string $base, string $key): array
    {
        $auth = ['xi-api-key' => $key];
        $voices = [];
        foreach ((array)(Http::getJson("$base/voices", $auth)['voices'] ?? []) as $v) {
            $voices[] = ['id' => (string)$v['voice_id'], 'name' => (string)($v['name'] ?? $v['voice_id'])];
        }
        $out = ['tts' => []];
        foreach (Http::getJson("$base/models", $auth) as $m) {
            if (!empty($m['can_do_text_to_speech'])) {
                $out['tts'][] = ['id' => (string)$m['model_id'], 'label' => (string)($m['name'] ?? $m['model_id']), 'voices' => $voices];
            }
        }
        return $out;
    }

    private static function mistral(string $base, string $key): array
    {
        $auth = ['Authorization' => 'Bearer ' . $key];
        $res = Http::getJson("$base/models", $auth);
        $out = ['stt' => [], 'tts' => []];
        foreach ((array)($res['data'] ?? []) as $m) {
            $id = (string)($m['id'] ?? '');
            if ($id === '') continue;
            if (str_contains($id, 'transcribe') && !str_contains($id, 'realtime')) {
                $out['stt'][] = ['id' => $id];
            } elseif (str_contains($id, 'tts') && !str_contains($id, 'realtime')) {
                $out['tts'][] = ['id' => $id, 'voices' => self::MISTRAL_VOICES];
            }
        }
        return $out;
    }

    /** "US$ 0,75 / 3,75 por 1M tokens" a partir do preço por token do OpenRouter. */
    private static function pricePerMillion(array $pricing): ?string
    {
        if (!isset($pricing['prompt'], $pricing['completion'])) {
            return null;
        }
        $in = (float)$pricing['prompt'] * 1e6;
        $out = (float)$pricing['completion'] * 1e6;
        if ($in <= 0 && $out <= 0) {
            return 'grátis';
        }
        $fmt = static fn(float $v) => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
        return 'US$ ' . $fmt($in) . ' / ' . $fmt($out) . ' por 1M tokens';
    }

    private static function friendly(string $msg): string
    {
        return match (true) {
            (bool)preg_match('/HTTP 40[13]|API_KEY_INVALID|API key not valid|User not found/i', $msg) => 'Chave recusada pelo provedor.',
            str_contains($msg, 'HTTP 404') => 'Endereço não encontrado — confira a URL.',
            str_contains($msg, 'HTTP 429') => 'Limite de uso atingido no provedor. Tente de novo em instantes.',
            (bool)preg_match('/HTTP \d+/', $msg) => 'O provedor respondeu com erro: ' . mb_substr($msg, 0, 160),
            default => 'Não foi possível conectar: ' . mb_substr($msg, 0, 160),
        };
    }
}
