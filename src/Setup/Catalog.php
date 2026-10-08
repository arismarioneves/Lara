<?php
declare(strict_types=1);

namespace Lara\Setup;

/**
 * O que cada provedor oferece e o que cada papel de IA faz.
 * Fonte única para a validação no servidor e para o assistente de configuração.
 */
final class Catalog
{
    public const THINKING = ['', 'minimal', 'low', 'medium', 'high'];

    /** @return array<string, array> */
    public static function providers(): array
    {
        return [
            'openrouter' => [
                'label' => 'OpenRouter',
                'tagline' => 'Uma chave, centenas de modelos. Sozinho cobre conversa, triagem, transcrição e voz.',
                'recommended' => true,
                'auth' => 'key',
                'key_url' => 'https://openrouter.ai/settings/keys',
                'base_url' => 'https://openrouter.ai/api/v1',
                'roles' => ['chat', 'decider', 'stt', 'tts'],
                'defaults' => [
                    'chat' => ['model' => 'google/gemini-3.8-flash', 'thinking' => 'low'],
                    'decider' => ['model' => 'google/gemini-3.5-flash-lite', 'thinking' => 'minimal'],
                    'stt' => ['model' => 'google/gemini-3.5-transcribe'],
                    'tts' => ['model' => 'google/gemini-3.8-flash-lite-tts'],
                ],
            ],
            'gemini' => [
                'label' => 'Google Gemini',
                'tagline' => 'Direto no Google: Gemini Flash, Gemini Transcribe e vozes TTS. Tem camada gratuita.',
                'auth' => 'key',
                'key_url' => 'https://aistudio.google.com/apikey',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'roles' => ['chat', 'decider', 'stt', 'tts'],
                'defaults' => [
                    'chat' => ['model' => 'gemini-3.8-flash', 'thinking' => 'low'],
                    'decider' => ['model' => 'gemini-3.5-flash-lite', 'thinking' => 'minimal'],
                    'stt' => ['model' => 'gemini-3.5-transcribe'],
                    'tts' => ['model' => 'gemini-3.8-flash-lite-tts'],
                ],
            ],
            'typesafe' => [
                'label' => 'TypeSafe Jev',
                'tagline' => 'Decisor RLCD: classifica cada fala em milissegundos e cobra só a entrada. Acesso antecipado.',
                'auth' => 'key',
                'key_url' => 'https://console.typesafe.ai',
                'base_url' => 'https://api.typesafe.ai/v1',
                'roles' => ['decider'],
                'defaults' => ['decider' => ['model' => 'jev-latest']],
            ],
            'groq' => [
                'label' => 'Groq',
                'tagline' => 'Inferência muito rápida de modelos abertos (Llama, Qwen) e Whisper.',
                'auth' => 'key',
                'key_url' => 'https://console.groq.com/keys',
                'base_url' => 'https://api.groq.com/openai/v1',
                'roles' => ['chat', 'decider', 'stt'],
                'defaults' => [
                    'chat' => ['model' => 'llama-3.3-70b-versatile'],
                    'decider' => ['model' => 'llama-3.1-8b-instant'],
                    'stt' => ['model' => 'whisper-large-v3-turbo'],
                ],
            ],
            'openai' => [
                'label' => 'OpenAI',
                'tagline' => 'Modelos GPT, transcrição e vozes da OpenAI.',
                'auth' => 'key',
                'key_url' => 'https://platform.openai.com/api-keys',
                'base_url' => 'https://api.openai.com/v1',
                'roles' => ['chat', 'decider', 'stt', 'tts'],
                'defaults' => [
                    'chat' => ['model' => 'gpt-5-mini'],
                    'decider' => ['model' => 'gpt-5-nano'],
                    'stt' => ['model' => 'gpt-4o-mini-transcribe'],
                    'tts' => ['model' => 'gpt-4o-mini-tts'],
                ],
            ],
            'elevenlabs' => [
                'label' => 'ElevenLabs',
                'tagline' => 'Vozes muito naturais, inclusive em português.',
                'auth' => 'key',
                'key_url' => 'https://elevenlabs.io/app/settings/api-keys',
                'base_url' => 'https://api.elevenlabs.io/v1',
                'roles' => ['tts'],
                'defaults' => ['tts' => ['model' => 'eleven_flash_v2_5']],
            ],
            'ollama' => [
                'label' => 'Ollama',
                'tagline' => 'Modelos rodando no seu próprio servidor. Sem custo por uso; a qualidade depende da máquina.',
                'auth' => 'url',
                'key_url' => 'https://ollama.com/download',
                'base_url' => '',
                'placeholder' => 'http://127.0.0.1:11434/v1',
                'roles' => ['chat', 'decider'],
                'defaults' => [],
            ],
        ];
    }

    /** Papéis de IA, na ordem em que aparecem. `prefer` define a sugestão automática. */
    public static function roles(): array
    {
        return [
            'chat' => [
                'title' => 'Conversa',
                'subtitle' => 'IA principal',
                'description' => 'É quem pensa e responde. Só é chamada quando a Lara decide falar, então vale um modelo mais capaz.',
                'prefer' => ['gemini', 'openrouter', 'openai', 'groq', 'ollama'],
            ],
            'decider' => [
                'title' => 'Triagem',
                'subtitle' => 'decisor RLCD',
                'description' => 'Avalia cada fala ouvida: é comigo? precisa de resposta? é memória, contexto ou ruído? Roda o tempo todo, então precisa ser rápido e barato.',
                'prefer' => ['typesafe', 'gemini', 'openrouter', 'groq', 'openai', 'ollama'],
            ],
            'stt' => [
                'title' => 'Transcrição online',
                'subtitle' => 'opcional',
                'description' => 'Por padrão a fala vira texto no próprio aparelho, de graça (Whisper local). Um serviço online dá mais precisão; você liga e desliga nas configurações.',
                'prefer' => ['gemini', 'openrouter', 'groq', 'openai'],
            ],
            'tts' => [
                'title' => 'Voz',
                'subtitle' => 'como a Lara fala',
                'description' => 'A voz do navegador é grátis e instantânea. Vozes de IA soam bem mais naturais e custam centavos por resposta.',
                'prefer' => ['elevenlabs', 'gemini', 'openrouter', 'openai'],
            ],
        ];
    }

    public static function supports(string $provider, string $role): bool
    {
        return in_array($role, self::providers()[$provider]['roles'] ?? [], true);
    }
}
