<?php
// src/OpenRouterAPI.php - Integração com a API do OpenRouter

class OpenRouterAPI
{
    private $apiKey;
    private $apiEndpoint = 'https://openrouter.ai/api/v1/chat/completions';
    private $model = 'google/gemini-2.0-flash-001'; // Modelo padrão

    public function __construct($apiKey, $model = null)
    {
        $this->apiKey = $apiKey;
        if ($model) {
            $this->model = $model;
        }
    }

    /**
     * Define o modelo de IA a ser usado
     *
     * @param string $model ID do modelo no OpenRouter
     */
    public function setModel($model)
    {
        $this->model = $model;
    }

    /**
     * Gera uma resposta baseada na entrada do usuário e no contexto da conversa
     *
     * @param string $userInput Texto de entrada do usuário
     * @param array $context Histórico da conversa para contexto
     * @return string Resposta gerada
     */
    public function generateResponse($userInput, $context = [])
    {
        // Preparar o contexto da conversa
        $messages = [];

        // Adicionar um sistema de prompt para definir o comportamento da Lara
        $messages[] = [
            'role' => 'system',
            'content' => 'Quando te chamarem pelo nome, nestes casos será o usuário falando contigo.

            Se a mensagem não está te chamando ou falando contigo, é uma conversa que o usuário está tendo com pessoas a sua volta, ou são terceiros conversando.

            As minhas falas e os sons em volta do usuário são convertidos em texto e enviados para você.

            Sua missão é ser uma assistente pessoal, ajudando o usuário a encontrar informações e resolver problemas. Analise o contexto e as mensagens anteriores para entender o que o usuário precisa. Você pode fazer perguntas para esclarecer dúvidas, mas evite ser repetitiva ou perguntar coisas que já foram respondidas. Foque em fornecer informações úteis e relevantes.

            Se o usuário não estiver falando diretamente com você, e a conversa não tem nada relevante para ser analisado, apenas responda com \'off\' e continue escutando e analisando o contexto.

            Resumindo, você está me escutando e as pessoas ao meu redor também, e quando surgir nescessidade você me dar suporte em contextos estrategicos.

            Qaundo os outros puderem te ouvir eu te avisarei.

            E lembre-se, quando não precisar reponder, reponda somente a palavra \'off\' minuscula e sem aspas.

            Info relacionadas ao mundo do usuário: Time:' . date('Y-m-d H:i:s') . ', Usuário: Arismário.'
        ];

        // Adicionar mensagens de contexto anteriores
        foreach ($context as $exchange) {
            $messages[] = [
                'role' => 'user',
                'content' => $exchange['user']
            ];

            if (isset($exchange['assistant'])) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $exchange['assistant']
                ];
            }
        }

        // Adicionar a mensagem atual do usuário
        $messages[] = [
            'role' => 'user',
            'content' => $userInput
        ];

        // Preparar os dados para a API
        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 1024,
        ];

        // Configurar a requisição cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiEndpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'HTTP-Referer: https://lara-assistant.com', // domínio
            'X-Title: Lara Assistant' // aplicação
        ]);

        // Executar a requisição
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Verificar se houve erro na requisição
        if ($httpCode !== 200) {
            error_log("Erro na API OpenRouter: $httpCode - $response");
            return "Desculpe, tive um problema ao processar sua solicitação.";
        }

        // Processar a resposta
        $responseData = json_decode($response, true);

        if (isset($responseData['choices'][0]['message']['content'])) {
            return $responseData['choices'][0]['message']['content'];
        } else {
            error_log("Resposta inesperada da API OpenRouter: " . print_r($responseData, true));
            return "Desculpe, não consegui gerar uma resposta adequada.";
        }
    }
}
