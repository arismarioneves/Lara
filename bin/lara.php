<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$cmd = $argv[1] ?? '';

if ($cmd === 'password') {
    $pass = $argv[2] ?? '';
    if (strlen($pass) < 8) {
        fwrite(STDERR, "Uso: php bin/lara.php password \"senha com 8+ caracteres\"\n");
        exit(1);
    }
    echo password_hash($pass, PASSWORD_DEFAULT), "\n";
    exit(0);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Lara\Assistant\Pipeline;
use Lara\Db;

switch ($cmd) {
    case 'migrate':
        Db::migrate();
        echo "Esquema aplicado.\n";
        break;

    case 'redecide':
        // Reprocessa falas que ficaram pendentes (ex.: decisor fora do ar). Não gera respostas atrasadas.
        $rows = Db::all("SELECT id FROM messages WHERE role = 'heard' AND relevance = 'pending' AND text IS NOT NULL ORDER BY id");
        $ok = 0;
        foreach ($rows as $r) {
            $res = Pipeline::decide((int)$r['id']);
            if (!isset($res['error'])) {
                if ($res['respond']) {
                    // Era dirigida à Lara, mas já passou: registra como contexto
                    Db::update('messages', (int)$r['id'], ['relevance' => 'relevant']);
                }
                $ok++;
            }
        }
        printf("%d/%d falas catalogadas.\n", $ok, count($rows));
        break;

    case 'stats':
        foreach (Db::all("SELECT role, relevance, COALESCE(transcript_source, '-') AS source, COUNT(*) AS n FROM messages GROUP BY role, relevance, source ORDER BY role, relevance") as $r) {
            printf("%-10s %-10s %-7s %d\n", $r['role'], $r['relevance'], $r['source'], $r['n']);
        }
        break;

    default:
        echo "Uso: php bin/lara.php <migrate|password|redecide|stats>\n";
        exit($cmd === '' ? 0 : 1);
}
