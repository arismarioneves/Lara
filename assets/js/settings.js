// Tela de configurações: só o que é interação direta. Provedores e modelos de IA ficam em "Reconfigurar IA".
import { browserVoices } from './voice.js';
import { encodeWav } from './wav.js';
import { listMicrophones } from './listener.js';

const LABELS = {
    local: 'Local (Whisper no aparelho, grátis)', online: 'Online (maior precisão)',
    'onnx-community/whisper-tiny': 'Whisper tiny (≈40 MB, mais rápido)',
    'onnx-community/whisper-base': 'Whisper base (≈80 MB, equilibrado)',
    'onnx-community/whisper-small': 'Whisper small (≈250 MB, melhor)',
    'onnx-community/whisper-large-v3-turbo': 'Whisper large-v3 turbo (WebGPU, ≈600 MB)',
};
const STYLE_PROVIDERS = ['gemini', 'openai', 'openrouter'];

// [chave, rótulo, ajuda, visível?(valores, ia)]
const SECTIONS = [
    ['Assistente', [
        ['assistant_name', 'Nome da assistente'],
        ['user_name', 'Seu nome'],
        ['wake_words', 'Palavras de ativação', 'Separadas por vírgula. Detectadas sem custo, antes de qualquer IA.'],
        ['language', 'Idioma (BCP-47)'],
        ['persona', 'Personalidade (prompt)'],
    ]],
    ['Voz', [
        ['tts_voice', 'Voz'],
        ['tts_style', 'Estilo de fala', 'Como ela deve soar: ritmo, emoção, tom.', (s, ai) => STYLE_PROVIDERS.includes(ai.tts_provider)],
    ]],
    ['Escuta e transcrição', [
        ['stt_mode', 'Transcrição', 'Trocar não altera o histórico nem o fluxo; só a precisão do texto.'],
        ['stt_local_model', 'Modelo local', 'Baixado uma vez e guardado no navegador.', (s) => s.stt_mode === 'local'],
        ['vad_threshold', 'Sensibilidade do detector de voz', 'Maior = ignora mais ruído de fundo.'],
        ['barge_in', 'Interromper a Lara falando por cima', 'Ideal com fone de ouvido (sem eco).'],
        ['store_audio', 'Guardar áudio das falas'],
    ]],
    ['Quando responder', [
        ['respond_threshold', 'Limiar para responder', 'Probabilidade mínima de a fala ser dirigida à Lara.'],
        ['followup_seconds', 'Janela de continuação (s)', 'Após a Lara falar, réplicas sem o nome dela também são consideradas.'],
    ]],
    ['Memória', [
        ['context_messages', 'Falas recentes enviadas à IA'],
        ['memory_results', 'Memórias antigas recuperadas por resposta'],
    ]],
];

export const LOCAL_KEYS = { mic: 'lara.mic', walk: 'lara.walk', wake: 'lara.wake', noise: 'lara.noise' };

export class SettingsPanel {
    constructor(root, { onPreview }) {
        this.root = root;
        this.onPreview = onPreview;
        this.values = {};
        this.options = {};
        this.ai = {};
    }

    load({ settings, options, ai }) {
        this.values = { ...settings };
        this.options = options;
        this.ai = ai;
    }

    /** Valores atuais do formulário (somente chaves do servidor). */
    read() {
        const out = {};
        for (const el of this.root.querySelectorAll('[data-key]')) {
            const key = el.dataset.key;
            if (el.type === 'checkbox') out[key] = el.checked;
            else if (el.type === 'range' || el.type === 'number') out[key] = Number(el.value);
            else if (key === 'wake_words') out[key] = el.value.split(',').map((w) => w.trim()).filter(Boolean);
            else out[key] = el.value;
        }
        return out;
    }

