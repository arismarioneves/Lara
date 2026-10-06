<?php
declare(strict_types=1);

// Testes das regras puras (sem banco nem rede): php tests/run.php

spl_autoload_register(static function (string $class): void {
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    if (str_starts_with($class, 'Lara\\') && is_file($file)) {
        require $file;
    }
});

$failures = 0;
$count = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
        echo "FALHOU: $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

echo $failures === 0 ? "OK ($count verificações)\n" : "$failures de $count falharam\n";
exit($failures === 0 ? 0 : 1);
