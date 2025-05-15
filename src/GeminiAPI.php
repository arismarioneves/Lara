<?php
// src/GeminiAPI.php - Integração com a API do Google Gemini

class GeminiAPI
{
    private $apiKey;
    private $apiEndpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash:generateContent';

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
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

        // Adicionar mensagens de contexto anteriores
        foreach ($context as $exchange) {
            $messages[] = [
                'role' => 'user',
                'parts' => [['text' => $exchange['user']]]
            ];

            if (isset($exchange['assistant'])) {
                $messages[] = [
                    'role' => 'model',
                    'parts' => [['text' => $exchange['assistant']]]
                ];
            }
        }

        // Adicionar a mensagem atual do usuário
        $messages[] = [
            'role' => 'user',
            'parts' => [['text' => $userInput]]
        ];

        // Preparar os dados para a API
        $data = [
            'contents' => $messages,
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 1024,
            ],
        ];

        // Configurar a requisição cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiEndpoint . '?key=' . $this->apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        // Executar a requisição
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Verificar se houve erro na requisição
        if ($httpCode !== 200) {
            error_log("Erro na API Gemini: $httpCode - $response");
            return "Desculpe, tive um problema ao processar sua solicitação.";
        }

        // Processar a resposta
        $responseData = json_decode($response, true);

        if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            return $responseData['candidates'][0]['content']['parts'][0]['text'];
        } else {
            error_log("Resposta inesperada da API Gemini: " . print_r($responseData, true));
            return "Desculpe, não consegui gerar uma resposta adequada.";
        }
    }
}
