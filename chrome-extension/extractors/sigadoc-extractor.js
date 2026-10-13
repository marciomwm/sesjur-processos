/**
 * Extrator do SIGADOC — Processo Administrativo.
 *
 *   DOM SIGADOC → SIGADOC Extractor → objeto normalizado → aplicação
 *
 * O extrator é desacoplado: mudanças no DOM externo exigem ajuste apenas
 * em config/selectors.js, nunca no banco ou no módulo de cadastro.
 */
(function (raiz) {
'use strict';

const dom  = raiz.GJ.dom;
const norm = raiz.GJ.normalizers;
const cfg  = raiz.GJ.selectors;

const ORIGEM = 'SIGADOC';

function falha(codigo, mensagem, detalhes) {
    const e = { success: false, source: ORIGEM, error: { code: codigo, message: mensagem } };
    if (detalhes) e.error.details = detalhes;
    return e;
}

/** Heurística conservadora para sessão expirada / tela de login. */
function pareceTelaDeLogin(doc) {
    if (doc.querySelector('input[type="password"]')) return true;
    const texto = (doc.body && (doc.body.innerText || doc.body.textContent) || '').toLowerCase();
    return /sess[aã]o (expirada|encerrada)|fa[çc]a login|autentica[çc][ãa]o necess[áa]ria|acesso negado/.test(texto);
}

/* --------------------------------------------------------------------------
 * SITUAÇÃO, RESPONSÁVEL E MOVIMENTAÇÕES
 * ------------------------------------------------------------------------ */

const PADRAO_SIGLA_DOC = /\b[A-Z0-9]{2,20}-[A-Z]{2,10}-\d{4}\/\d{1,10}(?:-[A-Z0-9]{1,5})?\b/;

/** Tipos de evento, do mais específico ao mais genérico (prefixo do texto). */
const TIPOS_EVENTO = [
    ['dessobrest', 'DESSOBRESTAMENTO'],
    ['sobrest', 'SOBRESTAMENTO'],
    ['desarquiv', 'DESARQUIVAMENTO'],
    ['arquiv', 'ARQUIVAMENTO'],
    ['desapens', 'DESAPENSACAO'],
    ['apens', 'APENSACAO'],
    ['desentranh', 'DESENTRANHAMENTO'],
    ['juntada', 'JUNTADA'],
    ['anota', 'ANOTACAO'],
    ['transfer', 'TRANSFERENCIA'],
    ['tramit', 'TRANSFERENCIA'],
    ['recebimento', 'RECEBIMENTO'],
    ['cria', 'CRIACAO']
];

function tipoEvento(textoEvento) {
    const k = dom.chave(textoEvento);
    for (const [prefixo, codigo] of TIPOS_EVENTO) {
        if (k.indexOf(prefixo) === 0) return codigo;
    }
    return 'OUTRO';
}

/** "NOME COMPLETO - SES000000" → {nome, matricula}. */
function pessoaDeTitulo(titulo) {
    const s = norm.texto(titulo);
    if (!s) return { nome: null, matricula: null };
    const m = s.match(/^(.*?)\s+-\s+([A-Z]{2,10}\d{2,})$/);
    return m ? { nome: norm.texto(m[1]), matricula: m[2] } : { nome: s, matricula: null };
}

/** Célula de pessoa: <span title="NOME - MATR">Apelido</span>. */
function lerPessoa(celula) {
    if (!celula) return { nome: null, matricula: null };
    const span = celula.querySelector('[title]');
    const titulo = span && norm.texto(span.getAttribute('title'));
    return titulo ? pessoaDeTitulo(titulo) : { nome: dom.texto(celula), matricula: null };
}

/** Célula de lotação: <span title="UNIDADE JURIDICA">UNIJUR</span>. */
function lerLotacao(celula) {
    if (!celula) return { sigla: null, nome: null };
    const span = celula.querySelector('[title]');
    return {
        sigla: dom.texto(celula),
        nome: span ? norm.texto(span.getAttribute('title')) : null
    };
}

/**
 * Separa os marcadores de "1º Volume - A [x], B [y, [z]]" respeitando os
 * colchetes aninhados. Devolve os nomes, sem o conteúdo entre colchetes.
 */
function marcadoresDe(texto) {
    const s = String(texto || '').replace(/^\s*\d+\s*[ºo°]?\s*volume\s*-\s*/i, '');
    const partes = [];
    let nivel = 0, atual = '';
    for (const c of s) {
        if (c === '[') nivel++;
        if (c === ']') nivel = Math.max(0, nivel - 1);
        if (c === ',' && nivel === 0) { partes.push(atual); atual = ''; continue; }
        atual += c;
    }
    partes.push(atual);
    return partes
        .map(function (p) { return norm.texto(p.replace(/\[[\s\S]*\]/g, '')); })
        .filter(Boolean);
}

const PRIORIDADE_SITUACAO = ['SOBRESTADO', 'ARQUIVADO', 'AGUARDANDO_ANDAMENTO', 'EM_TRANSITO', 'OUTRA'];

/** Situação do volume atual: {codigo, descricao, textoOriginal}. */
function extrairSituacao(doc, linhaVolume) {
    const S = cfg.SIGADOC_SELECTORS.situacao;
    const el = dom.porSeletores(doc, S.css);
    let bruto = dom.texto(el);
    let marcadores = marcadoresDe(bruto);

    if (marcadores.length === 0 && linhaVolume && linhaVolume.situacao) {
        bruto = linhaVolume.situacao;
        marcadores = [linhaVolume.situacao];
    }
    if (marcadores.length === 0) return null;

    // Entre vários marcadores prevalece o mais restritivo (ex.: Sobrestado
    // sobre Aguardando Andamento), preservando o rótulo oficial.
    const classificados = marcadores.map(function (m) {
        return { codigo: norm.situacaoAdministrativa(m), descricao: m };
    });
    classificados.sort(function (a, b) {
        return PRIORIDADE_SITUACAO.indexOf(a.codigo) - PRIORIDADE_SITUACAO.indexOf(b.codigo);
    });

    return {
        codigo: classificados[0].codigo,
        descricao: classificados[0].descricao,
        marcadores: Array.from(new Set(marcadores)),
        textoOriginal: bruto
    };
}

/** Última linha "Vnn" da tabela de volumes (volume corrente). */
function lerVolumeAtual(doc) {
    const S = cfg.SIGADOC_SELECTORS.volumes;
    const tabela = dom.porSeletores(doc, S.css);
    if (!tabela) return null;
    const col = S.colunas;
    let atual = null;
    for (const tr of tabela.querySelectorAll('tr')) {
        const celulas = tr.children;
        if (celulas.length <= col.lotacao) continue;
        const volume = dom.texto(celulas[col.volume]);
        if (!volume || !/^v\d+/i.test(volume)) continue;
        atual = {
            volume: volume,
            situacao: dom.texto(celulas[col.situacao]),
            pessoa: lerPessoa(celulas[col.pessoa]),
            lotacao: lerLotacao(celulas[col.lotacao])
        };
    }
    return atual;
}

/**
 * Agente público com o processo: volume corrente → spans da situação →
 * atendente da movimentação mais recente.
 */
function extrairResponsavel(doc, linhaVolume, eventos) {
    if (linhaVolume && linhaVolume.pessoa.nome) {
        return {
            nome: linhaVolume.pessoa.nome,
            matricula: linhaVolume.pessoa.matricula,
            lotacao: linhaVolume.lotacao.sigla,
            lotacaoNome: linhaVolume.lotacao.nome,
            estrategia: 'volumes'
        };
    }

    const sit = dom.porSeletores(doc, cfg.SIGADOC_SELECTORS.situacao.css);
    const span = sit && sit.querySelector('span[title]');
    if (span) {
        const lot = (dom.texto(sit) || '').match(/\[\s*([A-Z0-9]{2,20})\s*,\s*\[/);
        return {
            nome: norm.texto(span.getAttribute('title')),
            matricula: dom.texto(span),
            lotacao: lot ? lot[1] : null,
            lotacaoNome: null,
            estrategia: 'situacao'
        };
    }

    const recente = (eventos || []).find(function (e) { return e.atendente; });
    if (recente) {
        return {
            nome: recente.atendente,
            matricula: recente.atendenteMatricula,
            lotacao: recente.atendenteLotacao,
            lotacaoNome: null,
            estrategia: 'movimentacao'
        };
    }
    return null;
}

/** Texto da descrição sem botões/ações; separa a sigla do documento juntado. */
function lerDescricao(celula) {
    if (!celula) return { descricao: null, documento: null };
    const copia = celula.cloneNode(true);
    copia.querySelectorAll(cfg.SIGADOC_SELECTORS.eventos.ruidoDescricao).forEach(function (n) { n.remove(); });

    let documento = null;
    for (const a of copia.querySelectorAll('a')) {
        const m = (dom.limpar(a.textContent) || '').match(PADRAO_SIGLA_DOC);
        if (m) { documento = m[0]; break; }
    }

    let descricao = dom.limpar(copia.textContent);
    if (descricao) {
        descricao = descricao.replace(/^[\s|]+|[\s|]+$/g, '');
        if (documento && descricao.indexOf(documento) === 0) {
            descricao = descricao.slice(documento.length).replace(/^[\s|:\-–]+/, '');
        }
    }
    return { descricao: norm.texto(descricao), documento: documento };
}

function cabecalhoConfere(tabela, esperado) {
    const cab = dom.chave(tabela.tHead ? tabela.tHead.textContent : '');
    return esperado.every(function (r) { return cab.indexOf(dom.chave(r)) >= 0; });
}

/** Cabeçalho (h2–h4) imediatamente anterior à tabela, se houver. */
function tituloAnterior(tabela) {
    let el = tabela.previousElementSibling;
    while (el && !/^H[2-4]$/.test(el.tagName)) el = el.previousElementSibling;
    return el ? dom.texto(el) : null;
}

/** Visão completa (table.mov): todos os tipos de evento, cadastrante e atendente. */
function eventosCompletos(doc) {
    const E = cfg.SIGADOC_SELECTORS.eventos;
    const col = E.completa.colunas;
    let tabelas = dom.todosPorSeletores(doc, E.completa.css)
        .filter(function (t) { return cabecalhoConfere(t, E.completa.cabecalhoEsperado); });
    if (tabelas.length === 0) return null;

    const volumes = tabelas.filter(function (t) { return !E.cabecalhoIgnorado.test(tituloAnterior(t) || ''); });
    if (volumes.length) tabelas = volumes;

    const eventos = [];
    for (const tabela of tabelas) {
        let dataCorrente = null;
        const corpo = tabela.tBodies[0] || tabela;
        for (const tr of corpo.querySelectorAll(':scope > tr')) {
            const c = tr.children;
            if (c.length <= col.descricao) continue;

            const dataLida = norm.dataIsoAnoCurto(dom.texto(c[col.data]));
            if (dataLida) dataCorrente = dataLida; // datas vazias herdam a da linha anterior

            const textoEvento = dom.texto(c[col.evento]);
            if (!textoEvento) continue;

            const cadLot = lerLotacao(c[col.cadLotacao]);
            const cadPes = lerPessoa(c[col.cadPessoa]);
            const atLot = lerLotacao(c[col.atLotacao]);
            const atPes = lerPessoa(c[col.atPessoa]);
            const desc = lerDescricao(c[col.descricao]);

            eventos.push({
                data: dataCorrente,
                tipo: tipoEvento(textoEvento),
                tipoDescricao: textoEvento,
                lotacao: cadLot.sigla,
                responsavel: cadPes.nome,
                responsavelMatricula: cadPes.matricula,
                atendenteLotacao: atLot.sigla,
                atendente: atPes.nome,
                atendenteMatricula: atPes.matricula,
                descricao: desc.descricao,
                documento: desc.documento
            });
            if (eventos.length >= E.limite) return eventos;
        }
    }
    return eventos;
}

/** Visão resumida (padrão): apenas juntadas e anotações. */
function eventosResumidos(doc) {
    const E = cfg.SIGADOC_SELECTORS.eventos;
    const col = E.resumida.colunas;
    const tabela = dom.todosPorSeletores(doc, E.resumida.css)
        .find(function (t) { return cabecalhoConfere(t, E.resumida.cabecalhoEsperado); });
    if (!tabela) return null;

    const eventos = [];
    let dataCorrente = null;
    for (const tr of (tabela.tBodies[0] || tabela).querySelectorAll(':scope > tr')) {
        const c = tr.children;
        if (c.length <= col.descricao) continue;
        const dataLida = norm.dataIsoAnoCurto(dom.texto(c[col.data]));
        if (dataLida) dataCorrente = dataLida;
        const textoEvento = dom.texto(c[col.evento]);
        if (!textoEvento) continue;

        // <td title="NOME - LOTAÇÃO POR EXTENSO">SIGLA</td>
        const titulo = norm.texto(c[col.lotacao].getAttribute('title'));
        const desc = lerDescricao(c[col.descricao]);
        eventos.push({
            data: dataCorrente,
            tipo: tipoEvento(textoEvento),
            tipoDescricao: textoEvento,
            lotacao: dom.texto(c[col.lotacao]),
            responsavel: titulo ? norm.texto(titulo.split(' - ')[0]) : null,
            responsavelMatricula: null,
            atendenteLotacao: null,
            atendente: null,
            atendenteMatricula: null,
            descricao: desc.descricao,
            documento: desc.documento
        });
        if (eventos.length >= E.limite) break;
    }
    return eventos;
}

/**
 * URL da visão completa ("Ver Mais"), apenas se for da MESMA origem da
 * página — o collector só busca endereços do próprio SIGADOC.
 */
function urlHistoricoCompleto(doc, urlBase) {
    const link = dom.porSeletores(doc, cfg.SIGADOC_SELECTORS.eventos.linkCompleta.css);
    const href = link && link.getAttribute('href');
    if (!href || /^\s*(#|javascript:)/i.test(href)) return null;
    try {
        const base = new URL(urlBase);
        const alvo = new URL(href, base);
        return alvo.origin === base.origin ? alvo.href : null;
    } catch (e) {
        return null;
    }
}

/**
 * @param {Document} doc
 * @param {{protocoloEsperado: string, url?: string, documentoCompleto?: Document}} opcoes
 *        documentoCompleto: a página "Ver Mais" já carregada pelo collector.
 * @returns {object} contrato de dados do SIGADOC
 */
function extrair(doc, opcoes) {
    opcoes = opcoes || {};
    const avisos = [];
    const raizEl = doc.body || doc.documentElement;
    const S = cfg.SIGADOC_SELECTORS;

    if (!raizEl) {
        return falha('SELECTORS_MISSING', 'A página não pôde ser lida.');
    }

    if (!cfg.seletoresConfigurados(S)) {
        avisos.push('Seletores CSS do SIGADOC ainda não mapeados; usando estratégia por rótulo.');
    }

    // Escopo do documento renderizado. Restringir a busca por rótulo a ele
    // evita colidir com a barra lateral (Volumes, Documentos Relacionados,
    // Tramitação), que também exibe siglas de processo.
    const escopo = dom.porSeletores(raizEl, S._containerDocumento.css) || raizEl;
    if (escopo === raizEl) {
        avisos.push('Container do documento não localizado; a busca foi feita na página inteira.');
    }

    // 1. IDENTIFICADOR — sempre o primeiro campo extraído.
    //    O cabeçalho (<h2>) fica fora do container, por isso é resolvido no
    //    escopo da página; o rótulo "Processo Nº" é buscado dentro do documento.
    let resProtocolo = dom.resolverCampo(raizEl, { css: S.protocolo.css, padrao: S.protocolo.padrao });
    if (!resProtocolo.valor) {
        resProtocolo = dom.resolverCampo(escopo, S.protocolo);
    }
    let protocolo = norm.protocolo(resProtocolo.valor);

    if (!protocolo && opcoes.protocoloEsperado) {
        // Tentativa dirigida: o protocolo solicitado consta na página?
        const alvo = norm.protocolo(opcoes.protocoloEsperado);
        const conteudo = raizEl.innerText || raizEl.textContent || '';
        if (alvo && norm.protocolo(conteudo || '').indexOf(alvo) >= 0) {
            protocolo = alvo;
            avisos.push('Protocolo localizado por busca textual na página.');
        }
    }

    if (!protocolo) {
        if (pareceTelaDeLogin(doc)) {
            return falha('SESSION_REQUIRED',
                'Não foi possível acessar o processo. Verifique se você está autenticado no SIGADOC.');
        }
        if (!cfg.seletoresConfigurados(S)) {
            return falha('SELECTORS_NOT_CONFIGURED',
                'Os seletores do SIGADOC ainda não foram mapeados e o protocolo não foi localizado por rótulo. ' +
                'Mapeie config/selectors.js a partir do HTML real.');
        }
        return falha('SELECTORS_MISSING',
            'A página foi localizada, mas não foi possível identificar os campos necessários.');
    }

    // 2. VALIDAÇÃO — protocolo solicitado deve ser igual ao encontrado.
    if (opcoes.protocoloEsperado && !norm.protocolosEquivalentes(opcoes.protocoloEsperado, protocolo)) {
        return falha('IDENTIFIER_MISMATCH',
            'O identificador encontrado na página não corresponde ao processo informado.', {
                solicitado: norm.protocolo(opcoes.protocoloEsperado),
                encontrado: protocolo
            });
    }

    // 3. DEMAIS CAMPOS — ausentes permanecem null, com aviso.
    const resData = dom.resolverCampo(escopo, S.dataAbertura);
    const dataAbertura = norm.dataIso(resData.valor);
    if (resData.valor && !dataAbertura) {
        avisos.push('Data de abertura encontrada em formato não reconhecido: "' + resData.valor + '".');
    } else if (!resData.valor) {
        avisos.push('Campo não encontrado no DOM: dataAbertura.');
    }

    const resObjeto = dom.resolverCampo(escopo, S.objeto);
    const objeto = norm.texto(resObjeto.valor);
    if (!objeto) {
        avisos.push('Campo não encontrado no DOM: objeto.');
    }

    // 4. MOVIMENTAÇÕES — preferência pela visão completa, desde que seja do
    //    MESMO processo (a página "Ver Mais" é conferida pelo cabeçalho).
    let completo = opcoes.documentoCompleto || null;
    if (completo) {
        const siglaCompleto = norm.protocolo(dom.resolverCampo(completo.body || completo,
            { css: S.protocolo.css, padrao: S.protocolo.padrao }).valor);
        if (!norm.protocolosEquivalentes(siglaCompleto, protocolo)) {
            avisos.push('A página de histórico completo não corresponde ao processo e foi descartada.');
            completo = null;
        }
    }

    let eventos = eventosCompletos(doc) || (completo && eventosCompletos(completo));
    let historicoCompleto = !!eventos;
    if (!eventos) {
        eventos = eventosResumidos(doc);
        if (eventos) {
            avisos.push('Histórico parcial: a página exibe apenas juntadas e anotações (visão "Ver Mais" indisponível).');
        } else {
            eventos = [];
            avisos.push('Tabela de movimentações não encontrada no DOM.');
        }
    }

    // 5. SITUAÇÃO E RESPONSÁVEL ATUAL — a visão padrão e a completa trazem
    //    os mesmos blocos; a página aberta tem precedência.
    const linhaVolume = lerVolumeAtual(doc) || (completo && lerVolumeAtual(completo));
    const situacao = extrairSituacao(doc, linhaVolume) || (completo && extrairSituacao(completo, null));
    if (!situacao) {
        avisos.push('Campo não encontrado no DOM: situacao.');
    }
    const responsavel = extrairResponsavel(doc, linhaVolume, eventos);
    if (!responsavel) {
        avisos.push('Campo não encontrado no DOM: responsavel.');
    }

    return {
        success: true,
        source: ORIGEM,
        extractedAt: norm.agoraIso(),
        extractorVersion: cfg.VERSAO_EXTRATOR,
        sourceUrl: opcoes.url || (doc.location ? doc.location.href : null),
        strategies: {
            protocolo: resProtocolo.estrategia,
            dataAbertura: resData.estrategia,
            objeto: resObjeto.estrategia,
            responsavel: responsavel ? responsavel.estrategia : 'nao-encontrado',
            eventos: historicoCompleto ? 'completa' : (eventos.length ? 'resumida' : 'nao-encontrado')
        },
        data: {
            protocolo: protocolo,
            dataAbertura: dataAbertura,
            objeto: objeto,
            situacao: situacao,
            responsavel: responsavel ? {
                nome: responsavel.nome,
                matricula: responsavel.matricula,
                lotacao: responsavel.lotacao,
                lotacaoNome: responsavel.lotacaoNome
            } : null,
            eventos: eventos,
            historicoCompleto: historicoCompleto
        },
        warnings: avisos
    };
}

const API = { extrair, urlHistoricoCompleto, marcadoresDe, tipoEvento, ORIGEM };
raiz.GJ.sigadocExtractor = API;
if (typeof module !== 'undefined' && module.exports) { module.exports = API; }

})(typeof globalThis !== 'undefined' ? globalThis : self);
