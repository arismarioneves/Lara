// public/js/main.js - Lógica do cliente para o agente Lara

document.addEventListener('DOMContentLoaded', function () {
    // Elementos da interface
    const startButton = document.getElementById('start-button');
    const stopButton = document.getElementById('stop-button');
    // const clearButton = document.getElementById('clear-button');
    const statusIndicator = document.getElementById('status-indicator');
    const statusText = document.getElementById('status-text');
    const conversationLog = document.getElementById('conversation-log');
    const modelSelector = document.getElementById('ai-model');

    // Estado da aplicação
    let isListening = false;
    let recognition = null;

    // Configurar o reconhecimento de fala
    function setupSpeechRecognition() {
        // Verificar se o navegador suporta reconhecimento de fala
        if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
            alert('Seu navegador não suporta reconhecimento de fala. Por favor, use Chrome, Edge ou Safari.');
            return false;
        }

        // Criar instância de reconhecimento de fala
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        recognition = new SpeechRecognition();

        // Configurar opções
        recognition.lang = 'pt-BR';
        recognition.continuous = true;
        recognition.interimResults = false;

        // Evento quando o reconhecimento retorna resultados
        recognition.onresult = function (event) {
            const result = event.results[event.results.length - 1];
            const transcript = result[0].transcript.trim();

            // Adicionar texto reconhecido ao log
            addToConversationLog('user', transcript);

            // Enviar para processamento no servidor
            processUserInput(transcript);
        };

        // Evento de erro
        recognition.onerror = function (event) {
            console.error('Erro no reconhecimento de fala:', event.error);
            updateStatus('error', 'Erro no reconhecimento de fala');
        };

        // Evento quando o reconhecimento é interrompido
        recognition.onend = function () {
            // Reiniciar o reconhecimento se estiver em modo de escuta
            if (isListening) {
                recognition.start();
            }
        };

        return true;
    }

    // Iniciar a escuta
    function startListening() {
        if (!recognition && !setupSpeechRecognition()) {
            return;
        }

        try {
            recognition.start();
            isListening = true;
            updateStatus('listening', 'Escutando... Diga "Lara" para ativar');

            // Atualizar botões
            startButton.disabled = true;
            stopButton.disabled = false;

            // Notificar o servidor
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=listen'
            });
        } catch (error) {
            console.error('Erro ao iniciar reconhecimento:', error);
        }
    }

    // Parar a escuta
    function stopListening() {
        if (recognition) {
            recognition.stop();
        }

        isListening = false;
        updateStatus('idle', 'Aguardando ativação...');

        // Atualizar botões
        startButton.disabled = false;
        stopButton.disabled = true;
    }

    // Limpar a conversa
    function clearConversation() {
        // Limpar o log visual
        conversationLog.innerHTML = '';

        // Reiniciar o contexto no servidor
        fetch('index.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=clear_context'
        })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    addSystemMessage('Conversa limpa. Estou pronta para começar de novo!');
                }
            })
            .catch(error => {
                console.error('Erro ao limpar conversa:', error);
            });
    }

    // Mudar o modelo de IA
    function changeModel() {
        const selectedModel = modelSelector.value;

        fetch('index.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=change_model&model=${encodeURIComponent(selectedModel)}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    addSystemMessage(`Modelo alterado para ${getModelDisplayName(selectedModel)}.`);
                }
            })
            .catch(error => {
                console.error('Erro ao mudar modelo:', error);
            });
    }

    // Obter nome amigável do modelo
    function getModelDisplayName(modelId) {
        const modelNames = {
            'google/gemini-2.0-flash-001': 'Google: Gemini 2.0 Flash',
            'openai/gpt-4o-mini': 'OpenAI: GPT-4o-mini',
            'meta-llama/llama-3.3-70b-instruct': 'Meta: Llama 3.3 70B Instruct',
            'x-ai/grok-3-mini-beta': 'xAI: Grok 3 Mini Beta',
            'mistralai/mistral-small-24b-instruct-2501': 'Mistral: Mistral Small 3',
            'qwen/qwen3-235b-a22b': 'Qwen: Qwen3 235B A22B',
        };

        return modelNames[modelId] || modelId;
    }

    // Processar entrada do usuário
    function processUserInput(text) {
        // Enviar texto para o servidor
        fetch('index.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=process_text&text=${encodeURIComponent(text)}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    // Adicionar resposta ao log
                    addToConversationLog('assistant', data.response);

                    // Reproduzir áudio se disponível
                    if (data.audio_url) {
                        playAudio(data.audio_url);
                    }

                    updateStatus('active', 'Lara está respondendo...');
                    setTimeout(() => {
                        if (isListening) {
                            updateStatus('listening', 'Escutando... Diga "Lara" para ativar');
                        }
                    }, 2000);
                }
            })
            .catch(error => {
                console.error('Erro ao processar texto:', error);
            });
    }

    // Adicionar mensagem ao log de conversa
    function addToConversationLog(role, text) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${role}-message`;

        const contentP = document.createElement('p');
        contentP.textContent = text;

        messageDiv.appendChild(contentP);
        conversationLog.appendChild(messageDiv);

        // Rolar para a mensagem mais recente
        conversationLog.scrollTop = conversationLog.scrollHeight;
    }

    // Adicionar mensagem do sistema
    function addSystemMessage(text) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'message system-message';

        const contentP = document.createElement('p');
        contentP.textContent = text;

        messageDiv.appendChild(contentP);
        conversationLog.appendChild(messageDiv);

        // Rolar para a mensagem mais recente
        conversationLog.scrollTop = conversationLog.scrollHeight;
    }

    // Reproduzir áudio
    function playAudio(url) {
        const audio = new Audio(url);
        audio.play();
    }

    // Atualizar indicador de status
    function updateStatus(state, message) {
        statusIndicator.className = `status-indicator ${state}`;
        statusText.textContent = message;
    }

    // Configurar eventos dos botões
    startButton.addEventListener('click', startListening);
    stopButton.addEventListener('click', stopListening);
    // clearButton.addEventListener('click', clearConversation);
    modelSelector.addEventListener('change', changeModel);

    // Inicializar com status padrão
    updateStatus('idle', 'Aguardando ativação...');
});
