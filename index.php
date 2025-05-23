<?php

// Verificar se está sendo solicitado um arquivo de áudio
$requestUri = $_SERVER['REQUEST_URI'];
if (preg_match('/\/audio\/([a-z0-9]+\.mp3)$/i', $requestUri, $matches)) {
    $audioFile = __DIR__ . '/public/audio/' . $matches[1];

    // Verificar se o arquivo existe
    if (file_exists($audioFile)) {
        // Definir cabeçalhos para o arquivo de áudio
        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . filesize($audioFile));
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=86400');
        header('Access-Control-Allow-Origin: *');

        // Enviar o arquivo
        readfile($audioFile);
        exit;
    } else {
        // Retornar 404 se o arquivo não existir
        header('HTTP/1.0 404 Not Found');
        echo 'Arquivo de áudio não encontrado';
        exit;
    }
}

setlocale(LC_TIME, 'pt_BR', 'pt_BR.utf-8', 'pt_BR.utf-8', 'portuguese');
date_default_timezone_set('America/Sao_Paulo');

// Carregar configurações
require_once 'config.php';

// Carregar classes necessárias
require_once 'src/SpeechRecognition.php';
require_once 'src/OpenRouterAPI.php';
require_once 'src/ElevenLabsAPI.php';
require_once 'src/ConversationManager.php';

// Iniciar sessão para manter o contexto da conversa
session_start();

// Verificar se é uma requisição AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Processar a entrada de áudio/texto
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        // Ação para iniciar a escuta
        if ($action === 'listen') {
            // Iniciar reconhecimento de fala
            $speechRecognition = new SpeechRecognition();
            $result = $speechRecognition->startListening();
            echo json_encode(['status' => 'listening']);
            exit;
        }

        // Ação para processar o texto reconhecido
        if ($action === 'process_text' && isset($_POST['text'])) {
            $userInput = $_POST['text'];
            $continuousMode = isset($_POST['continuous_mode']) && $_POST['continuous_mode'] === 'true';

            // Verificar se o nome "Lara" foi mencionado ou se está em modo contínuo
            if (stripos($userInput, 'lara') !== false || $continuousMode) {
                // Gerenciar a conversa e contexto
                $conversationManager = new ConversationManager();
                $context = $conversationManager->getContext();

                // Obter resposta do OpenRouter
                $openRouter = new OpenRouterAPI(OPENROUTER_API_KEY, OPENROUTER_MODEL, ASSISTANT_PROMPT);
                $response = $openRouter->generateResponse($userInput, $context);

                // Verificar se a resposta é "off"
                if (trim(strtolower($response)) === 'off') {
                    // Se for "off", apenas responder com status especial
                    echo json_encode([
                        'status' => 'off',
                        'message' => 'Assistente está apenas escutando'
                    ]);
                    exit;
                }

                // Atualizar o contexto com a nova interação
                $conversationManager->updateContext($userInput, $response);

                // Gerar áudio da resposta usando ElevenLabs
                $elevenLabs = new ElevenLabsAPI(ELEVEN_LABS_API_KEY);
                $audioUrl = $elevenLabs->generateSpeech($response);

                echo json_encode([
                    'status' => 'success',
                    'response' => $response,
                    'audio_url' => $audioUrl
                ]);
            } else {
                echo json_encode(['status' => 'waiting', 'message' => 'Aguardando ser chamada pelo nome']);
            }
            exit;
        }

        // Ação para alterar o modelo de IA
        if ($action === 'change_model' && isset($_POST['model'])) {
            $_SESSION['current_model'] = $_POST['model'];
            echo json_encode(['status' => 'success', 'message' => 'Modelo alterado com sucesso']);
            exit;
        }

        // Ação para limpar o contexto da conversa
        if ($action === 'clear_context') {
            $conversationManager = new ConversationManager();
            $result = $conversationManager->clearContext();
            echo json_encode(['status' => 'success', 'message' => 'Contexto limpo com sucesso']);
            exit;
        }
    }

    // Resposta padrão para requisições inválidas
    echo json_encode(['status' => 'error', 'message' => 'Requisição inválida']);
    exit;
}

$cssVersion = filemtime(__DIR__ . '/public/css/style.css');
$jsVersion = filemtime(__DIR__ . '/public/js/main.js');
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lara</title>
    <link rel="stylesheet" href="public/css/style.css?v=<?= $cssVersion; ?>">

    <style>
        .header {
            text-align: center;
            margin-bottom: 32px;
        }

        .logo {
            font-size: 2rem;
            font-weight: 600;
            color: #fff;
            letter-spacing: -0.02em;
            margin-bottom: 4px;
        }

        .subtitle {
            font-size: 0.875rem;
            color: #666;
            font-weight: 400;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1 class="logo">Lara</h1>
            <p class="subtitle">AI Assistant</p>
        </div>

        <main>
            <div class="status-container">
                <div id="status-indicator" class="status-indicator idle"></div>
                <p id="status-text">Aguardando ativação...</p>
            </div>

            <div class="conversation-container">
                <div id="conversation-log" class="conversation-log"></div>
            </div>

            <div class="controls">
                <button id="start-button" class="primary-button">Iniciar</button>
                <button id="stop-button" class="secondary-button" disabled>Parar</button>
                <!-- <button id="clear-button" class="tertiary-button">Limpar</button> -->
            </div>

            <div class="text-input-container">
                <input type="text" id="text-input" placeholder="Envie uma mensagem..." />
                <button id="send-button" class="primary-button">Enviar</button>
            </div>
        </main>

        <footer>
            <p>Assistente virtual com IA avançada para interações naturais por voz e texto</p>
        </footer>
    </div>

    <script src="public/js/main.js?v=<?= $jsVersion; ?>"></script>

    <script>
        // Configurações do servidor
        const appConfig = {
            continuousListeningDuration: <?= CONTINUOUS_LISTENING_DURATION; ?>
        };
    </script>
</body>

</html>