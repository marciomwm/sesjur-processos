/**
 * Ponte APLICAÇÃO (localhost) ↔ EXTENSÃO.
 *
 * Injetado apenas em http://localhost/* e http://127.0.0.1/*.
 * Repassa mensagens `window.postMessage` da página para o service worker e
 * devolve a resposta. Mantém o isolamento de origem: só aceita mensagens
 * originadas na própria janela e na mesma origem.
 *
 * Por que não `externally_connectable`: exigiria fixar o ID da extensão na
 * aplicação PHP. Esta ponte funciona com qualquer ID, inclusive o ID gerado
 * ao carregar a extensão sem compactação.
 */
(function () {
'use strict';

const ORIGEM_APP = 'GJ_APP';
const ORIGEM_EXT = 'GJ_EXTENSAO';

const TIPOS_PERMITIDOS = [
    'GJ_PING',
    'GJ_SYNC_CONFIG',
    'GJ_EXTRACT_SIGADOC',
    'GJ_EXTRACT_PJE',
    'GJ_OBTER_PENDENTE'
];

/**
 * Progresso de uma extração longa, enviado pelo service worker. Só texto: a
 * página exibe, mas nada disso é resposta de pedido nem dado do processo.
 */
chrome.runtime.onMessage.addListener(function (mensagem, remetente) {
    if (!remetente || remetente.id !== chrome.runtime.id) return false;
    if (!mensagem || mensagem.type !== 'GJ_PROGRESSO' || typeof mensagem.texto !== 'string') return false;
    window.postMessage({ source: ORIGEM_EXT, type: 'GJ_PROGRESSO', texto: mensagem.texto }, window.location.origin);
    return false;
});

window.addEventListener('message', function (evento) {
    if (evento.source !== window) return;
    if (evento.origin !== window.location.origin) return;

    const dados = evento.data;
    if (!dados || dados.source !== ORIGEM_APP || !dados.id) return;
    if (TIPOS_PERMITIDOS.indexOf(dados.type) < 0) return;

    chrome.runtime.sendMessage(
        { type: dados.type, payload: dados.payload || {} },
        function (resposta) {
            const erroRuntime = chrome.runtime.lastError;
            window.postMessage({
                source: ORIGEM_EXT,
                id: dados.id,
                response: erroRuntime
                    ? {
                        success: false,
                        source: (dados.payload && dados.payload.source) || 'EXTENSAO',
                        error: { code: 'EXTENSION_UNAVAILABLE', message: erroRuntime.message }
                      }
                    : resposta
            }, window.location.origin);
        }
    );
});
})();