    async render() {
        const s = this.values;
        const frag = document.createDocumentFragment();
        for (const [title, fields] of SECTIONS) {
            const fs = document.createElement('fieldset');
            const legend = document.createElement('legend');
            legend.textContent = title;
            fs.append(legend);
            for (const [key, label, help, visible] of fields) {
                if (visible && !visible(s, this.ai)) continue;
                fs.append(this.#field(key, label, help));
            }
            if (title === 'Voz') fs.append(this.#previewButton());
            if (title === 'Escuta e transcrição') fs.append(...(await this.#deviceFields()));
            if (title === 'Memória') fs.append(this.#localCheck(LOCAL_KEYS.noise, 'Mostrar ruído no histórico', 'Exibe, esmaecidas, as falas catalogadas como ruído (TV, conversas alheias, frases soltas). Vale só para este aparelho.'));
            frag.append(fs);
        }
        if (this.ai?.stt_provider === 'mistral') frag.append(await this.#speakerRefsSection());
        frag.append(this.#aiSummary());
        this.root.replaceChildren(frag);
        this.root.querySelector('[data-key="stt_mode"]')?.addEventListener('change', () => {
            Object.assign(this.values, this.read());
            this.render();
        });
    }

    /** Resumo do que está configurado no config.php + atalho para o assistente. */
    #aiSummary() {
        const fs = document.createElement('fieldset');
        const legend = document.createElement('legend');
        legend.textContent = 'Inteligência artificial';
        const dl = document.createElement('dl');
        dl.className = 'ai-summary';
        for (const [k, v] of [
            ['Conversa', this.ai.main],
            ['Triagem', this.ai.decider],
            ['Transcrição online', this.ai.stt ?? 'não configurada'],
            ['Voz', this.ai.tts],
        ]) {
            const dt = document.createElement('dt');
            dt.textContent = k;
            const dd = document.createElement('dd');
            dd.textContent = v;
            dl.append(dt, dd);
        }
        const link = document.createElement('a');
        link.href = 'setup';
        link.className = 'button ghost';
        link.textContent = 'Reconfigurar IA';
        const small = document.createElement('small');
        small.className = 'muted';
        small.textContent = 'Provedores, chaves e modelos. Pede a sua senha.';
        fs.append(legend, dl, link, small);
        return fs;
    }

    #voiceSelect(value) {
        const select = document.createElement('select');
        const browser = this.ai.tts_provider === 'browser';
        const lang = (this.values.language || 'pt').slice(0, 2);
        const voices = browser
            ? browserVoices().filter((v) => v.lang.startsWith(lang)).map((v) => ({ id: v.name, name: `${v.name}${v.localService ? '' : ' (online)'}` }))
            : this.ai.voices ?? [];
        select.append(new Option('Padrão', '', false, value === ''));
        for (const v of voices) select.append(new Option(v.name, v.id, false, v.id === value));
        if (value && !voices.some((v) => v.id === value)) select.append(new Option(`${value} (indisponível)`, value, false, true));
        return select;
    }

    #field(key, label, help) {
        const s = this.values;
        const wrap = document.createElement('label');
        wrap.className = 'field';
        const title = document.createElement('span');
        title.textContent = label;
        wrap.append(title);
        let input;
        const value = s[key];
        if (key === 'tts_voice') {
            input = this.#voiceSelect(value);
        } else if (this.options[key]) {
            input = document.createElement('select');
            for (const o of this.options[key]) input.append(new Option(LABELS[o] ?? o, o, false, o === value));
            if (key === 'stt_mode' && !this.ai.stt) {
                input.value = 'local';
                input.disabled = true;
                help = 'Nenhuma transcrição online configurada. Ative em Reconfigurar IA.';
            } else if (key === 'stt_mode') {
                help = `${help} Online: ${this.ai.stt}.`;
            }
        } else if (key === 'persona') {
            input = document.createElement('textarea');
            input.rows = 6;
            input.value = value;
        } else if (typeof value === 'boolean') {
            wrap.classList.add('check');
            input = document.createElement('input');
            input.type = 'checkbox';
            input.checked = value;
        } else if (key === 'respond_threshold' || key === 'vad_threshold') {
            input = document.createElement('input');
            input.type = 'range';
            input.min = key === 'vad_threshold' ? '0.1' : '0.05';
            input.max = '0.95';
            input.step = '0.05';
            input.value = value;
            const out = document.createElement('output');
            out.textContent = Number(value).toFixed(2);
            input.addEventListener('input', () => { out.textContent = Number(input.value).toFixed(2); });
            title.append(' ', out);
        } else if (typeof value === 'number') {
            input = document.createElement('input');
            input.type = 'number';
            input.value = value;
        } else {
            input = document.createElement('input');
            input.type = 'text';
            input.value = Array.isArray(value) ? value.join(', ') : value;
        }
        input.dataset.key = key;
        wrap.append(input);
        if (help) {
            const small = document.createElement('small');
            small.textContent = help;
            wrap.append(small);
        }
        return wrap;
    }

    #previewButton() {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'ghost';
        b.textContent = 'Ouvir prévia (salva antes)';
        b.addEventListener('click', () => this.onPreview());
        return b;
    }

    /** Preferências deste aparelho (não vão para o servidor). */
    async #deviceFields() {
        const mic = document.createElement('label');
        mic.className = 'field';
        mic.innerHTML = '<span>Microfone deste aparelho</span>';
        const sel = document.createElement('select');
        sel.dataset.local = LOCAL_KEYS.mic;
        sel.append(new Option('Padrão do sistema', ''));
        try {
            for (const m of await listMicrophones()) sel.append(new Option(m.label, m.id, false, m.id === localStorage.getItem(LOCAL_KEYS.mic)));
        } catch { /* sem permissão ainda */ }
        mic.append(sel, Object.assign(document.createElement('small'), { textContent: 'Escolha o fone Bluetooth para falar andando.' }));

        return [
            mic,
            this.#localCheck(LOCAL_KEYS.walk, 'Modo caminhada', 'Mantém a escuta com a tela bloqueada (Android) e liga os botões do fone: play/pause liga e pausa a Lara.'),
            this.#localCheck(LOCAL_KEYS.wake, 'Manter tela ligada', 'Impede que a tela apague enquanto a Lara escuta.'),
        ];
    }

    #localCheck(key, text, help) {
        const l = document.createElement('label');
        l.className = 'field check';
        l.innerHTML = `<span>${text}</span>`;
        const i = document.createElement('input');
        i.type = 'checkbox';
        i.dataset.local = key;
        i.checked = localStorage.getItem(key) === '1';
        l.append(i, Object.assign(document.createElement('small'), { textContent: help }));
        return l;
    }

    readLocal() {
        const out = {};
        for (const el of this.root.querySelectorAll('[data-local]')) {
            out[el.dataset.local] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        }
        return out;
    }

    async #speakerRefsSection() {
        const section = document.createElement('fieldset');
        section.className = 'section';
        const legend = document.createElement('legend');
        legend.textContent = 'Reconhecimento de falante';
        section.append(legend);

        const help = document.createElement('p');
        help.className = 'help';
        help.textContent = 'Grave amostras de voz (3–15 s) para que a Lara identifique quem está falando. Até 8 falantes. Funciona com transcrição Mistral.';
        section.append(help);

        let speakers = [];
        try {
            const res = await fetch('api/speakers', { credentials: 'same-origin' });
            const data = await res.json();
            speakers = data.speakers || [];
        } catch { /* endpoint indisponível */ }

        const count = document.createElement('span');
        count.className = 'speaker-count';
        count.textContent = `${speakers.length}/8 referências`;
        section.append(count);

        const list = document.createElement('div');
        list.className = 'speaker-list';
        section.append(list);

        const refresh = async () => {
            try {
                const res = await fetch('api/speakers', { credentials: 'same-origin' });
                const data = await res.json();
                speakers = data.speakers || [];
            } catch { /* ignora */ }
            list.replaceChildren();
            count.textContent = `${speakers.length}/8 referências`;
            for (const s of speakers) {
                const row = document.createElement('div');
                row.className = 'speaker-row';
                const label = document.createElement('span');
                label.textContent = `${s.name} (${(s.duration_ms / 1000).toFixed(0)} s)`;
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'btn-small btn-danger';
                del.textContent = 'Remover';
                del.addEventListener('click', async () => {
                    del.disabled = true;
                    const csrf = document.querySelector('meta[name=csrf]')?.content || '';
                    await fetch('api/speakers', {
                        method: 'DELETE',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify({ id: s.id }),
                        credentials: 'same-origin',
                    });
                    await refresh();
                });
                row.append(label, del);
                list.append(row);
            }
        };

        const form = document.createElement('div');
        form.className = 'speaker-form';

        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.placeholder = 'Nome do falante';
        nameInput.maxLength = 64;

        const recordBtn = document.createElement('button');
        recordBtn.type = 'button';
        recordBtn.className = 'ghost';
        recordBtn.textContent = 'Gravar';

        const status = document.createElement('span');
        status.className = 'speaker-status';

        let recorder = null;
        let chunks = [];
        let startTime = 0;

        recordBtn.addEventListener('click', async () => {
            if (recorder && recorder.state === 'recording') {
                recorder.stop();
                return;
            }
            const name = nameInput.value.trim();
            if (!name) { status.textContent = 'Informe o nome do falante'; return; }
            if (speakers.length >= 8 && !speakers.some(sp => sp.name === name)) {
                status.textContent = 'Limite de 8 referências atingido';
                return;
            }
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                chunks = [];
                recorder = new MediaRecorder(stream, { mimeType: 'audio/webm;codecs=opus' });
                recorder.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
                recorder.onstop = async () => {
                    stream.getTracks().forEach(t => t.stop());
                    const elapsed = Date.now() - startTime;
                    if (elapsed < 3000) {
                        status.textContent = 'Muito curto (mínimo 3 s)';
                        recordBtn.textContent = 'Gravar';
                        return;
                    }
                    status.textContent = 'Convertendo…';
                    recordBtn.disabled = true;
                    try {
                        const blob = new Blob(chunks, { type: 'audio/webm' });
                        const arrayBuf = await blob.arrayBuffer();
                        const audioCtx = new AudioContext({ sampleRate: 16000 });
                        const decoded = await audioCtx.decodeAudioData(arrayBuf);
                        const samples = decoded.getChannelData(0);
                        await audioCtx.close();
                        const wavBlob = encodeWav(samples, 16000);

                        const fd = new FormData();
                        fd.append('audio', wavBlob, 'reference.wav');
                        fd.append('name', name);
                        fd.append('duration_ms', String(Math.round(elapsed)));
                        const csrf = document.querySelector('meta[name=csrf]')?.content || '';
                        const res = await fetch('api/speakers', {
                            method: 'POST',
                            headers: { 'X-CSRF-Token': csrf },
                            body: fd,
                            credentials: 'same-origin',
                        });
                        const data = await res.json();
                        if (data.error) {
                            status.textContent = data.error;
                        } else {
                            status.textContent = 'Salvo!';
                            nameInput.value = '';
                            await refresh();
                        }
                    } catch (err) {
                        status.textContent = 'Erro: ' + err.message;
                    }
                    recordBtn.textContent = 'Gravar';
                    recordBtn.disabled = false;
                };
                recorder.start();
                startTime = Date.now();
                recordBtn.textContent = 'Parar (máx 15 s)';
                status.textContent = 'Gravando…';
                setTimeout(() => { if (recorder?.state === 'recording') recorder.stop(); }, 15000);
            } catch (err) {
                status.textContent = 'Erro no microfone: ' + err.message;
            }
        });

        form.append(nameInput, recordBtn, status);
        section.append(form);

        await refresh();
        return section;
    }
}
