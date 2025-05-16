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

    // Elementos adicionais
    const textInput = document.getElementById('text-input');
    const sendButton = document.getElementById('send-button');

    // Estado da aplicação
    let isListening = false;
    let recognition = null;
    let recognitionActive = false;

    // Estado de escuta contínua
    let continuousListeningMode = false;
    let continuousListeningEndTime = null;
    let continuousListeningTimer = null;

    // Configuração de tempo de escuta contínua (padrão: 120 segundos = 2 minutos)
    const continuousListeningDuration = window.appConfig?.continuousListeningDuration || 120;

    // Função para processar entrada de texto manual
    function handleTextInput() {
        const text = textInput.value.trim();
        if (text) {
            // Adicionar ao log de conversa
            addToConversationLog('user', text);

            // Processar o texto
            processUserInput(text);

            // Limpar o campo
            textInput.value = '';
        }
    }

    // Evento para o botão de envio
    sendButton.addEventListener('click', handleTextInput);

    // Evento para pressionar Enter no campo de texto
    textInput.addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            handleTextInput();
        }
    });

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

            console.log("Fala reconhecida:", transcript);

            // Adicionar texto reconhecido ao log
            addToConversationLog('user', transcript);

            // Verificar se o nome "Lara" foi mencionado
            if (transcript.toLowerCase().includes('lara') && !continuousListeningMode) {
                // Ativar modo de escuta contínua
                activateContinuousListening();
            }

            // Enviar para processamento no servidor
            processUserInput(transcript);
        };

        // Evento de erro
        recognition.onerror = function (event) {
            console.error('Erro no reconhecimento de fala:', event.error);

            // Marcar como não ativo
            recognitionActive = false;

            // Tratamento específico para cada tipo de erro
            if (event.error === 'no-speech' || event.error === 'aborted') {
                console.log(`Reconhecimento ${event.error}, reiniciando...`);
                // Reiniciar após um pequeno atraso
                if (isListening) {
                    setTimeout(() => {
                        safeStartRecognition();
                    }, 300);
                }
            } else {
                updateStatus('error', `Erro: ${event.error}`);
            }
        };

        // Evento quando o reconhecimento é interrompido
        recognition.onend = function () {
            console.log("Reconhecimento finalizado");

            // Marcar como não ativo
            recognitionActive = false;

            // Reiniciar o reconhecimento se estiver em modo de escuta
            if (isListening) {
                setTimeout(() => {
                    safeStartRecognition();
                }, 200);
            }
        };

        return true;
    }

    // Ativar modo de escuta contínua
    function activateContinuousListening() {
        // Cancelar qualquer temporizador anterior
        if (continuousListeningTimer) {
            clearTimeout(continuousListeningTimer);
        }

        // Ativar modo de escuta contínua
        continuousListeningMode = true;

        // Definir o tempo de término
        continuousListeningEndTime = Date.now() + (continuousListeningDuration * 1000);

        // Atualizar status
        updateStatus('active', `Modo de escuta contínua ativado por ${formatDuration(continuousListeningDuration)}`);

        // Configurar temporizador para desativar o modo após o tempo configurado
        continuousListeningTimer = setTimeout(() => {
            deactivateContinuousListening();
        }, continuousListeningDuration * 1000);

        // Adicionar mensagem do sistema
        addSystemMessage(`Modo de escuta contínua ativado por ${formatDuration(continuousListeningDuration)}`);

        // Iniciar atualizações de status periódicas
        startStatusUpdates();
    }

    // Desativar modo de escuta contínua
    function deactivateContinuousListening() {
        continuousListeningMode = false;
        continuousListeningEndTime = null;

        if (continuousListeningTimer) {
            clearTimeout(continuousListeningTimer);
            continuousListeningTimer = null;
        }

        // Atualizar status
        if (isListening) {
            updateStatus('listening', 'Escutando... Diga "Lara" para ativar');
        }

        // Adicionar mensagem do sistema
        addSystemMessage('Modo de escuta contínua desativado');
    }

    // Iniciar atualizações periódicas do status
    function startStatusUpdates() {
        // Atualizar a cada segundo
        const statusInterval = setInterval(() => {
            if (!continuousListeningMode) {
                clearInterval(statusInterval);
                return;
            }

            const remainingTime = formatRemainingTime();
            if (remainingTime) {
                updateStatus('active', `Modo de escuta contínua ativo (${remainingTime})`);
            }
        }, 1000);
    }

    // Formatar duração em formato legível
    function formatDuration(seconds) {
        if (!seconds && seconds !== 0) return '';

        if (seconds < 60) {
            return `${seconds} ${seconds === 1 ? 'segundo' : 'segundos'}`;
        } else {
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = seconds % 60;

            if (remainingSeconds === 0) {
                return `${minutes} ${minutes === 1 ? 'minuto' : 'minutos'}`;
            } else {
                return `${minutes} ${minutes === 1 ? 'minuto' : 'minutos'} e ${remainingSeconds} ${remainingSeconds === 1 ? 'segundo' : 'segundos'}`;
            }
        }
    }

    // Iniciar o reconhecimento com segurança
    function safeStartRecognition() {
        if (!recognition) {
            console.error("Reconhecimento não inicializado");
            return;
        }

        // Só iniciar se não estiver ativo
        if (!recognitionActive) {
            try {
                recognition.start();
                recognitionActive = true;
                console.log("Reconhecimento iniciado com sucesso");
            } catch (error) {
                console.error("Erro ao iniciar reconhecimento:", error);
                // Em caso de erro, marcar como não ativo
                recognitionActive = false;
            }
        } else {
            console.log("Reconhecimento já está ativo, ignorando chamada para iniciar");
        }
    }

    // Iniciar a escuta
    function startListening() {
        if (!recognition && !setupSpeechRecognition()) {
            return;
        }

        try {
            // Parar qualquer instância anterior
            if (recognitionActive) {
                try {
                    recognition.stop();
                    // A função onend irá reiniciar o reconhecimento
                } catch (e) {
                    console.error("Erro ao parar reconhecimento:", e);
                    recognitionActive = false;
                }
            } else {
                // Iniciar diretamente se não estiver ativo
                safeStartRecognition();
            }

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
            }).catch(error => {
                console.error("Erro ao notificar servidor:", error);
            });
        } catch (error) {
            console.error('Erro ao configurar reconhecimento:', error);
            updateStatus('error', 'Falha ao iniciar reconhecimento');
        }
    }

    // Parar a escuta
    function stopListening() {
        isListening = false;

        // Desativar modo de escuta contínua se estiver ativo
        if (continuousListeningMode) {
            deactivateContinuousListening();
        }

        if (recognition && recognitionActive) {
            try {
                recognition.stop();
                recognitionActive = false;
            } catch (error) {
                console.error("Erro ao parar reconhecimento:", error);
            }
        }

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
            body: `action=process_text&text=${encodeURIComponent(text)}&continuous_mode=${continuousListeningMode}`
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
                            if (continuousListeningMode) {
                                const remainingTime = formatRemainingTime();
                                updateStatus('active', `Modo de escuta contínua ativo (${remainingTime})`);
                            } else {
                                updateStatus('listening', 'Escutando... Diga "Lara" para ativar');
                            }
                        }
                    }, 2000);
                } else if (data.status === 'waiting' && !continuousListeningMode) {
                    // Se não estiver em modo de escuta contínua, mostrar mensagem de espera
                    console.log("Aguardando ser chamada pelo nome");
                }
            })
            .catch(error => {
                console.error('Erro ao processar texto:', error);
            });
    }

    // Formatar o tempo restante de escuta contínua
    function formatRemainingTime() {
        if (!continuousListeningEndTime) return '';

        const now = Date.now();
        const remainingMs = Math.max(0, continuousListeningEndTime - now);
        const remainingSec = Math.ceil(remainingMs / 1000);

        return formatDuration(remainingSec);
    }

    // Função para garantir o scroll automático
    function scrollToBottom() {
        // Usar requestAnimationFrame para garantir que o scroll ocorra após a renderização
        requestAnimationFrame(() => {
            conversationLog.scrollTop = conversationLog.scrollHeight;
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
        scrollToBottom();
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
        scrollToBottom();
    }

    // Reproduzir áudio
    function playAudio(url) {
        console.log("Tentando reproduzir áudio:", url);

        // Verificar se a URL é válida
        if (!url) {
            console.error("URL de áudio inválida ou vazia");
            return;
        }

        // Verificar se a URL é uma URL de dados (data URL)
        const isDataUrl = url.startsWith('data:audio');

        // Se não for uma data URL e não começar com http/https, considerar como URL relativa
        if (!isDataUrl && !url.startsWith('http://') && !url.startsWith('https://')) {
            // Garantir que a URL relativa esteja correta
            // Não modificar o caminho se já estiver no formato correto
            console.log("Usando URL relativa:", url);
        }

        console.log("URL de áudio final:", url);

        const audio = new Audio();

        // Adicionar tratamento de erros
        audio.onerror = function (e) {
            console.error("Erro ao reproduzir áudio:", e);
            // Tentar reproduzir como fallback um áudio silencioso
            if (!isDataUrl) {
                console.log("Tentando reproduzir áudio silencioso como fallback");
                const silentAudio = new Audio('data:audio/mp3;base64,SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjU4Ljc2LjEwMAAAAAAAAAAAAAAA//tQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAACAAADQgD///////////////////////////////////////////8AAAA8TEFNRTMuMTAwAQAAAAAAAAAAABSAJAJAQgAAgAAAA0L2YLwxAAAAAAAAAAAAAAAAAAAA');
                silentAudio.play().catch(err => console.error("Não foi possível reproduzir áudio silencioso:", err));
            }
        };

        // Adicionar evento para quando o áudio estiver pronto
        audio.oncanplaythrough = function () {
            console.log("Áudio pronto para reprodução");
        };

        // Definir a fonte do áudio
        audio.src = url;

        // Tentar reproduzir o áudio
        audio.play().catch(error => {
            console.error("Erro na reprodução do áudio:", error);

            // Se for um problema de interação do usuário, informar
            if (error.name === 'NotAllowedError') {
                console.log("Reprodução automática bloqueada pelo navegador. Interação do usuário necessária.");

                // Adicionar um botão temporário para reproduzir o áudio
                const playButton = document.createElement('button');
                playButton.textContent = "Reproduzir resposta em áudio";
                playButton.className = "audio-play-button";
                playButton.onclick = function () {
                    audio.play();
                    this.remove();
                };

                // Adicionar o botão ao log de conversa
                const lastMessage = document.querySelector('.assistant-message:last-child');
                if (lastMessage) {
                    lastMessage.appendChild(playButton);
                    // Garantir que o botão fique visível
                    scrollToBottom();
                }
            }
        });
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

    // Observador de mutações para garantir scroll automático quando novas mensagens são adicionadas
    const conversationObserver = new MutationObserver(() => {
        scrollToBottom();
    });

    // Iniciar observação do container de conversa
    conversationObserver.observe(conversationLog, {
        childList: true,
        subtree: true
    });
});