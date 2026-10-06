// Tela de configurações gerada a partir das opções do servidor.
import { browserVoices } from './voice.js';
import { listMicrophones } from './listener.js';

const SUGGEST = {
    chat: {
        gemini: ['gemini-3.8-flash', 'gemini-3.7-flash', 'gemini-3.5-flash', 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite'],
    },
    stt: {
        gemini: ['gemini-3.5-transcribe', 'gemini-3.5-flash-lite'],
        groq: ['whisper-large-v3-turbo', 'whisper-large-v3'],
        openai: ['gpt-4o-mini-transcribe', 'gpt-4o-transcribe', 'whisper-1'],
    },
    ttsModel: {
        gemini: ['gemini-3.8-flash-lite-tts', 'gemini-3.8-flash-tts'],
        openai: ['gpt-4o-mini-tts'],
        elevenlabs: ['eleven_flash_v2_5', 'eleven_turbo_v2_5', 'eleven_multilingual_v2'],
    },
    ttsVoice: {
        gemini: ['Kore', 'Aoede', 'Leda', 'Zephyr', 'Sulafat', 'Achernar', 'Despina', 'Callirrhoe', 'Vindemiatrix', 'Laomedeia', 'Puck', 'Charon', 'Fenrir', 'Orus'],
        openai: ['coral', 'nova', 'shimmer', 'sage', 'alloy', 'ash', 'ballad', 'echo', 'fable', 'onyx', 'verse'],
    },
};

const LABELS = {
    gemini: 'Google Gemini', openrouter: 'OpenRouter', groq: 'Groq', openai: 'OpenAI', ollama: 'Ollama (local)',
    elevenlabs: 'ElevenLabs', browser: 'Navegador (grátis)', jev: 'TypeSafe Jev (RLCD)', llm: 'Adaptador Jev sobre LLM',
    local: 'Local (Whisper no aparelho)', online: 'Online (maior qualidade)', '': 'padrão do modelo',
    minimal: 'mínimo', low: 'baixo', medium: 'médio', high: 'alto',
    'onnx-community/whisper-tiny': 'Whisper tiny (≈40 MB, mais rápido)',
    'onnx-community/whisper-base': 'Whisper base (≈80 MB, equilibrado)',
    'onnx-community/whisper-small': 'Whisper small (≈250 MB, melhor)',
    'onnx-community/whisper-large-v3-turbo': 'Whisper large-v3 turbo (WebGPU, ≈600 MB)',
};

const SECTIONS = [
    ['Assistente', [
        ['assistant_name', 'Nome da assistente'],
        ['user_name', 'Seu nome'],
        ['wake_words', 'Palavras de ativação', 'Separadas por vírgula. Detectadas sem custo, antes de qualquer IA.'],
        ['language', 'Idioma (BCP-47)'],
        ['persona', 'Personalidade (prompt)'],
    ]],
    ['IA principal (responde)', [
        ['main_provider', 'Provedor'],
        ['main_model', 'Modelo', null, (s) => SUGGEST.chat[s.main_provider]],
        ['main_thinking', 'Raciocínio', 'Gemini 3.8 Flash não aceita "mínimo".'],
    ]],
    ['Decisor RLCD (responder? catalogar?)', [
        ['decider_engine', 'Motor'],
        ['jev_model', 'Modelo Jev', null, null, (s) => s.decider_engine === 'jev'],
        ['decider_provider', 'Provedor do adaptador', null, null, (s) => s.decider_engine === 'llm'],
        ['decider_model', 'Modelo do adaptador', 'Use um modelo barato/aberto: Flash-Lite, Llama no Groq, Ollama…', (s) => SUGGEST.chat[s.decider_provider], (s) => s.decider_engine === 'llm'],
        ['decider_thinking', 'Raciocínio do adaptador', null, null, (s) => s.decider_engine === 'llm'],
        ['respond_threshold', 'Limiar para responder', 'Probabilidade mínima de a fala ser dirigida à Lara.'],
        ['followup_seconds', 'Janela de continuação (s)', 'Após a Lara falar, réplicas sem o nome dela também são consideradas.'],
    ]],
    ['Transcrição', [
        ['stt_mode', 'Modo', 'Trocar o modo não altera o histórico nem o fluxo; só a qualidade do texto.'],
        ['stt_local_model', 'Modelo local', 'Baixado uma vez e guardado no navegador.', null, (s) => s.stt_mode === 'local'],
        ['stt_online_provider', 'Provedor online', null, null, (s) => s.stt_mode === 'online'],
        ['stt_online_model', 'Modelo online', null, (s) => SUGGEST.stt[s.stt_online_provider], (s) => s.stt_mode === 'online'],
    ]],
    ['Voz', [
        ['tts_provider', 'Provedor de voz'],
        ['tts_model', 'Modelo de voz', 'Vazio = padrão do provedor.', (s) => SUGGEST.ttsModel[s.tts_provider], (s) => s.tts_provider !== 'browser'],
        ['tts_voice', 'Voz', null, (s) => (s.tts_provider === 'browser'
            ? browserVoices().filter((v) => v.lang.startsWith((s.language || 'pt').slice(0, 2))).map((v) => v.name)
            : SUGGEST.ttsVoice[s.tts_provider])],
        ['tts_style', 'Estilo de fala', null, null, (s) => ['gemini', 'openai'].includes(s.tts_provider)],
    ]],
    ['Captura', [
        ['vad_threshold', 'Sensibilidade do detector de voz', 'Maior = ignora mais ruído de fundo.'],
        ['barge_in', 'Interromper a Lara falando por cima', 'Ideal com fone de ouvido (sem eco).'],
        ['store_audio', 'Guardar áudio das falas'],
    ]],
    ['Memória', [
        ['context_messages', 'Falas recentes enviadas à IA'],
        ['memory_results', 'Memórias antigas recuperadas por resposta'],
    ]],
];

export const LOCAL_KEYS = { mic: 'lara.mic', walk: 'lara.walk', wake: 'lara.wake' };

export class SettingsPanel {
    constructor(root, { onPreview }) {
        this.root = root;
        this.onPreview = onPreview;
        this.values = {};
        this.options = {};
        this.available = [];
    }

    load({ settings, options, available }) {
        this.values = { ...settings };
        this.options = options;
        this.available = available;
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
            fs.innerHTML = `<legend>${title}</legend>`;
            for (const [key, label, help, suggest, visible] of fields) {
                if (visible && !visible(s)) continue;
                fs.append(this.#field(key, label, help, suggest?.(s) ?? []));
            }
            if (title === 'Voz') fs.append(this.#previewButton());
            if (title === 'Captura') fs.append(...(await this.#deviceFields()));
            frag.append(fs);
        }
        this.root.replaceChildren(frag);
        // Campos que mudam a visibilidade de outros re-renderizam o painel
        for (const key of ['decider_engine', 'stt_mode', 'tts_provider', 'main_provider', 'decider_provider', 'stt_online_provider']) {
            this.root.querySelector(`[data-key="${key}"]`)?.addEventListener('change', () => {
                Object.assign(this.values, this.read());
                this.render();
            });
        }
    }

    #field(key, label, help, suggestions) {
        const s = this.values;
        const wrap = document.createElement('label');
        wrap.className = 'field';
        const title = document.createElement('span');
        title.textContent = label;
        wrap.append(title);
        let input;
        const value = s[key];
        if (this.options[key]) {
            input = document.createElement('select');
            let opts = this.options[key];
            if (/_provider$/.test(key)) opts = opts.filter((o) => this.available.includes(o) || o === value);
            for (const o of opts) {
                const opt = new Option(LABELS[o] ?? o, o, false, o === value);
                if (/_provider$/.test(key) && !this.available.includes(o)) opt.textContent += ' (sem chave)';
                input.append(opt);
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
            if (suggestions?.length) {
                const list = document.createElement('datalist');
                list.id = `dl-${key}`;
                for (const sug of suggestions) list.append(new Option(sug, sug));
                input.setAttribute('list', list.id);
                wrap.append(list);
            }
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

        const check = (key, text, help) => {
            const l = document.createElement('label');
            l.className = 'field check';
            l.innerHTML = `<span>${text}</span>`;
            const i = document.createElement('input');
            i.type = 'checkbox';
            i.dataset.local = key;
            i.checked = localStorage.getItem(key) === '1';
            l.append(i, Object.assign(document.createElement('small'), { textContent: help }));
            return l;
        };
        return [
            mic,
            check(LOCAL_KEYS.walk, 'Modo caminhada', 'Mantém a escuta com a tela bloqueada (Android) e liga os botões do fone: play/pause liga e pausa a Lara.'),
            check(LOCAL_KEYS.wake, 'Manter tela ligada', 'Impede que a tela apague enquanto a Lara escuta.'),
        ];
    }

    readLocal() {
        const out = {};
        for (const el of this.root.querySelectorAll('[data-local]')) {
            out[el.dataset.local] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        }
        return out;
    }
}
