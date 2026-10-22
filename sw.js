// Service worker: instalação como PWA + cache dos arquivos estáticos e das bibliotecas da CDN.
// API e páginas sempre vão à rede (dados privados nunca ficam em cache).
const VERSION = 'lara-v2';
const SHELL = [
    'assets/app.css',
    'assets/js/app.js',
    'assets/js/api.js',
    'assets/js/listener.js',
    'assets/js/mic.js',
    'assets/js/voice.js',
    'assets/js/companion.js',
    'assets/js/settings.js',
    'assets/js/wav.js',
    'assets/js/whisper.worker.js',
    'assets/js/login.js',
    'assets/icons/icon.svg',
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png',
];

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    if (e.request.method !== 'GET') return;

    // Bibliotecas com versão fixa na CDN: cache-first (imutáveis)
    if (url.hostname === 'cdn.jsdelivr.net') {
        e.respondWith(caches.open(VERSION).then(async (c) => {
            const hit = await c.match(e.request);
            if (hit) return hit;
            const res = await fetch(e.request);
            if (res.ok) c.put(e.request, res.clone());
            return res;
        }));
        return;
    }

    // Estáticos locais: rede primeiro (sempre atualizados), cache se offline
    if (url.origin === location.origin && url.pathname.includes('/assets/')) {
        e.respondWith(
            fetch(e.request)
                .then((res) => {
                    if (res.ok) {
                        const copy = res.clone();
                        caches.open(VERSION).then((c) => c.put(e.request, copy));
                    }
                    return res;
                })
                .catch(() => caches.match(e.request, { ignoreSearch: true })),
        );
    }
});
