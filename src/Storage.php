<?php
declare(strict_types=1);

namespace Lara;

/** Arquivos privados (áudio) dentro de storage/, nunca servidos diretamente pelo Apache. */
final class Storage
{
    private const AUDIO_TYPES = [
        'wav' => 'audio/wav',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'webm' => 'audio/webm',
    ];

    /** Grava o áudio e devolve o caminho relativo a storage/. */
    public static function putAudio(string $kind, string $bytes, string $ext): string
    {
        if (!isset(self::AUDIO_TYPES[$ext])) {
            throw new \InvalidArgumentException("Formato de áudio não suportado: $ext");
        }
        $rel = sprintf('audio/%s/%s/%s.%s', $kind, date('Y/m/d'), date('His') . '-' . bin2hex(random_bytes(6)), $ext);
        $abs = Config::storagePath($rel);
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0700, true) && !is_dir(dirname($abs))) {
            throw new \RuntimeException('Não foi possível criar a pasta de áudio');
        }
        if (file_put_contents($abs, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('Não foi possível gravar o áudio');
        }
        return $rel;
    }

    /** Caminho absoluto seguro (impede path traversal). */
    public static function resolve(string $rel): ?string
    {
        if (!preg_match('#^audio/[a-z]+/\d{4}/\d{2}/\d{2}/[\w-]+\.(wav|mp3|ogg|webm)$#', $rel)) {
            return null;
        }
        $abs = Config::storagePath($rel);
        return is_file($abs) ? $abs : null;
    }

    public static function mime(string $path): string
    {
        return self::AUDIO_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    public static function extFromMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        $ext = array_search($mime, self::AUDIO_TYPES, true);
        return $ext === false ? ($mime === 'audio/x-wav' || $mime === 'audio/wave' ? 'wav' : 'mp3') : $ext;
    }
}
