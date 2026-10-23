// Permissão do microfone.

/** 'granted' | 'prompt' | 'denied' | 'unknown' (navegador sem Permissions API para microfone). */
export async function micState() {
    try {
        return (await navigator.permissions.query({ name: 'microphone' })).state;
    } catch {
        return 'unknown';
    }
}

/** Abre e fecha o microfone para o navegador mostrar o pedido. Chame direto no clique. */
export async function requestMic() {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    for (const track of stream.getTracks()) track.stop();
}

/** Texto curto para falha do microfone; null quando o erro é de outra coisa. */
export function micErrorText(err) {
    if (!navigator.mediaDevices) return 'O microfone exige HTTPS.';
    switch (err?.name) {
        case 'NotAllowedError':
        case 'SecurityError':
            return 'Microfone bloqueado. Libere o microfone para o navegador nas configurações do celular e do site.';
        case 'NotFoundError':
            return 'Nenhum microfone encontrado.';
        case 'NotReadableError':
        case 'AbortError':
            return 'Microfone em uso por outro app.';
        case 'OverconstrainedError':
            return 'O microfone escolhido em Configurações não está disponível.';
        default:
            return null;
    }
}
