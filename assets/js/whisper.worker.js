// Whisper local (transformers.js) — roda no navegador, custo zero de API.
// WebGPU quando disponível; senão WebAssembly quantizado.
// Import dinâmico: o import estático de módulo cross-origin falha em module workers no Chrome
// (erro sem mensagem), enquanto import() funciona.
const TRANSFORMERS = 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@4.3.0';
let lib = null;
async function transformers() {
    if (!lib) {
        lib = await import(TRANSFORMERS);
        lib.env.allowLocalModels = false;
    }
    return lib;
}

let asr = null;
let loadedModel = null;
let loading = null;

async function hasWebGpu() {
    try {
        return !!(navigator.gpu && await navigator.gpu.requestAdapter());
    } catch {
        return false;
    }
}

async function load(model) {
    if (loadedModel === model && asr) return;
    if (asr) {
        await asr.dispose?.();
        asr = null;
    }
    const gpu = await hasWebGpu();
    const big = model.includes('large') || model.includes('turbo');
    const options = gpu
        ? { device: 'webgpu', dtype: { encoder_model: big ? 'fp16' : 'fp32', decoder_model_merged: 'q4' } }
        : { device: 'wasm', dtype: 'q8' };
    const files = new Map();
    options.progress_callback = (p) => {
        if (p.status === 'progress' && p.total) {
            files.set(p.file, [p.loaded, p.total]);
            let loaded = 0, total = 0;
            for (const [l, t] of files.values()) { loaded += l; total += t; }
            postMessage({ type: 'progress', pct: Math.round((loaded / total) * 100) });
        }
    };
    const { pipeline } = await transformers();
    asr = await pipeline('automatic-speech-recognition', model, options);
    loadedModel = model;
    postMessage({ type: 'ready', model, device: options.device });
}

// Fila serial: um trecho por vez, na ordem em que foi falado
let chain = Promise.resolve();

self.onmessage = (e) => {
    const msg = e.data;
    if (msg.type === 'load') {
        loading = load(msg.model).catch((err) => {
            asr = null;
            loadedModel = null;
            postMessage({ type: 'error', error: String(err?.message || err) });
        });
        return;
    }
    if (msg.type === 'transcribe') {
        chain = chain.then(async () => {
            try {
                await loading;
                if (!asr) throw new Error('modelo de transcrição local não carregado');
                const out = await asr(msg.audio, {
                    language: msg.language,
                    task: 'transcribe',
                    chunk_length_s: 30,
                    stride_length_s: 5,
                });
                postMessage({ type: 'result', id: msg.id, text: (out.text || '').trim() });
            } catch (err) {
                postMessage({ type: 'result', id: msg.id, text: null, error: String(err?.message || err) });
            }
        });
    }
};
