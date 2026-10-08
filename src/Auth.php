<?php
declare(strict_types=1);

namespace Lara;

/** Usuário único, senha com hash em config.php, sessão longa para o PWA e token CSRF. */
final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $lifetime = max(1, (int)Config::get('app.session_days', 30)) * 86400;
        $dir = Config::storagePath('sessions');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        session_save_path($dir);
        ini_set('session.gc_maxlifetime', (string)$lifetime);
        ini_set('session.use_strict_mode', '1');
        session_name('lara_sid');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => self::cookiePath(),
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function check(): bool
    {
        self::start();
        return !empty($_SESSION['uid']);
    }

    public static function attempt(string $password): bool
    {
        self::start();
        if (!self::verify($password)) {
            return false;
        }
        self::grant();
        return true;
    }

    /** Inicia a sessão autenticada (login ou fim da instalação). */
    public static function grant(): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['uid'] = 1;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }

    /** Reconfirma a senha antes de mexer em chaves de IA; vale por 15 minutos. */
    public static function confirm(string $password): bool
    {
        self::start();
        if (!self::verify($password)) {
            return false;
        }
        $_SESSION['sudo_until'] = time() + 900;
        return true;
    }

    public static function sudo(): bool
    {
        self::start();
        return self::check() && ($_SESSION['sudo_until'] ?? 0) > time();
    }

    private static function verify(string $password): bool
    {
        $hash = (string)Config::get('app.password_hash', '');
        // Atraso fixo atenua força bruta sem precisar de tabela de tentativas
        usleep(400_000);
        return $hash !== '' && password_verify($password, $hash);
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
        setcookie('lara_sid', '', ['expires' => time() - 3600, 'path' => self::cookiePath()]);
    }

    public static function csrf(): string
    {
        self::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): bool
    {
        $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return $sent !== '' && hash_equals(self::csrf(), $sent);
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /** Caminho da app (funciona em subpasta, ex.: /lara/). */
    private static function cookiePath(): string
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
        $dir = rtrim(dirname($script), '/');
        if (str_ends_with($dir, '/api')) {
            $dir = substr($dir, 0, -4);
        }
        return $dir === '' ? '/' : $dir . '/';
    }
}
