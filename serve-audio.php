<?php
// serve-audio.php - Script específico para servir arquivos de áudio

// Obter o nome do arquivo da URL
$filename = isset($_GET['file']) ? $_GET['file'] : '';

// Verificar se o nome do arquivo é válido (apenas caracteres alfanuméricos e extensão .mp3)
if (!preg_match('/^[a-zA-Z0-9]+\.mp3$/', $filename)) {
    header('HTTP/1.0 400 Bad Request');
    exit('Nome de arquivo inválido');
}

// Caminho completo para o arquivo
$filePath = __DIR__ . '/public/audio/' . $filename;

// Verificar se o arquivo existe
if (!file_exists($filePath)) {
    header('HTTP/1.0 404 Not Found');
    exit('Arquivo não encontrado');
}

// Definir cabeçalhos para evitar problemas de cache e condições prévias
header('Content-Type: audio/mpeg');
header('Content-Length: ' . filesize($filePath));
header('Accept-Ranges: bytes');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Remover cabeçalhos que podem causar problemas de condição prévia
header_remove('ETag');
header_remove('Last-Modified');

// Permitir CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: *');

// Enviar o arquivo
readfile($filePath);
exit;
