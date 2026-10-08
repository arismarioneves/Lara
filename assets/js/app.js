import { api } from './api.js';
import { Listener } from './listener.js';
import { Voice } from './voice.js';
import { Companion } from './companion.js';
import { SettingsPanel, LOCAL_KEYS } from './settings.js';
import { encodeWav, levelDb } from './wav.js';

const $ = (id) => document.getElementById(id);
const orb = $('orb');
const statusEl = $('status');
const feed = $('feed');
const meter = $('meter');

const listener = new Listener();
const voice = new Voice();
const companion = new Companion();
let settings = {};
let ai = {}; // resumo da IA do config.php (provedores e modelos)
let worker = null;
let workerModel = '';
let workerSeq = 0;
const pendingLocal = new Map();
let speakingWindow = { from: 0, to: 0 }; // período em que a Lara falou (descarta o eco dela mesma)
let inflight = 0;

// ---------- estado visual ----------
function setState(state, text) {
    orb.dataset.state = state;
    if (text !== undefined) statusEl.textContent = text;
}

function idleState() {
    if (voice.speaking) return setState('speaking', 'Falando…');
    if (inflight > 0) return setState('thinking', 'Pensando…');
    if (listener.running) return setState('listening', 'Ouvindo');
    setState('off', 'Pausada · toque para ouvir');
}

function badge() {
    $('stt-badge').textContent = sttMode() === 'online' ? 'transcrição online' : 'transcrição local';
}

/** Online só quando escolhido em Configurações E existe serviço configurado no config.php. */
function sttMode() {
    return settings.stt_mode === 'online' && ai.stt ? 'online' : 'local';
}

// ---------- feed ----------
const RELEVANCE_LABEL = { addressed: 'para a Lara', relevant: 'contexto', noise: 'ruído', pending: '…', failed: 'falhou' };

function renderMessage(m) {
    let li = feed.querySelector(`[data-id="${m.id}"]`);
    if (!li) {
        li = document.createElement('li');
        li.dataset.id = m.id;
        feed.append(li);
    }
    li.className = `msg ${m.role} ${m.relevance}`;
    const time = new Date(m.at.replace(' ', 'T')).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    li.innerHTML = '';
    const meta = document.createElement('div');
    meta.className = 'meta';
    meta.textContent = m.role === 'assistant' ? `${settings.assistant_name} · ${time}` : `${time} · ${RELEVANCE_LABEL[m.relevance] ?? m.relevance}`;
    const body = document.createElement('p');
    body.textContent = m.text ?? '(sem transcrição — áudio guardado)';
    li.append(meta, body);
    if (m.has_audio && m.role === 'heard') {
        const play = document.createElement('button');
        play.className = 'play';
        play.ariaLabel = 'Ouvir trecho';
        play.textContent = '▶';
        play.addEventListener('click', () => new Audio(`api/audio.php?id=${m.id}`).play());
        meta.append(play);
    }
    const nearBottom = feed.scrollHeight - feed.scrollTop - feed.clientHeight < 120;
    if (nearBottom) feed.scrollTop = feed.scrollHeight;
}

function placeholder(tempId) {
    const li = document.createElement('li');
    li.dataset.id = tempId;
    li.className = 'msg heard pending';
    li.innerHTML = '<div class="meta">agora · transcrevendo…</div><p>…</p>';
    feed.append(li);
    feed.scrollTop = feed.scrollHeight;
    return li;
}

async function loadHistory() {
    const { messages } = await api.history({ limit: 80 });
    feed.replaceChildren();
    messages.forEach(renderMessage);
    feed.scrollTop = feed.scrollHeight;
}

// ---------- transcrição local ----------
function ensureWorker() {
    if (sttMode() !== 'local') return;
    if (!worker) {
        worker = new Worker(new URL('./whisper.worker.js', import.meta.url), { type: 'module' });
        worker.onerror = () => {
            // Script do worker não carregou: libera os trechos pendentes (vão como "falhou", com áudio)
            setState('error', 'Transcrição local indisponível (falha ao carregar)');
            for (const resolve of pendingLocal.values()) resolve({ text: null });
            pendingLocal.clear();
            worker = null;
            workerModel = '';
        };
        worker.onmessage = ({ data }) => {
            if (data.type === 'progress') setState(orb.dataset.state, `Baixando transcrição local ${data.pct}%`);
            if (data.type === 'ready') idleState();
            if (data.type === 'error') setState('error', `Transcrição local indisponível: ${data.error}`);
            if (data.type === 'result') {
                pendingLocal.get(data.id)?.(data);
                pendingLocal.delete(data.id);
            }
        };
    }
    if (workerModel !== settings.stt_local_model) {
        workerModel = settings.stt_local_model;
        worker.postMessage({ type: 'load', model: workerModel });
    }
}

function transcribeLocal(audio) {
    ensureWorker();
    const id = ++workerSeq;
    return new Promise((resolve) => {
        pendingLocal.set(id, resolve);
        worker.postMessage({ type: 'transcribe', id, audio, language: (settings.language || 'pt').slice(0, 2) });
    });
}

