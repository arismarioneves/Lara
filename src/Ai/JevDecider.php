<?php
declare(strict_types=1);

namespace Lara\Ai;

use Lara\Config;
use Lara\Http;
use Lara\ProviderException;

/** TypeSafe Jev (RLCD) hospedado: decisões calibradas em ~70–500 ms, saída sem tokens gerados. */
final class JevDecider implements Decider
{
    public function __construct(private string $model)
    {
    }

    public function name(): string
    {
        return 'jev:' . $this->model;
    }

    public function answers(string|array $state, array $questions): array
    {
        $p = Config::provider('typesafe');
        $res = Http::postJson($p['base_url'] . '/systemone', ['Authorization' => 'Bearer ' . $p['api_key']], [
            'state' => $state,
            'model' => $this->model,
            'questions' => $questions,
        ], 10);
        $answers = $res['answers'] ?? null;
        if (!is_array($answers)) {
            throw new ProviderException('typesafe: resposta sem answers');
        }
        foreach (array_keys($questions) as $id) {
            if (!isset($answers[$id]['type'])) {
                throw new ProviderException("typesafe: resposta sem a pergunta $id");
            }
        }
        return $answers;
    }
}
