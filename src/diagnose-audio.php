<?php
// diagnose-audio.php - Diagnóstico de problemas com áudio em produção

// Definir cabeçalho como texto plano
header('Content-Type: text/plain');

echo "=== DIAGNÓSTICO DE ÁUDIO EM PRODUÇÃO ===\n\n";

// Informações do servidor
echo "Servidor: " . $_SERVER['SERVER_SOFTWARE'] . "\n";
echo "PHP Version: " . phpversion() . "\n";
echo "URL Base: " . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
    "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "\n\n";

// Verificar diretório de áudio
$audioDir = __DIR__ . '/public/audio';
echo "Diretório de áudio: $audioDir\n";
echo "Existe: " . (file_exists($audioDir) ? 'Sim' : 'Não') . "\n";
echo "É gravável: " . (is_writable($audioDir) ? 'Sim' : 'Não') . "\n\n";

// Listar arquivos de áudio
echo "Arquivos de áudio existentes:\n";
if (file_exists($audioDir) && is_readable($audioDir)) {
    $files = scandir($audioDir);
    $mp3Files = array_filter($files, function ($file) {
        return pathinfo($file, PATHINFO_EXTENSION) === 'mp3';
    });

    if (count($mp3Files) > 0) {
        foreach ($mp3Files as $file) {
            $filePath = $audioDir . '/' . $file;
            echo "- $file (" . filesize($filePath) . " bytes)\n";
        }
    } else {
        echo "Nenhum arquivo MP3 encontrado.\n";
    }
} else {
    echo "Não foi possível ler o diretório de áudio.\n";
}
echo "\n";

// Testar criação de arquivo
echo "Teste de criação de arquivo:\n";
$testFile = $audioDir . '/test_' . time() . '.txt';
if (file_put_contents($testFile, 'Teste de escrita')) {
    echo "Arquivo de teste criado com sucesso: $testFile\n";
    unlink($testFile);
    echo "Arquivo de teste removido.\n";
} else {
    echo "Falha ao criar arquivo de teste.\n";
}
echo "\n";

// Testar cabeçalhos HTTP
echo "Teste de cabeçalhos HTTP para arquivos MP3:\n";
if (count($mp3Files ?? []) > 0) {
    $testMp3 = reset($mp3Files);
    $testUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
        "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) .
        "/public/audio/" . $testMp3;

    echo "Testando URL: $testUrl\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $testUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    echo "Código HTTP: $httpCode\n";
    echo "Cabeçalhos:\n$response\n";

    curl_close($ch);
} else {
    echo "Nenhum arquivo MP3 para testar cabeçalhos.\n";
}
echo "\n";

// Testar criação de arquivo MP3 vazio
echo "Teste de criação de arquivo MP3 vazio:\n";
$emptyMp3 = base64_decode('SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjU4Ljc2LjEwMAAAAAAAAAAAAAAA//tQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAACAAADQgD///////////////////////////////////////////8AAAA8TEFNRTMuMTAwAQAAAAAAAAAAABSAJAJAQgAAgAAAA0L2YLwxAAAAAAAAAAAAAAAAAAAA');
$testMp3File = $audioDir . '/test_empty_' . time() . '.mp3';

if (file_put_contents($testMp3File, $emptyMp3)) {
    echo "Arquivo MP3 vazio criado com sucesso: $testMp3File\n";

    // Testar acesso ao arquivo MP3 vazio
    $testMp3Url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
        "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) .
        "/public/audio/" . basename($testMp3File);

    echo "URL do arquivo MP3 vazio: $testMp3Url\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $testMp3Url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    echo "Código HTTP: $httpCode\n";
    echo "Primeiros 200 bytes de cabeçalhos:\n" . substr($response, 0, 200) . "...\n";

    curl_close($ch);

    // Remover arquivo de teste
    unlink($testMp3File);
    echo "Arquivo MP3 de teste removido.\n";
} else {
    echo "Falha ao criar arquivo MP3 vazio.\n";
}

echo "\n=== FIM DO DIAGNÓSTICO ===\n";
