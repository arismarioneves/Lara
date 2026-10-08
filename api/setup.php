<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Lara\Api;
use Lara\Auth;
use Lara\Config;
use Lara\ProviderException;
use Lara\Settings;
use Lara\Setup\Catalog;
use Lara\Setup\Installer;
use Lara\Setup\Probe;

/**
 * Assistente de configuração.
 *  - Sem config.php (instalação): aberto, todas as ações de instalação.
 *  - Com config.php (reconfiguração): exige login, CSRF e senha reconfirmada; só provedores e modelos de IA.
 */
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$installed = Config::installed();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? '');

if ($installed && !Auth::check()) {
    Api::fail(401, 'Sessão expirada');
}
if ($method === 'POST' && !Auth::verifyCsrf()) {
    Api::fail(403, 'Token CSRF inválido');
}
$need = static function (string $m, bool $installMode, bool $sudo = false) use ($method, $installed): void {
    if ($method !== $m) {
        Api::fail(405, 'Método não permitido');
    }
    if ($installMode === $installed) {
        Api::fail(409, $installed ? 'A Lara já está instalada' : 'A Lara ainda não foi instalada');
    }
    if ($sudo && !Auth::sudo()) {
        Api::fail(403, 'Confirme sua senha');
    }
};

Api::run(static function () use ($installed, $method, $action, $need): void {
    switch ($action) {
        case 'state':
            if ($method !== 'GET') {
                Api::fail(405, 'Método não permitido');
            }
            $catalog = [];
            foreach (Catalog::providers() as $name => $p) {
                $catalog[$name] = array_intersect_key($p, array_flip(['label', 'tagline', 'recommended', 'auth', 'key_url', 'placeholder', 'roles', 'systemone', 'defaults']));
            }
            $state = [
                'mode' => $installed ? 'reconfigure' : 'install',
                'csrf' => Auth::csrf(),
                'catalog' => $catalog,
                'roles' => Catalog::roles(),
                'thinking' => Catalog::THINKING,
            ];
            if (!$installed) {
                $state['requirements'] = Installer::requirements();
                $state['timezones'] = \DateTimeZone::listIdentifiers();
                $state['db'] = Config::defaults()['db'];
            } else {
                $state['unlocked'] = Auth::sudo();
                if ($state['unlocked']) {
                    $state['providers'] = Installer::providersState();
                    $state['ai'] = Config::get('ai');
                }
            }
            Api::ok($state);

        case 'unlock':
            $need('POST', false);
            if (!Auth::confirm((string)(Api::jsonBody()['password'] ?? ''))) {
                Api::fail(401, 'Senha incorreta');
            }
            Api::ok(['providers' => Installer::providersState(), 'ai' => Config::get('ai')]);

        case 'db_test':
            $need('POST', true);
            session_write_close();
            Api::ok(Installer::testDb(Api::jsonBody()));

        case 'probe':
            if ($method !== 'POST') {
                Api::fail(405, 'Método não permitido');
            }
            if ($installed && !Auth::sudo()) {
                Api::fail(403, 'Confirme sua senha');
            }
            session_write_close();
            $in = Api::jsonBody();
            $name = (string)($in['provider'] ?? '');
            $meta = Catalog::providers()[$name] ?? null;
            if ($meta === null) {
                throw new InvalidArgumentException('Provedor desconhecido');
            }
            // Campo vazio na reconfiguração = testar a chave/URL já salva
            $saved = $installed ? Config::provider($name) : ['api_key' => '', 'base_url' => $meta['base_url']];
            $key = trim((string)($in['api_key'] ?? '')) ?: $saved['api_key'];
            $url = $meta['auth'] === 'url'
                ? (Installer::url((string)($in['base_url'] ?? ''), $meta['label']) ?: $saved['base_url'])
                : $saved['base_url'];
            if ($meta['auth'] === 'key' && $key === '') {
                throw new InvalidArgumentException('Informe a chave');
            }
            try {
                Api::ok(['ok' => true] + Probe::run($name, $key, $url));
            } catch (ProviderException $e) {
                Api::ok(['ok' => false, 'error' => $e->getMessage()]);
            }

        case 'install':
            $need('POST', true);
            set_time_limit(120);
            $result = Installer::install(Api::jsonBody());
            if ($result['written']) {
                Auth::grant();
            }
            Api::ok($result);

        case 'save':
            $need('POST', false, true);
            Installer::reconfigure(Api::jsonBody());
            Api::ok(['ai' => Config::get('ai'), 'settings' => Settings::all()]);
    }
    Api::fail(404, 'Ação desconhecida');
});
