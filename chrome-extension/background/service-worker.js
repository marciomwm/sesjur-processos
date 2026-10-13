/**
 * Service Worker (MV3) — orquestração.
 *
 * Responsabilidades: receber os pedidos da aplicação local, localizar/abrir a
 * aba correta, injetar os extratores e devolver o contrato de dados.
 *
 * Princípios aplicados:
 *  - menor privilégio: só atua em hosts presentes na allowlist E com permissão
 *    concedida pelo usuário (chrome.permissions);
 *  - nenhum dado sai do navegador: não há fetch para serviços externos;
 *  - nenhuma credencial, cookie ou token é lido, guardado ou repassado;
 *  - nada é salvo automaticamente: o service worker apenas devolve os dados.
 */

/**
 * Template de URL do SIGADOC, observado no sistema real (ver
 * config/selectors.js). Serve apenas como ATALHO: quando nenhuma aba do
 * SIGADOC corresponde ao protocolo, a extensão abre a página diretamente.
 * Pode ser sobrescrito pela aplicação (Sistema › Configurações).
 */
const SIGADOC_URL_TEMPLATE_PADRAO =
    'https://{host}/sigaex/app/expediente/doc/exibir?sigla={protocolo}';

const ARQUIVOS_EXTRATOR = [
    'config/selectors.js',
    'extractors/dom-utils.js',
    'extractors/normalizers.js',
    'extractors/sigadoc-extractor.js',
    'extractors/pje-extractor.js',
    'extractors/pje-documentos.js',
    'content/collector.js'
];

const TIMEOUT_CARREGAMENTO = 25000;

/**
 * Aba do PJe em extração → aba da aplicação que a pediu. Permite repassar o
 * progresso das etapas longas (cronologia completa, íntegras) à tela certa.
 */
const abaSolicitante = new Map();

/* ------------------------------------------------------------------ */
/* Utilitários                                                        */
/* ------------------------------------------------------------------ */

function falha(origem, codigo, mensagem, detalhes) {
    const r = { success: false, source: origem, error: { code: codigo, message: mensagem } };
    if (detalhes) r.error.details = detalhes;
    return r;
}

/** Normaliza a lista de hosts autorizados. */
function normalizarHosts(lista) {
    return (lista || [])
        .map(function (h) { return String(h || '').trim().toLowerCase().replace(/^\*\./, ''); })
        .filter(Boolean);
}

/** Host pertence à allowlist (host exato ou subdomínio). */
function hostPermitido(host, permitidos) {
    host = String(host || '').toLowerCase();
    return permitidos.some(function (p) {
        return host === p || host.endsWith('.' + p);
    });
}

/** Padrão de origem usado em chrome.permissions. */
function padraoOrigem(host) {
    return 'https://' + host + '/*';
}

async function temPermissao(host) {
    try {
        return await chrome.permissions.contains({ origins: [padraoOrigem(host)] });
    } catch (e) {
        return false;
    }
}

/** Guarda a allowlist para a página de opções. */
async function guardarConfig(config) {
    const dados = {
        sigadocHosts: normalizarHosts(config.sigadocHosts),
        pjeHosts: normalizarHosts(config.pjeHosts),
        atualizadoEm: new Date().toISOString()
    };
    if (typeof config.sigadocUrlTemplate === 'string' && config.sigadocUrlTemplate.trim()) {
        dados.sigadocUrlTemplate = config.sigadocUrlTemplate.trim();
    }
    await chrome.storage.local.set(dados);
    await atualizarMenus();
}

async function lerConfig() {
    const c = await chrome.storage.local.get(['sigadocHosts', 'pjeHosts', 'sigadocUrlTemplate']);
    return {
        sigadocHosts: normalizarHosts(c.sigadocHosts),
        pjeHosts: normalizarHosts(c.pjeHosts),
        sigadocUrlTemplate: c.sigadocUrlTemplate || SIGADOC_URL_TEMPLATE_PADRAO
    };
}

