<?php

// Desativar exibição de erros para produção
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Diagnóstico do Servidor para Lara</h1>";

// Verificar versão do PHP
echo "<h2>Versão do PHP</h2>";
echo "<p>Versão atual: " . phpversion() . "</p>";
echo "<p>Recomendado: 7.4 ou superior</p>";

// Verificar extensões necessárias
echo "<h2>Extensões PHP</h2>";
$requiredExtensions = ['curl', 'json', 'session', 'fileinfo'];
echo "<ul>";
foreach ($requiredExtensions as $ext) {
    $loaded = extension_loaded($ext);
    echo "<li>$ext: " . ($loaded ? "✅ Carregada" : "❌ Não carregada") . "</li>";
}
echo "</ul>";

// Verificar permissões de diretório
echo "<h2>Permissões de Diretórios</h2>";
$directories = [
    '.' => 'Diretório raiz',
    './public' => 'Diretório public',
    './public/audio' => 'Diretório de áudio',
    './public/css' => 'Diretório CSS',
    './public/js' => 'Diretório JavaScript'
];

echo "<ul>";
foreach ($directories as $dir => $desc) {
    if (!file_exists($dir)) {
        echo "<li>$desc ($dir): ❌ Não existe</li>";
        continue;
    }

    $isWritable = is_writable($dir);
    echo "<li>$desc ($dir): " . ($isWritable ? "✅ Gravável" : "❌ Não gravável");

    // Informações adicionais
    $perms = fileperms($dir);
    $permsOctal = substr(sprintf('%o', $perms), -4);
    echo " (Permissões: $permsOctal)";

    // Tentar criar um arquivo de teste
    if ($isWritable) {
        $testFile = $dir . '/test_' . time() . '.txt';
        $testContent = 'Teste de escrita: ' . date('Y-m-d H:i:s');

        $writeSuccess = @file_put_contents($testFile, $testContent);
        if ($writeSuccess !== false) {
            echo " ✅ Teste de escrita bem-sucedido";
            // Limpar o arquivo de teste
            @unlink($testFile);
        } else {
            echo " ❌ Teste de escrita falhou";
        }
    }

    echo "</li>";
}
echo "</ul>";

// Verificar configurações de sessão
echo "<h2>Configurações de Sessão</h2>";
echo "<ul>";
echo "<li>session.save_path: " . ini_get('session.save_path') . "</li>";
echo "<li>session.cookie_httponly: " . (ini_get('session.cookie_httponly') ? "Sim" : "Não") . "</li>";
echo "<li>session.use_strict_mode: " . (ini_get('session.use_strict_mode') ? "Sim" : "Não") . "</li>";
echo "</ul>";

// Verificar configurações de CORS
echo "<h2>Cabeçalhos HTTP</h2>";
echo "<p>Verifique se seu servidor está configurado para permitir cabeçalhos CORS adequados.</p>";

// Verificar limites de PHP
echo "<h2>Limites de PHP</h2>";
echo "<ul>";
echo "<li>max_execution_time: " . ini_get('max_execution_time') . " segundos</li>";
echo "<li>memory_limit: " . ini_get('memory_limit') . "</li>";
echo "<li>post_max_size: " . ini_get('post_max_size') . "</li>";
echo "<li>upload_max_filesize: " . ini_get('upload_max_filesize') . "</li>";
echo "</ul>";

// Verificar ambiente
echo "<h2>Ambiente</h2>";
echo "<ul>";
echo "<li>SERVER_SOFTWARE: " . $_SERVER['SERVER_SOFTWARE'] . "</li>";
echo "<li>HTTPS: " . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "Ativo" : "Inativo") . "</li>";
echo "</ul>";

// Verificar se o .env existe e é legível
echo "<h2>Arquivo .env</h2>";
if (file_exists('.env')) {
    echo "<p>✅ Arquivo .env existe</p>";
    if (is_readable('.env')) {
        echo "<p>✅ Arquivo .env é legível</p>";

        // Verificar se as chaves necessárias estão definidas (sem mostrar os valores)
        $envContent = file_get_contents('.env');
        $requiredKeys = ['OPENROUTER_API_KEY', 'ELEVEN_LABS_API_KEY'];

        echo "<ul>";
        foreach ($requiredKeys as $key) {
            $hasKey = preg_match("/^\s*$key\s*=/m", $envContent);
            echo "<li>$key: " . ($hasKey ? "✅ Definida" : "❌ Não definida") . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>❌ Arquivo .env não é legível</p>";
    }
} else {
    echo "<p>❌ Arquivo .env não existe</p>";
}

// Testar conexão com APIs externas
echo "<h2>Teste de Conexão com APIs</h2>";

// Função para testar conexão
function testApiConnection($url, $name)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    echo "<li>$name: ";
    if ($httpCode > 0) {
        echo "✅ Conectado (HTTP $httpCode)";
    } else {
        echo "❌ Falha na conexão: $error";
    }
    echo "</li>";
}

echo "<ul>";
testApiConnection('https://openrouter.ai/api/v1/auth/key', 'OpenRouter');
testApiConnection('https://api.elevenlabs.io/v1/voices', 'ElevenLabs');
echo "</ul>";

echo "<h2>Conclusão</h2>";
echo "<p>Este diagnóstico pode ajudar a identificar problemas comuns com a configuração do servidor.</p>";
echo "<p>Data do diagnóstico: " . date('Y-m-d H:i:s') . "</p>";
