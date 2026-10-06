<?php
declare(strict_types=1);

namespace Lara\Ai;

use Lara\Settings;

/** Monta os provedores a partir das configurações salvas. */
final class Ai
{
    public static function chat(string $provider, string $model, string $thinking = ''): Chat
    {
        return $provider === 'gemini' ? new GeminiChat($model, $thinking) : new OpenAiChat($provider, $model);
    }

    public static function main(): Chat
    {
        return self::chat(Settings::get('main_provider'), Settings::get('main_model'), Settings::get('main_thinking'));
    }

    public static function decider(): Decider
    {
        if (Settings::get('decider_engine') === 'jev') {
            return new JevDecider(Settings::get('jev_model'));
        }
        return new LlmDecider(self::chat(Settings::get('decider_provider'), Settings::get('decider_model'), Settings::get('decider_thinking')));
    }

    public static function transcriber(): Transcriber
    {
        return new Transcriber(Settings::get('stt_online_provider'), Settings::get('stt_online_model'));
    }

    /** null = voz do navegador (sem custo, sintetizada no cliente) */
    public static function speech(): ?Speech
    {
        $provider = Settings::get('tts_provider');
        if ($provider === 'browser') {
            return null;
        }
        return new Speech($provider, Settings::get('tts_model'), Settings::get('tts_voice'), Settings::get('tts_style'));
    }
}
