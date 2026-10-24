<?php
declare(strict_types=1);

use Lara\Config;
use Lara\Setup\AiConfig;
use Lara\Setup\ConfigFile;
use Lara\Setup\Installer;

$throws = static function (callable $fn, string $contains = ''): bool {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return $contains === '' || str_contains($e->getMessage(), $contains);
    }
    return false;
};

// ---- AiConfig ----
$onlyOpenRouter = [
    'main' => ['provider' => 'openrouter', 'model' => 'google/gemini-3.8-flash', 'thinking' => 'low'],
    'decider' => ['engine' => 'llm', 'provider' => 'openrouter', 'model' => 'google/gemini-3.5-flash-lite', 'thinking' => 'minimal'],
    'stt' => ['provider' => 'openrouter', 'model' => 'google/gemini-3.5-transcribe'],
    'tts' => ['provider' => 'openrouter', 'model' => 'google/gemini-3.8-flash-lite-tts', 'voices' => [['id' => 'Kore', 'name' => 'Kore'], ['id' => ''], 'lixo']],
];
$ai = AiConfig::normalize($onlyOpenRouter, ['openrouter']);
check('só OpenRouter cobre os quatro papéis', $ai['main']['provider'] === 'openrouter' && $ai['decider']['provider'] === 'openrouter'
    && $ai['stt']['provider'] === 'openrouter' && $ai['tts']['provider'] === 'openrouter');
check('vozes inválidas são descartadas', $ai['tts']['voices'] === [['id' => 'Kore', 'name' => 'Kore']]);

check('provedor sem chave é recusado', $throws(fn() => AiConfig::normalize($onlyOpenRouter, ['gemini']), 'Conversa'));
$jevViaOr = ['decider' => ['engine' => 'systemone', 'provider' => 'openrouter', 'model' => '~typesafe/jev-latest', 'thinking' => 'low']] + $onlyOpenRouter;
$jev = AiConfig::normalize($jevViaOr, ['openrouter']);
check('Jev pelo OpenRouter, sem chave TypeSafe', $jev['decider'] === ['engine' => 'systemone', 'provider' => 'openrouter', 'model' => '~typesafe/jev-latest', 'thinking' => '']);
$jev = AiConfig::normalize(['decider' => ['engine' => 'systemone', 'provider' => 'typesafe', 'model' => 'jev-latest']] + $onlyOpenRouter, ['openrouter', 'typesafe']);
check('Jev direto na TypeSafe', $jev['decider']['provider'] === 'typesafe');
check('System One sem chave do provedor é recusado', $throws(fn() => AiConfig::normalize(
    ['decider' => ['engine' => 'systemone', 'provider' => 'typesafe', 'model' => 'jev-latest']] + $onlyOpenRouter,
    ['openrouter']
), 'Jev'));
check('provedor sem System One (Gemini) não serve de Jev', $throws(fn() => AiConfig::normalize(
    ['decider' => ['engine' => 'systemone', 'provider' => 'gemini', 'model' => 'x']] + $onlyOpenRouter,
    ['openrouter', 'gemini']
)));
check('TypeSafe não serve como adaptador LLM', $throws(fn() => AiConfig::normalize(
    ['decider' => ['engine' => 'llm', 'provider' => 'typesafe', 'model' => 'x']] + $onlyOpenRouter,
    ['openrouter', 'typesafe']
)));
check('provedor que não faz o papel é recusado (ElevenLabs conversando)', $throws(fn() => AiConfig::normalize(
    ['main' => ['provider' => 'elevenlabs', 'model' => 'x']] + $onlyOpenRouter,
    ['openrouter', 'elevenlabs']
)));
check('modelo com espaço ou aspas é recusado', $throws(fn() => AiConfig::normalize(
    ['main' => ['provider' => 'openrouter', 'model' => "x' y"]] + $onlyOpenRouter,
    ['openrouter']
)));

// ---- Mistral como STT/TTS ----
$mistralStt = AiConfig::normalize([
    'main' => ['provider' => 'openrouter', 'model' => 'google/gemini-3.8-flash', 'thinking' => 'low'],
    'decider' => ['engine' => 'llm', 'provider' => 'openrouter', 'model' => 'google/gemini-3.5-flash-lite', 'thinking' => 'minimal'],
    'stt' => ['provider' => 'mistral', 'model' => 'voxtral-mini-transcribe-3'],
    'tts' => ['provider' => 'mistral', 'model' => 'voxtral-mini-tts-3-0', 'voices' => [['id' => 'voice_480', 'name' => 'Gabriela (pt)']]],
], ['openrouter', 'mistral']);
check('Mistral aceito como STT', $mistralStt['stt']['provider'] === 'mistral' && $mistralStt['stt']['model'] === 'voxtral-mini-transcribe-3');
check('Mistral aceito como TTS', $mistralStt['tts']['provider'] === 'mistral' && $mistralStt['tts']['model'] === 'voxtral-mini-tts-3-0');
check('Mistral vozes preservadas', $mistralStt['tts']['voices'] === [['id' => 'voice_480', 'name' => 'Gabriela (pt)']]);
check('Mistral não serve de chat', $throws(fn() => AiConfig::normalize(
    ['main' => ['provider' => 'mistral', 'model' => 'x']] + $onlyOpenRouter,
    ['openrouter', 'mistral']
)));
check('Mistral não serve de decisor', $throws(fn() => AiConfig::normalize(
    ['decider' => ['engine' => 'llm', 'provider' => 'mistral', 'model' => 'x']] + $onlyOpenRouter,
    ['openrouter', 'mistral']
)));

