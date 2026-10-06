<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Lara\Ai\Ai;
use Lara\Api;
use Lara\Db;
use Lara\Storage;

/**
 * GET  ?id=N   → voz da resposta N (sintetiza uma vez e guarda em storage/)
 * POST {text}  → prévia da voz configurada (não é salva)
 */
Api::guard('GET', 'POST');
Api::run(static function (): void {
    $speech = Ai::speech();
    if ($speech === null) {
        Api::fail(409, 'Voz configurada é a do navegador');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $text = trim((string)(Api::jsonBody()['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 300) {
            throw new InvalidArgumentException('Texto de prévia inválido');
        }
        $out = $speech->synthesize($text);
        stream($out['bytes'], Storage::mime('x.' . $out['ext']));
    }

    $m = Db::one("SELECT id, text, audio_path FROM messages WHERE id = ? AND role = 'assistant'", [(int)($_GET['id'] ?? 0)]);
    if ($m === null) {
        Api::fail(404, 'Resposta não encontrada');
    }
    $path = $m['audio_path'] !== null ? Storage::resolve($m['audio_path']) : null;
    if ($path === null) {
        $out = $speech->synthesize((string)$m['text']);
        $rel = Storage::putAudio('spoken', $out['bytes'], $out['ext']);
        Db::update('messages', (int)$m['id'], ['audio_path' => $rel]);
        $path = Storage::resolve($rel);
    }
    stream((string)file_get_contents($path), Storage::mime($path));
});

function stream(string $bytes, string $mime): never
{
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}
