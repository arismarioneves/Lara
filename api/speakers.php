<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Lara\Api;
use Lara\Config;
use Lara\Db;
use Lara\Storage;

Api::guard('GET', 'POST', 'DELETE');
Api::run(static function (): void {
    $method = $_SERVER['REQUEST_METHOD'];

    // GET — lista as referências (sem o áudio, só metadados)
    if ($method === 'GET') {
        $rows = Db::all('SELECT id, name, duration_ms, created_at FROM speaker_references ORDER BY name');
        Api::ok(['speakers' => $rows]);
    }

    // DELETE — remove uma referência por id
    if ($method === 'DELETE') {
        $in = Api::jsonBody();
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('ID inválido');
        }
        $row = Db::one('SELECT audio_path FROM speaker_references WHERE id = ?', [$id]);
        if ($row === null) {
            throw new InvalidArgumentException('Referência não encontrada');
        }
        // Remove arquivo
        $abs = Config::storagePath($row['audio_path']);
        if (is_file($abs)) {
            unlink($abs);
        }
        Db::exec('DELETE FROM speaker_references WHERE id = ?', [$id]);
        Api::ok();
    }

    // POST — grava nova referência de voz
    if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Envie o áudio da referência de voz');
    }
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 64) {
        throw new InvalidArgumentException('Nome do falante: 1 a 64 caracteres');
    }
    if (!preg_match('/^[\p{L}\p{N} _.-]+$/u', $name)) {
        throw new InvalidArgumentException('Nome do falante: apenas letras, números, espaços, pontos e hífens');
    }
    // Limite de 8 referências
    $count = (int)(Db::one('SELECT COUNT(*) AS n FROM speaker_references')['n'] ?? 0);
    $existing = Db::one('SELECT id FROM speaker_references WHERE name = ?', [$name]);
    if ($count >= 8 && $existing === null) {
        throw new InvalidArgumentException('Limite de 8 referências de falante atingido');
    }
    if ($_FILES['audio']['size'] > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('Áudio muito grande (máximo 2 MB)');
    }
    $audio = (string)file_get_contents($_FILES['audio']['tmp_name']);
    if (!str_starts_with($audio, 'RIFF') || substr($audio, 8, 4) !== 'WAVE') {
        throw new InvalidArgumentException('Áudio deve ser WAV');
    }
    $durationMs = max(0, (int)($_POST['duration_ms'] ?? 0));
    if ($durationMs < 3000 || $durationMs > 15000) {
        throw new InvalidArgumentException('A referência deve ter entre 3 e 15 segundos');
    }

    // Se já existe com o mesmo nome, substitui
    if ($existing !== null) {
        $old = Db::one('SELECT audio_path FROM speaker_references WHERE id = ?', [(int)$existing['id']]);
        if ($old !== null) {
            $abs = Config::storagePath($old['audio_path']);
            if (is_file($abs)) unlink($abs);
        }
        Db::exec('DELETE FROM speaker_references WHERE id = ?', [(int)$existing['id']]);
    }

    $path = Storage::putAudio('references', $audio, 'wav');
    $id = Db::insert('speaker_references', [
        'name' => $name,
        'audio_path' => $path,
        'duration_ms' => $durationMs,
    ]);
    Api::ok(['id' => $id, 'name' => $name, 'duration_ms' => $durationMs]);
});