// Catalog: Mistral existe com os papéis certos
check('Catalog reconhece Mistral STT', \Lara\Setup\Catalog::supports('mistral', 'stt'));
check('Catalog reconhece Mistral TTS', \Lara\Setup\Catalog::supports('mistral', 'tts'));
check('Catalog rejeita Mistral chat', !\Lara\Setup\Catalog::supports('mistral', 'chat'));
check('Catalog rejeita Mistral decider', !\Lara\Setup\Catalog::supports('mistral', 'decider'));

$minimal = AiConfig::normalize([
    'main' => $onlyOpenRouter['main'],
    'decider' => $onlyOpenRouter['decider'],
    'stt' => ['provider' => '', 'model' => 'ignorado'],
    'tts' => ['provider' => 'browser', 'model' => 'ignorado', 'voices' => [['id' => 'x']]],
], ['openrouter']);
check('sem transcrição online e com voz do navegador', $minimal['stt'] === ['provider' => '', 'model' => '']
    && $minimal['tts'] === ['provider' => 'browser', 'model' => '', 'voices' => []]);

// ---- Chaves vindas da interface ----
$current = Config::defaults()['providers'];
$current['openrouter']['api_key'] = 'sk-or-antiga';
$p = Installer::providers($current, ['openrouter' => ['api_key' => '']]);
check('chave vazia mantém a salva', $p['openrouter']['api_key'] === 'sk-or-antiga');
$p = Installer::providers($current, ['openrouter' => ['api_key' => ' sk-or-nova ']]);
check('chave nova substitui (sem espaços)', $p['openrouter']['api_key'] === 'sk-or-nova');
$p = Installer::providers($current, ['openrouter' => ['remove' => true]]);
check('remover apaga a chave', $p['openrouter']['api_key'] === '');
$p = Installer::providers($current, ['openrouter' => ['base_url' => 'http://evil.example']]);
check('URL de provedor com chave não é editável pela interface', $p['openrouter']['base_url'] === 'https://openrouter.ai/api/v1');
$p = Installer::providers($current, ['ollama' => ['base_url' => 'http://127.0.0.1:11434/v1/']]);
check('URL do Ollama é aceita e normalizada', $p['ollama']['base_url'] === 'http://127.0.0.1:11434/v1');
check('URL inválida é recusada', $throws(fn() => Installer::providers($current, ['ollama' => ['base_url' => 'javascript:alert(1)']])));
check('chave com espaço no meio é recusada', $throws(fn() => Installer::providers($current, ['gemini' => ['api_key' => 'abc def']])));

// ---- config.php gerado ----
$tricky = Config::defaults();
$tricky['app']['password_hash'] = '$2y$10$abc\\def\'ghi';
$tricky['db']['pass'] = "aspas ' \" barra \\ fim ?> <?php";
$tricky['ai']['tts']['voices'] = [['id' => 'Kore', 'name' => 'Kore'], ['id' => 'v2', 'name' => "Nome com\nquebra"]];
$tmp = sys_get_temp_dir() . '/lara-config-' . bin2hex(random_bytes(4)) . '.php';
ConfigFile::write($tricky, true, $tmp);
$loaded = require $tmp;
check('config.php gerado volta idêntico (aspas, barras, ?>, quebras)', $loaded === $tricky);

$recusou = false;
try {
    ConfigFile::write($tricky, true, $tmp);
} catch (RuntimeException) {
    $recusou = true;
}
check('instalação não sobrescreve um config.php existente', $recusou);

$tricky['db']['name'] = 'outro';
ConfigFile::write($tricky, false, $tmp);
check('reconfiguração regrava o arquivo', (require $tmp)['db']['name'] === 'outro');
@unlink($tmp);

// Listas do arquivo substituem as padrão por inteiro; o resto mescla com os padrões
Config::replace(['ai' => ['tts' => ['provider' => 'gemini', 'voices' => [['id' => 'A', 'name' => 'A']]]]]);
check('mescla do config com os padrões', Config::get('ai.tts.voices') === [['id' => 'A', 'name' => 'A']]
    && Config::get('ai.tts.model') === '' && Config::get('app.session_days') === 30);
Config::replace([]);
