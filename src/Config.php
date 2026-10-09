<?php
declare(strict_types=1);

namespace Lara;

use Lara\Setup\Catalog;

/**
 * config.php: infraestrutura (banco, senha), chaves dos provedores e escolha dos modelos de IA.
 * É criado pelo assistente de primeiro uso; "Reconfigurar IA" regrava providers/ai.
 * Sem o arquivo, a aplicação roda com os padrões abaixo e só o assistente fica disponível.
 */
final class Config
{
    public const FILE = __DIR__ . '/../config.php';

    /** @var array<string, mixed> */
    private static array $data = [];
    private static bool $installed = false;

    public static function defaults(): array
    {
        $providers = [];
        foreach (Catalog::providers() as $name => $p) {
            $providers[$name] = ['api_key' => '', 'base_url' => $p['base_url']];
        }
        return [
            'app' => [
                'timezone' => 'America/Sao_Paulo',
                'debug' => false,
                'password_hash' => '',
                'session_days' => 30,
            ],
            'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'lara', 'user' => 'root', 'pass' => ''],
            'providers' => $providers,
            'ai' => [
                'main' => ['provider' => '', 'model' => '', 'thinking' => ''],
                // engine: 'systemone' (Jev e afins, via TypeSafe ou OpenRouter) ou 'llm' (adaptador sobre um LLM)
                'decider' => ['engine' => 'llm', 'provider' => '', 'model' => '', 'thinking' => ''],
                'stt' => ['provider' => '', 'model' => ''],
                'tts' => ['provider' => 'browser', 'model' => '', 'voices' => []],
            ],
        ];
    }

    public static function load(string $file = self::FILE): void
    {
        self::$installed = is_file($file);
        $data = self::$installed ? require $file : [];
        if (!is_array($data)) {
            throw new \RuntimeException('config.php deve retornar um array');
        }
        self::$data = self::merge(self::defaults(), $data);
    }

    public static function installed(): bool
    {
        return self::$installed;
    }

    /** Substitui a configuração em memória (instalação e testes). */
    public static function replace(array $data): void
    {
        self::$data = self::merge(self::defaults(), $data);
    }

    /** @return array<string, mixed> configuração completa, como será gravada */
    public static function all(): array
    {
        return self::$data;
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        $node = self::$data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }

    /** @return array{api_key: string, base_url: string} */
    public static function provider(string $name): array
    {
        $p = self::get("providers.$name");
        if (!is_array($p)) {
            throw new ProviderException("Provedor desconhecido: $name");
        }
        $url = (string)($p['base_url'] ?? '');
        if ($url === '') {
            $url = (string)(Catalog::providers()[$name]['base_url'] ?? '');
        }
        return ['api_key' => (string)($p['api_key'] ?? ''), 'base_url' => rtrim($url, '/')];
    }

    /** Provedores utilizáveis: têm chave (ou URL, no caso do Ollama). */
    public static function availableProviders(): array
    {
        $out = [];
        foreach (Catalog::providers() as $name => $meta) {
            $p = self::get("providers.$name", []);
            $ok = $meta['auth'] === 'url' ? (string)($p['base_url'] ?? '') !== '' : (string)($p['api_key'] ?? '') !== '';
            if ($ok) {
                $out[] = $name;
            }
        }
        return $out;
    }

    public static function storagePath(string $sub = ''): string
    {
        $base = rtrim((string)self::get('storage_path', dirname(__DIR__) . '/storage'), '/\\');
        return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
    }

    /** Mescla recursiva em que listas (arrays sequenciais) do arquivo substituem as padrão por inteiro. */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            $base[$k] = is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($v) && !array_is_list($base[$k])
                ? self::merge($base[$k], $v)
                : $v;
        }
        return $base;
    }
}
