// Permissão do microfone: o pedido sai no toque do usuário e, quando o navegador bloqueia,
// a Lara mostra como liberar neste aparelho (no app instalado não há barra de endereço).

/** 'granted' | 'prompt' | 'denied' | 'unknown' (navegador sem Permissions API para microfone). */
export async function micPermission() {
    try {
        return (await navigator.permissions.query({ name: 'microphone' })).state;
    } catch {
        return 'unknown';
    }
}

/** Avisa quando a permissão muda (ex.: o usuário liberou nas configurações e voltou ao app). */
export async function watchMicPermission(onChange) {
    try {
        const status = await navigator.permissions.query({ name: 'microphone' });
        status.addEventListener('change', () => onChange(status.state));
    } catch { /* sem Permissions API: o botão de tentar de novo cobre */ }
}

/** Recusa mais rápida que isso não passou por um pedido na tela: ninguém lê e toca tão rápido. */
const SILENT_DENIAL_MS = 600;

/**
 * Abre e fecha o microfone só para o navegador mostrar o pedido de permissão.
 * Recusa instantânea sai marcada com `silent`: o navegador bloqueou sem perguntar.
 */
export async function requestMic() {
    if (!navigator.mediaDevices?.getUserMedia) throw new DOMException('getUserMedia indisponível', 'SecurityError');
    const started = performance.now();
    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (err) {
        if (err?.name === 'NotAllowedError' && performance.now() - started < SILENT_DENIAL_MS) err.silent = true;
        throw err;
    }
    for (const track of stream.getTracks()) track.stop();
}

/**
 * Traduz a falha do microfone em orientação para o usuário.
 * Retorna null quando o erro não é do microfone.
 */
export function micProblem(err, permission) {
    switch (err?.name) {
        case 'SecurityError':
            if (!window.isSecureContext) {
                return {
                    title: 'Microfone indisponível neste endereço',
                    text: 'O navegador só libera o microfone em conexão segura. Abra a Lara por https://.',
                };
            }
        // fallthrough: com HTTPS, SecurityError é bloqueio de permissão
        case 'NotAllowedError':
            if (err.silent) {
                return {
                    title: 'O navegador recusou sem perguntar',
                    text: 'O pedido de permissão nem chegou a aparecer: o microfone está bloqueado para este site ou para o próprio navegador no sistema. Para liberar:',
                    steps: unblockSteps(),
                    action: 'Já liberei, tentar de novo',
                };
            }
            if (permission === 'prompt') {
                return {
                    title: 'Permita o microfone',
                    text: 'A Lara precisa do microfone para ouvir. Toque no botão abaixo e escolha Permitir.',
                    steps: unblockSteps(),
                    stepsCollapsed: true,
                    action: 'Permitir microfone',
                };
            }
            return {
                title: 'Microfone bloqueado',
                text: 'O navegador está bloqueando o microfone da Lara. Para liberar:',
                steps: unblockSteps(),
                action: 'Já liberei, tentar de novo',
            };
        case 'NotFoundError':
            return {
                title: 'Nenhum microfone encontrado',
                text: 'Conecte um microfone ou fone de ouvido e tente de novo.',
                action: 'Tentar de novo',
            };
        case 'NotReadableError':
        case 'AbortError':
            return {
                title: 'Microfone ocupado',
                text: 'Outro app está usando o microfone (ligação, gravador, outra aba). Feche-o e tente de novo.',
                action: 'Tentar de novo',
            };
        case 'OverconstrainedError':
            return {
                title: 'Microfone escolhido indisponível',
                text: 'O microfone salvo em Configurações não está conectado. Conecte-o ou escolha outro em Configurações.',
                action: 'Tentar de novo',
            };
        default:
            return null;
    }
}

/**
 * Preenche o cartão de ajuda; onAction roda no toque do botão (gesto do usuário).
 * `note` aparece em destaque (ex.: a nova tentativa falhou do mesmo jeito).
 */
export function renderMicHelp(container, problem, onAction, note = '') {
    const el = (tag, text) => Object.assign(document.createElement(tag), text ? { textContent: text } : {});
    const parts = [el('h2', problem.title), el('p', problem.text)];

    if (problem.steps?.length) {
        const list = el('ol');
        list.append(...problem.steps.map((s) => el('li', s)));
        if (problem.stepsCollapsed) {
            const details = el('details');
            details.append(el('summary', 'O pedido não apareceu? Libere manualmente'), list);
            parts.push(details);
        } else {
            parts.push(list);
        }
    }
    if (note) {
        const p = el('p', note);
        p.className = 'note';
        parts.push(p);
    }
    if (problem.action) {
        const button = el('button', problem.action);
        button.type = 'button';
        button.addEventListener('click', async () => {
            button.disabled = true;
            button.textContent = 'Aguardando o navegador…';
            try {
                await onAction();
            } finally {
                button.disabled = false;
                button.textContent = problem.action;
            }
        });
        parts.push(button);
    }
    container.replaceChildren(...parts);
    container.hidden = false;
}

function unblockSteps() {
    const ua = navigator.userAgent;
    const host = location.host;
    const installed = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    const ios = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    if (/FBAN|FBAV|Instagram|Line\//.test(ua)) {
        return [`Este navegador interno não libera o microfone. Abra ${host} no Chrome ou no Safari.`];
    }
    if (ios && installed) {
        return [
            'Abra os Ajustes do iPhone → Safari (no iOS 18: Ajustes → Apps → Safari) → Microfone → Permitir.',
            'Feche a Lara no alternador de apps e abra de novo.',
            'Quando o pedido aparecer, toque em Permitir.',
        ];
    }
    if (ios) {
        return [
            'Toque em aA (ou no ícone de menu) na barra de endereço → Ajustes do Site → Microfone → Permitir.',
            'Se não houver essa opção: Ajustes do iPhone → Safari → Microfone → Permitir.',
            'Volte aqui e toque no botão abaixo.',
        ];
    }
    if (/Android/.test(ua) && installed) {
        return [
            'Abra o Chrome → ⋮ → Configurações → Configurações do site → Microfone.',
            `Toque em ${host} e escolha Permitir.`,
            'Se o Chrome inteiro estiver sem microfone: Configurações do Android → Apps → Chrome → Permissões → Microfone → Permitir.',
            'Volte para a Lara e toque no botão abaixo.',
        ];
    }
    if (/Android/.test(ua)) {
        return [
            'Toque no ícone à esquerda do endereço → Permissões → Microfone → Permitir.',
            'Se o Chrome inteiro estiver sem microfone: Configurações do Android → Apps → Chrome → Permissões → Microfone → Permitir.',
            'Volte aqui e toque no botão abaixo.',
        ];
    }
    return [
        'Clique no ícone à esquerda do endereço → Microfone → Permitir.',
        'Se o navegador pedir, recarregue a página.',
    ];
}
