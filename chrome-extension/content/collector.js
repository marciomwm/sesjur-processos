/**
 * Content script injetado sob demanda nas páginas do SIGADOC/PJe.
 *
 * Responsabilidade única: executar o extrator correspondente e devolver o
 * objeto normalizado ao service worker. Não envia nada para fora do navegador,
 * não lê cookies, não toca em credenciais.
 *
 * É injetado junto de config/selectors.js, extractors/dom-utils.js,
 * extractors/normalizers.js, os dois extratores e extractors/pje-documentos.js,
 * nessa ordem.
 */
(function () {
'use strict';

if (globalThis.__GJ_COLLECTOR_INSTALADO__) return;
globalThis.__GJ_COLLECTOR_INSTALADO__ = true;

const GJ = globalThis.GJ;

/**
 * A visão padrão do SIGADOC lista só juntadas e anotações. O histórico
 * completo (transferências, recebimentos, sobrestamentos, arquivamentos…)
 * está na página "Ver Mais", que é lida aqui com um GET de MESMA ORIGEM — a
 * mesma requisição que o usuário faria ao clicar no link, com a sessão que
 * ele já tem. Falhas são silenciosas: o extrator recai na visão resumida.
 */
async function carregarHistoricoCompleto() {
    const url = GJ.sigadocExtractor.urlHistoricoCompleto(document, location.href);
    if (!url) return null;
    const controle = new AbortController();
    const cronometro = setTimeout(function () { controle.abort(); }, 10000);
    try {
        const resposta = await fetch(url, { credentials: 'same-origin', signal: controle.signal });
        if (!resposta.ok) return null;
        const html = await resposta.text();
        return new DOMParser().parseFromString(html, 'text/html');
    } catch (e) {
        return null;
    } finally {
        clearTimeout(cronometro);
    }
}

/**
 * Progresso das etapas longas do PJe, repassado pelo service worker à tela da
 * aplicação. O batimento periódico também mantém o service worker ativo
 * enquanto a página responde aos cliques (ele hiberna após 30 s sem eventos).
 */
function avisarProgresso(texto) {
    try {
        chrome.runtime.sendMessage({ type: 'GJ_PROGRESSO', payload: { texto: texto || null } }, function () {
            void chrome.runtime.lastError; // sem ouvinte: ignorar
        });
    } catch (e) { /* extensão recarregada: ignorar */ }
}

/**
 * PJe em três tempos: (1) leitura rápida, que valida o CNJ antes de qualquer
 * interação com a página; (2) opcionalmente, rolagem da timeline até a última
 * página e nova leitura; (3) opcionalmente, leitura da íntegra das peças
 * decisórias no painel de documentos.
 */
async function coletarPje(mensagem) {
    const opcoes = mensagem.opcoes || {};
    const base = { cnjEsperado: mensagem.expected, url: location.href };

    let resultado = GJ.pjeExtractor.extrair(document, base);
    if (!resultado.success || (!opcoes.cronologiaCompleta && !opcoes.integra)) {
        return resultado;
    }

    const batimento = setInterval(function () { avisarProgresso(null); }, 10000);
    try {
        if (opcoes.cronologiaCompleta) {
            const pag = await GJ.pjeDocumentos.carregarCronologiaCompleta(document, { progresso: avisarProgresso });
            resultado = GJ.pjeExtractor.extrair(document, base);
            if (!resultado.success) return resultado;
            resultado.cronologiaCarregada = pag;
        }
        if (opcoes.integra) {
            await GJ.pjeDocumentos.extrairIntegras(document, resultado, { progresso: avisarProgresso });
        }
    } finally {
        clearInterval(batimento);
    }
    return resultado;
}

chrome.runtime.onMessage.addListener(function (mensagem, remetente, responder) {
    if (!mensagem || mensagem.type !== 'GJ_COLLECT') return false;

    (async function () {
        try {
            // Páginas dinâmicas: aguarda o DOM estabilizar (com timeout).
            await GJ.dom.esperarEstabilizar(document.body || document.documentElement, 500, 5000);

            let resultado;
            if (mensagem.source === 'SIGADOC') {
                resultado = GJ.sigadocExtractor.extrair(document, {
                    protocoloEsperado: mensagem.expected,
                    url: location.href,
                    documentoCompleto: await carregarHistoricoCompleto()
                });
            } else if (mensagem.source === 'PJE') {
                resultado = await coletarPje(mensagem);
            } else {
                resultado = {
                    success: false,
                    source: mensagem.source || 'DESCONHECIDA',
                    error: { code: 'UNKNOWN_SOURCE', message: 'Origem de extração desconhecida.' }
                };
            }
            responder(resultado);
        } catch (erro) {
            responder({
                success: false,
                source: mensagem.source || 'DESCONHECIDA',
                error: { code: 'EXTRACTION_FAILED', message: 'Falha ao ler a página: ' + erro.message }
            });
        }
    })();

    return true; // resposta assíncrona
});
})();
