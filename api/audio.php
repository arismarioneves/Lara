<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Lara\Api;
use Lara\Db;
use Lara\Storage;

Api::guard('GET');
Api::run(static function (): void {
    $m = Db::one('SELECT audio_path FROM messages WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
    $path = $m && $m['audio_path'] !== null ? Storage::resolve($m['audio_path']) : null;
    if ($path === null) {
        Api::fail(404, 'Áudio não encontrado');
    }
    header('Content-Type: ' . Storage::mime($path));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
});
