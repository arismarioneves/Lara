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
            'content' => 'Você é Lara, uma assistente virtual amigável e prestativa. Responda de forma concisa, breve e natural, como em uma conversa. Simule um tom de voz humano, amigável e natural. Evite respostas longas ou excessivamente técnicas. Info relacionadas ao mundo do usuário: Time:' . date('Y-m-d H:i:s') . ', Usuário: Arismário.'
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