/** Monta a URL de consulta direta a um protocolo do SIGADOC. */
function montarUrlSigadoc(template, host, protocolo) {
    if (!template || !host || !protocolo) return null;
    const url = template
        .replace('{host}', host)
        .replace('{protocolo}', encodeURIComponent(protocolo));
    try {
        const u = new URL(url);
        return (u.protocol === 'https:' && u.hostname === host) ? u.href : null;
    } catch (e) {
        return null;
    }
}

/**
 * Abre uma URL em aba de segundo plano, extrai e fecha a aba.
 * Usado quando não há aba aberta correspondente ao processo.
 */
async function extrairEmAbaTemporaria(url, origem, esperado, opcoes, abaApp) {
    let aba = null;
    try {
        aba = await chrome.tabs.create({ url: url, active: false });
    } catch (e) {
        return falha(origem, 'TAB_LOAD_FAILED', 'Não foi possível abrir a página: ' + e.message);
    }

    try {
        const carregou = await esperarAbaCarregar(aba.id, TIMEOUT_CARREGAMENTO);
        if (!carregou) {
            return falha(origem, 'TAB_LOAD_FAILED', 'A página não terminou de carregar a tempo.');
        }
        return await coletarNaAba(aba.id, origem, esperado, opcoes, abaApp);
    } finally {
        try { await chrome.tabs.remove(aba.id); } catch (e) { /* aba já fechada */ }
    }
}

/** Injeta o pacote de extração e solicita a coleta na aba indicada. */
async function coletarNaAba(tabId, origem, esperado, opcoes, abaApp) {
    if (abaApp != null) abaSolicitante.set(tabId, abaApp);
    try {
        return await coletarNaAbaSemProgresso(tabId, origem, esperado, opcoes);
    } finally {
        abaSolicitante.delete(tabId);
    }
}

async function coletarNaAbaSemProgresso(tabId, origem, esperado, opcoes) {
    try {
        await chrome.scripting.executeScript({
            target: { tabId: tabId },
            files: ARQUIVOS_EXTRATOR,
            world: 'ISOLATED'
        });
    } catch (erro) {
        return falha(origem, 'INJECTION_FAILED',
            'Não foi possível executar o extrator nesta página: ' + erro.message);
    }

    try {
        const resposta = await chrome.tabs.sendMessage(tabId, {
            type: 'GJ_COLLECT',
            source: origem,
            expected: esperado,
            opcoes: opcoes || {}
        });
        return resposta || falha(origem, 'EXTRACTION_FAILED', 'O extrator não retornou resposta.');
    } catch (erro) {
        return falha(origem, 'EXTRACTION_FAILED', 'Falha na comunicação com a página: ' + erro.message);
    }
}

/** Espera uma aba terminar de carregar, com timeout. */
function esperarAbaCarregar(tabId, timeoutMs) {
    return new Promise(function (resolver) {
        let concluido = false;

        const finalizar = function (ok) {
            if (concluido) return;
            concluido = true;
            chrome.tabs.onUpdated.removeListener(ouvinte);
            clearTimeout(cronometro);
            resolver(ok);
        };

        const ouvinte = function (id, info) {
            if (id === tabId && info.status === 'complete') finalizar(true);
        };

        chrome.tabs.onUpdated.addListener(ouvinte);
        const cronometro = setTimeout(function () { finalizar(false); }, timeoutMs || TIMEOUT_CARREGAMENTO);

        // A aba pode já estar pronta.
        chrome.tabs.get(tabId).then(function (aba) {
            if (aba && aba.status === 'complete') finalizar(true);
        }).catch(function () { finalizar(false); });
    });
}

/* ------------------------------------------------------------------ */
/* Operações                                                           */
/* ------------------------------------------------------------------ */

/**
 * SIGADOC: a extensão NÃO inventa URLs. Ela procura, entre as abas abertas,
 * uma página de um host autorizado e verifica se o protocolo solicitado está
 * nela. O usuário permanece responsável por autenticar-se e abrir o processo.
 */
