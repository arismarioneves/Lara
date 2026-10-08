<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Lara\Auth;
use Lara\Config;

// Instalada: só quem está logado reconfigura a IA. Não instalada: assistente de primeiro uso.
if (Config::installed() && !Auth::check()) {
    header('Location: ./');
    exit;
}
$v = static fn(string $f): string => $f . '?v=' . filemtime(__DIR__ . '/' . $f);

header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' data:; "
    . "style-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b0d12">
    <meta name="robots" content="noindex">
    <title><?= Config::installed() ? 'Reconfigurar IA' : 'Configurar' ?> · Lara</title>
    <link rel="icon" href="assets/icons/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $v('assets/app.css') ?>">
    <link rel="stylesheet" href="<?= $v('assets/setup.css') ?>">
</head>
<body class="setup">
<main class="wizard">
    <header class="wizard-head">
        <div class="brand">
            <span class="orb mini" data-state="idle"><span></span></span>
            <div>
                <h1 id="title">Lara</h1>
                <p id="subtitle" class="muted"></p>
            </div>
        </div>
        <ol class="steps" id="steps"></ol>
    </header>
    <section class="wizard-body" id="step" aria-live="polite"></section>
    <footer class="wizard-nav">
        <button type="button" class="ghost" id="back">Voltar</button>
        <p class="nav-msg" id="nav-msg" role="alert"></p>
        <button type="button" id="next">Continuar</button>
    </footer>
</main>
<script type="module" src="<?= $v('assets/js/setup.js') ?>"></script>
</body>
</html>
