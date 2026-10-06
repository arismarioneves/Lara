<?php
declare(strict_types=1);

namespace Lara\Assistant;

/**
 * Perguntas tipadas para o decisor (Jev ou adaptador) e a política que transforma
 * as probabilidades em: responder agora? como catalogar a fala?
 */
final class Decision
{
    /** @return array<string, array> perguntas no formato System One */
    public static function questions(string $assistant, string $user): array
    {
        $who = $user !== '' ? "o usuário ($user)" : 'o usuário';
        return [
            'addressed' => [
                'type' => 'noul',
                'instructions' => "A fala `latest` é dirigida à assistente virtual $assistant (um pedido, pergunta ou resposta a ela), e não a outras pessoas presentes?",
                'criteria' => [
                    'true' => "Fala com $assistant, a chama pelo nome, ou responde ao que ela acabou de dizer",
                    'false' => 'Conversa entre pessoas, TV, música, ruído ou fala de terceiros',
                ],
            ],
            'needs_reply' => [
                'type' => 'noul',
                'instructions' => "Uma assistente pessoal discreta que acompanha $who deveria falar AGORA em resposta à fala `latest`?",
                'criteria' => [
                    'true' => 'Há pedido, pergunta ou necessidade clara de intervenção',
                    'false' => 'Nada a dizer agora; apenas continuar ouvindo',
                ],
            ],
            'relevance' => [
                'type' => 'choice',
                'instructions' => "Como catalogar a fala `latest` para a memória de longo prazo de $who?",
                'criteria' => [
                    'noise' => 'Ruído: TV, música, vozes distantes, fragmentos sem sentido, conversa alheia sem relação com o usuário',
                    'context' => 'Conversa envolvendo o usuário que pode dar contexto a respostas futuras',
                    'memory' => 'Fato concreto que vale lembrar: nomes, datas, compromissos, tarefas, preferências, números, decisões',
                ],
            ],
            'importance' => [
                'type' => 'score',
                'instructions' => 'Quão importante é lembrar o conteúdo da fala `latest` no futuro?',
                'criteria' => ['Irrelevante', 'Pouco importante', 'Útil', 'Importante'],
            ],
        ];
    }

    /**
     * Estado enviado ao decisor. Somente texto — sem metadados de transcrição.
     * @param list<array{at: string, text: string, role: string}> $recent
     */
    public static function state(string $latest, array $recent, ?array $lastReply, string $assistant): array
    {
        $state = [
            'assistant_name' => $assistant,
            'recent_conversation' => array_map(
                static fn($m) => ($m['role'] === 'assistant' ? "$assistant: " : '') . "[{$m['at']}] {$m['text']}",
                $recent
            ),
            'latest' => $latest,
        ];
        if ($lastReply !== null) {
            $state['assistant_last_reply'] = [
                'text' => $lastReply['text'],
                'seconds_ago' => $lastReply['seconds_ago'],
            ];
        }
        return $state;
    }

    /**
     * Política: responde se for dirigida à Lara; durante a janela de continuação (logo após ela falar)
     * também responde se houver necessidade clara. Fora disso a Lara não interrompe.
     *
     * @return array{respond: bool, respond_p: float, relevance: string, category: string, importance: float}
     */
    public static function apply(array $answers, float $threshold, bool $inFollowup): array
    {
        $addressed = (float)($answers['addressed']['noul'] ?? 0);
        $needs = (float)($answers['needs_reply']['noul'] ?? 0);
        $p = $inFollowup ? max($addressed, min($addressed + 0.25, $needs)) : $addressed;
        $respond = $p >= $threshold;

        $category = (string)($answers['relevance']['choice'] ?? 'context');
        $importance = (float)($answers['importance']['score'] ?? 1);
        if ($respond) {
            $relevance = 'addressed';
        } elseif ($category === 'noise' && $addressed < 0.35) {
            $relevance = 'noise';
        } else {
            $relevance = 'relevant';
        }
        return [
            'respond' => $respond,
            'respond_p' => round($p, 4),
            'relevance' => $relevance,
            'category' => $category,
            'importance' => round($importance, 2),
        ];
    }
}
