<?php
declare(strict_types=1);

namespace Lara\Setup;

use Lara\Config;
use Lara\Db;
use Lara\Settings;
use PDO;

/** Primeiro uso (cria config.php, banco e preferências) e reconfiguração da IA. */
final class Installer
{
    /** Arquivo comum (não oculto) em storage/: o navegador tenta baixá-lo; 403 prova que o .htaccess está ativo. */
    public const PROBE = 'protegido.txt';

    /** @return list<array{id: string, label: string, ok: bool, required: bool, detail: string}> */
    public static function requirements(): array
    {
        $root = dirname(__DIR__, 2);
        $storage = Config::storagePath();
        $dirs = ['sessions', 'logs', 'audio/heard', 'audio/spoken'];
        $missing = array_values(array_filter($dirs, static fn(string $d) => !is_dir("$storage/$d") || !is_writable("$storage/$d")));
        if (is_dir($storage) && !is_file("$storage/" . self::PROBE)) {
            @file_put_contents("$storage/" . self::PROBE, "Se você consegue ler isto pelo navegador, a pasta storage/ NÃO está protegida.\n");
        }
        $ext = static fn(string $e, bool $req, string $why) => [
            'id' => "ext-$e", 'label' => "Extensão PHP $e", 'ok' => extension_loaded($e), 'required' => $req,
            'detail' => extension_loaded($e) ? 'instalada' : $why,
        ];
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $host = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        return [
            ['id' => 'php', 'label' => 'PHP 8.1 ou superior', 'ok' => PHP_VERSION_ID >= 80100, 'required' => true, 'detail' => 'versão ' . PHP_VERSION],
            $ext('pdo_mysql', true, 'necessária para o MySQL'),
            $ext('curl', true, 'necessária para falar com as IAs'),
            $ext('mbstring', true, 'necessária para textos em português'),
            $ext('intl', false, 'recomendada: melhora a detecção da palavra de ativação com acentos'),
            [
                'id' => 'storage', 'label' => 'Pasta storage/ gravável', 'required' => true,
                'ok' => is_dir($storage) && is_writable($storage) && !$missing,
                'detail' => !is_writable($storage) ? "dê permissão de escrita em $storage"
                    : ($missing ? 'crie (com permissão de escrita) as pastas: ' . implode(', ', $missing) : 'áudios, sessões e logs ficam aqui'),
            ],
            [
                'id' => 'config', 'label' => 'Pasta do projeto gravável', 'required' => false,
                'ok' => is_writable($root),
                'detail' => is_writable($root) ? 'o config.php será criado automaticamente' : 'sem permissão: no fim você copia o config.php gerado manualmente',
            ],
            [
                'id' => 'https', 'label' => 'HTTPS', 'required' => false, 'ok' => $https || $local,
                'detail' => $https ? 'ativo' : ($local ? 'localhost dispensa HTTPS' : 'sem HTTPS o navegador não libera o microfone (exceto em localhost)'),
            ],
        ];
    }

