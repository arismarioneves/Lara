<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Lara\Api;
use Lara\Assistant\Pipeline;

Api::guard('POST');
Api::run(static function (): void {
    set_time_limit(120);
    $source = $_POST['source'] ?? '';
    if (!in_array($source, ['local', 'online'], true)) {
        throw new InvalidArgumentException('source inválido');
    }

    $audio = null;
    if (isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['audio']['size'] > 8 * 1024 * 1024) {
            throw new InvalidArgumentException('Áudio muito grande');
        }
        $audio = (string)file_get_contents($_FILES['audio']['tmp_name']);
        if (!str_starts_with($audio, 'RIFF') || substr($audio, 8, 4) !== 'WAVE') {
            throw new InvalidArgumentException('Áudio deve ser WAV');
        }
    }

    // Horário em que a fala terminou, medido no aparelho (ms desde epoch)
    $ms = (int)($_POST['spoken_at'] ?? 0);
    $nowMs = (int)floor(microtime(true) * 1000);
    if ($ms <= 0 || abs($nowMs - $ms) > 86_400_000) {
        $ms = $nowMs;
    }
    $spokenAt = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%03d', intdiv($ms, 1000), $ms % 1000))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format('Y-m-d H:i:s.v');

    $text = isset($_POST['text']) ? mb_substr((string)$_POST['text'], 0, 5000) : null;
    Api::ok(Pipeline::ingest([
        'text' => $source === 'local' ? $text : null,
        'audio' => $audio,
        'audio_mime' => 'audio/wav',
        'source' => $source,
        'engine' => mb_substr((string)($_POST['engine'] ?? ''), 0, 96),
        'duration_ms' => isset($_POST['duration_ms']) ? max(0, (int)$_POST['duration_ms']) : null,
        'level_db' => isset($_POST['level_db']) && is_numeric($_POST['level_db']) ? max(-120, min(0, round((float)$_POST['level_db'], 1))) : null,
        'spoken_at' => $spokenAt,
    ]));
});
