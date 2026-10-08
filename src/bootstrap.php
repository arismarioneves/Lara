<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Lara\\', 5) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Lara\Config;

// Sem config.php a aplicação sobe com os padrões e só o assistente de instalação funciona
Config::load();
date_default_timezone_set(Config::get('app.timezone', 'UTC'));
ini_set('display_errors', Config::get('app.debug', false) ? '1' : '0');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
