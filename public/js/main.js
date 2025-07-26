document.addEventListener('DOMContentLoaded', function () {
    // Elementos da interface
    const startButton = document.getElementById('start-button');
    const stopButton = document.getElementById('stop-button');
    // const clearButton = document.getElementById('clear-button');
    const statusIndicator = document.getElementById('status-indicator');
    const statusText = document.getElementById('status-text');
    const conversationLog = document.getElementById('conversation-log');

    // Elementos adicionais
    const textInput = document.getElementById('text-input');
    const sendButton = document.getElementById('send-button');

    // Estado da aplicação
    let isListening = false;
    let recognition = null;
    let recognitionActive = false;
    let isPlayingAudio = false; // Controlar quando áudio está sendo reproduzido
    let shouldResumeListening = false; // Saber se deve retomar a escuta após o áudio

    // Estado de escuta contínua
    let continuousListeningMode = false;
    let continuousListeningEndTime = null;
    let continuousListeningTimer = null;

    // Configuração de tempo de escuta contínua (padrão: 600 segundos = 10 minutos)
    const continuousListeningDuration = window.appConfig?.continuousListeningDuration || 600;

    // Função para processar entrada de texto manual
    function handleTextInput() {
        const text = textInput.value.trim();
        if (text) {
            // Processar o texto (a mensagem será adicionada ao log baseada na resposta)
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

        // Configurar opções para melhor precisão
        recognition.lang = 'pt-BR';
        recognition.continuous = true;
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;

        // Configurações adicionais para melhor precisão
        if ('webkitSpeechRecognition' in window) {
            recognition.webkitGrammars = null;
            recognition.serviceURI = null;
        }

        // Evento quando o reconhecimento retorna resultados
        recognition.onresult = function (event) {
            const result = event.results[event.results.length - 1];

            // Verificar se o resultado é final e tem boa confiança
            if (result.isFinal) {
                const transcript = result[0].transcript.trim();
                const confidence = result[0].confidence || 1;

                console.log("Fala reconhecida:", transcript, "Confiança:", confidence);

                // Filtrar resultados com baixa confiança ou muito curtos
                if (transcript.length < 2) {
                    console.log("Texto muito curto, ignorando:", transcript);
                    return;
                }

                // Verificar se o nome "Lara" foi mencionado
                if (transcript.toLowerCase().includes('lara') && !continuousListeningMode) {
                    // Ativar modo de escuta contínua
                    activateContinuousListening();
                }

                // Enviar para processamento no servidor (a mensagem será adicionada ao log baseada na resposta)
                processUserInput(transcript);
            }
        };

        // Evento de erro
        recognition.onerror = function (event) {
            console.error('Erro no reconhecimento de fala:', event.error);

            // Marcar como não ativo
            recognitionActive = false;

            // Tratamento específico para cada tipo de erro
            if (event.error === 'no-speech') {
                console.log('Nenhuma fala detectada, reiniciando...');
                if (isListening && !isPlayingAudio) {
                    setTimeout(() => {
                        safeStartRecognition();
                    }, 500);
                }
            } else if (event.error === 'aborted') {
                console.log('Reconhecimento abortado, reiniciando...');
                if (isListening && !isPlayingAudio) {
                    setTimeout(() => {
                        safeStartRecognition();
                    }, 300);
                }
            } else if (event.error === 'network') {
                console.log('Erro de rede no reconhecimento, tentando novamente...');
                updateStatus('error', 'Problema de conexão');
                if (isListening) {
                    setTimeout(() => {
                        safeStartRecognition();
                    }, 2000);
                }
            } else if (event.error === 'not-allowed') {
                updateStatus('error', 'Permissão de microfone negada');
                stopListening();
            } else {
                console.log(`Erro ${event.error}, reiniciando...`);
                if (isListening && !isPlayingAudio) {
                    setTimeout(() => {
                        safeStartRecognition();
                    }, 1000);
                }
            }
        };

        // Evento quando o reconhecimento é interrompido
        recognition.onend = function () {
            console.log("Reconhecimento finalizado");

            // Marcar como não ativo
            recognitionActive = false;

            // Reiniciar o reconhecimento se estiver em modo de escuta e não reproduzindo áudio
            if (isListening && !isPlayingAudio) {
                setTimeout(() => {
                    safeStartRecognition();
                }, 300);
            } else if (isListening && isPlayingAudio) {
                console.log("Áudio sendo reproduzido, aguardando para reiniciar reconhecimento");
                shouldResumeListening = true;
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

    // Pausar reconhecimento de voz temporariamente
    function pauseRecognition() {
        if (recognition && recognitionActive) {
            try {
                shouldResumeListening = true;
                recognition.stop();
                recognitionActive = false;
                console.log("Reconhecimento pausado temporariamente");
            } catch (error) {
                console.error("Erro ao pausar reconhecimento:", error);
            }
        }
    }

    // Retomar reconhecimento de voz após pausa
    function resumeRecognition() {
        if (isListening && shouldResumeListening && !isPlayingAudio) {
            shouldResumeListening = false;
            setTimeout(() => {
                safeStartRecognition();
                // Atualizar status quando retomar a escuta
                if (continuousListeningMode) {
                    const remainingTime = formatRemainingTime();
                    updateStatus('active', `Modo de escuta contínua ativo (${remainingTime})`);
                } else {
                    updateStatus('listening', 'Escutando... Diga "Lara" para ativar');
                }
            }, 500); // Pequena pausa antes de retomar
        }
    }

    // Iniciar o reconhecimento com segurança
    function safeStartRecognition() {
        if (!recognition) {
            console.error("Reconhecimento não inicializado");
            return;
        }

        // Não iniciar se áudio estiver sendo reproduzido
        if (isPlayingAudio) {
            console.log("Áudio sendo reproduzido, não iniciando reconhecimento");
            shouldResumeListening = true;
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
        shouldResumeListening = false; // Resetar flag de retomada

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
                    // Adicionar mensagem do usuário ao log (contextualizada e respondida - cor azul)
                    addToConversationLog('user', text, 'normal');

                    // Adicionar resposta ao log
                    addToConversationLog('assistant', data.response);

                    // Reproduzir áudio se disponível
                    if (data.audio_url) {
                        playAudio(data.audio_url);
                    }

                    // Atualizar status durante reprodução
                    if (data.audio_url) {
                        updateStatus('speaking', 'Lara está falando...');
                    } else {
                        updateStatus('active', 'Lara está respondendo...');
                        // Se não há áudio, atualizar status após um tempo
                        setTimeout(() => {
                            if (isListening && !isPlayingAudio) {
                                if (continuousListeningMode) {
                                    const remainingTime = formatRemainingTime();
                                    updateStatus('active', `Modo de escuta contínua ativo (${remainingTime})`);
                                } else {
                                    updateStatus('listening', 'Escutando... Diga "Lara" para ativar');
                                }
                            }
                        }, 2000);
                    }
                } else if (data.status === 'off') {
                    // Adicionar mensagem do usuário ao log (contextualizada mas não respondida - cor cinza)
                    addToConversationLog('user', text, 'listening-only');

                    console.log("Assistente está apenas escutando (resposta 'off')");

                    // Atualizar o status para indicar que a assistente está escutando
                    if (isListening) {
                        if (continuousListeningMode) {
                            const remainingTime = formatRemainingTime();
                            updateStatus('active', `Modo de escuta contínua ativo (${remainingTime})`);
                        } else {
                            updateStatus('listening', 'Escutando... Diga "Lara" para ativar');
                        }
                    }
                } else if (data.status === 'waiting' && !continuousListeningMode) {
                    // Adicionar mensagem do usuário ao log (NÃO contextualizada - cor vermelha)
                    addToConversationLog('user', text, 'not-contextualized');

                    // Se não estiver em modo de escuta contínua, mostrar mensagem de espera
                    console.log("Aguardando ser chamada pelo nome");
                }
            })
            .catch(error => {
                console.error('Erro ao processar texto:', error);
                // Em caso de erro, adicionar a mensagem como não contextualizada
                addToConversationLog('user', text, 'not-contextualized');
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
    function addToConversationLog(role, text, messageType = 'normal') {
        const messageDiv = document.createElement('div');
        let className = `message ${role}-message`;

        // Adicionar classes especiais baseadas no tipo de mensagem
        if (role === 'user') {
            if (messageType === 'not-contextualized') {
                className += ' not-contextualized'; // Vermelho - não vai para contexto
            } else if (messageType === 'listening-only') {
                className += ' listening-only'; // Cinza - vai para contexto mas não responde
            }
            // messageType === 'normal' fica com cor azul padrão
        }

        messageDiv.className = className;

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

        // Pausar reconhecimento de voz antes de reproduzir áudio
        isPlayingAudio = true;
        pauseRecognition();

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

        // Função para finalizar reprodução e retomar reconhecimento
        function finishAudioPlayback() {
            isPlayingAudio = false;
            console.log("Reprodução de áudio finalizada, retomando reconhecimento");
            resumeRecognition();
        }

        // Adicionar tratamento de erros
        audio.onerror = function (e) {
            console.error("Erro ao reproduzir áudio:", e);
            finishAudioPlayback(); // Retomar reconhecimento mesmo em caso de erro

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

        // Evento quando o áudio terminar de ser reproduzido
        audio.onended = function () {
            console.log("Áudio terminou de ser reproduzido");
            finishAudioPlayback();
        };

        // Definir a fonte do áudio
        audio.src = url;

        // Tentar reproduzir o áudio
        audio.play().then(() => {
            console.log("Áudio iniciado com sucesso");
        }).catch(error => {
            console.error("Erro na reprodução do áudio:", error);
            finishAudioPlayback(); // Retomar reconhecimento mesmo se não conseguir reproduzir

            // Se for um problema de interação do usuário, informar
            if (error.name === 'NotAllowedError') {
                console.log("Reprodução automática bloqueada pelo navegador. Interação do usuário necessária.");

                // Adicionar um botão temporário para reproduzir o áudio
                const playButton = document.createElement('button');
                playButton.textContent = "Reproduzir resposta em áudio";
                playButton.className = "audio-play-button";
                playButton.onclick = function () {
                    // Pausar novamente o reconhecimento quando o usuário clicar para reproduzir
                    isPlayingAudio = true;
                    pauseRecognition();

                    audio.play().then(() => {
                        console.log("Áudio iniciado manualmente com sucesso");
                        this.remove();
                    }).catch(err => {
                        console.error("Erro ao reproduzir áudio manualmente:", err);
                        finishAudioPlayback();
                        this.remove();
                    });
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