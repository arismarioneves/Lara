<?php
// index.php - Ponto de entrada principal para o agente Lara

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

            // Verificar se o nome "Lara" foi mencionado
            if (stripos($userInput, 'lara') !== false) {
                // Gerenciar a conversa e contexto
                $conversationManager = new ConversationManager();
                $context = $conversationManager->getContext();

                // Obter o modelo atual (ou usar o padrão)
                $currentModel = $_SESSION['current_model'] ?? OPENROUTER_MODEL;

                // Obter resposta do OpenRouter
                $openRouter = new OpenRouterAPI(OPENROUTER_API_KEY, $currentModel);
                $response = $openRouter->generateResponse($userInput, $context);

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

// Obter o modelo atual (ou usar o padrão)
$currentModel = $_SESSION['current_model'] ?? OPENROUTER_MODEL;
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lara IA</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>

<body>
    <div class="container">
        <header>
            <h1>Lara IA</h1>
        </header>

        <main>
            <div class="status-container">
                <div id="status-indicator" class="status-indicator"></div>
                <p id="status-text">Aguardando ativação...</p>
            </div>

            <div class="model-selector">
                <label for="ai-model">Modelo de IA:</label>
                <select id="ai-model" name="ai-model">
                    <!-- Google: Gemini 2.0 Flash / google/gemini-2.0-flash-001 -->
                    <option value="google/gemini-2.0-flash-001" <?= $currentModel === 'google/gemini-2.0-flash-001' ? 'selected' : '' ?>>Gemini 2.0 Flash</option>
                    <!-- OpenAI: GPT-4o-mini / openai/gpt-4o-mini -->
                    <option value="openai/gpt-4o-mini" <?= $currentModel === 'openai/gpt-4o-mini' ? 'selected' : '' ?>>GPT-4o Mini</option>
                    <!-- Meta: Llama 3.3 70B Instruct / meta-llama/llama-3.3-70b-instruct -->
                    <option value="meta-llama/llama-3.3-70b-instruct" <?= $currentModel === 'meta-llama/llama-3.3-70b-instruct' ? 'selected' : '' ?>>Llama 3.3 70B</option>
                    <!-- xAI: Grok 3 Mini Beta / x-ai/grok-3-mini-beta -->
                    <option value="x-ai/grok-3-mini-beta" <?= $currentModel === 'x-ai/grok-3-mini-beta' ? 'selected' : '' ?>>Grok 3 Mini</option>
                    <!-- Mistral: Mistral Small 3 / mistralai/mistral-small-24b-instruct-2501 -->
                    <option value="mistralai/mistral-small-24b-instruct-2501" <?= $currentModel === 'mistralai/mistral-small-24b-instruct-2501' ? 'selected' : '' ?>>Mistral Small 3</option>
                    <!-- Qwen: Qwen3 235B A22B / qwen/qwen3-235b-a22b -->
                    <option value="qwen/qwen3-235b-a22b" <?= $currentModel === 'qwen/qwen3-235b-a22b' ? 'selected' : '' ?>>Qwen 3 235B</option>
                </select>
            </div>

            <div class="conversation-container">
                <div id="conversation-log" class="conversation-log"></div>
            </div>

            <div class="controls">
                <button id="start-button" class="primary-button">Iniciar Lara</button>
                <button id="stop-button" class="secondary-button" disabled>Parar</button>
                <!-- <button id="clear-button" class="tertiary-button">Limpar Conversa</button> -->
            </div>

            <div class="text-input-container">
                <input type="text" id="text-input" placeholder="Digite sua mensagem para Lara..." />
                <button id="send-button" class="primary-button">Enviar</button>
            </div>
        </main>

        <footer>
            <p>Lara é uma assistente virtual projetada para interações por voz, oferecendo suporte eficiente em diversos contextos.</p>
        </footer>
    </div>

    <script src="public/js/main.js"></script>
</body>

</html>