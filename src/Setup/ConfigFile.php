<?php
declare(strict_types=1);

namespace Lara\Setup;

use Lara\Config;

/** Gera e grava o config.php (PHP válido, legível para edição manual). */
final class ConfigFile
{
    public static function render(array $config): string
    {
        return "<?php\n"
            . "// Lara — gerado pelo assistente em " . date('Y-m-d H:i') . ".\n"
            . "// Pode ser editado à mão. \"Reconfigurar IA\" regrava este arquivo (comentários são perdidos).\n"
            . "// Segredos: nunca versione este arquivo.\n"
            . 'return ' . self::export($config, 0) . ";\n";
    }

    /**
     * @param bool $create true na instalação: falha se o arquivo já existir (impede sobrescrever uma instalação)
     * @throws \RuntimeException sem permissão de escrita ou arquivo já existente
     */
    public static function write(array $config, bool $create, string $file = Config::FILE): void
    {
        $content = self::render($config);
        if ($create) {
            $fh = @fopen($file, 'x');
            if ($fh === false) {
                throw new \RuntimeException(is_file($file) ? 'config.php já existe' : 'Sem permissão para criar o config.php');
            }
            $ok = fwrite($fh, $content) === strlen($content);
            fclose($fh);
            if (!$ok) {
                @unlink($file);
                throw new \RuntimeException('Falha ao gravar o config.php');
            }
        } else {
            $tmp = $file . '.tmp';
            if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content) || !@rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('Sem permissão para regravar o config.php');
            }
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }

    private static function export(mixed $value, int $depth): string
    {
        if (!is_array($value)) {
            return match (true) {
                $value === null => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                is_int($value) => (string)$value,
                is_float($value) => var_export($value, true),
                default => var_export((string)$value, true),
            };
        }
        if ($value === []) {
            return '[]';
        }
        $pad = str_repeat('    ', $depth + 1);
        $list = array_is_list($value);
        $lines = [];
        foreach ($value as $k => $v) {
            $lines[] = $pad . ($list ? '' : var_export($k, true) . ' => ') . self::export($v, $depth + 1) . ',';
        }
        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
    }
}