async function extrairSigadoc(payload) {
    const origem = 'SIGADOC';
    const protocolo = String(payload.protocolo || '').trim();
    if (!protocolo) {
        return falha(origem, 'INVALID_PROTOCOL', 'Protocolo não informado.');
    }

    const hosts = normalizarHosts(payload.allowedHosts);
    if (hosts.length === 0) {
        return falha(origem, 'ALLOWLIST_EMPTY',
            'Nenhum domínio do SIGADOC autorizado. Cadastre o domínio em Sistema › Configurações.');
    }
    await guardarConfig({ sigadocHosts: hosts, pjeHosts: (await lerConfig()).pjeHosts });

    let ultimaFalha = null;

    // Estratégia 1 — reaproveitar uma aba já aberta do SIGADOC. Preferida:
    // não gera navegação nova e funciona mesmo que o padrão de URL mude.
    const abas = await chrome.tabs.query({});
    const candidatas = abas.filter(function (aba) {
        if (!aba.url) return false;
        try {
            return hostPermitido(new URL(aba.url).hostname, hosts);
        } catch (e) {
            return false;
        }
    });

    for (const aba of candidatas) {
        const host = new URL(aba.url).hostname;
        if (!(await temPermissao(host))) {
            ultimaFalha = falha(origem, 'PERMISSION_DENIED',
                'A extensão não tem permissão para ler ' + host +
                '. Conceda o acesso nas opções da extensão.');
            continue;
        }
        const resultado = await coletarNaAba(aba.id, origem, protocolo);
        if (resultado.success) {
            return resultado;
        }
        // Divergência de identificador em uma aba não impede tentar as demais.
        ultimaFalha = resultado;
    }

    // Estratégia 2 — consulta direta pelo padrão de URL observado no sistema.
    // A navegação ocorre no navegador do usuário, já autenticado; a extensão
    // não envia credenciais nem tenta reproduzir a sessão.
    const cfg = await lerConfig();
    const template = payload.urlTemplate || cfg.sigadocUrlTemplate;

    for (const host of hosts) {
        if (!(await temPermissao(host))) {
            ultimaFalha = ultimaFalha || falha(origem, 'PERMISSION_DENIED',
                'A extensão não tem permissão para acessar ' + host +
                '. Conceda o acesso nas opções da extensão.');
            continue;
        }
        const url = montarUrlSigadoc(template, host, protocolo);
        if (!url) continue;

        const resultado = await extrairEmAbaTemporaria(url, origem, protocolo);
        if (resultado.success) {
            return resultado;
        }
        ultimaFalha = resultado;
    }

    if (ultimaFalha && ultimaFalha.error && ultimaFalha.error.code === 'IDENTIFIER_MISMATCH') {
        return falha(origem, 'PROCESS_NOT_FOUND',
            'O processo informado não foi localizado no SIGADOC.',
            ultimaFalha.error.details);
    }
    if (!ultimaFalha) {
        return falha(origem, 'NO_TAB',
            'Não foi possível consultar o SIGADOC. Autentique-se, abra a página do processo ' +
            protocolo + ' e tente novamente.');
    }
    return ultimaFalha;
}

/**
 * PJe: o usuário informa a URL da página do processo, que é validada contra a
 * allowlist antes de qualquer navegação. A navegação ocorre no navegador do
 * usuário (nunca no servidor), eliminando a superfície de SSRF.
 */
