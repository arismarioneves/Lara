<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Lara\Api;
use Lara\Auth;

/** POST {password} → login · DELETE → logout */
header('Cache-Control: no-store');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $password = (string)(Api::jsonBody()['password'] ?? '');
    if (!Auth::attempt($password)) {
        Api::fail(401, 'Senha incorreta');
    }
    Api::ok(['csrf' => Auth::csrf()]);
}

if ($method === 'DELETE') {
    if (Auth::check() && Auth::verifyCsrf()) {
        Auth::logout();
    }
    Api::ok();
}

Api::fail(405, 'Método não permitido');
