// Assistente de configuração: primeiro uso (instalação completa) e reconfiguração da IA.

const ROLE_CHIP = { chat: 'Conversa', decider: 'Triagem', stt: 'Transcrição', tts: 'Voz' };
const THINKING_LABEL = { '': 'padrão do modelo', minimal: 'mínimo', low: 'baixo', medium: 'médio', high: 'alto' };
const ICONS = {
    chat: 'M4 5h16v10H8l-4 4z',
    decider: 'M4 6h16M7 12h10M10 18h4',
    stt: 'M12 3a3 3 0 0 1 3 3v6a3 3 0 0 1-6 0V6a3 3 0 0 1 3-3zm-7 9a7 7 0 0 0 14 0M12 19v2',
    tts: 'M4 10v4M8 7v10M12 4v16M16 8v8M20 11v2',
};

const S = {
    mode: 'install',
    csrf: '',
    step: 0,
    db: { host: '127.0.0.1', port: 3306, name: 'lara', user: 'root', pass: '', create: true },
    dbResult: null,
    app: { password: '', password2: '', timezone: '' },
    profile: { user_name: '', assistant_name: 'Lara' },
    prov: {},
    ai: null,
    unlocked: true,
    manual: null,
};

const $ = (sel, root = document) => root.querySelector(sel);
const stepEl = $('#step');
const nextBtn = $('#next');
const backBtn = $('#back');
const navMsg = $('#nav-msg');

/** Criação de elementos sem innerHTML (nada vindo do usuário vira HTML). */
function h(tag, attrs = {}, ...children) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v === undefined || v === null || v === false) continue;
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k === 'on') for (const [ev, fn] of Object.entries(v)) el.addEventListener(ev, fn);
        else if (k === 'value' || k === 'checked' || k === 'disabled' || k === 'selected') el[k] = v;
        else el.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children.flat()) if (c !== null && c !== undefined && c !== false) el.append(c);
    return el;
}

function icon(path) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    const p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    p.setAttribute('d', path);
    svg.append(p);
    return svg;
}