async function extrairPje(payload, abaApp) {
    const origem = 'PJE';
    // Só as duas opções conhecidas, como booleanos: nada mais da página da
    // aplicação chega ao content script.
    const opcoes = {
        cronologiaCompleta: payload.cronologiaCompleta === true,
        integra: payload.integra === true
    };
    const cnj = String(payload.numeroCNJ || '').trim();
    const urlBruta = String(payload.url || '').trim();

    if (!cnj)      return falha(origem, 'INVALID_CNJ', 'Número CNJ não informado.');
    if (!urlBruta) return falha(origem, 'URL_REQUIRED', 'URL do processo não informada.');

    let url;
    try {
        url = new URL(urlBruta);
    } catch (e) {
        return falha(origem, 'URL_INVALID', 'URL inválida.');
    }
    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
        return falha(origem, 'URL_SCHEME_BLOCKED', 'Esquema de URL não autorizado.');
    }

    const hosts = normalizarHosts(payload.allowedHosts);
    if (hosts.length === 0) {
        return falha(origem, 'ALLOWLIST_EMPTY',
            'Nenhum domínio do PJe autorizado. Cadastre o domínio em Sistema › Configurações.');
    }
    if (!hostPermitido(url.hostname, hosts)) {
        return falha(origem, 'DOMAIN_NOT_ALLOWED',
            'O domínio "' + url.hostname + '" não está na lista de domínios autorizados.');
    }
    if (!(await temPermissao(url.hostname))) {
        return falha(origem, 'PERMISSION_DENIED',
            'A extensão não tem permissão para acessar ' + url.hostname +
            '. Conceda o acesso nas opções da extensão.');
    }

    await guardarConfig({ pjeHosts: hosts, sigadocHosts: (await lerConfig()).sigadocHosts });

    // Reaproveita uma aba já aberta na mesma URL: é mais rápido e evita abrir
    // janelas na frente do usuário.
    const abas = await chrome.tabs.query({});
    const abaExistente = abas.find(function (t) { return t.url === url.href; });

    if (!abaExistente) {
        return await extrairEmAbaTemporaria(url.href, origem, cnj, opcoes, abaApp);
    }

    const resultado = await coletarNaAba(abaExistente.id, origem, cnj, opcoes, abaApp);

    // Uma aba antiga do PJe pode ter perdido o bloco "Mais detalhes" do topo,
    // descartado pelas atualizações parciais do JSF conforme o usuário navega
    // — e com ele vão classe, assunto, jurisdição, datas, valor e as partes.
    // Nesse caso vale reler em uma aba nova, onde a página vem inteira.
    if (resultado && resultado.success === true && resultado.paginaIncompleta === true) {
        const novaLeitura = await extrairEmAbaTemporaria(url.href, origem, cnj, opcoes, abaApp);
        if (novaLeitura && novaLeitura.success === true && novaLeitura.paginaIncompleta !== true) {
            return novaLeitura;
        }
    }

    return resultado;
}

/* ------------------------------------------------------------------ */
/* Criar processo no SESJUR a partir da página do SIGADOC/PJe          */
/* ------------------------------------------------------------------ */

/*
 * Fluxo: o usuário, na página de um processo (ou com o número selecionado),
 * escolhe "Criar processo no SESJUR". A extensão identifica o processo —
 * pelo texto selecionado ou lendo a página — e abre o cadastro no SESJUR com
 * o identificador. O cadastro então executa a importação normal (autorização
 * no servidor, extração, revalidação) e espera a revisão humana: nada é
 * gravado aqui.
 */

const MENU_PAGINA  = 'gj-criar-pagina';
const MENU_SELECAO = 'gj-criar-selecao';
const APP_URL_PADRAO = 'http://localhost/gestao-juridica/public/';
const PENDENTE_VALIDADE_MS = 5 * 60 * 1000;
const SELECAO_MAX = 4000;

/* Identificadores em texto livre (a conferência de dígito fica no servidor). */
const RE_CNJ = /(\d{7})-?(\d{2})\.?(\d{4})\.?(\d)\.?(\d{2})\.?(\d{4})/;
const RE_PROTOCOLO = /\b([A-Z0-9]{2,20})\s*-\s*([A-Z]{2,10})\s*-\s*(\d{4})\s*[\/.]\s*(\d{1,10})\b/i;