// ---------- cada trecho de fala ----------
async function onSegment({ audio, startedAt, endedAt }) {
    // Sem barge-in, o que foi captado enquanto a Lara falava é o eco dela: descarta
    if (!settings.barge_in && startedAt < speakingWindow.to + 400 && endedAt > speakingWindow.from) return;

    const mode = sttMode(); // fixa o modo do trecho: trocar no meio não perde nada
    const tempId = `t${endedAt}`;
    const li = placeholder(tempId);
    inflight++;
    idleState();

    const form = new FormData();
    form.append('source', mode);
    form.append('spoken_at', String(endedAt));
    form.append('duration_ms', String(Math.round((audio.length / 16000) * 1000)));
    form.append('level_db', levelDb(audio).toFixed(1));
    if (settings.store_audio || mode === 'online') form.append('audio', encodeWav(audio), 'speech.wav');

    try {
        if (mode === 'local') {
            const r = await transcribeLocal(audio);
            form.append('engine', `whisper-web:${settings.stt_local_model.split('/').pop()}`);
            if (r.text !== null) form.append('text', r.text);
        }
        const res = await api.ingest(form);
        li.remove();
        renderMessage(res.message);
        if (res.reply) {
            renderMessage(res.reply);
            voice.say(res.reply);
        }
        if (res.error && res.message.relevance !== 'noise') toast(res.error);
    } catch (err) {
        li.remove();
        toast(err.message);
    } finally {
        inflight--;
        idleState();
    }
}

function toast(text) {
    statusEl.textContent = text;
    statusEl.classList.add('warn');
    setTimeout(() => { statusEl.classList.remove('warn'); idleState(); }, 4000);
}

// ---------- ligar / pausar ----------
async function toggleListening(force) {
    const on = force ?? !listener.running;
    try {
        if (on) {
            setState('thinking', 'Abrindo microfone…');
            ensureWorker();
            await listener.start();
            await companion.setWalkMode(localStorage.getItem(LOCAL_KEYS.walk) === '1', settings.assistant_name);
            await companion.setWakeLock(localStorage.getItem(LOCAL_KEYS.wake) === '1');
        } else {
            await listener.pause();
            await companion.setWakeLock(false);
        }
    } catch (err) {
        setState('error', err.name === 'NotAllowedError' ? 'Permita o uso do microfone' : err.message);
        return;
    }
    companion.setListeningState(listener.running);
    idleState();
}

orb.addEventListener('click', () => toggleListening());
companion.onToggle = (action) => toggleListening(action === 'play');

listener.addEventListener('segment', (e) => onSegment(e.detail));
listener.addEventListener('speechconfirmed', () => {
    if (settings.barge_in && voice.speaking) voice.stop(); // falou por cima: a Lara se cala
    if (!voice.speaking) setState('hearing', 'Ouvindo você…');
});
listener.addEventListener('misfire', idleState);
listener.addEventListener('level', (e) => meter.style.setProperty('--level', e.detail.toFixed(2)));

voice.addEventListener('start', () => {
    speakingWindow = { from: Date.now(), to: Number.MAX_SAFE_INTEGER };
    idleState();
});
voice.addEventListener('end', () => {
    speakingWindow.to = Date.now();
    idleState();
});
voice.addEventListener('error', (e) => toast(`Voz: ${e.detail.message}`));

// ---------- configurações ----------
const dialog = $('settings');
const panel = new SettingsPanel($('settings-body'), {
    onPreview: async () => {
        await saveSettings();
        if (ai.tts_provider === 'browser') {
            voice.say({ id: 0, text: `Oi! Eu sou a ${settings.assistant_name}. É assim que eu falo.` });
            return;
        }
        try {
            const res = await api.ttsPreview(`Oi! Eu sou a ${settings.assistant_name}. É assim que eu falo.`);
            await voice.preview(await res.blob());
        } catch (err) {
            toast(err.message);
        }
    },
});

function applySettings(data) {
    settings = data.settings;
    ai = data.ai;
    voice.settings = settings;
    voice.provider = ai.tts_provider;
    panel.load(data);
    badge();
}

async function saveSettings() {
    const before = { ...settings };
    applySettings(await api.saveSettings(panel.read()));
    const local = panel.readLocal();
    const prevMic = localStorage.getItem(LOCAL_KEYS.mic) ?? '';
    for (const [k, v] of Object.entries(local)) localStorage.setItem(k, v);

    if (prevMic !== (local[LOCAL_KEYS.mic] ?? '') || before.vad_threshold !== settings.vad_threshold) {
        await listener.reconfigure({ deviceId: local[LOCAL_KEYS.mic], threshold: settings.vad_threshold });
    }
    if (listener.running) {
        await companion.setWalkMode(local[LOCAL_KEYS.walk] === '1', settings.assistant_name);
        await companion.setWakeLock(local[LOCAL_KEYS.wake] === '1');
        ensureWorker();
    }
    $('saved').hidden = false;
    setTimeout(() => { $('saved').hidden = true; }, 1500);
}

$('open-settings').addEventListener('click', async () => {
    await panel.render();
    dialog.showModal();
});
$('save').addEventListener('click', () => saveSettings().catch((err) => toast(err.message)));
$('logout').addEventListener('click', async () => {
    await listener.pause();
    await api.logout();
    location.reload();
});
$('show-noise').addEventListener('change', (e) => feed.classList.toggle('show-noise', e.target.checked));
if ('speechSynthesis' in window) speechSynthesis.addEventListener?.('voiceschanged', () => dialog.open && panel.render());

// ---------- início ----------
(async () => {
    try {
        applySettings(await api.settings());
        listener.deviceId = localStorage.getItem(LOCAL_KEYS.mic) ?? '';
        listener.threshold = settings.vad_threshold;
        await loadHistory();
        idleState();
    } catch (err) {
        setState('error', err.message);
    }
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
})();