async function api(action, body) {
    const res = await fetch(`api/setup?action=${action}`, {
        method: body === undefined ? 'GET' : 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': S.csrf },
        body: body === undefined ? undefined : JSON.stringify(body),
        credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
}

function say(text, kind = 'error') {
    navMsg.textContent = text || '';
    navMsg.dataset.kind = kind;
}

// ---------- provedores ----------
function provState(name) {
    S.prov[name] ??= { api_key: '', base_url: '', remove: false, saved: null, status: 'idle', error: '', models: null };
    return S.prov[name];
}

function ready(name) {
    const p = provState(name);
    if (p.remove) return false;
    const typed = S.catalog[name].auth === 'url' ? p.base_url.trim() : p.api_key.trim();
    return typed !== '' || !!p.saved?.configured;
}

const available = () => Object.keys(S.catalog).filter(ready);
const supports = (name, role) => S.catalog[name].roles.includes(role);
const systemOne = (name) => !!S.catalog[name]?.systemone;

/** Listas do "Testar". `systemone` = modelos de decisão (Jev); `decider` = LLMs usados pelo adaptador. */
function modelsFor(name, role) {
    const m = provState(name).models;
    if (!m) return null;
    if (role === 'decider') return m.chat;
    if (role === 'systemone') return m.decisions;
    return m[role] ?? [];
}

async function probe(name) {
    const p = provState(name);
    p.status = 'testing';
    p.error = '';
    refreshCard(name);
    try {
        const r = await api('probe', { provider: name, api_key: p.api_key.trim(), base_url: p.base_url.trim() });
        if (r.ok) {
            p.status = 'ok';
            p.models = r.models;
        } else {
            p.status = 'error';
            p.error = r.error;
        }
    } catch (err) {
        p.status = 'error';
        p.error = err.message;
    }
    refreshCard(name);
    updateCoverage();
    // Na reconfiguração os testes rodam em segundo plano: atualiza as listas se o usuário já está nos recursos
    if (order[S.step] === 'resources' && S.unlocked) render();
}

function probeSummary(models) {
    const parts = [];
    if (models.chat.length) parts.push(`${models.chat.length} de texto`);
    if (models.stt.length) parts.push(`${models.stt.length} de transcrição`);
    if (models.tts.length) parts.push(`${models.tts.length} de voz`);
    if (models.decisions.length) parts.push(models.decisions.length === 1 ? 'Jev disponível' : `${models.decisions.length} de decisão (Jev e outros)`);
    return parts.length ? `Conectado · ${parts.join(' · ')}` : 'Conectado';
}

// ---------- recomendação de modelos ----------
function defaultsFor(name, role) {
    const d = S.catalog[name]?.defaults?.[role] ?? {};
    const list = modelsFor(name, role) ?? [];
    // Sem sugestão do catálogo (ex.: Ollama), usa o primeiro modelo listado; uma sugestão fora da lista
    // é mantida e o campo avisa que o modelo não foi encontrado.
    const model = d.model ?? list[0]?.id ?? '';
    return { model, thinking: d.thinking ?? '' };
}

function pick(role, exclude = []) {
    return S.roles[role].prefer.find((p) => !exclude.includes(p) && ready(p) && supports(p, role)) ?? '';
}

function recommendRole(role) {
    if (role === 'chat') {
        const p = pick('chat');
        return { provider: p, ...defaultsFor(p, 'chat') };
    }
    if (role === 'decider') {
        // Jev (System One) sempre que houver TypeSafe ou OpenRouter; senão o adaptador sobre um LLM
        const so = S.roles.decider.prefer_systemone.find(ready);
        if (so) return { engine: 'systemone', provider: so, model: defaultsFor(so, 'systemone').model, thinking: '' };
        const llm = pick('decider');
        return { engine: 'llm', provider: llm, ...defaultsFor(llm, 'decider') };
    }
    if (role === 'stt') {
        const p = pick('stt');
        return { provider: p, model: p ? defaultsFor(p, 'stt').model : '' };
    }
    const p = pick('tts');
    return p ? { provider: p, model: defaultsFor(p, 'tts').model, voices: [] } : { provider: 'browser', model: '', voices: [] };
}

/** Garante que cada papel aponta para um provedor ainda disponível. */
function ensureAi() {
    S.ai ??= { main: recommendRole('chat'), decider: recommendRole('decider'), stt: recommendRole('stt'), tts: recommendRole('tts') };
    const a = S.ai;
    if (!ready(a.main.provider)) a.main = recommendRole('chat');
    const d = a.decider;
    if (d.engine === 'systemone' ? !(ready(d.provider) && systemOne(d.provider)) : !ready(d.provider)) {
        a.decider = recommendRole('decider');
    }
    if (a.stt.provider && !ready(a.stt.provider)) a.stt = recommendRole('stt');
    if (a.tts.provider !== 'browser' && !ready(a.tts.provider)) a.tts = recommendRole('tts');
}

function voicesFor(provider, model) {
    const m = modelsFor(provider, 'tts')?.find((x) => x.id === model);
    if (m?.voices) return m.voices;
    return provider === S.ai?.tts?.provider && model === S.ai?.tts?.model ? (S.ai.tts.voices ?? []) : [];
}

// ---------- passos ----------
const STEPS = {
    req: { title: 'Requisitos', render: renderRequirements, next: nextRequirements },
    db: { title: 'Banco de dados', render: renderDb, next: nextDb },
    access: { title: 'Acesso', render: renderAccess, next: nextAccess },
    providers: { title: 'Provedores de IA', render: renderProviders, next: nextProviders },
    resources: { title: 'Recursos', render: renderResources, next: nextResources },
    review: { title: 'Concluir', render: renderReview, next: submit },
};
let order = [];

function render(focus = false) {
    say('');
    const steps = $('#steps');
    steps.replaceChildren(...order.map((id, i) => h('li', {
        class: i === S.step ? 'current' : i < S.step ? 'done' : '',
        on: i < S.step ? { click: () => go(i) } : undefined,
    }, h('span', { class: 'num', text: String(i + 1) }), h('span', { text: STEPS[id].title }))));

    if (S.mode === 'reconfigure' && !S.unlocked) {
        stepEl.replaceChildren(renderUnlock());
        nextBtn.textContent = 'Desbloquear';
        backBtn.textContent = 'Cancelar';
        return;
    }
    const id = order[S.step];
    stepEl.replaceChildren(STEPS[id].render());
    const last = S.step === order.length - 1;
    nextBtn.textContent = last ? (S.mode === 'install' ? 'Instalar a Lara' : 'Salvar') : 'Continuar';
    nextBtn.hidden = !!S.manual;
    backBtn.textContent = S.step === 0 ? (S.mode === 'install' ? 'Voltar' : 'Cancelar') : 'Voltar';
    backBtn.disabled = S.step === 0 && S.mode === 'install';
    if (focus) stepEl.querySelector('input:not([type=checkbox]), select')?.focus({ preventScroll: true });
}

function go(i) {
    S.step = Math.max(0, Math.min(order.length - 1, i));
    render(true);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

nextBtn.addEventListener('click', async () => {
    if (S.mode === 'reconfigure' && !S.unlocked) return unlock();
    nextBtn.disabled = true;
    try {
        if (await STEPS[order[S.step]].next()) go(S.step + 1);
    } catch (err) {
        say(err.message);
    } finally {
        nextBtn.disabled = false;
    }
});
backBtn.addEventListener('click', () => {
    if (S.mode === 'reconfigure' && (S.step === 0 || !S.unlocked)) location.href = './';
    else go(S.step - 1);
});

function section(title, lead, ...body) {
    return h('div', { class: 'step-inner' }, h('h2', { text: title }), lead ? h('p', { class: 'lead', text: lead }) : null, ...body);
}

function field(label, input, help) {
    return h('label', { class: 'field' }, h('span', { text: label }), input, help ? h('small', { text: help }) : null);
}

// --- requisitos ---
let clientChecks = [];

async function runClientChecks() {
    let protectedOk = false;
    try {
        const r = await fetch('storage/protegido.txt', { cache: 'no-store' });
        protectedOk = r.status === 403;
    } catch { /* rede */ }
    clientChecks = [
        {
            label: 'Pasta privada protegida', required: true, ok: protectedOk,
            detail: protectedOk ? 'storage/ e config.php bloqueados pelo .htaccess'
                : 'storage/ está acessível pela web: o Apache precisa de AllowOverride All e mod_rewrite',
        },
        {
            label: 'Microfone liberado neste endereço', required: false, ok: window.isSecureContext,
            detail: window.isSecureContext ? 'contexto seguro' : 'use HTTPS para o navegador liberar o microfone',
        },
    ];
}

function renderRequirements() {
    const all = [...S.requirements, ...clientChecks];
    const rows = all.map((r) => h('li', { class: r.ok ? 'ok' : r.required ? 'fail' : 'warn' },
        h('span', { class: 'mark', 'aria-hidden': 'true' }),
        h('div', {}, h('strong', { text: r.label }), h('small', { text: r.detail }))));
    return section(
        'Vamos configurar a Lara',
        'Em poucos passos: banco de dados, sua senha e as IAs que ela vai usar. Primeiro, conferimos o servidor.',
        h('ul', { class: 'checks' }, rows),
        h('button', { type: 'button', class: 'ghost small', text: 'Verificar de novo', on: { click: reloadRequirements } }),
    );
}

async function reloadRequirements() {
    const st = await api('state');
    S.requirements = st.requirements;
    await runClientChecks();
    render();
}

async function nextRequirements() {
    const failed = [...S.requirements, ...clientChecks].filter((r) => r.required && !r.ok);
    if (failed.length) {
        say(`Resolva antes de continuar: ${failed.map((r) => r.label).join(', ')}`);
        return false;
    }
    return true;
}

// --- banco ---
function renderDb() {
    const d = S.db;
    const bind = (key, type = 'text', extra = {}) => h('input', {
        type, value: String(d[key]), autocomplete: 'off', ...extra,
        on: { input: (e) => { d[key] = type === 'number' ? Number(e.target.value) : e.target.value; S.dbResult = null; resultBox.replaceChildren(); } },
    });
    const resultBox = h('div', { class: 'result' });
    if (S.dbResult) resultBox.append(dbResultView(S.dbResult));
    const test = h('button', {
        type: 'button', class: 'ghost', text: 'Testar conexão', on: {
            click: async () => {
                test.disabled = true;
                resultBox.replaceChildren(h('p', { class: 'status testing', text: 'Conectando…' }));
                try {
                    S.dbResult = await api('db_test', d);
                    resultBox.replaceChildren(dbResultView(S.dbResult));
                    say('');
                } catch (err) {
                    S.dbResult = null;
                    resultBox.replaceChildren(h('p', { class: 'status err', text: err.message }));
                }
                test.disabled = false;
            },
        },
    });
    return section(
        'Banco de dados',
        'Onde a Lara guarda as falas catalogadas, as respostas e as suas preferências. Os áudios ficam em arquivos na pasta protegida storage/.',
        h('div', { class: 'grid two' },
            field('Servidor', bind('host'), 'Em hospedagens, geralmente localhost.'),
            field('Porta', bind('port', 'number', { min: 1, max: 65535 })),
            field('Nome do banco', bind('name'), 'Letras, números e _.'),
            field('Usuário', bind('user')),
            field('Senha do banco', bind('pass', 'password')),
        ),
        h('label', { class: 'check' },
            h('input', { type: 'checkbox', checked: d.create, on: { change: (e) => { d.create = e.target.checked; } } }),
            h('span', { text: 'Criar o banco se ainda não existir' })),
        h('div', { class: 'row' }, test),
        resultBox,
    );
}

function dbResultView(r) {
    let msg = `Conectado ao MySQL ${r.version}. `;
    if (!r.exists) msg += S.db.create ? `O banco "${S.db.name}" será criado.` : `O banco "${S.db.name}" não existe: crie-o ou marque a opção acima.`;
    else if (r.lara_tables) msg += `O banco já tem dados da Lara (${r.messages} falas) — eles serão mantidos.`;
    else msg += `O banco "${S.db.name}" existe; as tabelas serão criadas.`;
    return h('p', { class: `status ${!r.exists && !S.db.create ? 'err' : 'ok'}`, text: msg });
}

async function nextDb() {
    if (!S.dbResult) {
        say('Teste a conexão antes de continuar.');
        return false;
    }
    if (!S.dbResult.exists && !S.db.create) {
        say('O banco não existe: marque "Criar o banco" ou crie-o manualmente.');
        return false;
    }
    return true;
}

// --- acesso ---
function renderAccess() {
    const a = S.app;
    const p = S.profile;
    const tz = h('select', { on: { change: (e) => { a.timezone = e.target.value; } } },
        S.timezones.map((t) => h('option', { value: t, text: t.replace(/_/g, ' '), selected: t === a.timezone })));
    return section(
        'Acesso e perfil',
        'A Lara é pessoal: uma senha protege o histórico, os áudios e as configurações. Use a mesma senha no computador e no celular.',
        h('div', { class: 'grid two' },
            field('Senha', h('input', { type: 'password', value: a.password, autocomplete: 'new-password', on: { input: (e) => { a.password = e.target.value; } } }), 'Mínimo de 8 caracteres.'),
            field('Repita a senha', h('input', { type: 'password', value: a.password2, autocomplete: 'new-password', on: { input: (e) => { a.password2 = e.target.value; } } })),
            field('Seu nome', h('input', { type: 'text', value: p.user_name, maxlength: 80, on: { input: (e) => { p.user_name = e.target.value; } } }), 'Como a Lara vai chamar você.'),
            field('Nome da assistente', h('input', { type: 'text', value: p.assistant_name, maxlength: 40, on: { input: (e) => { p.assistant_name = e.target.value; } } }), 'É também a palavra de ativação.'),
            field('Fuso horário', tz),
        ),
    );
}

async function nextAccess() {
    if (S.app.password.length < 8) return say('A senha precisa ter pelo menos 8 caracteres.'), false;
    if (S.app.password !== S.app.password2) return say('As senhas não conferem.'), false;
    if (!S.profile.assistant_name.trim()) return say('Dê um nome para a assistente.'), false;
    return true;
}

// --- provedores ---
const cards = new Map();

function renderProviders() {
    cards.clear();
    const grid = h('div', { class: 'providers' });
    for (const name of Object.keys(S.catalog)) grid.append(providerCard(name));
    return section(
        S.mode === 'install' ? 'Provedores de IA' : 'Chaves dos provedores',
        'Cole as chaves dos serviços que quiser usar. Só o OpenRouter já basta: com uma chave a Lara conversa, faz a triagem, transcreve e fala. Os demais são opcionais, para quem prefere ir direto ao fornecedor.',
        h('div', { class: 'coverage', id: 'coverage' }),
        grid,
        h('p', { class: 'fineprint', text: 'As chaves ficam só no servidor, elas nunca são enviadas de volta ao navegador.' }),
    );
}

function providerCard(name) {
    const meta = S.catalog[name];
    const p = provState(name);
    const isUrl = meta.auth === 'url';
    const input = h('input', {
        type: isUrl ? 'url' : 'password',
        value: isUrl ? p.base_url : p.api_key,
        placeholder: isUrl ? (p.saved?.base_url || meta.placeholder) : (p.saved?.masked ? `Mantida (${p.saved.masked}) — cole para trocar` : 'Cole a chave aqui'),
        autocomplete: 'off',
        spellcheck: 'false',
        on: {
            input: (e) => {
                if (isUrl) p.base_url = e.target.value; else p.api_key = e.target.value;
                p.remove = false;
                p.status = 'idle';
                p.models = null;
                refreshCard(name);
                updateCoverage();
            },
        },
    });
    const test = h('button', { type: 'button', class: 'ghost small', text: 'Testar', on: { click: () => probe(name) } });
    const status = h('p', { class: 'status' });
    const saved = p.saved?.configured
        ? h('p', { class: 'saved-key' },
            h('span', { text: isUrl ? `Atual: ${p.saved.base_url}` : `Chave atual ${p.saved.masked}` }),
            h('button', {
                type: 'button', class: 'link', text: p.remove ? 'desfazer remoção' : 'remover',
                on: { click: () => { p.remove = !p.remove; p.status = 'idle'; p.models = null; render(); } },
            }))
        : null;
    const card = h('article', { class: `provider${meta.recommended ? ' recommended' : ''}` },
        h('header', {},
            h('h3', { text: meta.label }),
            meta.recommended ? h('span', { class: 'pill', text: 'Recomendado' }) : null),
        h('p', { class: 'tagline', text: meta.tagline }),
        h('ul', { class: 'chips' }, Object.keys(ROLE_CHIP).map((r) => h('li', { class: meta.roles.includes(r) ? 'on' : '', text: ROLE_CHIP[r] }))),
        h('div', { class: 'key-row' }, input, test),
        saved,
        h('a', { href: meta.key_url, target: '_blank', rel: 'noopener', class: 'key-link', text: isUrl ? 'Instalar o Ollama ↗' : 'Obter chave ↗' }),
        status,
    );
    cards.set(name, { card, status, test });
    refreshCard(name);
    return card;
}

function refreshCard(name) {
    const c = cards.get(name);
    if (!c) return;
    const p = provState(name);
    c.card.classList.toggle('ready', ready(name));
    c.card.classList.toggle('removed', p.remove);
    c.test.disabled = !ready(name) || p.status === 'testing';
    const text = p.remove ? 'Será removida ao salvar.'
        : p.status === 'testing' ? 'Testando…'
        : p.status === 'ok' ? probeSummary(p.models)
        : p.status === 'error' ? p.error
        : '';
    c.status.textContent = text;
    c.status.className = `status ${p.status === 'ok' ? 'ok' : p.status === 'error' ? 'err' : p.status === 'testing' ? 'testing' : ''}`;
}

function updateCoverage() {
    const box = $('#coverage');
    if (!box) return;
    const names = (role, excl = []) => Object.keys(S.catalog).filter((n) => !excl.includes(n) && ready(n) && supports(n, role)).map((n) => S.catalog[n].label);
    const items = [
        ['chat', names('chat'), 'obrigatório'],
        ['decider', names('decider'), 'obrigatório'],
        ['stt', names('stt'), 'usa só o Whisper local'],
        ['tts', names('tts'), 'usa a voz do navegador'],
    ];
    box.replaceChildren(...items.map(([role, list, fallback]) => h('div', { class: `cov ${list.length ? 'ok' : role === 'chat' || role === 'decider' ? 'fail' : 'opt'}` },
        icon(ICONS[role]),
        h('div', {}, h('strong', { text: S.roles[role].title }), h('small', { text: list.length ? list.join(', ') : fallback })))));
}

async function nextProviders() {
    if (!available().some((n) => supports(n, 'chat'))) {
        say('Configure pelo menos um provedor de conversa (o OpenRouter resolve tudo).');
        return false;
    }
    const pending = available().filter((n) => provState(n).status !== 'ok');
    if (pending.length) {
        say('Testando as chaves…', 'info');
        await Promise.all(pending.map(probe));
    }
    const failed = available().filter((n) => provState(n).status === 'error');
    if (failed.length) {
        say(`Corrija ou remova: ${failed.map((n) => S.catalog[n].label).join(', ')}.`);
        return false;
    }
    ensureAi();
    return true;
}

// --- recursos ---
function renderResources() {
    ensureAi();
    return section(
        'Recursos de IA',
        'Escolha quem faz cada trabalho. As sugestões já vêm preenchidas com o melhor custo-benefício entre os provedores que você configurou.',
        h('div', { class: 'roles' }, roleCard('chat'), roleCard('decider'), roleCard('stt'), roleCard('tts')),
    );
}

function roleCard(role) {
    const meta = S.roles[role];
    const body = h('div', { class: 'role-fields' });
    const card = h('article', { class: 'role' },
        h('header', {}, h('span', { class: 'role-icon' }, icon(ICONS[role])),
            h('div', {}, h('h3', {}, meta.title, ' ', h('small', { text: meta.subtitle })), h('p', { text: meta.description }))),
        body);

    // Na triagem, `kind` separa o System One (Jev) do adaptador; nos demais papéis é o próprio papel
    let kind = role;
    if (role === 'decider') {
        const d = S.ai.decider;
        const soProviders = S.roles.decider.prefer_systemone.filter(ready);
        const seg = h('div', { class: 'segmented', role: 'radiogroup' },
            [
                ['systemone', 'Jev (System One)', 'decisões calibradas em ~0,1–0,5 s'],
                ['llm', 'Adaptador sobre LLM', 'mesmas perguntas a um modelo de texto'],
            ].map(([v, label, hint]) => h('button', {
                type: 'button', role: 'radio', 'aria-checked': String(d.engine === v), class: d.engine === v ? 'on' : '',
                disabled: v === 'systemone' && !soProviders.length,
                on: {
                    click: () => {
                        if (d.engine === v) return;
                        const p = v === 'systemone' ? soProviders[0] : pick('decider');
                        S.ai.decider = { engine: v, provider: p, ...defaultsFor(p, v === 'systemone' ? 'systemone' : 'decider') };
                        if (v === 'systemone') S.ai.decider.thinking = '';
                        render();
                    },
                },
            }, h('strong', { text: label }), h('small', { text: v === 'systemone' && !soProviders.length ? 'requer OpenRouter ou TypeSafe' : hint }))));
        body.append(seg);
        if (d.engine === 'systemone') kind = 'systemone';
    }

    const cfg = role === 'chat' ? S.ai.main : S.ai[role];
    const offers = (n) => kind === 'systemone' ? systemOne(n) : kind === 'decider' ? supports(n, 'chat') : supports(n, role);
    const opts = [];
    if (role === 'stt') opts.push(['', 'Somente local (Whisper no aparelho, grátis)']);
    if (role === 'tts') opts.push(['browser', 'Voz do navegador (grátis)']);
    for (const n of Object.keys(S.catalog)) {
        if (ready(n) && offers(n)) opts.push([n, S.catalog[n].label]);
    }
    const sel = h('select', {
        on: {
            change: (e) => {
                const p = e.target.value;
                const d = p && p !== 'browser' ? defaultsFor(p, kind) : { model: '', thinking: '' };
                Object.assign(cfg, { provider: p, model: d.model });
                if ('thinking' in cfg) cfg.thinking = d.thinking;
                render();
            },
        },
    }, opts.map(([v, label]) => h('option', { value: v, text: label, selected: v === cfg.provider })));
    body.append(field('Provedor', sel));

    if (cfg.provider && cfg.provider !== 'browser') {
        body.append(modelField(cfg.provider, kind, cfg, 'model'));
        // O nível de raciocínio só é enviado ao Gemini e ao OpenRouter (e não existe no System One)
        if ('thinking' in cfg) {
            if (kind !== 'systemone' && ['gemini', 'openrouter'].includes(cfg.provider)) body.append(thinkingField(cfg, kind));
            else cfg.thinking = '';
        }
        if (role === 'tts') {
            const n = voicesFor(cfg.provider, cfg.model).length;
            body.append(h('p', { class: 'hint', text: n ? `${n} vozes disponíveis. Você escolhe a voz em Configurações.` : 'A voz é escolhida em Configurações.' }));
        }
    }
    return card;
}

function modelField(provider, role, cfg, key) {
    const list = modelsFor(provider, role) ?? [];
    const id = `dl-${role}-${key}`;
    const hint = h('small');
    const update = () => {
        const found = list.find((m) => m.id === cfg[key]);
        hint.textContent = !list.length ? 'Teste a chave do provedor para ver a lista de modelos.'
            : found ? (found.label && found.label !== found.id ? found.label : 'Modelo disponível.')
            : cfg[key] ? 'Este modelo não está na lista do provedor.' : `${list.length} modelos disponíveis — digite para buscar.`;
        hint.className = list.length && cfg[key] && !found ? 'warn' : '';
    };
    const input = h('input', {
        type: 'text', value: cfg[key], list: id, spellcheck: 'false', autocomplete: 'off',
        placeholder: list.length ? 'Digite para buscar' : 'id do modelo',
        on: {
            input: (e) => { cfg[key] = e.target.value.trim(); update(); },
            change: () => render(),
        },
    });
    update();
    return h('label', { class: 'field' }, h('span', { text: 'Modelo' }), input,
        h('datalist', { id }, list.map((m) => h('option', { value: m.id, text: m.label && m.label !== m.id ? m.label : undefined }))), hint);
}

function thinkingField(cfg, role) {
    const model = (modelsFor(cfg.provider, role) ?? []).find((m) => m.id === cfg.model);
    const options = model?.efforts?.length ? model.efforts : S.thinking;
    if (!options.includes(cfg.thinking)) cfg.thinking = options.includes('low') ? 'low' : options[0];
    const help = 'Menos raciocínio = resposta mais rápida e barata.';
    return field('Raciocínio', h('select', { on: { change: (e) => { cfg.thinking = e.target.value; } } },
        options.map((t) => h('option', { value: t, text: THINKING_LABEL[t] ?? t, selected: t === cfg.thinking }))), help);
}

async function nextResources() {
    const a = S.ai;
    if (!a.main.model) return say('Escolha o modelo de conversa.'), false;
    if (a.decider.engine === 'llm' && !a.decider.model) return say('Escolha o modelo da triagem.'), false;
    if (a.stt.provider && !a.stt.model) return say('Escolha o modelo de transcrição ou use só a local.'), false;
    if (a.tts.provider !== 'browser' && !a.tts.model) return say('Escolha o modelo de voz.'), false;
    return true;
}

// --- revisão ---
function label(provider, model) {
    return `${S.catalog[provider]?.label ?? provider}${model ? ` · ${model}` : ''}`;
}

function renderReview() {
    if (S.manual) return renderManual();
    const a = S.ai;
    const rows = [];
    if (S.mode === 'install') {
        rows.push(['Banco de dados', `${S.db.user}@${S.db.host}:${S.db.port} / ${S.db.name}`]);
        rows.push(['Você', `${S.profile.user_name || '(sem nome)'} · assistente "${S.profile.assistant_name}" · ${S.app.timezone}`]);
    }
    rows.push(['Provedores', available().map((n) => S.catalog[n].label).join(', ')]);
    rows.push(['Conversa', label(a.main.provider, a.main.model) + (a.main.thinking ? ` · raciocínio ${THINKING_LABEL[a.main.thinking]}` : '')]);
    rows.push(['Triagem', a.decider.engine === 'systemone' ? `System One · ${label(a.decider.provider, a.decider.model)}` : `Adaptador · ${label(a.decider.provider, a.decider.model)}`]);
    rows.push(['Transcrição', a.stt.provider ? `Local (padrão) ou online: ${label(a.stt.provider, a.stt.model)}` : 'Somente local (Whisper no aparelho)']);
    rows.push(['Voz', a.tts.provider === 'browser' ? 'Voz do navegador' : label(a.tts.provider, a.tts.model)]);
    return section(
        S.mode === 'install' ? 'Tudo pronto' : 'Revisar alterações',
        S.mode === 'install'
            ? 'Ao instalar, a Lara configura, prepara o banco e já entra no app. Ajustes de interação (voz, palavras de ativação, sensibilidade) ficam em Configurações.'
            : 'O config.php será regravado com as novas chaves e modelos. O histórico e as preferências continuam como estão.',
        h('dl', { class: 'review' }, rows.flatMap(([k, v]) => [h('dt', { text: k }), h('dd', { text: v })])),
    );
}

function renderManual() {
    const blob = new Blob([S.manual], { type: 'text/x-php' });
    return section(
        'Falta um passo: enviar o config.php',
        'O servidor não deu permissão para criar o arquivo. O banco já está pronto; baixe o config.php abaixo, envie-o para a pasta da Lara (ao lado do index.php) e recarregue.',
        h('div', { class: 'row' },
            h('a', { class: 'button', href: URL.createObjectURL(blob), download: 'config.php', text: 'Baixar config.php' }),
            h('button', { type: 'button', class: 'ghost', text: 'Já enviei, recarregar', on: { click: () => { location.href = './'; } } })),
        h('textarea', { class: 'code', readonly: true, rows: 14, value: S.manual }),
        h('p', { class: 'fineprint', text: 'Este arquivo contém sua senha (em hash) e as chaves das IAs. Não o compartilhe.' }),
    );
}

function payloadProviders() {
    const out = {};
    for (const [name, p] of Object.entries(S.prov)) {
        if (p.remove) out[name] = { remove: true };
        else if (p.api_key.trim() || p.base_url.trim()) out[name] = { api_key: p.api_key.trim(), base_url: p.base_url.trim() };
    }
    return out;
}

function payloadAi() {
    const a = structuredClone(S.ai);
    a.tts.voices = a.tts.provider === 'browser' ? [] : voicesFor(a.tts.provider, a.tts.model);
    return a;
}

async function submit() {
    if (S.mode === 'install') {
        say('Instalando…', 'info');
        const r = await api('install', {
            db: S.db,
            app: { password: S.app.password, timezone: S.app.timezone },
            profile: S.profile,
            providers: payloadProviders(),
            ai: payloadAi(),
        });
        if (r.written) {
            location.href = './';
            return false;
        }
        S.manual = r.content;
        render();
        return false;
    }
    say('Salvando…', 'info');
    await api('save', { providers: payloadProviders(), ai: payloadAi() });
    location.href = './';
    return false;
}

// --- desbloqueio (reconfiguração) ---
let unlockInput;
function renderUnlock() {
    unlockInput = h('input', { type: 'password', autocomplete: 'current-password', on: { keydown: (e) => { if (e.key === 'Enter') unlock(); } } });
    return section('Confirme sua senha', 'Por segurança, alterar chaves e modelos de IA exige a senha de novo.', field('Senha', unlockInput));
}

async function unlock() {
    nextBtn.disabled = true;
    try {
        const r = await api('unlock', { password: unlockInput.value });
        applyCurrent(r);
        S.unlocked = true;
        render(true);
        probeSaved();
    } catch (err) {
        say(err.message);
    } finally {
        nextBtn.disabled = false;
    }
}

function applyCurrent({ providers, ai }) {
    for (const [name, saved] of Object.entries(providers)) provState(name).saved = saved;
    S.ai = ai;
}

/** Na reconfiguração, testa em segundo plano as chaves já salvas para listar os modelos. */
function probeSaved() {
    for (const name of available()) if (provState(name).status === 'idle') probe(name);
}

// ---------- início ----------
(async () => {
    try {
        const st = await api('state');
        Object.assign(S, { mode: st.mode, csrf: st.csrf, catalog: st.catalog, roles: st.roles, thinking: st.thinking });
        if (st.mode === 'install') {
            S.requirements = st.requirements;
            S.timezones = st.timezones;
            S.db = { ...st.db, create: true };
            const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            S.app.timezone = st.timezones.includes(tz) ? tz : 'America/Sao_Paulo';
            order = ['req', 'db', 'access', 'providers', 'resources', 'review'];
            $('#subtitle').textContent = 'Configuração inicial';
            await runClientChecks();
        } else {
            order = ['providers', 'resources', 'review'];
            $('#title').textContent = 'Reconfigurar IA';
            $('#subtitle').textContent = 'Provedores, chaves e modelos';
            S.unlocked = st.unlocked;
            if (st.unlocked) {
                applyCurrent(st);
                probeSaved();
            }
        }
        render(true);
    } catch (err) {
        stepEl.replaceChildren(section('Não foi possível carregar', err.message));
    }
})();

// Atualiza a cobertura sempre que o passo de provedores é desenhado
new MutationObserver(updateCoverage).observe(stepEl, { childList: true });
