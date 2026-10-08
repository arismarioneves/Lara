<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Lara\Ai\Ai;
use Lara\Api;
use Lara\Config;
use Lara\Settings;

Api::guard('GET', 'POST');
Api::run(static function (): void {
    $ai = Config::get('ai');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = Api::jsonBody();
        if (($input['stt_mode'] ?? '') === 'online' && $ai['stt']['provider'] === '') {
            throw new InvalidArgumentException('Nenhuma transcrição online configurada: use Reconfigurar IA');
        }
        $values = Settings::save($input);
    } else {
        $values = Settings::all();
    }
    $options = [];
    foreach (Settings::schema() as $key => $def) {
        if (isset($def['options'])) {
            $options[$key] = $def['options'];
        }
    }
    $decider = $ai['decider']['engine'] === 'jev'
        ? Ai::label('typesafe', $ai['decider']['jev_model'])
        : Ai::label($ai['decider']['provider'], $ai['decider']['model']);
    Api::ok([
        'settings' => $values,
        'options' => $options,
        // Resumo da IA definida no config.php (sem chaves); trocar é em Reconfigurar IA
        'ai' => [
            'main' => Ai::label($ai['main']['provider'], $ai['main']['model']),
            'decider' => $decider,
            'stt' => $ai['stt']['provider'] === '' ? null : Ai::label($ai['stt']['provider'], $ai['stt']['model']),
            'tts' => $ai['tts']['provider'] === 'browser' ? 'Voz do navegador' : Ai::label($ai['tts']['provider'], $ai['tts']['model']),
            'tts_provider' => $ai['tts']['provider'],
            'voices' => $ai['tts']['voices'],
        ],
    ]);
});
