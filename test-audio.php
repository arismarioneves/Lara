<?php

// Incluir arquivo de configuração
require_once 'config.php';

// Incluir a classe ElevenLabsAPI
require_once 'src/ElevenLabsAPI.php';

// Função para exibir mensagens de status
function showStatus($message, $success = true)
{
    echo '<div style="padding: 10px; margin: 5px 0; background-color: ' .
        ($success ? '#d4edda' : '#f8d7da') . '; color: ' .
        ($success ? '#155724' : '#721c24') . '; border-radius: 4px;">' .
        $message . '</div>';
}

// Função para testar permissões de diretório
function testDirectoryPermissions($dir)
{
    if (!file_exists($dir)) {
        showStatus("O diretório $dir não existe. Tentando criar...", false);
        if (mkdir($dir, 0755, true)) {
            showStatus("Diretório $dir criado com sucesso.");
        } else {
            showStatus("Falha ao criar diretório $dir.", false);
            return false;
        }
    }

    if (is_writable($dir)) {
        showStatus("O diretório $dir tem permissões de escrita.");
    } else {
        showStatus("O diretório $dir NÃO tem permissões de escrita!", false);
        return false;
    }

    // Testar criação de arquivo
    $testFile = $dir . '/test_' . time() . '.txt';
    if (file_put_contents($testFile, 'Teste de escrita')) {
        showStatus("Arquivo de teste criado com sucesso em $dir.");
        unlink($testFile); // Remover arquivo de teste
    } else {
        showStatus("Falha ao criar arquivo de teste em $dir!", false);
        return false;
    }

    return true;
}

// Exibir cabeçalho
echo '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste de Áudio - Lara</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        h1, h2 {
            color: #333;
        }
        .test-section {
            margin-bottom: 30px;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
        }
        button {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 10px 15px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            margin: 4px 2px;
            cursor: pointer;
            border-radius: 4px;
        }
        button:hover {
            background-color: #45a049;
        }
        pre {
            background-color: #f5f5f5;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <h1>Teste de Áudio - Lara</h1>';

// Testar configuração do ElevenLabs
echo '<div class="test-section">
    <h2>1. Verificação de Configuração</h2>';

// Verificar se a chave API está definida
if (defined('ELEVEN_LABS_API_KEY') && !empty(ELEVEN_LABS_API_KEY)) {
    showStatus("Chave API do ElevenLabs está configurada.");
} else {
    showStatus("Chave API do ElevenLabs NÃO está configurada!", false);
}

// Verificar se o ID da voz está definido
if (defined('ELEVEN_LABS_VOICE_ID') && !empty(ELEVEN_LABS_VOICE_ID)) {
    showStatus("ID da voz do ElevenLabs está configurado.");
} else {
    showStatus("ID da voz do ElevenLabs NÃO está configurado!", false);
}

echo '</div>';

// Testar permissões de diretório
echo '<div class="test-section">
    <h2>2. Verificação de Permissões</h2>';

$audioDir = __DIR__ . '/public/audio';
$dirOk = testDirectoryPermissions($audioDir);

echo '</div>';

// Testar geração de áudio
echo '<div class="test-section">
    <h2>3. Teste de Geração de Áudio</h2>';

if ($dirOk && defined('ELEVEN_LABS_API_KEY') && !empty(ELEVEN_LABS_API_KEY)) {
    // Criar instância da classe ElevenLabsAPI
    $elevenLabs = new ElevenLabsAPI(ELEVEN_LABS_API_KEY);

    // Texto de teste
    $testText = "Olá, este é um teste de geração de áudio para a assistente Lara.";

    try {
        // Gerar áudio
        showStatus("Tentando gerar áudio com o texto: \"$testText\"");
        $audioUrl = $elevenLabs->generateSpeech($testText);

        if (strpos($audioUrl, 'data:audio') === 0) {
            showStatus("Áudio gerado como data URL (fallback).", false);
            echo '<p>URL do áudio: <code>' . htmlspecialchars(substr($audioUrl, 0, 100) . '...') . '</code></p>';
            echo '<p><audio controls><source src="' . htmlspecialchars($audioUrl) . '" type="audio/mpeg">Seu navegador não suporta o elemento de áudio.</audio></p>';
        } else {
            showStatus("Áudio gerado com sucesso!");
            echo '<p>URL do áudio: <code>' . htmlspecialchars($audioUrl) . '</code></p>';

            // Verificar se o arquivo existe
            $audioFilePath = __DIR__ . '/' . $audioUrl;
            if (file_exists($audioFilePath)) {
                showStatus("O arquivo de áudio existe no caminho: $audioFilePath");
                echo '<p>Tamanho do arquivo: ' . filesize($audioFilePath) . ' bytes</p>';
            } else {
                showStatus("O arquivo de áudio NÃO existe no caminho: $audioFilePath", false);
            }

            // Exibir player de áudio
            echo '<p><audio controls><source src="' . htmlspecialchars($audioUrl) . '" type="audio/mpeg">Seu navegador não suporta o elemento de áudio.</audio></p>';

            // Exibir URL completa
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $fullUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/' . $audioUrl;
            echo '<p>URL completa: <a href="' . htmlspecialchars($fullUrl) . '" target="_blank">' . htmlspecialchars($fullUrl) . '</a></p>';
        }
    } catch (Exception $e) {
        showStatus("Erro ao gerar áudio: " . $e->getMessage(), false);
    }
} else {
    showStatus("Não é possível testar a geração de áudio devido a problemas de configuração.", false);
}

echo '</div>';

// Testar reprodução de áudio via JavaScript
echo '<div class="test-section">
    <h2>4. Teste de Reprodução via JavaScript</h2>
    <p>Clique no botão abaixo para testar a função playAudio do JavaScript:</p>
    <div id="js-test-result"></div>
    <button id="test-js-audio">Testar Reprodução JS</button>';

if (isset($audioUrl) && !empty($audioUrl)) {
    echo '<script>
        document.getElementById("test-js-audio").addEventListener("click", function() {
            const resultDiv = document.getElementById("js-test-result");
            resultDiv.innerHTML = "<p>Tentando reproduzir áudio via JavaScript...</p>";

            try {
                const audio = new Audio("' . $audioUrl . '");

                audio.onerror = function(e) {
                    resultDiv.innerHTML += "<p style=\'color: red\'>Erro ao reproduzir áudio: " + e.target.error.message + "</p>";
                };

                audio.oncanplaythrough = function() {
                    resultDiv.innerHTML += "<p style=\'color: green\'>Áudio carregado com sucesso!</p>";
                };

                audio.onplay = function() {
                    resultDiv.innerHTML += "<p style=\'color: green\'>Reprodução iniciada!</p>";
                };

                audio.play().catch(error => {
                    resultDiv.innerHTML += "<p style=\'color: red\'>Erro na reprodução: " + error.message + "</p>";
                });
            } catch (error) {
                resultDiv.innerHTML += "<p style=\'color: red\'>Exceção: " + error.message + "</p>";
            }
        });
    </script>';
} else {
    echo '<p>Nenhuma URL de áudio disponível para teste.</p>';
}

echo '</div>';

// Exibir rodapé
echo '<div class="test-section">
    <h2>Informações do Servidor</h2>
    <pre>';
echo 'PHP Version: ' . phpversion() . "\n";
echo 'Server Software: ' . $_SERVER['SERVER_SOFTWARE'] . "\n";
echo 'Document Root: ' . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo 'Script Path: ' . __FILE__ . "\n";
echo 'Audio Directory: ' . $audioDir . "\n";
echo '</pre>
</div>

</body>
</html>';
