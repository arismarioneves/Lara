<?php
declare(strict_types=1);

namespace Lara\Ai;

interface Chat
{
    /**
     * @param list<array{role: 'user'|'assistant', text: string}> $turns
     * @param array{json_schema?: array} $opts json_schema força resposta JSON nesse formato
     */
    public function complete(string $system, array $turns, array $opts = []): string;

    public function name(): string;
}
