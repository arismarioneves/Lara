const csrf = () => document.querySelector('meta[name="csrf"]')?.content ?? '';

export class ApiError extends Error {
    constructor(status, message) {
        super(message);
        this.status = status;
    }
}

async function request(path, { method = 'GET', json, form, raw = false } = {}) {
    const headers = { 'X-CSRF-Token': csrf() };
    let body;
    if (json !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(json);
    } else if (form) {
        body = form;
    }
    const res = await fetch(path, { method, headers, body, credentials: 'same-origin' });
    if (res.status === 401 && !path.endsWith('session')) {
        location.reload(); // sessão expirou → tela de login
        throw new ApiError(401, 'Sessão expirada');
    }
    if (raw && res.ok) return res;
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new ApiError(res.status, data.error || `HTTP ${res.status}`);
    return data;
}

export const api = {
    login: (password) => request('api/session', { method: 'POST', json: { password } }),
    logout: () => request('api/session', { method: 'DELETE' }),
    settings: () => request('api/settings'),
    saveSettings: (values) => request('api/settings', { method: 'POST', json: values }),
    history: (params = {}) => request('api/history?' + new URLSearchParams(params)),
    ingest: (form) => request('api/ingest', { method: 'POST', form }),
    ttsPreview: (text) => request('api/tts', { method: 'POST', json: { text }, raw: true }),
};
