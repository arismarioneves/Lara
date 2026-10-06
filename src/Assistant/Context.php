<?php
declare(strict_types=1);

namespace Lara\Assistant;

use Lara\Db;
use Lara\Settings;

/**
 * Monta o que a IA principal enxerga: somente falas catalogadas como dirigidas à Lara ou relevantes,
 * respostas dela e memórias antigas recuperadas por busca textual. Ruído, pendentes e a origem da
 * transcrição (local/online) nunca entram.
 */
final class Context
{
    /** @return array{system: string, turns: list<array{role: string, text: string}>} */
    public static function forReply(int $messageId): array
    {
        $current = Db::one('SELECT id, text, spoken_at FROM messages WHERE id = ?', [$messageId]);
        if ($current === null) {
            throw new \InvalidArgumentException('Mensagem inexistente');
        }
        $limit = (int)Settings::get('context_messages');
        $window = array_reverse(Db::all(
            "SELECT id, role, text, relevance, spoken_at FROM messages
             WHERE id <> ? AND spoken_at <= ? AND text IS NOT NULL
               AND (role = 'assistant' OR relevance IN ('addressed', 'relevant'))
             ORDER BY spoken_at DESC, id DESC LIMIT $limit",
            [$messageId, $current['spoken_at']]
        ));
        $window[] = ['id' => $messageId, 'role' => 'heard', 'text' => $current['text'], 'relevance' => 'addressed', 'spoken_at' => $current['spoken_at']];

        $oldestId = (int)($window[0]['id'] ?? $messageId);
        $memories = self::memories((string)$current['text'], $oldestId);

        return ['system' => self::system($memories), 'turns' => self::turns($window)];
    }

    /** Falas recentes (para o decisor). @return list<array{at: string, text: string, role: string}> */
    public static function recent(int $beforeId, string $beforeAt, int $limit = 8): array
    {
        $rows = Db::all(
            "SELECT role, text, spoken_at FROM messages
             WHERE id <> ? AND spoken_at <= ? AND spoken_at >= (? - INTERVAL 15 MINUTE) AND text IS NOT NULL
               AND (role = 'assistant' OR relevance IN ('addressed', 'relevant', 'pending'))
             ORDER BY spoken_at DESC, id DESC LIMIT $limit",
            [$beforeId, $beforeAt, $beforeAt]
        );
        return array_map(static fn($r) => [
            'at' => substr($r['spoken_at'], 11, 8),
            'text' => $r['text'],
            'role' => $r['role'],
        ], array_reverse($rows));
    }

    /** @return list<array{text: string, spoken_at: string}> */
    private static function memories(string $query, int $beforeId): array
    {
        $n = (int)Settings::get('memory_results');
        $terms = self::keywords($query);
        if ($n === 0 || $terms === '') {
            return [];
        }
        return Db::all(
            "SELECT text, spoken_at FROM messages
             WHERE id < ? AND role = 'heard' AND relevance IN ('addressed', 'relevant')
               AND MATCH(text) AGAINST (? IN NATURAL LANGUAGE MODE)
             ORDER BY MATCH(text) AGAINST (? IN NATURAL LANGUAGE MODE) * (1 + COALESCE(importance, 1)) DESC
             LIMIT $n",
            [$beforeId, $terms, $terms]
        );
    }

    /** Palavras com conteúdo (remove nome da assistente e palavras curtas). */
    private static function keywords(string $text): string
    {
        $skip = array_map([Prefilter::class, 'normalize'], (array)Settings::get('wake_words'));
        $words = array_filter(
            explode(' ', Prefilter::normalize($text)),
            static fn($w) => mb_strlen($w) >= 4 && !in_array($w, $skip, true)
        );
        return implode(' ', array_unique($words));
    }

    private static function system(array $memories): string
    {
        $s = Settings::all();
        $now = new \DateTimeImmutable();
        $days = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
        $lines = [
            trim((string)$s['persona']),
            '',
            '# Como você recebe as falas',
            "Um microfone fica ligado o tempo todo. As falas são transcritas automaticamente (podem ter erros: interprete pelo sentido) e catalogadas.",
            'Linhas marcadas com "(ao redor)" são conversas próximas que não foram dirigidas a você: use como contexto, sem responder a elas diretamente.',
            'Sempre responda à ÚLTIMA fala, que foi dirigida a você.',
            'Sua resposta será falada em voz alta: texto corrido, 1 a 3 frases, sem markdown, sem listas, sem emojis — a menos que peçam detalhes.',
            "Idioma: {$s['language']}.",
            '',
            '# Situação',
            "Agora: {$days[(int)$now->format('w')]}, " . $now->format('d/m/Y H:i') . '.',
            "Seu nome: {$s['assistant_name']}.",
        ];
        if ($s['user_name'] !== '') {
            $lines[] = "Usuário: {$s['user_name']}.";
        }
        if ($memories) {
            $lines[] = '';
            $lines[] = '# Memórias de conversas anteriores (podem ajudar)';
            foreach ($memories as $m) {
                $lines[] = '- [' . self::stamp($m['spoken_at'], $now) . '] ' . $m['text'];
            }
        }
        return implode("\n", $lines);
    }

    /** Agrupa falas consecutivas (o modelo espera alternância usuário/assistente). */
    private static function turns(array $window): array
    {
        $now = new \DateTimeImmutable();
        $turns = [];
        foreach ($window as $m) {
            $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
            $text = $role === 'assistant'
                ? $m['text']
                : '[' . self::stamp($m['spoken_at'], $now) . ']' . ($m['relevance'] === 'relevant' ? ' (ao redor)' : '') . ' ' . $m['text'];
            $last = array_key_last($turns);
            if ($last !== null && $turns[$last]['role'] === $role) {
                $turns[$last]['text'] .= "\n" . $text;
            } else {
                $turns[] = ['role' => $role, 'text' => $text];
            }
        }
        while ($turns && $turns[0]['role'] === 'assistant') {
            array_shift($turns);
        }
        return $turns;
    }

    private static function stamp(string $spokenAt, \DateTimeImmutable $now): string
    {
        $t = new \DateTimeImmutable($spokenAt);
        return $t->format('Y-m-d') === $now->format('Y-m-d') ? $t->format('H:i') : $t->format('d/m H:i');
    }
}
