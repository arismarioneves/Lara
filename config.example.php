<?php
/**
 * Copie para config.php (ignorado pelo git) e preencha.
 * Segredos ficam SOMENTE aqui. Preferências (modelos, voz, limiares) ficam no banco
 * e são editadas pela tela de configurações.
 */
return [
    'app' => [
        'timezone' => 'America/Sao_Paulo',
        'debug' => false,
        // Gere com: php bin/lara.php password "sua-senha"
        'password_hash' => '',
        // Duração da sessão (o PWA fica logado no celular)
        'session_days' => 30,
    ],

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'lara',
        'user' => 'root',
        'pass' => '',
    ],

    // Pasta privada (áudios, sessões, logs). Protegida por .htaccess; fora do webroot é ainda melhor.
    'storage_path' => __DIR__ . '/storage',

    // Somente provedores com chave (ou base_url local, no caso do Ollama) aparecem na configuração.
    'providers' => [
        'gemini' => [
            'api_key' => '',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ],
        // TypeSafe Jev (RLCD) — decisões tipadas e calibradas
        'typesafe' => [
            'api_key' => '',
            'base_url' => 'https://api.typesafe.ai/v1',
        ],
        // Compatíveis com a API OpenAI (chat / transcrição / TTS)
        'openrouter' => [
            'api_key' => '',
            'base_url' => 'https://openrouter.ai/api/v1',
        ],
        'groq' => [
            'api_key' => '',
            'base_url' => 'https://api.groq.com/openai/v1',
        ],
        'openai' => [
            'api_key' => '',
            'base_url' => 'https://api.openai.com/v1',
        ],
        'ollama' => [
            'api_key' => '',
            'base_url' => '', // ex.: http://127.0.0.1:11434/v1
        ],
        'elevenlabs' => [
            'api_key' => '',
            'base_url' => 'https://api.elevenlabs.io/v1',
        ],
    ],
];
