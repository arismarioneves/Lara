<?php
declare(strict_types=1);

use Lara\Ai\LlmDecider;
use Lara\Assistant\Decision;
use Lara\Assistant\Prefilter;

// ---- Pré-filtro ----
$wake = ['lara', 'ei lara'];
check('wake word com pontuação e acento', Prefilter::classify('Lára, que horas são?', $wake) === Prefilter::ADDRESSED);
check('wake word não casa dentro de outra palavra', Prefilter::classify('Vou para a Clara amanhã', $wake) === Prefilter::UNKNOWN);
check('"para" não ativa a Lara', Prefilter::classify('Isso é para você', $wake) === Prefilter::UNKNOWN);
check('wake word de várias palavras', Prefilter::classify('ei, Lara! anota isso', ['ei lara']) === Prefilter::ADDRESSED);
check('alucinação do Whisper é ruído', Prefilter::classify('Legendas pela comunidade Amara.org', $wake) === Prefilter::NOISE);
check('marcação [Música] é ruído', Prefilter::classify('[Música]', $wake) === Prefilter::NOISE);
check('só hesitação é ruído', Prefilter::classify('Hum... ah.', $wake) === Prefilter::NOISE);
check('vazio é ruído', Prefilter::classify("  \n ", $wake) === Prefilter::NOISE);
check('fala comum segue para o decisor', Prefilter::classify('A reunião ficou para quinta às 15h', $wake) === Prefilter::UNKNOWN);

// ---- Política de decisão ----
$a = fn(float $addr, float $needs, string $rel = 'context', float $imp = 1.0) => [
    'addressed' => ['type' => 'noul', 'noul' => $addr],
    'needs_reply' => ['type' => 'noul', 'noul' => $needs],
    'relevance' => ['type' => 'choice', 'choice' => $rel],
    'importance' => ['type' => 'score', 'score' => $imp],
];
$d = Decision::apply($a(0.82, 0.9), 0.6, false);
check('dirigida acima do limiar responde', $d['respond'] && $d['relevance'] === 'addressed');

$d = Decision::apply($a(0.2, 0.95), 0.6, false);
check('fora da continuação, "precisa de resposta" sozinho não faz a Lara interromper', !$d['respond'] && $d['relevance'] === 'relevant');

$d = Decision::apply($a(0.45, 0.9), 0.6, true);
check('na continuação, necessidade eleva a chance (até +0.25)', $d['respond'] && abs($d['respond_p'] - 0.7) < 1e-9, (string)$d['respond_p']);

$d = Decision::apply($a(0.1, 0.99), 0.6, true);
check('na continuação, conversa alheia não vira resposta', !$d['respond'], (string)$d['respond_p']);

$d = Decision::apply($a(0.1, 0.1, 'noise'), 0.6, false);
check('ruído com baixa chance de ser para a Lara é catalogado como ruído', $d['relevance'] === 'noise');

$d = Decision::apply($a(0.5, 0.1, 'noise'), 0.6, false);
check('ruído ambíguo (talvez para a Lara) é mantido como contexto', $d['relevance'] === 'relevant');

$d = Decision::apply($a(0.6, 0.0, 'memory', 3.0), 0.6, false);
check('limiar é inclusivo', $d['respond'] && $d['category'] === 'memory' && $d['importance'] === 3.0);

// ---- Adaptador Jev sobre LLM ----
$q = Decision::questions('Lara', 'Ana');
$schema = LlmDecider::schema($q);
check('schema exige todas as perguntas', $schema['required'] === array_keys($q));
check('schema de choice lista as opções', array_keys($schema['properties']['relevance']['properties']) === ['noise', 'context', 'memory']);

$n = LlmDecider::normalize($q, [
    'addressed' => 1.7,
    'needs_reply' => -0.2,
    'relevance' => ['noise' => 1, 'context' => 3, 'memory' => 0],
    'importance' => 9,
]);
check('noul é limitado a [0,1]', $n['addressed']['noul'] === 1.0 && $n['needs_reply']['noul'] === 0.0);
check('choice normaliza para somar 1 e escolhe o maior', $n['relevance']['choice'] === 'context' && abs(array_sum($n['relevance']['probabilities']) - 1) < 1e-9);
check('score é limitado ao último nível', $n['importance']['score'] === 3.0);

$n = LlmDecider::normalize($q, ['addressed' => 0.3, 'needs_reply' => 0.3, 'relevance' => 'memory', 'importance' => 1]);
check('choice como string também é aceito', $n['relevance']['choice'] === 'memory');

$threw = false;
try {
    LlmDecider::normalize($q, ['addressed' => 0.3]);
} catch (Lara\ProviderException) {
    $threw = true;
}
check('resposta incompleta do LLM é erro (não inventa decisão)', $threw);

// Respostas do adaptador alimentam a política exatamente como as do Jev
$d = Decision::apply(LlmDecider::normalize($q, ['addressed' => 0.9, 'needs_reply' => 0.8, 'relevance' => ['noise' => 0, 'context' => 1, 'memory' => 0], 'importance' => 2]), 0.6, false);
check('adaptador → política', $d['respond'] && $d['category'] === 'context');
