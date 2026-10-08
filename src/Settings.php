<?php
declare(strict_types=1);

namespace Lara;

/**
 * Preferências de interação editáveis em Configurações, persistidas na tabela `settings`.
 * Provedores, chaves e modelos de IA ficam no config.php (assistente de configuração).
 */
final class Settings
{
    private const PERSONA = <<<TXT
Você é a Lara, assistente pessoal por voz que acompanha o usuário o dia inteiro pelo microfone.
Fale como gente: frases curtas, tom caloroso, natural e direto. Nada de listas, markdown ou emojis — tudo vira voz.
Prefira afirmações a perguntas; só pergunte quando faltar algo essencial. Não repita o nome do usuário à toa.
Use o histórico e as memórias para dar respostas contextualizadas, lembrando de nomes, combinados e detalhes ditos antes.
TXT;

    /** @return array<string, array{type: string, default: mixed, options?: array, min?: float, max?: float, max_len?: int}> */
    public static function schema(): array
    {
        return [
            'assistant_name' => ['type' => 'string', 'default' => 'Lara', 'max_len' => 40],
            'user_name' => ['type' => 'string', 'default' => '', 'max_len' => 80],
            'language' => ['type' => 'string', 'default' => 'pt-BR', 'max_len' => 16],
            'persona' => ['type' => 'text', 'default' => self::PERSONA, 'max_len' => 6000],
            'wake_words' => ['type' => 'list', 'default' => ['lara']],

            // Quando responder (política do decisor)
            'respond_threshold' => ['type' => 'float', 'default' => 0.6, 'min' => 0.05, 'max' => 0.99],
            'followup_seconds' => ['type' => 'int', 'default' => 25, 'min' => 0, 'max' => 300],

            // Transcrição: local (Whisper no navegador, custo zero) ou online (serviço definido no config.php)
            'stt_mode' => ['type' => 'enum', 'default' => 'local', 'options' => ['local', 'online']],
            'stt_local_model' => ['type' => 'enum', 'default' => 'onnx-community/whisper-base', 'options' => [
                'onnx-community/whisper-tiny',
                'onnx-community/whisper-base',
                'onnx-community/whisper-small',
                'onnx-community/whisper-large-v3-turbo',
            ]],

            // Voz (o provedor e o modelo vêm do config.php)
            'tts_voice' => ['type' => 'string', 'default' => '', 'max_len' => 120],
            'tts_style' => ['type' => 'string', 'default' => 'calorosa, natural, ritmo de conversa', 'max_len' => 300],

            // Captura e memória
            'vad_threshold' => ['type' => 'float', 'default' => 0.5, 'min' => 0.1, 'max' => 0.95],
            // Com fone de ouvido não há eco: a escuta segue enquanto a Lara fala e a sua voz a interrompe
            'barge_in' => ['type' => 'bool', 'default' => false],
            'store_audio' => ['type' => 'bool', 'default' => true],
            'context_messages' => ['type' => 'int', 'default' => 30, 'min' => 4, 'max' => 200],
            'memory_results' => ['type' => 'int', 'default' => 6, 'min' => 0, 'max' => 30],
        ];
    }

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $values = [];
            foreach (self::schema() as $key => $def) {
                $values[$key] = $def['default'];
            }
            foreach (Db::all('SELECT name, value FROM settings') as $row) {
                if (array_key_exists($row['name'], $values)) {
                    $values[$row['name']] = json_decode($row['value'], true);
                }
            }
            self::$cache = $values;
        }
        return self::$cache;
    }

    public static function get(string $key): mixed
    {
        $all = self::all();
        if (!array_key_exists($key, $all)) {
            throw new \InvalidArgumentException("Configuração desconhecida: $key");
        }
        return $all[$key];
    }

    /**
     * Valida e grava somente chaves conhecidas.
     * @param array<string, mixed> $input
     * @return array<string, mixed> configurações resultantes
     */
    public static function save(array $input): array
    {
        $schema = self::schema();
        $clean = [];
        foreach ($input as $key => $value) {
            if (!isset($schema[$key])) {
                throw new \InvalidArgumentException("Configuração desconhecida: $key");
            }
            $clean[$key] = self::coerce($key, $schema[$key], $value);
        }
        $st = Db::pdo()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach ($clean as $key => $value) {
            $st->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
        }
        self::$cache = null;
        return self::all();
    }

    private static function coerce(string $key, array $def, mixed $value): mixed
    {
        switch ($def['type']) {
            case 'string':
            case 'text':
                if (!is_scalar($value)) {
                    throw new \InvalidArgumentException("$key: texto esperado");
                }
                $value = trim((string)$value);
                if (mb_strlen($value) > ($def['max_len'] ?? 255)) {
                    throw new \InvalidArgumentException("$key: texto muito longo");
                }
                if ($def['type'] === 'string' && preg_match('/[\r\n]/', $value)) {
                    throw new \InvalidArgumentException("$key: quebra de linha não permitida");
                }
                return $value;
            case 'int':
            case 'float':
                if (!is_numeric($value)) {
                    throw new \InvalidArgumentException("$key: número esperado");
                }
                $n = $def['type'] === 'int' ? (int)$value : (float)$value;
                if ($n < $def['min'] || $n > $def['max']) {
                    throw new \InvalidArgumentException("$key: fora do intervalo {$def['min']}–{$def['max']}");
                }
                return $n;
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'enum':
                if (!in_array($value, $def['options'], true)) {
                    throw new \InvalidArgumentException("$key: opção inválida");
                }
                return $value;
            case 'list':
                $items = is_array($value) ? $value : explode(',', (string)$value);
                $items = array_values(array_unique(array_filter(array_map(
                    static fn($v) => mb_strtolower(trim((string)$v)),
                    $items
                ), static fn($v) => $v !== '')));
                if (count($items) > 20) {
                    throw new \InvalidArgumentException("$key: no máximo 20 itens");
                }
                return $items;
        }
        throw new \LogicException("Tipo desconhecido para $key");
    }
}