/** Hosts onde a aplicação roda: os mesmos em que a ponte é injetada. */
function hostsDaAplicacao() {
    const hosts = [];
    (chrome.runtime.getManifest().content_scripts || []).forEach(function (cs) {
        (cs.matches || []).forEach(function (m) {
            try { hosts.push(new URL(m.replace(/\*$/, '')).hostname); } catch (e) { /* padrão sem URL */ }
        });
    });
    return hosts;
}

/** Endereço base da aplicação informado pela própria página, se for de um host da ponte. */
function urlAplicacaoValida(valor, urlRemetente) {
    try {
        const u = new URL(String(valor || ''));
        if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
        if (hostsDaAplicacao().indexOf(u.hostname) < 0) return null;
        if (urlRemetente && new URL(urlRemetente).origin !== u.origin) return null;
        return u.origin + u.pathname.replace(/\/?$/, '/');
    } catch (e) {
        return null;
    }
}

function identificadorDoTexto(texto) {
    const t = String(texto || '');
    const cnj = RE_CNJ.exec(t);
    if (cnj) {
        return {
            origem: 'PJE', bruto: cnj[0],
            identificador: cnj[1] + '-' + cnj[2] + '.' + cnj[3] + '.' + cnj[4] + '.' + cnj[5] + '.' + cnj[6]
        };
    }
    const prot = RE_PROTOCOLO.exec(t);
    if (prot) {
        return {
            origem: 'SIGADOC', bruto: prot[0],
            identificador: (prot[1] + '-' + prot[2] + '-' + prot[3] + '/' + prot[4]).toUpperCase()
        };
    }
    return null;
}

function mesmoIdentificador(a, b) {
    const limpar = function (v) { return String(v || '').toUpperCase().replace(/[\s.\-\/]/g, ''); };
    return !!a && limpar(a) === limpar(b);
}

/** Menus de contexto só nas páginas dos domínios autorizados. */
async function atualizarMenus() {
    if (!chrome.contextMenus) return;
    const cfg = await lerConfig();
    const padroes = [];
    [].concat(cfg.sigadocHosts, cfg.pjeHosts).forEach(function (h) {
        padroes.push('https://' + h + '/*', 'https://*.' + h + '/*');
    });
    await chrome.contextMenus.removeAll();
    if (padroes.length === 0) return;
    const criar = function (props) {
        chrome.contextMenus.create(props, function () { void chrome.runtime.lastError; });
    };
    criar({
        id: MENU_PAGINA,
        title: 'Criar processo no SESJUR a partir desta página',
        contexts: ['page', 'frame'],
        documentUrlPatterns: padroes
    });
    criar({
        id: MENU_SELECAO,
        title: 'Criar processo no SESJUR com “%s”',
        contexts: ['selection'],
        documentUrlPatterns: padroes
    });
}

/** Texto selecionado na aba (só em host autorizado e com permissão). */
async function selecaoDaAba(tabId) {
    try {
        const [r] = await chrome.scripting.executeScript({
            target: { tabId: tabId },
            func: function () { return String(window.getSelection ? window.getSelection() : ''); }
        });
        return r && typeof r.result === 'string' ? r.result : '';
    } catch (e) {
        return '';
    }
}

/**
 * Identifica o processo e abre o cadastro correspondente no SESJUR.
 * @returns {Promise<{identificador: ?string, origem: ?string, erro: ?string}>}
 */