    /** @return array{version: string, exists: bool, lara_tables: bool, messages: ?int} */
    public static function testDb(array $in): array
    {
        $db = self::db($in);
        $pdo = self::connect($db);
        $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $st->execute([$db['name']]);
        $exists = (int)$st->fetchColumn() > 0;
        $tables = false;
        $messages = null;
        if ($exists) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('messages', 'settings')");
            $st->execute([$db['name']]);
            $tables = (int)$st->fetchColumn() === 2;
            if ($tables) {
                $messages = (int)$pdo->query("SELECT COUNT(*) FROM `{$db['name']}`.messages")->fetchColumn();
            }
        }
        return ['version' => $version, 'exists' => $exists, 'lara_tables' => $tables, 'messages' => $messages];
    }

    /**
     * @return array{written: bool, content?: string} content = config.php para copiar quando não há permissão de escrita
     */
    public static function install(array $in): array
    {
        foreach (self::requirements() as $r) {
            if ($r['required'] && !$r['ok']) {
                throw new \InvalidArgumentException("Requisito não atendido: {$r['label']}");
            }
        }
        $db = self::db((array)($in['db'] ?? []));
        $password = (string)($in['app']['password'] ?? '');
        if (strlen($password) < 8 || strlen($password) > 200) {
            throw new \InvalidArgumentException('A senha precisa ter de 8 a 200 caracteres');
        }
        $tz = (string)($in['app']['timezone'] ?? '');
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Fuso horário inválido');
        }

        $config = Config::defaults();
        $config['app']['timezone'] = $tz;
        $config['app']['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $config['db'] = $db;
        $config['providers'] = self::providers($config['providers'], (array)($in['providers'] ?? []));
        Config::replace($config);
        $config['ai'] = AiConfig::normalize((array)($in['ai'] ?? []), Config::availableProviders());
        Config::replace($config);
        date_default_timezone_set($tz);

        $pdo = self::connect($db);
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $st->execute([$db['name']]);
        if ((int)$st->fetchColumn() === 0) {
            if (empty($in['db']['create'])) {
                throw new \InvalidArgumentException("O banco {$db['name']} não existe");
            }
            try {
                $pdo->exec("CREATE DATABASE `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (\PDOException $e) {
                throw new \InvalidArgumentException("Não foi possível criar o banco {$db['name']}: o usuário não tem permissão. Crie-o manualmente e tente de novo.");
            }
        }
        Db::migrate();

        $profile = (array)($in['profile'] ?? []);
        $assistant = trim((string)($profile['assistant_name'] ?? 'Lara')) ?: 'Lara';
        Settings::save([
            'assistant_name' => $assistant,
            'user_name' => (string)($profile['user_name'] ?? ''),
            'wake_words' => [$assistant],
            'stt_mode' => 'local',
            'tts_voice' => '',
        ]);

        try {
            ConfigFile::write($config, true);
        } catch (\RuntimeException $e) {
            if (is_file(Config::FILE)) {
                throw new \InvalidArgumentException('Já existe um config.php: a Lara já foi instalada');
            }
            return ['written' => false, 'content' => ConfigFile::render($config)];
        }
        return ['written' => true];
    }

    /** Troca chaves/modelos de IA de uma instalação existente. */
    public static function reconfigure(array $in): void
    {
        $old = Config::all();
        $config = $old;
        $config['providers'] = self::providers($old['providers'], (array)($in['providers'] ?? []));
        Config::replace($config);
        try {
            $config['ai'] = AiConfig::normalize((array)($in['ai'] ?? []), Config::availableProviders());
        } catch (\InvalidArgumentException $e) {
            Config::replace($old);
            throw $e;
        }
        try {
            ConfigFile::write($config, false);
        } catch (\RuntimeException $e) {
            Config::replace($old);
            throw new \InvalidArgumentException($e->getMessage() . '. Verifique as permissões do arquivo no servidor.');
        }
        Config::replace($config);

        // Preferências que dependiam da IA antiga
        $reset = [];
        $oldTts = $old['ai']['tts'];
        $newTts = $config['ai']['tts'];
        if ($oldTts['provider'] !== $newTts['provider'] || $oldTts['model'] !== $newTts['model']) {
            $reset['tts_voice'] = '';
        }
        if ($config['ai']['stt']['provider'] === '') {
            $reset['stt_mode'] = 'local';
        }
        if ($reset) {
            Settings::save($reset);
        }
    }

    /**
     * Aplica as alterações de chaves vindas da interface.
     * Chave vazia mantém a atual; `remove` apaga. URL só é editável em provedores autenticados por URL (Ollama).
     */
    public static function providers(array $current, array $input): array
    {
        $catalog = Catalog::providers();
        foreach ($input as $name => $change) {
            if (!isset($catalog[$name]) || !is_array($change)) {
                throw new \InvalidArgumentException("Provedor desconhecido: $name");
            }
            $p = $current[$name] ?? ['api_key' => '', 'base_url' => $catalog[$name]['base_url']];
            if (!empty($change['remove'])) {
                $p['api_key'] = '';
                if ($catalog[$name]['auth'] === 'url') {
                    $p['base_url'] = '';
                }
            } else {
                $key = trim((string)($change['api_key'] ?? ''));
                if ($key !== '') {
                    if (strlen($key) > 400 || preg_match('/\s/', $key)) {
                        throw new \InvalidArgumentException("{$catalog[$name]['label']}: chave inválida");
                    }
                    $p['api_key'] = $key;
                }
                if ($catalog[$name]['auth'] === 'url' && isset($change['base_url'])) {
                    $p['base_url'] = self::url((string)$change['base_url'], $catalog[$name]['label']);
                }
            }
            $current[$name] = $p;
        }
        return $current;
    }

    /** Estado dos provedores para a interface: nunca devolve a chave, só os 4 últimos caracteres. */
    public static function providersState(): array
    {
        $out = [];
        foreach (Catalog::providers() as $name => $meta) {
            $p = Config::provider($name);
            $key = $p['api_key'];
            $out[$name] = [
                'configured' => in_array($name, Config::availableProviders(), true),
                'masked' => $key === '' ? '' : '••••' . (strlen($key) >= 12 ? substr($key, -4) : ''),
                'base_url' => $meta['auth'] === 'url' ? (string)Config::get("providers.$name.base_url", '') : '',
            ];
        }
        return $out;
    }

    public static function url(string $url, string $label): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://[^\s/]+(/\S*)?$#i', $url) || strlen($url) > 300) {
            throw new \InvalidArgumentException("$label: URL inválida");
        }
        return $url;
    }

    /** @return array{host: string, port: int, name: string, user: string, pass: string} */
    private static function db(array $in): array
    {
        $host = trim((string)($in['host'] ?? ''));
        $port = (int)($in['port'] ?? 3306);
        $name = trim((string)($in['name'] ?? ''));
        $user = trim((string)($in['user'] ?? ''));
        if ($host === '' || strlen($host) > 255 || preg_match('/[\s;=]/', $host)) {
            throw new \InvalidArgumentException('Servidor do banco inválido');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Porta do banco inválida');
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new \InvalidArgumentException('Nome do banco: use só letras, números e _');
        }
        if ($user === '' || strlen($user) > 80) {
            throw new \InvalidArgumentException('Usuário do banco inválido');
        }
        return ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => (string)($in['pass'] ?? '')];
    }

    private static function connect(array $db): PDO
    {
        try {
            return new PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']),
                $db['user'],
                $db['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
        } catch (\PDOException $e) {
            preg_match('/\[(\d{4})\]/', $e->getMessage(), $m);
            $code = (int)($e->errorInfo[1] ?? 0) ?: (int)($m[1] ?? 0);
            throw new \InvalidArgumentException(match ($code) {
                1045 => 'Usuário ou senha do banco recusados',
                2002, 2003, 2005, 2006 => 'Não foi possível conectar ao servidor do banco (confira servidor e porta)',
                default => 'Falha ao conectar no banco: ' . mb_substr($e->getMessage(), 0, 160),
            });
        }
    }
}
