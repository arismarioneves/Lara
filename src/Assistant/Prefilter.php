<?php
declare(strict_types=1);

namespace Lara\Assistant;

/**
 * Filtro determinístico, gratuito e instantâneo antes de qualquer IA:
 * descarta ruído óbvio (alucinações típicas do Whisper, sílabas soltas) e detecta a palavra de ativação.
 */
final class Prefilter
{
    /** Frases que o Whisper "inventa" em silêncio/ruído (corpus de legendas do YouTube). */
    private const HALLUCINATIONS = [
        'legendas pela comunidade amara org',
        'legenda adriana zanotto',
        'obrigado por assistir',
        'obrigada por assistir',
        'inscreva se no canal',
        'se inscreva no canal',
        'ativem o sininho',
        'thanks for watching',
        'thank you for watching',
        'subtitles by the amara org community',
        'transcricao e legendas',
        'musica',
        'aplausos',
        'risos',
    ];

    public const NOISE = 'noise';
    public const ADDRESSED = 'addressed';
    public const UNKNOWN = 'unknown';

    /** @param list<string> $wakeWords */
    public static function classify(string $text, array $wakeWords): string
    {
        $norm = self::normalize($text);
        if ($norm === '') {
            return self::NOISE;
        }
        if (in_array($norm, self::HALLUCINATIONS, true)) {
            return self::NOISE;
        }
        $tokens = explode(' ', $norm);
        foreach ($wakeWords as $w) {
            $w = self::normalize($w);
            if ($w === '') {
                continue;
            }
            // Palavra de ativação com várias palavras ("ei lara") → busca por frase
            if (str_contains($w, ' ') ? str_contains(" $norm ", " $w ") : in_array($w, $tokens, true)) {
                return self::ADDRESSED;
            }
        }
        // Só onomatopeias/hesitações ("hum", "ah", "é é") → ruído
        $content = array_filter($tokens, static fn($t) => !in_array($t, ['a', 'ah', 'e', 'eh', 'hum', 'hm', 'uh', 'um', 'ha', 'oh', 'ai', 'ui', 'o', 'u'], true));
        if (!$content) {
            return self::NOISE;
        }
        return self::UNKNOWN;
    }

    /** minúsculas, sem acento, sem pontuação, espaços simples. Também remove marcações [Música] (Música). */
    public static function normalize(string $text): string
    {
        $text = preg_replace('/[\[(][^\])]*[\])]|♪/u', ' ', $text) ?? $text;
        $text = mb_strtolower($text);
        $ascii = class_exists(\Transliterator::class)
            ? \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')->transliterate($text)
            : $text;
        $ascii = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string)$ascii) ?? '';
        return trim(preg_replace('/\s+/', ' ', $ascii) ?? '');
    }
}
