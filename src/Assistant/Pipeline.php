<?php
declare(strict_types=1);

namespace Lara\Assistant;

use Lara\Ai\Ai;
use Lara\Db;
use Lara\Log;
use Lara\ProviderException;
use Lara\Settings;
use Lara\Storage;

/**
 * Fluxo de cada trecho de fala capturado:
 *   transcrição (local já feita no navegador, ou online aqui) → grava → pré-filtro → decisor (Jev/adaptador)
 *   → cataloga → se precisar, IA principal responde.
 * A partir do momento em que existe texto, o fluxo é idêntico para as duas origens de transcrição.
 */
final class Pipeline
{
    /**
     * @param array{text?: ?string, audio?: ?string, audio_mime?: string, source: 'local'|'online', engine?: string,
     *              duration_ms?: int, level_db?: ?float, spoken_at: string} $in
     */
    public static function ingest(array $in): array
    {
        $s = Settings::all();
        $audio = $in['audio'] ?? null;
        $audioPath = ($audio !== null && $s['store_audio']) ? Storage::putAudio('heard', $audio, 'wav') : null;

        $text = isset($in['text']) ? trim((string)$in['text']) : null;
        $engine = (string)($in['engine'] ?? '');
        $error = null;
        if ($in['source'] === 'online') {
            if ($audio === null) {
                throw new \InvalidArgumentException('Transcrição online requer o áudio');
            }
            $stt = Ai::transcriber();
            if ($stt === null) {
                throw new \InvalidArgumentException('Transcrição online não configurada: use a local ou configure em Reconfigurar IA');
            }
            $engine = $stt->name();
            try {
                $vocab = array_values(array_filter([$s['assistant_name'], $s['user_name']]));
                $text = $stt->transcribe($audio, (string)($in['audio_mime'] ?? 'audio/wav'), $s['language'], $vocab);
            } catch (ProviderException $e) {
                Log::error('stt', $e->getMessage());
                $text = null;
                $error = 'Falha na transcrição online';
            }
        }

        $id = Db::insert('messages', [
            'role' => 'heard',
            'text' => $text,
            'relevance' => $text === null ? 'failed' : 'pending',
            'transcript_source' => $in['source'],
            'transcript_engine' => mb_substr($engine, 0, 96),
            'audio_path' => $audioPath,
            'duration_ms' => isset($in['duration_ms']) ? (int)$in['duration_ms'] : null,
            'level_db' => $in['level_db'] ?? null,
            'spoken_at' => $in['spoken_at'],
        ]);
        if ($text === null) {
            return ['message' => self::publicMessage($id), 'reply' => null, 'error' => $error ?? 'Transcrição vazia'];
        }

        $result = self::decide($id);
        $reply = null;
        if ($result['respond']) {
            try {
                $reply = self::reply($id);
            } catch (ProviderException $e) {
                Log::error('reply', $e->getMessage());
                $result['error'] = 'A IA principal não respondeu';
            }
        }
        return ['message' => self::publicMessage($id), 'reply' => $reply, 'error' => $result['error'] ?? null];
    }

    /**
     * Cataloga uma fala já transcrita. Falha do decisor deixa a fala como `pending`
     * (reprocessável com `php bin/lara.php redecide`), sem inventar classificação.
     * @return array{respond: bool, error?: string}
     */
    public static function decide(int $id): array
    {
        $s = Settings::all();
        $m = Db::one('SELECT id, text, spoken_at FROM messages WHERE id = ?', [$id]);
        $text = (string)$m['text'];

        $pre = Prefilter::classify($text, $s['wake_words']);
        if ($pre === Prefilter::NOISE) {
            Db::update('messages', $id, ['relevance' => 'noise', 'category' => 'noise', 'decided_by' => 'prefilter']);
            return ['respond' => false];
        }
        if ($pre === Prefilter::ADDRESSED) {
            Db::update('messages', $id, ['relevance' => 'addressed', 'respond_p' => 1, 'decided_by' => 'wake_word']);
            return ['respond' => true];
        }

        $lastReply = Db::one(
            "SELECT text, TIMESTAMPDIFF(SECOND, spoken_at, ?) AS seconds_ago FROM messages
             WHERE role = 'assistant' AND spoken_at <= ? ORDER BY spoken_at DESC, id DESC LIMIT 1",
            [$m['spoken_at'], $m['spoken_at']]
        );
        $inFollowup = $lastReply !== null && (int)$lastReply['seconds_ago'] <= (int)$s['followup_seconds'];

        $decider = Ai::decider();
        try {
            $answers = $decider->answers(
                Decision::state($text, Context::recent($id, $m['spoken_at']), $lastReply, $s['assistant_name']),
                Decision::questions($s['assistant_name'], $s['user_name'])
            );
        } catch (ProviderException $e) {
            Log::error('decider', $e->getMessage());
            Db::update('messages', $id, ['decided_by' => mb_substr('error:' . $decider->name(), 0, 96)]);
            return ['respond' => false, 'error' => 'Decisor indisponível'];
        }
        $d = Decision::apply($answers, (float)$s['respond_threshold'], $inFollowup);
        Db::update('messages', $id, [
            'relevance' => $d['relevance'],
            'category' => $d['category'],
            'importance' => $d['importance'],
            'respond_p' => $d['respond_p'],
            'decided_by' => mb_substr($decider->name(), 0, 96),
            'decision' => json_encode($answers, JSON_UNESCAPED_UNICODE),
        ]);
        return ['respond' => $d['respond']];
    }

    /** IA principal responde à fala $id. */
    public static function reply(int $id): array
    {
        $ctx = Context::forReply($id);
        $chat = Ai::main();
        $t0 = hrtime(true);
        $text = $chat->complete($ctx['system'], $ctx['turns']);
        $latency = intdiv(hrtime(true) - $t0, 1_000_000);
        if ($text === '') {
            throw new ProviderException($chat->name() . ': resposta vazia');
        }
        $replyId = Db::insert('messages', [
            'role' => 'assistant',
            'text' => $text,
            'relevance' => 'addressed',
            'reply_to' => $id,
            'model' => mb_substr($chat->name(), 0, 96),
            'latency_ms' => $latency,
            'spoken_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ]);
        return self::publicMessage($replyId);
    }

    /** Formato exposto à interface (sem a flag interna de origem da transcrição). */
    public static function publicMessage(int $id): array
    {
        $m = Db::one('SELECT id, role, text, relevance, category, importance, respond_p, audio_path, spoken_at FROM messages WHERE id = ?', [$id]);
        return [
            'id' => (int)$m['id'],
            'role' => $m['role'],
            'text' => $m['text'],
            'relevance' => $m['relevance'],
            'category' => $m['category'],
            'importance' => $m['importance'] === null ? null : (float)$m['importance'],
            'respond_p' => $m['respond_p'] === null ? null : (float)$m['respond_p'],
            'has_audio' => $m['audio_path'] !== null,
            'at' => $m['spoken_at'],
        ];
    }
}
