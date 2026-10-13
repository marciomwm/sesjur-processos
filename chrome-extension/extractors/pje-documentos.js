/**
 * PJe — etapas assíncronas da extração, que INTERAGEM com a página:
 *
 *   1. carregarCronologiaCompleta — rola a timeline até carregar todas as
 *      páginas (#paginaAtual = #totalPaginas), para que o histórico importado
 *      seja integral;
 *   2. extrairIntegras — abre, uma a uma, as peças decisórias (sentença,
 *      decisão, acórdão, despacho, embargos) no painel #detalheDocumento e lê
 *      o texto do iframe#frameHtml, de MESMA ORIGEM.
 *
 * Por que aqui e não no pje-extractor.js: o extrator é puro (lê um Document e
 * devolve o contrato); estas etapas clicam, esperam postbacks do JSF e leem
 * iframes. Ficam separadas para que o extrator continue testável contra
 * fixtures estáticas.
 *
 * Nenhuma URL é montada nem persistida: o documento é aberto pelo mesmo clique
 * que o usuário daria, e o token de sessão (`ca=`) dos links nunca é lido.
 * Todas as esperas têm timeout; nenhum laço é infinito.
 */
(function (raiz) {
'use strict';

const dom  = raiz.GJ.dom;
const norm = raiz.GJ.normalizers;
const cfg  = raiz.GJ.selectors;

/** Métodos de obtenção da íntegra, gravados junto do documento. */
const METODO = {
    IFRAME_HTML:    'IFRAME_HTML',    // texto lido do iframe de mesma origem
    FETCH_HTML:     'FETCH_HTML',     // iframe inacessível; mesma URL lida por GET de mesma origem
    PDF_SEM_TEXTO:  'PDF_SEM_TEXTO',  // o documento é um PDF: sem texto legível pelo DOM
    NAO_LOCALIZADO: 'NAO_LOCALIZADO', // o link do documento não está na cronologia carregada
    FALHA:          'FALHA'           // o painel ou o iframe não responderam a tempo
};

function pausa(ms) {
    return new Promise(function (r) { setTimeout(r, ms); });
}

/**
 * Repete `teste` até devolver um valor verdadeiro ou o tempo acabar.
 * @returns {Promise<*>} o valor devolvido pelo teste, ou null no timeout
 */
async function aguardar(teste, timeoutMs, intervaloMs) {
    const limite = Date.now() + (timeoutMs || 5000);
    const passo = intervaloMs || 150;
    for (;;) {
        let v = null;
        try { v = teste(); } catch (e) { v = null; }
        if (v) return v;
        if (Date.now() >= limite) return null;
        await pausa(passo);
    }
}

function contarItens(container) {
    const M = cfg.PJE_SELECTORS.movimentacoes;
    return container ? container.querySelectorAll(M.itens.join(', ')).length : 0;
}

/**
 * Rola o container da timeline até carregar todas as páginas. O gatilho de
 * carga do PJe é a rolagem; o evento é despachado explicitamente porque, em
 * aba de segundo plano, o navegador não o emite sozinho.
 *
 * Alterna o sentido quando uma tentativa não traz nada (o marcador de rolagem
 * é o primeiro filho da lista), e desiste após três tentativas sem progresso.
 *
 * @returns {Promise<{completa: boolean, paginaAtual: number, totalPaginas: number}>}
 */
async function carregarCronologiaCompleta(doc, opcoes) {
    opcoes = opcoes || {};
    const M = cfg.PJE_SELECTORS.movimentacoes;
    const pje = raiz.GJ.pjeExtractor;
    const progresso = opcoes.progresso || function () {};

    let pag = pje.paginacaoCronologia(doc);
    const resultado = function () {
        return { completa: pag.completa, paginaAtual: pag.atual, totalPaginas: pag.total };
    };
    if (pag.completa) return resultado();

    const rolavel = dom.porSeletores(doc, M.rolagem.css);
    const container = dom.porSeletores(doc, M.container.css);
    if (!rolavel || !container) return resultado();

    const limite = Date.now() + (opcoes.timeoutMs || 90000);
    const esperaPagina = opcoes.esperaPaginaMs || 8000;
    let semProgresso = 0;
    let paraBaixo = true;

    while (!pag.completa && semProgresso < 3 && Date.now() < limite) {
        progresso('Carregando a cronologia do PJe: página ' + pag.atual + ' de ' + pag.total + '…');

        const itensAntes = contarItens(container);
        const paginaAntes = pag.atual;

        rolavel.scrollTop = paraBaixo ? rolavel.scrollHeight : 0;
        rolavel.dispatchEvent(new Event('scroll', { bubbles: true }));

        const avancou = await aguardar(function () {
            return pje.paginacaoCronologia(doc).atual > paginaAntes || contarItens(container) > itensAntes;
        }, esperaPagina);

        if (avancou) {
            semProgresso = 0;
            await dom.esperarEstabilizar(container, 300, 3000);
        } else {
            semProgresso++;
            paraBaixo = !paraBaixo;
        }
        pag = pje.paginacaoCronologia(doc);
    }
    return resultado();
}

/** Número do documento exibido no painel direito, ou null. */
function idNoPainel(doc) {
    const aberto = raiz.GJ.pjeExtractor.extrairDocumentoAberto(doc.body || doc);
    return aberto ? aberto.id : null;
}

/** Link da cronologia que abre o documento de número `id`. */
function localizarLink(doc, id) {
    const M = cfg.PJE_SELECTORS.movimentacoes;
    const container = dom.porSeletores(doc, M.container.css) || doc;
    const D = M.campos.documentos;
    for (const link of container.querySelectorAll(D.link.join(', '))) {
        const rotulo = dom.texto(dom.porSeletores(link, D.rotulo) || link) || '';
        if (raiz.GJ.pjeExtractor.partirDocumento(rotulo).id === id) return link;
        const irmao = link.nextElementSibling;
        if (irmao && irmao.matches(D.copiarId) && norm.argumentoCopiar(irmao.getAttribute('onclick')) === id) {
            return link;
        }
    }
    return null;
}

/** Texto visível de um documento, sem scripts nem estilos. */
function textoDoCorpo(docInterno) {
    if (!docInterno || !docInterno.body) return null;
    const copia = docInterno.body.cloneNode(true);
    copia.querySelectorAll('script, style, noscript, template').forEach(function (el) { el.remove(); });
    // innerText só respeita a formatação (e omite scripts) em documento
    // renderizado — o do iframe. Documento de DOMParser: texto da cópia limpa.
    let bruto = docInterno.defaultView ? docInterno.body.innerText : null;
    if (bruto == null || String(bruto).trim() === '') bruto = copia.textContent;
    const texto = String(bruto || '')
        .replace(/ /g, ' ')
        .replace(/[ \t]+\n/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
    return texto === '' ? null : texto;
}

function ehPdf(docInterno) {
    const D = cfg.PJE_SELECTORS.documentoAberto;
    return !!(docInterno && docInterno.querySelector && docInterno.querySelector(D.visualizadorPdf)) ||
           /pdf/i.test((docInterno && docInterno.contentType) || '');
}

/**
 * Lê o conteúdo do iframe do painel. Preferência: o próprio contentDocument
 * (mesma origem). Sem acesso a ele, a mesma URL é lida por GET de mesma
 * origem — a requisição que o navegador do usuário já faria ao exibi-lo.
 *
 * @returns {Promise<{texto: ?string, metodo: string}>}
 */
async function lerIframePadrao(iframe) {
    let interno = null;
    try { interno = iframe.contentDocument; } catch (e) { interno = null; }

    if (interno && interno.body) {
        if (ehPdf(interno)) return { texto: null, metodo: METODO.PDF_SEM_TEXTO };
        const texto = textoDoCorpo(interno);
        if (texto) return { texto: texto, metodo: METODO.IFRAME_HTML };
    }

    const src = iframe.getAttribute('src');
    if (!src) return { texto: null, metodo: METODO.FALHA };

    let url;
    try { url = new URL(src, iframe.ownerDocument.baseURI); } catch (e) { return { texto: null, metodo: METODO.FALHA }; }
    if (url.origin !== iframe.ownerDocument.defaultView.location.origin) {
        return { texto: null, metodo: METODO.FALHA };
    }

    const controle = new AbortController();
    const cronometro = setTimeout(function () { controle.abort(); }, 20000);
    try {
        const resposta = await fetch(url.href, { credentials: 'same-origin', signal: controle.signal });
        if (!resposta.ok) return { texto: null, metodo: METODO.FALHA };
        const tipo = resposta.headers.get('content-type') || '';
        if (/pdf/i.test(tipo)) return { texto: null, metodo: METODO.PDF_SEM_TEXTO };
        const html = await resposta.text();
        const parseado = new DOMParser().parseFromString(html, 'text/html');
        if (ehPdf(parseado)) return { texto: null, metodo: METODO.PDF_SEM_TEXTO };
        const texto = textoDoCorpo(parseado);
        return texto ? { texto: texto, metodo: METODO.FETCH_HTML } : { texto: null, metodo: METODO.FALHA };
    } catch (e) {
        return { texto: null, metodo: METODO.FALHA };
    } finally {
        clearTimeout(cronometro);
    }
}

/** Assinatura do iframe atual, para perceber a troca de documento. */
function assinaturaIframe(iframe) {
    if (!iframe) return null;
    let texto = '';
    try {
        const d = iframe.contentDocument;
        texto = d && d.body ? String(d.body.textContent || '').slice(0, 200) : '';
    } catch (e) { texto = ''; }
    return { el: iframe, src: iframe.getAttribute('src') || '', texto: texto };
}

/**
 * Espera o iframe refletir o documento `id` e terminar de carregar.
 *
 * Troca de documento: src que cita o número, elemento novo ou src diferente
 * do anterior. Carregamento: o contentDocument precisa ser o da URL nova —
 * logo após a troca do src ele ainda é o documento anterior, já "complete".
 */
async function aguardarIframe(doc, id, anterior, timeoutMs) {
    const D = cfg.PJE_SELECTORS.documentoAberto;
    return aguardar(function () {
        const iframe = dom.porSeletores(doc, D.iframe.css);
        if (!iframe) return null;
        const src = iframe.getAttribute('src') || '';
        const trocou = src.indexOf(id) >= 0 ||
                       !anterior || iframe !== anterior.el || src !== anterior.src;
        if (!trocou) return null;

        let d = null;
        try { d = iframe.contentDocument; } catch (e) { return iframe; } // origem diferente: segue para o fetch
        if (!d) return iframe;
        if (d.readyState !== 'complete' || !d.body) return null;

        if (src) {
            let esperado = src;
            try { esperado = new URL(src, doc.baseURI).href; } catch (e) { /* mantém o bruto */ }
            if (d.URL !== esperado && d.URL.indexOf(id) < 0) return null;
        } else if (anterior && String(d.body.textContent || '').slice(0, 200) === anterior.texto) {
            return null; // conteúdo escrito por script: espera o texto mudar
        }
        return iframe;
    }, timeoutMs, 200);
}

/**
 * Preenche `integra`, `metodoExtracao`, `juntadoPor` e `dataJuntada` dos
 * documentos classificados como peça decisória em `resultado.data.documentos`.
 *
 * @param {Document} doc
 * @param {object} resultado contrato devolvido por pjeExtractor.extrair()
 * @param {{progresso?: function, lerConteudo?: function, limite?: number,
 *          timeoutPainelMs?: number, timeoutIframeMs?: number}} opcoes
 *        `lerConteudo(iframe)` substitui a leitura padrão (usado nos testes).
 */
async function extrairIntegras(doc, resultado, opcoes) {
    opcoes = opcoes || {};
    const I = cfg.PJE_SELECTORS.integra;
    const D = cfg.PJE_SELECTORS.documentoAberto;
    const progresso = opcoes.progresso || function () {};
    const lerConteudo = opcoes.lerConteudo || lerIframePadrao;
    const dados = resultado.data;
    const avisos = resultado.warnings;

    dados.integraSolicitada = true;

    if (dados.segredoJustica === true) {
        avisos.push('Processo em segredo de justiça: a íntegra dos documentos não foi extraída ' +
                    '(apenas os metadados). Consulte as peças diretamente no PJe.');
        return;
    }

    const limite = opcoes.limite || I.limite;
    const candidatos = (dados.documentos || []).filter(function (d) { return !!d.classificacao; });
    const alvos = candidatos.slice(0, limite);
    if (candidatos.length > limite) {
        avisos.push('Foram lidas as ' + limite + ' peças decisórias mais recentes, de ' + candidatos.length +
                    '. As demais ficaram apenas com os metadados.');
    }
    if (alvos.length === 0) return;

    let lidos = 0;
    const problemas = { pdf: 0, falha: 0, ausente: 0 };

    for (let i = 0; i < alvos.length; i++) {
        const alvo = alvos[i];
        progresso('Lendo a íntegra: ' + (alvo.tipo || 'documento') + ' ' + alvo.id +
                  ' (' + (i + 1) + ' de ' + alvos.length + ')…');

        const link = localizarLink(doc, alvo.id);
        const jaAberto = idNoPainel(doc) === alvo.id;
        if (!link && !jaAberto) {
            alvo.metodoExtracao = METODO.NAO_LOCALIZADO;
            problemas.ausente++;
            continue;
        }

        const iframeAntes = assinaturaIframe(dom.porSeletores(doc, D.iframe.css));
        if (!jaAberto) {
            link.click();
            const abriu = await aguardar(function () { return idNoPainel(doc) === alvo.id; },
                                         opcoes.timeoutPainelMs || I.timeoutPainelMs);
            if (!abriu) {
                alvo.metodoExtracao = METODO.FALHA;
                problemas.falha++;
                continue;
            }
        }

        const aberto = raiz.GJ.pjeExtractor.extrairDocumentoAberto(doc.body || doc) || {};
        alvo.juntadoPor = aberto.juntadoPor || null;
        alvo.dataJuntada = aberto.dataJuntada || null;

        const iframe = await aguardarIframe(doc, alvo.id, jaAberto ? null : iframeAntes,
                                            opcoes.timeoutIframeMs || I.timeoutIframeMs);
        if (!iframe) {
            alvo.metodoExtracao = METODO.FALHA;
            problemas.falha++;
            continue;
        }

        const leitura = await lerConteudo(iframe);
        alvo.metodoExtracao = leitura.metodo;
        if (leitura.texto) {
            alvo.integra = leitura.texto.length > I.limiteCaracteres
                ? leitura.texto.slice(0, I.limiteCaracteres)
                : leitura.texto;
            lidos++;
        } else if (leitura.metodo === METODO.PDF_SEM_TEXTO) {
            problemas.pdf++;
        } else {
            problemas.falha++;
        }
    }

    if (problemas.pdf) {
        avisos.push(problemas.pdf + ' peça(s) decisória(s) estão em PDF sem texto legível pelo navegador — ' +
                    'ficaram registradas sem a íntegra.');
    }
    if (problemas.ausente) {
        avisos.push(problemas.ausente + ' peça(s) não foram localizadas na cronologia carregada — ' +
                    'carregue a cronologia completa para lê-las.');
    }
    if (problemas.falha) {
        avisos.push('O PJe não exibiu ' + problemas.falha + ' peça(s) a tempo; refaça a extração para tentar de novo.');
    }
    progresso('Íntegra lida de ' + lidos + ' de ' + alvos.length + ' peça(s) decisória(s).');
}

const API = { carregarCronologiaCompleta, extrairIntegras, textoDoCorpo, METODO };
raiz.GJ.pjeDocumentos = API;
if (typeof module !== 'undefined' && module.exports) { module.exports = API; }

})(typeof globalThis !== 'undefined' ? globalThis : self);
