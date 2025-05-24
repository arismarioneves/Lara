<?php

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
     * @return string URL do arquivo de áudio gerado ou URL de áudio base64
     */
    public function generateSpeech($text)
    {
        // Diretório para salvar os arquivos de áudio
        $audioDir = __DIR__ . '/../public/audio';

        // Nome do arquivo baseado em hash do texto e timestamp
        $filename = md5($text . time()) . '.mp3';
        $filePath = $audioDir . '/' . $filename;

        // Usar o script serve-audio.php para servir o arquivo
        $fileUrl = 'serve-audio.php?file=' . $filename;

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
        curl_setopt($ch, CURLOPT_URL, $this->apiEndpoint . '/' . ELEVEN_LABS_VOICE_ID);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'xi-api-key: ' . $this->apiKey,
            'Accept: audio/mpeg'
        ]);

        // Executar a requisição
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        // Verificar erros cURL
        if ($response === false) {
            $error = curl_error($ch);
            error_log("Erro cURL na API ElevenLabs: $error");
            curl_close($ch);
            return $this->getEmptyAudioDataUrl();
        }

        curl_close($ch);

        // Verificar se houve erro na requisição
        if ($httpCode !== 200) {
            error_log("Erro na API ElevenLabs: $httpCode - " . substr($response, 0, 1000));
            return $this->getEmptyAudioDataUrl();
        }

        // Verificar se a resposta é realmente um áudio
        if (strpos($contentType, 'audio/') !== 0) {
            error_log("Resposta não é áudio: $contentType");
            return $this->getEmptyAudioDataUrl();
        }

        try {
            // Tentar salvar o áudio em um arquivo
            if (file_put_contents($filePath, $response) === false) {
                error_log("Falha ao salvar arquivo de áudio: $filePath");
                return $this->getEmptyAudioDataUrl();
            }

            // Verificar se o arquivo foi criado e tem tamanho maior que zero
            if (!file_exists($filePath) || filesize($filePath) === 0) {
                error_log("Arquivo de áudio vazio ou inexistente: $filePath");
                return $this->getEmptyAudioDataUrl();
            }

            // Retornar a URL para o script que serve o arquivo de áudio
            return $fileUrl;
        } catch (Exception $e) {
            error_log("Exceção ao processar áudio: " . $e->getMessage());
            return $this->getEmptyAudioDataUrl();
        }
    }

    /**
     * Gera uma URL de dados para um áudio vazio como fallback
     *
     * @return string URL de dados de áudio base64
     */
    private function getEmptyAudioDataUrl()
    {
        // Este é um MP3 vazio/silencioso de 1 segundo
        $emptyMp3Base64 = 'SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjU4Ljc2LjEwMAAAAAAAAAAAAAAA//tQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAACAAADQgD///////////////////////////////////////////8AAAA8TEFNRTMuMTAwAQAAAAAAAAAAABSAJAJAQgAAgAAAA0L2YLwxAAAAAAAAAAAAAAAAAAAA';
        return 'data:audio/mp3;base64,' . $emptyMp3Base64;
    }
}
