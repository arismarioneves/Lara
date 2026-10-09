<?php
declare(strict_types=1);

namespace Lara;

final class Log
{
    public static function error(string $channel, string $message): void
    {
        self::write('error', $channel, $message);
    }

    public static function info(string $channel, string $message): void
    {
        self::write('info', $channel, $message);
    }

    private static function write(string $level, string $channel, string $message): void
    {
        $dir = Config::storagePath('logs');
        $line = sprintf("[%s] %s.%s: %s\n", date('c'), $channel, $level, str_replace("\n", ' ', $message));
        @file_put_contents($dir . '/lara-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
