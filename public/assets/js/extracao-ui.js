/**
 * Estados visuais da extração (seção 52 da especificação) e
 * fluxo comum EXTRAIR → VALIDAR → PREENCHER → REVISAR → CONFIRMAR → SALVAR.
 *
 * Princípio de falha segura: nada é salvo automaticamente e os campos já
 * digitados pelo usuário nunca são apagados por uma extração malsucedida.
 */
(function () {
    'use strict';

    const GJ = window.GJ;

    /** Mensagens por código de erro, comuns a SIGADOC e PJe. */
    const MENSAGENS = {
        EXTENSION_TIMEOUT:   'A extensão não respondeu. Verifique se ela está instalada e habilitada no Chrome.',
        EXTENSION_MISSING:   'Extensão não detectada. Instale a extensão em chrome://extensions (modo desenvolvedor).',
        ALLOWLIST_EMPTY:     'Nenhum domínio autorizado configurado. Cadastre os domínios em Sistema › Configurações.',
        DOMAIN_NOT_ALLOWED:  'O domínio informado não está na lista de domínios autorizados.',
        PERMISSION_DENIED:   'Permissão de acesso ao domínio não concedida à extensão. Abra as opções da extensão e conceda o acesso.',
        SESSION_REQUIRED:    'Não foi possível acessar o processo. Verifique se você está autenticado no sistema de origem.',
        PROCESS_NOT_FOUND:   'O processo informado não foi localizado.',
        SELECTORS_MISSING:   'A página foi localizada, mas não foi possível identificar os campos necessários.',
        SELECTORS_NOT_CONFIGURED: 'Os seletores do extrator ainda não foram mapeados para este sistema. Consulte o README (seção “Mapeamento do DOM”).',
        IDENTIFIER_MISMATCH: 'O identificador encontrado na página não corresponde ao processo informado.',
        URL_REQUIRED:        'Informe a URL do processo.',
        URL_INVALID:         'URL inválida.',
        URL_NOT_HTTPS:       'Somente URLs HTTPS são aceitas.',
        URL_SCHEME_BLOCKED:  'Esquema de URL não autorizado.',
        NO_TAB:              'Nenhuma aba do sistema de origem está aberta. Abra a página do processo e tente novamente.',
        TAB_LOAD_FAILED:     'Não foi possível carregar a página de origem.',
        EXTRACTION_FAILED:   'Não foi possível concluir a extração.'
    };

    function caixa(el, tipo, titulo, detalhes) {
        const itens = (detalhes || []).filter(Boolean);
        el.classList.add('is-visivel');
        el.innerHTML =
            '<div class="status-caixa status-caixa--' + tipo + '">' +
            '<span>' + (tipo === 'sucesso' ? '✓' : tipo === 'erro' ? '✕' : tipo === 'aviso' ? '⚠' : '⏳') + '</span>' +
            '<div><strong>' + GJ.esc(titulo) + '</strong>' +
            (itens.length ? '<ul class="status-caixa__lista">' + itens.map(function (d) {
                return '<li>' + GJ.esc(d) + '</li>';
            }).join('') + '</ul>' : '') +
            '</div></div>';
    }

    GJ.extracaoUI = {
        mensagemDe: function (resposta, padrao) {
            const erro = (resposta && resposta.error) || {};
            return MENSAGENS[erro.code] || erro.message || padrao || 'Falha na extração.';
        },
        processando: function (el, texto) { caixa(el, 'info', texto); },
        sucesso: function (el, texto, avisos) { caixa(el, 'sucesso', texto, avisos); },
        erro: function (el, texto, detalhes) { caixa(el, 'erro', texto, detalhes); },
        aviso: function (el, texto, detalhes) { caixa(el, 'aviso', texto, detalhes); },
        limpar: function (el) { el.classList.remove('is-visivel'); el.innerHTML = ''; },

        /** Preenche um campo apenas quando há valor extraído; marca visualmente. */
        preencher: function (input, valor) {
            if (!input || valor == null || valor === '') return false;
            input.value = valor;
            input.classList.add('is-preenchido-extracao');
            input.dispatchEvent(new Event('input', { bubbles: true }));
            return true;
        },

        /** Registra a proveniência no formulário (campos ocultos + rótulo). */
        registrarProveniencia: function (form, origem, extractedAt, url) {
            const def = function (nome, valor) {
                let el = form.querySelector('[name="' + nome + '"]');
                if (!el) {
                    el = document.createElement('input');
                    el.type = 'hidden';
                    el.name = nome;
                    form.appendChild(el);
                }
                el.value = valor == null ? '' : valor;
            };
            def('origem', origem);
            def('data_extracao', extractedAt || new Date().toISOString());
            if (url) def('url_origem', url);

            const rotulo = form.querySelector('[data-proveniencia]');
            if (rotulo) {
                const quando = extractedAt ? new Date(extractedAt) : new Date();
                rotulo.innerHTML =
                    '<span class="badge badge--azul">Origem: ' + GJ.esc(origem) + '</span>' +
                    '<span>Extraído em: ' + GJ.esc(quando.toLocaleString('pt-BR')) + '</span>';
            }
        },

        /** Estado de carregamento de um botão. */
        botaoOcupado: function (botao, ocupado, textoOcupado) {
            if (!botao) return;
            if (ocupado) {
                botao.dataset.textoOriginal = botao.dataset.textoOriginal || botao.innerHTML;
                botao.innerHTML = '<span class="spinner"></span> ' + GJ.esc(textoOcupado || 'Processando…');
                botao.disabled = true;
            } else {
                if (botao.dataset.textoOriginal) botao.innerHTML = botao.dataset.textoOriginal;
                botao.disabled = false;
            }
        }
    };
})();
