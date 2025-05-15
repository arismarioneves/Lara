<?php
// index.php - Ponto de entrada principal para o agente Lara

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
    <title>Lara - Assistente IA</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>

<body>
    <div class="container">
        <header>
            <h1>Lara - Sua Assistente Virtual</h1>
        </header>

        <main>
            <div class="status-container">
                <div id="status-indicator" class="status-indicator"></div>
                <p id="status-text">Aguardando ativação...</p>
            </div>

            <div class="model-selector">
                <label for="ai-model">Modelo de IA:</label>
                <select id="ai-model" name="ai-model">
                    <option value="google/gemini-2.0-flash-001" <?= $currentModel === 'google/gemini-2.0-flash-001' ? 'selected' : '' ?>>Gemini 2.0 Flash</option>
                    <option value="google/gemini-1.5-pro" <?= $currentModel === 'google/gemini-1.5-pro' ? 'selected' : '' ?>>Gemini 1.5 Pro</option>
                    <option value="anthropic/claude-3-opus:beta" <?= $currentModel === 'anthropic/claude-3-opus:beta' ? 'selected' : '' ?>>Claude 3 Opus</option>
                    <option value="anthropic/claude-3-sonnet:beta" <?= $currentModel === 'anthropic/claude-3-sonnet:beta' ? 'selected' : '' ?>>Claude 3 Sonnet</option>
                    <option value="anthropic/claude-3-haiku:beta" <?= $currentModel === 'anthropic/claude-3-haiku:beta' ? 'selected' : '' ?>>Claude 3 Haiku</option>
                    <option value="openai/gpt-4o" <?= $currentModel === 'openai/gpt-4o' ? 'selected' : '' ?>>GPT-4o</option>
                    <option value="openai/gpt-4-turbo" <?= $currentModel === 'openai/gpt-4-turbo' ? 'selected' : '' ?>>GPT-4 Turbo</option>
                    <option value="meta-llama/llama-3-70b-instruct" <?= $currentModel === 'meta-llama/llama-3-70b-instruct' ? 'selected' : '' ?>>Llama 3 70B</option>
                </select>
            </div>

            <div class="conversation-container">
                <div id="conversation-log" class="conversation-log"></div>
            </div>

            <div class="controls">
                <button id="start-button" class="primary-button">Iniciar Lara</button>
                <button id="stop-button" class="secondary-button" disabled>Parar</button>
                <button id="clear-button" class="tertiary-button">Limpar Conversa</button>
            </div>
        </main>

        <footer>
            <p>Lara está utilizando OpenRouter para processamento de linguagem natural e ElevenLabs para voz.</p>
        </footer>
    </div>

    <script src="public/js/main.js"></script>
</body>

</html>