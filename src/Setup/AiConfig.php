<?php
declare(strict_types=1);

namespace Lara\Setup;

/** Valida e normaliza o bloco `ai` do config.php contra os provedores com chave. */
final class AiConfig
{
    /**
     * @param list<string> $available provedores com chave/URL configurada
     * @throws \InvalidArgumentException mensagem pronta para o usuário
     */
    public static function normalize(array $in, array $available): array
    {
        $roles = Catalog::roles();
        $usable = static fn(string $p, string $role): bool => in_array($p, $available, true) && Catalog::supports($p, $role);

        $main = (array)($in['main'] ?? []);
        $mainProvider = (string)($main['provider'] ?? '');
        if (!$usable($mainProvider, 'chat')) {
            throw new \InvalidArgumentException("{$roles['chat']['title']}: escolha um provedor com chave configurada");
        }
        $out['main'] = [
            'provider' => $mainProvider,
            'model' => self::model($main['model'] ?? '', $roles['chat']['title']),
            'thinking' => self::thinking($main['thinking'] ?? ''),
        ];

        $dec = (array)($in['decider'] ?? []);
        $engine = (string)($dec['engine'] ?? 'llm');
        if ($engine === 'jev') {
            if (!in_array('typesafe', $available, true)) {
                throw new \InvalidArgumentException("{$roles['decider']['title']}: o Jev precisa da chave TypeSafe");
            }
            $out['decider'] = [
                'engine' => 'jev',
                'jev_model' => self::model($dec['jev_model'] ?? 'jev-latest', $roles['decider']['title']),
                'provider' => '',
                'model' => '',
                'thinking' => '',
            ];
        } elseif ($engine === 'llm') {
            $p = (string)($dec['provider'] ?? '');
            if ($p === 'typesafe' || !$usable($p, 'decider')) {
                throw new \InvalidArgumentException("{$roles['decider']['title']}: escolha um provedor com chave configurada");
            }
            $out['decider'] = [
                'engine' => 'llm',
                'jev_model' => 'jev-latest',
                'provider' => $p,
                'model' => self::model($dec['model'] ?? '', $roles['decider']['title']),
                'thinking' => self::thinking($dec['thinking'] ?? ''),
            ];
        } else {
            throw new \InvalidArgumentException("{$roles['decider']['title']}: motor inválido");
        }

        $stt = (array)($in['stt'] ?? []);
        $p = (string)($stt['provider'] ?? '');
        if ($p === '') {
            $out['stt'] = ['provider' => '', 'model' => ''];
        } elseif ($usable($p, 'stt')) {
            $out['stt'] = ['provider' => $p, 'model' => self::model($stt['model'] ?? '', $roles['stt']['title'])];
        } else {
            throw new \InvalidArgumentException("{$roles['stt']['title']}: provedor sem chave configurada");
        }

        $tts = (array)($in['tts'] ?? []);
        $p = (string)($tts['provider'] ?? 'browser');
        if ($p === 'browser') {
            $out['tts'] = ['provider' => 'browser', 'model' => '', 'voices' => []];
        } elseif ($usable($p, 'tts')) {
            $out['tts'] = [
                'provider' => $p,
                'model' => self::model($tts['model'] ?? '', $roles['tts']['title']),
                'voices' => self::voices($tts['voices'] ?? []),
            ];
        } else {
            throw new \InvalidArgumentException("{$roles['tts']['title']}: provedor sem chave configurada");
        }
        return $out;
    }

    private static function model(mixed $v, string $role): string
    {
        $v = is_scalar($v) ? trim((string)$v) : '';
        if ($v === '' || strlen($v) > 200 || preg_match('/[\s\'"\\\\]/', $v)) {
            throw new \InvalidArgumentException("$role: informe um modelo válido");
        }
        return $v;
    }

    private static function thinking(mixed $v): string
    {
        $v = (string)$v;
        if (!in_array($v, Catalog::THINKING, true)) {
            throw new \InvalidArgumentException('Nível de raciocínio inválido');
        }
        return $v;
    }

    /** @return list<array{id: string, name: string}> */
    private static function voices(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? array_slice($list, 0, 500) : [] as $v) {
            $id = is_array($v) ? trim((string)($v['id'] ?? '')) : '';
            if ($id === '' || strlen($id) > 120) {
                continue;
            }
            $name = mb_substr(trim((string)($v['name'] ?? $id)), 0, 120);
            $out[] = ['id' => $id, 'name' => $name === '' ? $id : $name];
        }
        return $out;
    }
}
