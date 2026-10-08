// Tela de configurações: só o que é interação direta. Provedores e modelos de IA ficam em "Reconfigurar IA".
import { browserVoices } from './voice.js';
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

export const LOCAL_KEYS = { mic: 'lara.mic', walk: 'lara.walk', wake: 'lara.wake' };

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
            frag.append(fs);
        }
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
        link.href = 'setup.php';
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
