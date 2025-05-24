<?php

class OpenAITTS
{
    private $apiKey;
    private $model;
    private $voice;
    private $instructions;

    public function __construct($apiKey, $model, $voice, $instructions)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->voice = $voice;
        $this->instructions = $instructions;
    }

    public function generateSpeech($text)
    {
        $url = "https://api.openai.com/v1/audio/speech";
        $data = [
            "model" => $this->model,
            "input" => $text,
            "voice" => $this->voice,
            "instructions" => $this->instructions,
            "response_format" => "mp3"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->apiKey}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $audioContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $audioContent) {
            // Salvar o arquivo mp3 em public/audio/
            $fileName = md5($text . time()) . '.mp3';
            $filePath = __DIR__ . '/../public/audio/' . $fileName;
            file_put_contents($filePath, $audioContent);

            // Usar o script serve-audio.php para servir o arquivo
            $fileUrl = 'serve-audio.php?file=' . $fileName;

            return $fileUrl;
        } else {
            error_log("Erro ao gerar áudio com OpenAI TTS: HTTP $httpCode");
            return null;
        }
    }
}
