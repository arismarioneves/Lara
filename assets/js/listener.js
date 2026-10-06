// Captura contínua: Silero VAD (no navegador) recorta apenas os trechos com fala.
// Silêncio nunca sai do aparelho → custo e banda proporcionais ao que é realmente falado.
const VAD_ASSETS = 'https://cdn.jsdelivr.net/npm/@ricky0123/vad-web@0.0.31/dist/';
const ORT_ASSETS = 'https://cdn.jsdelivr.net/npm/onnxruntime-web@1.22.0/dist/';
const MAX_SEGMENT_MS = 60_000; // fala contínua longa é fatiada para transcrição e decisão

export class Listener extends EventTarget {
    #vad = null;
    #speechStartedAt = 0;
    #flushTimer = null;
    #lastLevelAt = 0;
    deviceId = '';
    threshold = 0.5;

    get running() {
        return !!this.#vad?.listening;
    }

    #constraints() {
        const audio = { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true };
        if (this.deviceId) audio.deviceId = { exact: this.deviceId };
        return { audio };
    }

    async start() {
        if (!window.vad) throw new Error('Biblioteca de VAD não carregou (verifique a conexão)');
        if (!this.#vad) {
            this.#vad = await window.vad.MicVAD.new({
                model: 'v5',
                baseAssetPath: VAD_ASSETS,
                onnxWASMBasePath: ORT_ASSETS,
                startOnLoad: false,
                positiveSpeechThreshold: this.threshold,
                negativeSpeechThreshold: Math.max(0.05, this.threshold - 0.15),
                minSpeechMs: 300,
                preSpeechPadMs: 400,
                redemptionMs: 900,
                submitUserSpeechOnPause: true,
                getStream: () => navigator.mediaDevices.getUserMedia(this.#constraints()),
                resumeStream: () => navigator.mediaDevices.getUserMedia(this.#constraints()),
                onFrameProcessed: (probs) => this.#level(probs.isSpeech),
                onSpeechStart: () => {
                    this.#speechStartedAt = Date.now();
                    this.dispatchEvent(new Event('speechstart'));
                    clearTimeout(this.#flushTimer);
                    this.#flushTimer = setTimeout(() => this.#flush(), MAX_SEGMENT_MS);
                },
                onSpeechRealStart: () => this.dispatchEvent(new Event('speechconfirmed')),
                onVADMisfire: () => {
                    clearTimeout(this.#flushTimer);
                    this.dispatchEvent(new Event('misfire'));
                },
                onSpeechEnd: (audio) => {
                    clearTimeout(this.#flushTimer);
                    this.dispatchEvent(new CustomEvent('segment', {
                        detail: { audio, startedAt: this.#speechStartedAt, endedAt: Date.now() },
                    }));
                },
            });
        }
        await this.#vad.start();
    }

    async pause() {
        clearTimeout(this.#flushTimer);
        await this.#vad?.pause();
    }

    /** Troca de microfone/sensibilidade: recria o VAD. */
    async reconfigure({ deviceId, threshold }) {
        const wasRunning = this.running;
        this.deviceId = deviceId ?? this.deviceId;
        this.threshold = threshold ?? this.threshold;
        if (this.#vad) {
            await this.#vad.destroy();
            this.#vad = null;
        }
        if (wasRunning) await this.start();
    }

    /** Força o fim do trecho atual (pause com submit → onSpeechEnd) e retoma. */
    async #flush() {
        if (!this.#vad?.listening) return;
        await this.#vad.pause();
        await this.#vad.start();
    }

    #level(p) {
        const now = performance.now();
        if (now - this.#lastLevelAt < 60) return;
        this.#lastLevelAt = now;
        this.dispatchEvent(new CustomEvent('level', { detail: p }));
    }
}

export async function listMicrophones() {
    const devices = await navigator.mediaDevices.enumerateDevices();
    return devices.filter((d) => d.kind === 'audioinput').map((d, i) => ({ id: d.deviceId, label: d.label || `Microfone ${i + 1}` }));
}
