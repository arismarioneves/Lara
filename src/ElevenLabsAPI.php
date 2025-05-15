<?php
// src/ElevenLabsAPI.php - Integração com a API do ElevenLabs

class ElevenLabsAPI
{
    private $apiKey;
    private $apiEndpoint = 'https://api.elevenlabs.io/v1/text-to-speech';

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Gera áudio a partir do texto usando ElevenLabs
     *
     * @param string $text Texto para converter em fala
     * @param string $voiceId ID da voz a ser usada (opcional)
     * @return string URL do arquivo de áudio gerado
     */
    public function generateSpeech($text, $voiceId = null)
    {
        // Usar o ID de voz padrão se não for especificado
        if ($voiceId === null) {
            $voiceId = ELEVEN_LABS_VOICE_ID;
        }

        // Diretório para salvar os arquivos de áudio
        $audioDir = __DIR__ . '/../public/audio';
        if (!file_exists($audioDir)) {
            mkdir($audioDir, 0755, true);
        }

        // Nome do arquivo baseado em hash do texto e timestamp
        $filename = md5($text . time()) . '.mp3';
        $filePath = $audioDir . '/' . $filename;

        // Configurações da voz
        $data = [
            'text' => $text,
            'model_id' => 'eleven_multilingual_v2',
            'voice_settings' => [
                'stability' => 0.5,
                'similarity_boost' => 0.75
            ]
        ];

        // Configurar a requisição cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiEndpoint . '/' . $voiceId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'xi-api-key: ' . $this->apiKey
        ]);

        // Executar a requisição
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Verificar se houve erro na requisição
        if ($httpCode !== 200) {
            error_log("Erro na API ElevenLabs: $httpCode");
            return '';
        }

        // Salvar o áudio em um arquivo
        file_put_contents($filePath, $response);

        // Retornar a URL relativa do arquivo de áudio
        return 'public/audio/' . $filename;
    }
}
