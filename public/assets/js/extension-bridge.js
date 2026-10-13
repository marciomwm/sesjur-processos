/**
 * Ponte entre a aplicação (localhost) e a extensão do Chrome.
 *
 * Mecanismo: window.postMessage entre a página e um content script da extensão
 * injetado apenas em http://localhost/*. O content script repassa a mensagem
 * ao service worker por chrome.runtime.sendMessage e devolve a resposta.
 *
 * Por que não usar a área de transferência: a especificação exige fluxo
 * programático; o clipboard é assíncrono, exige gesto do usuário e não
 * transporta estrutura com garantia de origem.
 *
 * Por que não usar externally_connectable: exigiria fixar o ID da extensão
 * na aplicação. A ponte por content script funciona com qualquer ID e mantém
 * o isolamento de origem (a mensagem só é aceita quando event.source === window
 * e event.origin === location.origin).
 */
(function () {
    'use strict';

    const GJ = window.GJ || (window.GJ = {});
    const ORIGEM_APP = 'GJ_APP';
    const ORIGEM_EXT = 'GJ_EXTENSAO';
    const TIMEOUT_PADRAO = 45000;

    let contador = 0;
    const pendentes = new Map();

    window.addEventListener('message', function (evento) {
        if (evento.source !== window) return;
        if (evento.origin !== window.location.origin) return;
        const dados = evento.data;
        if (!dados || dados.source !== ORIGEM_EXT) return;

        // Progresso de extração longa (sem id: não responde a um pedido).
        if (dados.type === 'GJ_PROGRESSO' && typeof dados.texto === 'string') {
            document.dispatchEvent(new CustomEvent('gj:progresso', { detail: { texto: dados.texto } }));
            return;
        }
        if (!dados.id) return;

        const pendente = pendentes.get(dados.id);
        if (!pendente) return;
        pendentes.delete(dados.id);
        clearTimeout(pendente.timer);
        pendente.resolver(dados.response);
    });

    /**
     * Envia uma solicitação à extensão.
     * @returns {Promise<object>} contrato padronizado {success, source, data|error, warnings}
     */
    function enviar(tipo, payload, timeoutMs) {
        return new Promise(function (resolver) {
            const id = ORIGEM_APP + ':' + (++contador) + ':' + Date.now();
            const timer = setTimeout(function () {
                pendentes.delete(id);
                resolver({
                    success: false,
                    source: (payload && payload.source) || 'EXTENSAO',
                    error: {
                        code: 'EXTENSION_TIMEOUT',
                        message: 'A extensão não respondeu. Verifique se ela está instalada e habilitada no Chrome.'
                    }
                });
            }, timeoutMs || TIMEOUT_PADRAO);

            pendentes.set(id, { resolver: resolver, timer: timer });
            window.postMessage({ source: ORIGEM_APP, id: id, type: tipo, payload: payload || {} }, window.location.origin);
        });
    }

    /**
     * Confirma a presença da extensão antes de uma operação longa.
     * Sem esta verificação, um navegador sem a extensão ficaria esperando
     * o timeout completo (45s) para então informar o óbvio.
     */
    /** Endereço desta aplicação: para onde a extensão leva ao criar um processo pelo SIGADOC/PJe. */
    function payloadPing() {
        const base = String(GJ.baseUrl || '').replace(/\/?$/, '/');
        return { appBaseUrl: window.location.origin + base };
    }

    async function garantirExtensao(origem) {
        const ping = await enviar('GJ_PING', payloadPing(), 1500);
        if (ping && ping.success) {
            return null;
        }
        return {
            success: false,
            source: origem,
            error: {
                code: 'EXTENSION_MISSING',
                message: 'Extensão não detectada. Instale a extensão em chrome://extensions ' +
                         '(Modo do desenvolvedor → Carregar sem compactação).'
            }
        };
    }

    GJ.extensao = {
        /** Verifica a presença da extensão (timeout curto). */
        ping: function () {
            return enviar('GJ_PING', payloadPing(), 1500);
        },

        /**
         * Dados que a extensão preparou para esta aba ao criar um processo a
         * partir da página do SIGADOC/PJe (entregues uma única vez).
         */
        obterPendente: async function () {
            const ausente = await garantirExtensao('EXTENSAO');
            if (ausente) return ausente;
            return enviar('GJ_OBTER_PENDENTE', {}, 5000);
        },

        /** Solicita a extração de um processo administrativo no SIGADOC. */
        extrairSigadoc: async function (protocolo, hostsPermitidos, timeoutMs) {
            const ausente = await garantirExtensao('SIGADOC');
            if (ausente) return ausente;
            return enviar('GJ_EXTRACT_SIGADOC', {
                protocolo: protocolo,
                allowedHosts: hostsPermitidos || [],
                source: 'SIGADOC'
            }, timeoutMs);
        },

        /**
         * Solicita a extração de um processo judicial no PJe.
         * @param {{cronologiaCompleta?: boolean, integra?: boolean}} [opcoes]
         *        etapas que interagem com a página do PJe (rolar a timeline,
         *        abrir as peças decisórias) — mais lentas, por isso o prazo
         *        de resposta passa a ser de 10 minutos.
         */
        extrairPje: async function (numeroCNJ, url, hostsPermitidos, opcoes) {
            const ausente = await garantirExtensao('PJE');
            if (ausente) return ausente;
            opcoes = opcoes || {};
            const longa = opcoes.cronologiaCompleta === true || opcoes.integra === true;
            return enviar('GJ_EXTRACT_PJE', {
                numeroCNJ: numeroCNJ,
                url: url,
                allowedHosts: hostsPermitidos || [],
                cronologiaCompleta: opcoes.cronologiaCompleta === true,
                integra: opcoes.integra === true,
                source: 'PJE'
            }, longa ? 600000 : undefined);
        },

        /** Repassa a allowlist configurada na aplicação para a extensão. */
        sincronizarConfig: function (config) {
            return enviar('GJ_SYNC_CONFIG', config || {}, 3000);
        }
    };

    // Indicador de status no cabeçalho.
    document.addEventListener('DOMContentLoaded', async function () {
        const chip = document.getElementById('statusExtensao');
        if (!chip) return;

        const resposta = await GJ.extensao.ping();
        const rotulo = chip.querySelector('.chip__ponto');
        if (resposta && resposta.success) {
            chip.classList.add('is-ok');
            chip.title = 'Extensão v' + (resposta.data && resposta.data.version ? resposta.data.version : '?') + ' conectada';
            chip.lastChild.textContent = ' Extensão conectada';
            GJ.extensaoDisponivel = true;
        } else {
            chip.classList.add('is-erro');
            chip.title = 'A extensão do Chrome não respondeu. O preenchimento automático ficará indisponível.';
            chip.lastChild.textContent = ' Extensão não detectada';
            GJ.extensaoDisponivel = false;
        }
        if (rotulo) { /* mantém o ponto de status */ }
        document.dispatchEvent(new CustomEvent('gj:extensao', { detail: { disponivel: GJ.extensaoDisponivel } }));
    });
})();
