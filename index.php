<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Lara\Auth;

$authed = Auth::check();
$csrf = $authed ? Auth::csrf() : '';
$v = static fn(string $f): string => $f . '?v=' . filemtime(__DIR__ . '/' . $f);

header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'wasm-unsafe-eval'; "
    . "worker-src 'self' blob:; connect-src 'self' https: blob: data:; media-src 'self' blob: data:; "
    . "img-src 'self' data:; style-src 'self'; font-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b0d12">
    <meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
    <title>Lara</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
    <link rel="stylesheet" href="<?= $v('assets/app.css') ?>">
</head>
<body data-auth="<?= $authed ? '1' : '0' ?>">

<?php if (!$authed): ?>
<main class="login">
    <div class="orb" data-state="idle"><span></span></div>
    <h1>Lara</h1>
    <form id="login" autocomplete="on">
        <input type="password" name="password" placeholder="Senha" autocomplete="current-password" required autofocus>
        <button type="submit">Entrar</button>
        <p class="error" hidden></p>
    </form>
</main>
<script type="module" src="<?= $v('assets/js/login.js') ?>"></script>
<?php else: ?>
<header class="top">
    <h1>Lara</h1>
    <span class="badge" id="stt-badge" title="Modo de transcrição"></span>
    <button class="icon" id="open-settings" aria-label="Configurações">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M19.4 13a7.6 7.6 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.4 7.4 0 0 0-1.7-1L15 3h-4l-.3 2.9a7.4 7.4 0 0 0-1.7 1l-2.5-1-2 3.5L6.6 11a7.6 7.6 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1a7.4 7.4 0 0 0 1.7 1L11 21h4l.3-2.9a7.4 7.4 0 0 0 1.7-1l2.5 1 2-3.5zM13 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7z" transform="translate(-1 0)"/></svg>
    </button>
</header>

<main class="app">
    <section class="stage">
        <button class="orb" id="orb" data-state="off" aria-label="Ligar ou pausar a escuta"><span></span></button>
        <p class="status" id="status">Toque para começar a ouvir</p>
        <div class="meter" aria-hidden="true"><i id="meter"></i></div>
    </section>

    <section class="feed-wrap">
        <div class="feed-tools">
            <label class="toggle"><input type="checkbox" id="show-noise"> mostrar ruído</label>
        </div>
        <ol class="feed" id="feed" aria-live="polite"></ol>
    </section>
</main>

<dialog id="settings">
    <form method="dialog" id="settings-form">
        <header>
            <h2>Configurações</h2>
            <button class="icon" value="close" aria-label="Fechar"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg></button>
        </header>
        <div class="settings-body" id="settings-body"></div>
        <footer>
            <button type="button" id="logout" class="ghost">Sair</button>
            <span class="saved" id="saved" hidden>Salvo</span>
            <button type="button" id="save">Salvar</button>
        </footer>
    </form>
</dialog>

<script src="https://cdn.jsdelivr.net/npm/onnxruntime-web@1.22.0/dist/ort.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/@ricky0123/vad-web@0.0.31/dist/bundle.min.js" crossorigin="anonymous"></script>
<script type="module" src="<?= $v('assets/js/app.js') ?>"></script>
<?php endif; ?>
</body>
</html>