async function criarNoSesjur(aba, selecao) {
    const cfg = await lerConfig();
    const pendente = {
        criadoEm: Date.now(),
        selecao: selecao ? String(selecao).trim().slice(0, SELECAO_MAX) || null : null,
        origem: null,
        identificador: null,
        url: null,
        erro: null
    };

    let host = null;
    try {
        const u = new URL(aba.url || '');
        if (u.protocol === 'https:') host = u.hostname;
    } catch (e) { /* aba sem URL legível */ }
    const origemPagina = !host ? null
        : hostPermitido(host, cfg.pjeHosts) ? 'PJE'
        : hostPermitido(host, cfg.sigadocHosts) ? 'SIGADOC'
        : null;

    // 1. Leitura da página, sem identificador esperado: descobre qual processo é.
    let lido = null;
    let falhaLeitura = null;
    if (origemPagina) {
        if (await temPermissao(host)) {
            const r = await coletarNaAba(aba.id, origemPagina, '', {});
            if (r && r.success && r.data) {
                lido = {
                    origem: origemPagina,
                    identificador: origemPagina === 'PJE' ? r.data.numeroCNJ : r.data.protocolo,
                    url: r.sourceUrl || aba.url
                };
            } else {
                falhaLeitura = (r && r.error && r.error.message) || 'Não foi possível ler o processo nesta página.';
            }
        } else {
            falhaLeitura = 'A extensão não tem permissão para ler ' + host + '. Conceda o acesso nas opções da extensão.';
        }
    }

    // 2. Um número selecionado prevalece: permite criar um processo citado na página.
    const doTexto = identificadorDoTexto(pendente.selecao);
    if (doTexto) {
        pendente.origem = doTexto.origem;
        pendente.identificador = doTexto.identificador;
        if (lido && lido.origem === doTexto.origem && mesmoIdentificador(lido.identificador, doTexto.identificador)) {
            pendente.url = lido.url;
        }
        // Seleção que é só o número não precisa ser oferecida como texto.
        if (pendente.selecao.length <= doTexto.bruto.length + 2) pendente.selecao = null;
    } else if (lido && lido.identificador) {
        Object.assign(pendente, lido);
    } else {
        pendente.origem = origemPagina;
        pendente.erro = !origemPagina
            ? 'Esta página não pertence a um domínio autorizado do SIGADOC ou do PJe.'
            : (falhaLeitura || 'Não foi possível identificar o processo nesta página.');
    }

    const judicial = (pendente.origem || origemPagina) === 'PJE';
    const params = new URLSearchParams({ extensao: '1' });
    if (pendente.identificador) params.set(judicial ? 'numero_cnj' : 'protocolo', pendente.identificador);

    const base = (await chrome.storage.local.get('appBaseUrl')).appBaseUrl || APP_URL_PADRAO;
    const destino = base + (judicial ? 'novo-processo-judicial.php' : 'novo-processo-administrativo.php') + '?' + params.toString();

    const novaAba = await chrome.tabs.create({ url: destino, index: aba.index + 1, openerTabId: aba.id });
    await chrome.storage.session.set({ ['pendente:' + novaAba.id]: pendente });

    return { identificador: pendente.identificador, origem: pendente.origem, erro: pendente.erro };
}

/**
 * Entrega (uma única vez) os dados preparados para a aba do SESJUR aberta
 * por criarNoSesjur. Espera um pouco: a página pode pedir antes de a
 * gravação terminar.
 */
async function retirarPendente(tabId) {
    if (tabId == null) return null;
    const chave = 'pendente:' + tabId;
    for (let tentativa = 0; tentativa < 10; tentativa++) {
        const r = await chrome.storage.session.get(chave);
        if (r[chave]) {
            await chrome.storage.session.remove(chave);
            return Date.now() - r[chave].criadoEm <= PENDENTE_VALIDADE_MS ? r[chave] : null;
        }
        await new Promise(function (ok) { setTimeout(ok, 200); });
    }
    return null;
}

if (chrome.contextMenus) {
    chrome.contextMenus.onClicked.addListener(function (info, aba) {
        if (!aba || (info.menuItemId !== MENU_PAGINA && info.menuItemId !== MENU_SELECAO)) return;
        criarNoSesjur(aba, info.menuItemId === MENU_SELECAO ? info.selectionText : null)
            .catch(function (e) { console.warn('[Gestão Jurídica] Falha ao criar processo no SESJUR:', e); });
    });
}

chrome.tabs.onRemoved.addListener(function (tabId) {
    chrome.storage.session.remove('pendente:' + tabId).catch(function () { /* nada a limpar */ });
});

chrome.runtime.onStartup.addListener(function () { atualizarMenus(); });

