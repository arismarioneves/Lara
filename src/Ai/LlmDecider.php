<?php
declare(strict_types=1);

namespace Lara\Ai;

use Lara\ProviderException;

/**
 * Adaptador Jev-compatível sobre qualquer LLM barato/aberto (Flash-Lite, Llama no Groq, Ollama local…).
 * Recebe as MESMAS perguntas tipadas e devolve respostas no MESMO formato do Jev, então o restante
 * do pipeline não muda ao trocar de motor.
 */
final class LlmDecider implements Decider
{
    public function __construct(private Chat $chat)
    {
    }

    public function name(): string
    {
        return 'llm:' . $this->chat->name();
    }

    public function answers(string|array $state, array $questions): array
    {
        $system = "Você é um classificador rápido e calibrado. Leia o ESTADO e responda cada pergunta.\n"
            . "Regras de saída (JSON):\n"
            . "- noul: probabilidade (0 a 1) de a resposta ser SIM.\n"
            . "- choice: probabilidade (0 a 1) de cada opção; somam 1.\n"
            . "- score: posição no intervalo de níveis (0 = primeiro nível), pode ser fracionária.\n"
            . 'Seja calibrado: use valores intermediários quando houver dúvida real.';
        $prompt = "ESTADO:\n" . (is_string($state) ? $state : json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
            . "\n\nPERGUNTAS:\n" . json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $raw = $this->chat->complete($system, [['role' => 'user', 'text' => $prompt]], ['json_schema' => self::schema($questions)]);
        $data = json_decode(self::stripFence($raw), true);
        if (!is_array($data)) {
            throw new ProviderException('decisor llm: JSON inválido');
        }
        return self::normalize($questions, $data);
    }

    /** JSON Schema estrito correspondente às perguntas. */
    public static function schema(array $questions): array
    {
        $props = [];
        foreach ($questions as $id => $q) {
            $props[$id] = match ($q['type']) {
                'noul' => ['type' => 'number', 'description' => 'P(sim), 0..1'],
                'score' => ['type' => 'number', 'description' => 'nível 0..' . (count($q['criteria']) - 1)],
                'choice' => [
                    'type' => 'object',
                    'properties' => array_map(static fn() => ['type' => 'number'], $q['criteria']),
                    'required' => array_keys($q['criteria']),
                    'additionalProperties' => false,
                ],
            };
        }
        return ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];
    }

    /** Converte a saída do LLM para o formato de respostas do Jev (com limites e normalização). */
    public static function normalize(array $questions, array $data): array
    {
        $out = [];
        foreach ($questions as $id => $q) {
            if (!array_key_exists($id, $data)) {
                throw new ProviderException("decisor llm: faltou a pergunta $id");
            }
            $v = $data[$id];
            switch ($q['type']) {
                case 'noul':
                    $out[$id] = ['type' => 'noul', 'noul' => self::clamp((float)$v, 0, 1)];
                    break;
                case 'score':
                    $max = count($q['criteria']) - 1;
                    $out[$id] = ['type' => 'score', 'score' => self::clamp((float)$v, 0, $max)];
                    break;
                case 'choice':
                    $probs = [];
                    foreach (array_keys($q['criteria']) as $opt) {
                        $probs[$opt] = max(0.0, (float)(is_array($v) ? ($v[$opt] ?? 0) : ($v === $opt ? 1 : 0)));
                    }
                    $sum = array_sum($probs);
                    if ($sum <= 0) {
                        $probs = array_fill_keys(array_keys($probs), 1 / count($probs));
                    } else {
                        foreach ($probs as $k => $p) {
                            $probs[$k] = $p / $sum;
                        }
                    }
                    arsort($probs);
                    $out[$id] = ['type' => 'choice', 'choice' => (string)array_key_first($probs), 'probabilities' => $probs];
                    break;
            }
        }
        return $out;
    }

    private static function clamp(float $v, float $min, float $max): float
    {
        return is_nan($v) ? $min : max($min, min($max, $v));
    }

    private static function stripFence(string $raw): string
    {
        return preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($raw)) ?? $raw;
    }
}
