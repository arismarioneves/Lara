<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Lara\Api;
use Lara\Config;
use Lara\Settings;

Api::guard('GET', 'POST');
Api::run(static function (): void {
    $values = $_SERVER['REQUEST_METHOD'] === 'POST' ? Settings::save(Api::jsonBody()) : Settings::all();
    $options = [];
    foreach (Settings::schema() as $key => $def) {
        if (isset($def['options'])) {
            $options[$key] = $def['options'];
        }
    }
    Api::ok([
        'settings' => $values,
        'options' => $options,
        // Só os provedores com chave configurada em config.php (as chaves nunca saem do servidor)
        'available' => array_merge(Config::availableProviders(), ['browser']),
    ]);
});
