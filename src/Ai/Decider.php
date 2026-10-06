<?php
declare(strict_types=1);

namespace Lara\Ai;

/**
 * Decisor no formato System One (TypeSafe Jev): estado + perguntas tipadas (noul/choice/score)
 * → respostas tipadas com probabilidades. Ver https://docs.typesafe.ai/api
 */
interface Decider
{
    /**
     * @param string|array $state
     * @param array<string, array{type: 'noul'|'choice'|'score', instructions: string|array, criteria?: mixed}> $questions
     * @return array<string, array{type: string, noul?: float, choice?: string, probabilities?: array<string, float>, score?: float, confidence?: float}>
     */
    public function answers(string|array $state, array $questions): array;

    public function name(): string;
}
