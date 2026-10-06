// Uso em movimento (fone de ouvido, tela bloqueada): Media Session + Wake Lock.
import { silentWav } from './wav.js';

export class Companion {
    #keepalive = null;
    #wakeLock = null;
    #wantWake = false;
    onToggle = () => {};

    constructor() {
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && this.#wantWake) this.#acquireWake();
        });
    }

    /**
     * Áudio silencioso em loop: o navegador passa a tratar a página como "tocando mídia",
     * o que mantém a captura viva com a tela apagada (Android) e liga os botões do fone/relógio.
     */
    async setWalkMode(on, title = 'Lara') {
        if (!('mediaSession' in navigator)) return;
        if (on && !this.#keepalive) {
            const a = new Audio(URL.createObjectURL(silentWav()));
            a.loop = true;
            this.#keepalive = a;
            await a.play().catch(() => {});
            navigator.mediaSession.metadata = new MediaMetadata({
                title,
                artist: 'Assistente por voz',
                artwork: [{ src: 'assets/icons/icon-512.png', sizes: '512x512', type: 'image/png' }],
            });
            for (const action of ['play', 'pause', 'stop']) {
                navigator.mediaSession.setActionHandler(action, () => this.onToggle(action));
            }
        } else if (!on && this.#keepalive) {
            this.#keepalive.pause();
            URL.revokeObjectURL(this.#keepalive.src);
            this.#keepalive = null;
            for (const action of ['play', 'pause', 'stop']) navigator.mediaSession.setActionHandler(action, null);
            navigator.mediaSession.metadata = null;
        }
    }

    setListeningState(listening) {
        if ('mediaSession' in navigator && this.#keepalive) {
            navigator.mediaSession.playbackState = listening ? 'playing' : 'paused';
        }
    }

    async setWakeLock(on) {
        this.#wantWake = on;
        if (on) await this.#acquireWake();
        else {
            await this.#wakeLock?.release().catch(() => {});
            this.#wakeLock = null;
        }
    }

    async #acquireWake() {
        if (!('wakeLock' in navigator) || this.#wakeLock) return;
        try {
            this.#wakeLock = await navigator.wakeLock.request('screen');
            this.#wakeLock.addEventListener('release', () => { this.#wakeLock = null; });
        } catch {
            /* sem permissão (bateria fraca, aba oculta) */
        }
    }
}
