<?php
declare(strict_types=1);

namespace Lara\Ai;

use Lara\Config;
use Lara\Http;
use Lara\ProviderException;

/**
 * Modelo System One (TypeSafe Jev e outros modelos de "decisions"): decisões calibradas em ~70–500 ms,
 * sem gerar texto. O mesmo POST /systemone funciona direto na TypeSafe e pelo OpenRouter.
 */
final class SystemOneDecider implements Decider
{
    public function __construct(private string $provider, private string $model)
    {
    }

    public function name(): string
    {
        return $this->provider . ':' . $this->model;
    }

    public function answers(string|array $state, array $questions): array
    {
        $p = Config::provider($this->provider);
        $res = Http::postJson($p['base_url'] . '/systemone', ['Authorization' => 'Bearer ' . $p['api_key']], [
            'state' => $state,
            'model' => $this->model,
            'questions' => $questions,
        ], 10);
        $answers = $res['answers'] ?? null;
        if (!is_array($answers)) {
            throw new ProviderException($this->provider . ': resposta sem answers');
        }
        foreach (array_keys($questions) as $id) {
            if (!isset($answers[$id]['type'])) {
                throw new ProviderException($this->provider . ": resposta sem a pergunta $id");
            }
        }
        return $answers;
    }
}