/* ------------------------------------------------------------------ */
/* Roteamento de mensagens                                             */
/* ------------------------------------------------------------------ */

chrome.runtime.onMessage.addListener(function (mensagem, remetente, responder) {
    if (!mensagem || !mensagem.type) return false;

    // Só aceita pedidos vindos do content script da própria extensão.
    if (!remetente || remetente.id !== chrome.runtime.id) return false;

    const payload = mensagem.payload || {};

    // Progresso vindo do coletor em uma aba do PJe: repassa à aba da aplicação
    // que pediu a extração. Mensagem sem texto é só batimento (keep-alive).
    if (mensagem.type === 'GJ_PROGRESSO') {
        const abaApp = remetente.tab ? abaSolicitante.get(remetente.tab.id) : undefined;
        if (abaApp != null && typeof payload.texto === 'string' && payload.texto) {
            chrome.tabs.sendMessage(abaApp, { type: 'GJ_PROGRESSO', texto: payload.texto.slice(0, 300) })
                .catch(function () { /* aba da aplicação fechada */ });
        }
        return false;
    }

    (async function () {
        try {
            switch (mensagem.type) {
                case 'GJ_PING': {
                    // A página informa o próprio endereço: é para onde a extensão
                    // leva o usuário ao criar um processo a partir do SIGADOC/PJe.
                    const appBaseUrl = urlAplicacaoValida(payload.appBaseUrl, remetente.tab ? remetente.tab.url : null);
                    if (appBaseUrl) await chrome.storage.local.set({ appBaseUrl: appBaseUrl });
                    responder({
                        success: true,
                        source: 'EXTENSAO',
                        data: {
                            version: chrome.runtime.getManifest().version,
                            config: await lerConfig()
                        }
                    });
                    break;
                }

                case 'GJ_SYNC_CONFIG': {
                    await guardarConfig(payload);
                    const todos = normalizarHosts([].concat(payload.sigadocHosts || [], payload.pjeHosts || []));
                    const semPermissao = [];
                    for (const host of todos) {
                        if (!(await temPermissao(host))) semPermissao.push(host);
                    }
                    responder({ success: true, source: 'EXTENSAO', data: { semPermissao: semPermissao } });
                    break;
                }

                case 'GJ_EXTRACT_SIGADOC':
                    responder(await extrairSigadoc(payload));
                    break;

                case 'GJ_EXTRACT_PJE':
                    responder(await extrairPje(payload, remetente.tab ? remetente.tab.id : null));
                    break;

                case 'GJ_OBTER_PENDENTE':
                    responder({ success: true, source: 'EXTENSAO', data: await retirarPendente(remetente.tab ? remetente.tab.id : null) });
                    break;

                case 'GJ_CRIAR_DA_ABA': {
                    // Botão do popup: só a própria página da extensão pede isto.
                    if (remetente.tab) {
                        responder(falha('EXTENSAO', 'UNKNOWN_MESSAGE', 'Tipo de mensagem desconhecido.'));
                        break;
                    }
                    const [aba] = await chrome.tabs.query({ active: true, lastFocusedWindow: true });
                    if (!aba) {
                        responder(falha('EXTENSAO', 'NO_TAB', 'Nenhuma aba ativa.'));
                        break;
                    }
                    responder({ success: true, source: 'EXTENSAO', data: await criarNoSesjur(aba, await selecaoDaAba(aba.id)) });
                    break;
                }

                default:
                    responder(falha('EXTENSAO', 'UNKNOWN_MESSAGE', 'Tipo de mensagem desconhecido.'));
            }
        } catch (erro) {
            responder(falha(payload.source || 'EXTENSAO', 'INTERNAL_ERROR', erro.message));
        }
    })();

    return true; // resposta assíncrona
});

chrome.runtime.onInstalled.addListener(function () {
    atualizarMenus();
    console.info('[Gestão Jurídica] Extensão instalada. Configure os domínios autorizados nas opções.');
});
