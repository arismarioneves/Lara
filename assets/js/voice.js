// Saída de voz: fila de respostas, voz do navegador (grátis) ou do servidor (Gemini/OpenAI/ElevenLabs).
export class Voice extends EventTarget {
    #queue = [];
    #busy = false;
    #audio = new Audio();
    #utterance = null;
    settings = {};

    get speaking() {
        return this.#busy;
    }

    say(reply) {
        this.#queue.push(reply);
        if (!this.#busy) this.#next();
    }

    stop() {
        this.#queue = [];
        this.#audio.pause();
        this.#audio.removeAttribute('src');
        if (this.#utterance) speechSynthesis.cancel();
        this.#done();
    }

    async #next() {
        const reply = this.#queue.shift();
        if (!reply) return;
        this.#busy = true;
        this.dispatchEvent(new Event('start'));
        try {
            if (this.settings.tts_provider === 'browser') {
                await this.#browser(reply.text);
            } else {
                await this.#play(`api/tts.php?id=${encodeURIComponent(reply.id)}`);
            }
        } catch (err) {
            this.dispatchEvent(new CustomEvent('error', { detail: err }));
        }
        this.#done();
        if (this.#queue.length) this.#next();
    }

    #done() {
        if (!this.#busy) return;
        this.#busy = false;
        this.#utterance = null;
        this.dispatchEvent(new Event('end'));
    }

    /**
     * Toca e resolve ao terminar. Watchdog: em aba em segundo plano o navegador pode adiar a mídia
     * indefinidamente; sem ele a Lara ficaria "falando" para sempre (e as falas seguintes seriam
     * descartadas como eco).
     */
    #play(src) {
        return new Promise((resolve, reject) => {
            const a = this.#audio;
            let timer = setTimeout(() => finish(new Error('A voz não começou a tocar (aba em segundo plano?)')), 10_000);
            const finish = (err) => {
                clearTimeout(timer);
                a.onended = a.onerror = a.onpause = a.onplaying = null;
                if (err) {
                    a.pause();
                    reject(err);
                } else {
                    resolve();
                }
            };
            a.onplaying = () => {
                clearTimeout(timer);
                const ms = Number.isFinite(a.duration) ? (a.duration - a.currentTime) * 1000 + 3000 : 120_000;
                timer = setTimeout(() => finish(new Error('A voz travou')), ms);
            };
            a.onended = () => finish();
            a.onerror = () => finish(new Error('Falha ao reproduzir a voz'));
            a.onpause = () => finish(); // stop()
            a.src = src;
            a.play().catch((err) => finish(err));
        });
    }

    /** Prévia de voz (blob vindo do servidor). */
    async preview(blob) {
        const url = URL.createObjectURL(blob);
        try {
            await this.#play(url);
        } finally {
            URL.revokeObjectURL(url);
        }
    }

    #browser(text) {
        return new Promise((resolve, reject) => {
            if (!('speechSynthesis' in window)) return reject(new Error('Navegador sem síntese de voz'));
            const u = new SpeechSynthesisUtterance(text);
            u.lang = this.settings.language || 'pt-BR';
            const voice = browserVoices().find((v) => v.name === this.settings.tts_voice);
            if (voice) u.voice = voice;
            // Watchdog: o speechSynthesis do Chrome às vezes não dispara onend
            const timer = setTimeout(() => { speechSynthesis.cancel(); resolve(); }, 8000 + text.length * 90);
            u.onend = () => { clearTimeout(timer); resolve(); };
            u.onerror = (e) => {
                clearTimeout(timer);
                if (e.error === 'interrupted' || e.error === 'canceled') resolve();
                else reject(new Error(e.error));
            };
            this.#utterance = u;
            speechSynthesis.speak(u);
        });
    }
}

export function browserVoices() {
    return 'speechSynthesis' in window ? speechSynthesis.getVoices() : [];
}
