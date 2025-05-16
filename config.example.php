<?php
// config.php - Configurações para o agente Lara

// Carregar variáveis de ambiente do arquivo .env se existir
if (file_exists('.env')) {
    $lines = file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
            putenv(sprintf('%s=%s', trim($key), trim($value)));
        }
    }
}

// Definir constantes para as chaves de API
define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: '');
define('OPENROUTER_MODEL', getenv('OPENROUTER_MODEL') ?: 'google/gemini-2.0-flash-001');
define('ELEVEN_LABS_API_KEY', getenv('ELEVEN_LABS_API_KEY') ?: '');

// Configurações adicionais
// define('ELEVEN_LABS_VOICE_ID', 'NQ10OlqJ7vYH6XwegHSW'); // Lucke
define('ELEVEN_LABS_VOICE_ID', 'lWq4KDY8znfkV0DrK8Vb'); // Yasmin Alves
define('DEBUG_MODE', false);

// Configuração do tempo de escuta contínua (em segundos)
define('CONTINUOUS_LISTENING_DURATION', getenv('CONTINUOUS_LISTENING_DURATION') ?: 120); // 2 minutos por padrão

// Verificar se as chaves de API estão configuradas
if (empty(OPENROUTER_API_KEY) || empty(ELEVEN_LABS_API_KEY)) {
    error_log('ERRO: Chaves de API não configuradas.');
}
