<?php
declare(strict_types=1);

namespace Lara\Ai;

use Lara\Config;
use Lara\Settings;

/** Monta os provedores a partir do bloco `ai` do config.php (definido no assistente de configuração). */
final class Ai
{
    public static function chat(string $provider, string $model, string $thinking = ''): Chat
    {
        return $provider === 'gemini' ? new GeminiChat($model, $thinking) : new OpenAiChat($provider, $model, $thinking);
    }

    public static function main(): Chat
    {
        $c = Config::get('ai.main');
        return self::chat($c['provider'], $c['model'], $c['thinking']);
    }

    public static function decider(): Decider
    {
        $c = Config::get('ai.decider');
        if ($c['engine'] === 'jev') {
            return new JevDecider($c['jev_model']);
        }
        return new LlmDecider(self::chat($c['provider'], $c['model'], $c['thinking']));
    }

    /** null = nenhum serviço online configurado (só transcrição local) */
    public static function transcriber(): ?Transcriber
    {
        $c = Config::get('ai.stt');
        return $c['provider'] === '' ? null : new Transcriber($c['provider'], $c['model']);
    }

    /** null = voz do navegador (sem custo, sintetizada no cliente) */
    public static function speech(): ?Speech
    {
        $c = Config::get('ai.tts');
        if ($c['provider'] === 'browser') {
            return null;
        }
        return new Speech($c['provider'], $c['model'], Settings::get('tts_voice'), Settings::get('tts_style'), $c['voices']);
    }

    /** "OpenRouter · google/gemini-3.8-flash" */
    public static function label(string $provider, string $model): string
    {
        $name = \Lara\Setup\Catalog::providers()[$provider]['label'] ?? $provider;
        return $model === '' ? $name : "$name · $model";
    }
}
