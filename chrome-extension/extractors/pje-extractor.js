/**
 * Extrator do PJe — Processo Judicial.
 *
 *   DOM PJe → PJe Extractor → objeto normalizado → aplicação
 *
 * Contrato (seção 25): escalares ausentes = null; coleções vazias = [].
 * Nunca fabricar informação.
 *
 * O DOM real (pje.tjmt.jus.br, 1º Grau) oferece duas marcações distintas, e o
 * extrator tenta a estrutural antes da genérica:
 *   estrutural — containers nomeados (#maisDetalhes, #poloAtivo, timeline);
 *   genérica   — rótulo → valor, que também atende outras instâncias do PJe
 *                e as fixtures sintéticas.
 */
(function (raiz) {
'use strict';

const dom  = raiz.GJ.dom;
const norm = raiz.GJ.normalizers;
const cfg  = raiz.GJ.selectors;

const ORIGEM = 'PJE';

function falha(codigo, mensagem, detalhes) {
    const e = { success: false, source: ORIGEM, error: { code: codigo, message: mensagem } };
    if (detalhes) e.error.details = detalhes;
    return e;
}

function pareceTelaDeLogin(doc) {
    if (doc.querySelector('input[type="password"]')) return true;
    const texto = (doc.body && (doc.body.innerText || doc.body.textContent) || '').toLowerCase();
    return /sess[aã]o (expirada|encerrada)|fa[çc]a login|autentica[çc][ãa]o necess[áa]ria|acesso negado|certificado digital/.test(texto);
}

/**
 * Escopo de leitura dos campos cadastrais. No PJe real é a <dl> de detalhes:
 * restringir a busca evita que rótulos como "Assunto" ou "Competência" casem
 * com o texto do documento aberto no visualizador à direita. Sem o container
 * (fixtures sintéticas, outras instâncias), vale a página inteira.
 */
function escopoDetalhes(raizEl) {
    return dom.porSeletores(raizEl, cfg.PJE_SELECTORS._containerDetalhes.css) || raizEl;
}

/** Lê um campo escalar e registra aviso quando ausente. */
function escalar(raizEl, descricao, nome, avisos, conversor) {
    const res = dom.resolverCampo(raizEl, descricao);
    if (!res.valor) {
        avisos.push('Campo não encontrado no DOM: ' + nome + '.');
        return { valor: null, estrategia: res.estrategia };
    }
    const convertido = conversor ? conversor(res.valor) : norm.texto(res.valor);
    if (convertido == null) {
        avisos.push('Campo "' + nome + '" encontrado em formato não reconhecido: "' + res.valor + '".');
    }
    return { valor: convertido, estrategia: res.estrategia };
}

/** Booleano tri-estado: preenchido apenas quando explicitamente exibido. */
function booleano(raizEl, descricao) {
    const res = dom.resolverCampo(raizEl, descricao);
    return res.valor ? norm.booleanoOuNulo(res.valor) : null;
}

/** Valor de atributo, com fallback entre nomes (title → data-original-title). */
function atributo(el, nomes) {
    if (!el) return null;
    for (const nome of nomes) {
        const v = norm.texto(el.getAttribute(nome));
        if (v) return v;
    }
    return null;
}

/**
 * Número CNJ. Preferência: o onclick do ícone "copiar" do cabeçalho, que traz
 * o número limpo; depois o texto do título, rótulo ou padrão na página.
 */
function resolverCnj(raizEl) {
    const S = cfg.PJE_SELECTORS;
    const icone = dom.porSeletores(raizEl, S.numeroCopiavel.css);
    const doOnclick = dom.aplicarPadrao(norm.argumentoCopiar(icone && icone.getAttribute(S.numeroCopiavel.atributo)),
                                        S.numeroProcesso.padrao);
    if (doOnclick) return { valor: doOnclick, estrategia: 'onclick' };
    return dom.resolverCampo(raizEl, S.numeroProcesso);
}

/**
 * Cabeçalho do topo: sigla da classe ("ProceComCiv") e resumo das partes
 * ("FULANO e outros X MUNICÍPIO e outros"). Ambos opcionais.
 */
function extrairCabecalho(raizEl) {
    const C = cfg.PJE_SELECTORS.cabecalho;
    const titulo = dom.porSeletores(raizEl, C.css);
    if (!titulo) return { classeAbreviada: null, resumoPartes: null };

    // A sigla está no primeiro nó de texto, antes do número.
    let primeiroTexto = '';
    for (const no of titulo.childNodes) {
        if (no.nodeType === 3 && no.nodeValue.trim()) { primeiroTexto = no.nodeValue; break; }
    }
    const sigla = (primeiroTexto || dom.texto(titulo) || '').match(C.classeAbreviada);

    return {
        classeAbreviada: sigla ? norm.texto(sigla[1]) : null,
        resumoPartes: norm.texto(dom.texto(dom.porSeletores(titulo, C.resumoPartes.css)))
    };
}

/** <dd> associado ao <dt> cujo texto casa com um dos rótulos. */
function ddDoRotulo(escopo, rotulos) {
    const alvos = (rotulos || []).map(dom.chave);
    for (const dt of escopo.querySelectorAll('dt')) {
        if (alvos.indexOf(dom.chave(dt.textContent)) < 0) continue;
        let irmao = dt.nextElementSibling;
        while (irmao && irmao.tagName !== 'DD') irmao = irmao.nextElementSibling;
        if (irmao) return irmao;
    }
    return null;
}

/**
 * Assuntos. No PJe real o <dd> do rótulo "Assunto" traz um <li> por assunto,
 * no formato "Descrição (código)", o primeiro sendo o principal. Sem essa
 * estrutura, cai para os rótulos "Assunto principal" / "Assuntos secundários".
 *
 * @returns {{principal: ?string, codigoPrincipal: ?string, secundarios: Array}}
 */
function extrairAssuntos(escopo, raizEl) {
    const A = cfg.PJE_SELECTORS.assuntos;
    const lista = [];

    const dd = ddDoRotulo(escopo, A.rotulo);
    if (dd) {
        const itens = dom.todosPorSeletores(dd, A.itens);
        const textos = itens.length ? itens.map(dom.texto) : [dom.texto(dd)];
        textos.forEach(function (t) {
            const parte = norm.descricaoComCodigo(t);
            if (parte.descricao) lista.push(parte);
        });
    }

    if (lista.length) {
        return {
            principal: lista[0].descricao,
            codigoPrincipal: lista[0].codigo,
            secundarios: lista.slice(1)
        };
    }

    // Fallback genérico: rótulos separados para principal e secundários.
    const principal = norm.texto(dom.resolverCampo(raizEl, {
        css: [], labels: ['Assunto principal', 'Assunto']
    }).valor);

    const secundarios = [];
    const bruto = dom.porRotulo(raizEl, A.labels);
    if (bruto) {
        bruto.split(/[;\n]|\s{2,}/).forEach(function (pedaco) {
            const parte = norm.descricaoComCodigo(pedaco);
            if (parte.descricao) secundarios.push(parte);
        });
    }

    const vistos = new Set(principal ? [principal.toLowerCase()] : []);
    return {
        principal: principal,
        codigoPrincipal: principal ? norm.descricaoComCodigo(principal).codigo : null,
        secundarios: secundarios.filter(function (a) {
            const k = a.descricao.toLowerCase();
            if (vistos.has(k)) return false;
            vistos.add(k);
            return true;
        })
    };
}

/**
 * Partes processuais.
 * Estratégia 1: um container por polo (#poloAtivo / #poloPassivo). O polo vem
 *               do container, não de heurística de cabeçalho.
 * Estratégia 2: tabela cujo cabeçalho contenha "Nome"/"Parte".
 * Sem correspondência → [] (nunca uma parte inventada).
 */
function extrairPartes(raizEl, avisos) {
    const P = cfg.PJE_SELECTORS.partes;
    const partes = [];
    let algumContainer = false;

    // Estratégia 1 — containers por polo.
    for (const grupo of (P.grupos || [])) {
        const container = dom.porSeletores(raizEl, grupo.css);
        if (!container) continue;
        algumContainer = true;

        for (const item of dom.todosPorSeletores(container, P.itens)) {
            const bruto = dom.texto(dom.porSeletores(item, P.campos.identificacao.css));
            const id = norm.identificacaoParte(bruto);
            if (!id.nome) continue;

            const representantes = extrairRepresentantes(item, P.campos.representantes);

            partes.push({
                nome: id.nome,
                documento: id.documento,
                polo: grupo.polo,
                tipo: id.tipo,
                representantes: representantes,
                representacao: representantes.length
                    ? representantes.map(function (r) { return r.tipo ? r.tipo + ': ' + r.nome : r.nome; }).join('; ')
                    : null
            });
        }
    }
    if (partes.length) return partes;

    // Estratégia 2 — tabela com cabeçalho reconhecível.
    for (const tabela of raizEl.querySelectorAll('table')) {
        const cabecalhos = Array.from(tabela.querySelectorAll('thead th, tr:first-child th'))
            .map(function (th) { return dom.chave(th.textContent); });
        if (cabecalhos.length === 0) continue;

        const iNome = cabecalhos.findIndex(function (c) { return c === 'nome' || c === 'parte' || c === 'nome da parte'; });
        if (iNome < 0) continue;

        const iDoc  = cabecalhos.findIndex(function (c) { return ['cpf', 'cnpj', 'cpf/cnpj', 'documento'].indexOf(c) >= 0; });
        const iTipo = cabecalhos.findIndex(function (c) { return ['tipo', 'participacao', 'qualificacao'].indexOf(c) >= 0; });
        const iRep  = cabecalhos.findIndex(function (c) { return ['advogado', 'representante', 'procurador', 'advogados'].indexOf(c) >= 0; });
        const iPolo = cabecalhos.findIndex(function (c) { return c === 'polo'; });

        const linhas = tabela.querySelectorAll('tbody tr');
        for (const linha of linhas) {
            const celulas = linha.children;
            const nome = dom.texto(celulas[iNome]);
            if (!nome) continue;
            partes.push({
                nome: nome,
                documento: iDoc >= 0 ? norm.documento(dom.texto(celulas[iDoc])) : null,
                polo: norm.polo(iPolo >= 0 ? dom.texto(celulas[iPolo]) : (poloDoContexto(tabela, P.polos) || '')),
                tipo: iTipo >= 0 ? norm.texto(dom.texto(celulas[iTipo])) : null,
                representacao: iRep >= 0 ? norm.texto(dom.texto(celulas[iRep])) : null
            });
        }
    }

    if (partes.length === 0) {
        // Sem os containers de polo, a causa é a mesma do bloco de detalhes
        // ausente — já relatada uma vez em extrair(); não repetir aqui.
        if (algumContainer) {
            avisos.push('Os quadros de partes existem na página, mas nenhuma parte pôde ser lida neles.');
        }
    }
    return partes;
}

/**
 * Representantes de uma parte: um <li> por advogado/órgão. O tipo
 * ("Defensoria", "Procuradoria", "Advogado") vem do title do ícone; o nome,
 * do texto do <span>. Quando o tipo repete o nome, fica só o nome.
 *
 * @returns {Array<{tipo: ?string, nome: string}>}
 */
function extrairRepresentantes(item, R) {
    const lista = [];
    for (const li of dom.todosPorSeletores(item, R.item)) {
        const nome = norm.texto(dom.texto(dom.porSeletores(li, R.nome)));
        if (!nome) continue;
        let tipo = null;
        for (const css of R.tipo) {
            tipo = atributo(li.querySelector(css), R.atributosTipo);
            if (tipo) break;
        }
        lista.push({ tipo: tipo && dom.chave(tipo) !== dom.chave(nome) ? tipo : null, nome: nome });
    }
    return lista;
}

/** Procura, entre os ancestrais, um cabeçalho que indique o polo. */
function poloDoContexto(el, mapaPolos) {
    if (!mapaPolos) return null;
    let atual = el;
    let saltos = 0;
    while (atual && saltos < 6) {
        const anterior = atual.previousElementSibling;
        if (anterior) {
            const t = dom.chave(anterior.textContent || '');
            for (const polo of Object.keys(mapaPolos)) {
                if (mapaPolos[polo].some(function (r) { return t.indexOf(dom.chave(r)) >= 0; })) {
                    return polo;
                }
            }
        }
        const rotulo = atual.getAttribute && (atual.getAttribute('aria-label') || atual.getAttribute('data-polo'));
        if (rotulo) {
            for (const polo of Object.keys(mapaPolos)) {
                if (mapaPolos[polo].some(function (r) { return dom.chave(rotulo).indexOf(dom.chave(r)) >= 0; })) {
                    return polo;
                }
            }
        }
        atual = atual.parentElement;
        saltos++;
    }
    return null;
}

/** "249562011 - Sentença" → id e tipo do documento juntado. */
function partirDocumento(valor) {
    const s = norm.texto(valor);
    if (!s) return { id: null, tipo: null, titulo: null };
    const m = s.match(/^(\d{4,})\s*[-–]\s*(.+)$/);
    return m
        ? { id: m[1], tipo: norm.texto(m[2]), titulo: s }
        : { id: null, tipo: s, titulo: s };
}

/**
 * Cronologia do processo: movimentações e documentos juntados.
 *
 * A lista alterna separadores de data e itens; a data do separador vale para
 * os itens seguintes até o próximo separador. Um item pode trazer o texto do
 * movimento, um documento, ou ambos.
 *
 * A timeline é paginada e carregada sob demanda: só o que já está no DOM é
 * lido. `historicoCompleto` informa se a leitura cobriu todo o processo, para
 * que a aplicação não apresente um histórico parcial como se fosse integral.
 *
 * @returns {{lista: Array, historicoCompleto: boolean}}
 */
function extrairMovimentacoes(raizEl, avisos) {
    const M = cfg.PJE_SELECTORS.movimentacoes;
    const container = dom.porSeletores(raizEl, M.container.css);
    if (!container) {
        return { lista: [], historicoCompleto: false };
    }

    const seletorSeparador = M.separadorData.css.join(', ');
    const seletorItem = M.itens.join(', ');
    const nos = container.querySelectorAll(seletorSeparador + ', ' + seletorItem);

    const lista = [];
    let dataAtual = null;

    for (const no of nos) {
        // Separador de data: atualiza o contexto e segue. O marcador de
        // rolagem (.div-data-rolagem) é vazio e não muda a data corrente.
        if (no.matches(seletorSeparador)) {
            if (M.separadorData.ignorar && no.matches(M.separadorData.ignorar)) continue;
            const texto = dom.texto(dom.porSeletores(no, M.separadorData.valor) || no);
            const data = norm.dataIsoExtensa(texto);
            if (data) dataAtual = data;
            continue;
        }

        const descricao = norm.texto(dom.texto(dom.porSeletores(no, M.campos.descricao.css)));
        const documentos = documentosDoEvento(no, M.campos.documentos);

        // Item sem movimento e sem documento não é informação — é ruído de layout.
        if (!descricao && documentos.length === 0) continue;

        const icone = dom.porSeletores(no, M.campos.natureza.css);
        const primeiro = documentos[0] || null;

        lista.push({
            data: dataAtual,
            hora: norm.horaHm(dom.texto(dom.porSeletores(no, M.campos.hora.css))),
            tipo: documentos.length ? 'DOCUMENTO' : 'MOVIMENTO',
            descricao: descricao,
            documentoId: primeiro ? primeiro.id : null,
            documentoTipo: primeiro ? primeiro.tipo : null,
            documentos: documentos,
            natureza: icone ? norm.texto(icone.getAttribute(M.campos.natureza.atributo)) : null,
            origem: origemDoEvento(dom.porSeletores(no, M.campos.origem.css), M.origens)
        });

        if (lista.length >= M.limite) {
            avisos.push('A cronologia foi truncada em ' + M.limite + ' movimentações.');
            break;
        }
    }

    const pag = paginacaoCronologia(raizEl);
    const historicoCompleto = lista.length > 0 && pag.completa;

    if (lista.length && !historicoCompleto) {
        avisos.push('A cronologia do PJe é carregada por páginas: foram lidas ' + lista.length +
                    ' movimentações (página ' + pag.atual + ' de ' + pag.total + '). Marque ' +
                    '"Carregar a cronologia completa" ou role a timeline até o fim antes de extrair.');
    }

    return { lista: lista, historicoCompleto: historicoCompleto };
}

/**
 * Estado da paginação da timeline (#paginaAtual / #totalPaginas). Sem os
 * inputs, a página é tratada como única.
 */
function paginacaoCronologia(raizEl) {
    const P = cfg.PJE_SELECTORS.movimentacoes.paginacao;
    const total = Number(dom.texto(dom.porSeletores(raizEl, P.total)) || '1');
    const atual = Number(dom.texto(dom.porSeletores(raizEl, P.atual)) || '1');
    const t = isFinite(total) && total > 0 ? total : 1;
    const a = isFinite(atual) && atual > 0 ? atual : 1;
    return { total: t, atual: a, completa: a >= t };
}

/**
 * Documentos juntados em um evento: um por <a> filho direto de .anexos.
 * O número vem do rótulo ("249562011 - Sentença") ou, na falta, do onclick do
 * ícone .copiar-clipboard que segue o link.
 */
function documentosDoEvento(no, D) {
    const vistos = new Set();
    const lista = [];
    for (const link of dom.todosPorSeletores(no, D.link)) {
        const rotulo = norm.texto(dom.texto(dom.porSeletores(link, D.rotulo) || link));
        const doc = partirDocumento(rotulo);
        if (!doc.titulo) continue;

        if (!doc.id) {
            let irmao = link.nextElementSibling;
            while (irmao && irmao.tagName !== 'A') {
                if (irmao.matches(D.copiarId)) {
                    const id = norm.argumentoCopiar(irmao.getAttribute('onclick'));
                    if (id && /^\d{4,}$/.test(id)) doc.id = id;
                    break;
                }
                irmao = irmao.nextElementSibling;
            }
        }

        const chave = doc.id || doc.titulo;
        if (vistos.has(chave)) continue;
        vistos.add(chave);
        lista.push({ id: doc.id, tipo: doc.tipo, titulo: doc.titulo });
    }
    return lista;
}

/** Código de origem a partir do 2º token da classe do ícone ("icon-auto PP"). */
function origemDoEvento(icone, mapa) {
    if (!icone || !icone.classList) return null;
    const tokens = Array.from(icone.classList);
    const i = tokens.indexOf('icon-auto');
    const token = i >= 0 ? tokens[i + 1] : null;
    return token && mapa[token] ? mapa[token] : null;
}

/**
 * Registro único dos documentos citados na cronologia, na ordem em que
 * aparecem (mais recente primeiro), já classificados. A íntegra é preenchida
 * depois, pelo coletor (extractors/pje-documentos.js), quando solicitada.
 */
function consolidarDocumentos(movimentacoes) {
    const regras = cfg.PJE_SELECTORS.integra.classificacao;
    const vistos = new Set();
    const lista = [];
    movimentacoes.forEach(function (mov, ordem) {
        (mov.documentos || []).forEach(function (d) {
            if (!d.id || vistos.has(d.id)) return;
            vistos.add(d.id);
            lista.push({
                id: d.id,
                tipo: d.tipo,
                data: mov.data,
                hora: mov.hora,
                ordemMovimentacao: ordem,
                classificacao: norm.classificarDocumento(d.tipo, regras),
                juntadoPor: null,
                dataJuntada: null,
                integra: null,
                metodoExtracao: null
            });
        });
    });
    return lista;
}

/** Documento aberto no visualizador — proveniência, não dado do processo. */
function extrairDocumentoAberto(raizEl) {
    const D = cfg.PJE_SELECTORS.documentoAberto;
    const titulo = dom.texto(dom.porSeletores(raizEl, D.titulo.css));
    if (!titulo) return null;
    const doc = partirDocumento(titulo);
    const juntadaBruta = norm.texto(dom.texto(dom.porSeletores(raizEl, D.juntada.css)));
    const juntada = norm.juntadaDocumento(juntadaBruta);
    return {
        id: doc.id,
        tipo: doc.tipo,
        juntada: juntadaBruta,
        juntadoPor: juntada.por,
        dataJuntada: juntada.dataHora,
        posicao: norm.texto(dom.texto(dom.porSeletores(raizEl, D.contador.css)))
    };
}

/**
 * @param {Document} doc
 * @param {{cnjEsperado: string, url?: string}} opcoes
 */
function extrair(doc, opcoes) {
    opcoes = opcoes || {};
    const avisos = [];
    const raizEl = doc.body || doc.documentElement;
    const S = cfg.PJE_SELECTORS;

    if (!raizEl) {
        return falha('SELECTORS_MISSING', 'A página não pôde ser lida.');
    }

    // 1. IDENTIFICADOR primeiro (seção 22).
    const resCnj = resolverCnj(raizEl);
    const cnjEncontrado = norm.numeroCnj(resCnj.valor);

    if (!cnjEncontrado) {
        if (pareceTelaDeLogin(doc)) {
            return falha('SESSION_REQUIRED',
                'Não foi possível acessar o processo. Verifique se você está autenticado no PJe.');
        }
        return falha('SELECTORS_MISSING',
            'A página foi localizada, mas não foi possível identificar os campos necessários.');
    }

    // 2. VALIDAÇÃO: divergiu → interrompe sem preencher nada.
    if (opcoes.cnjEsperado && !norm.cnjEquivalentes(opcoes.cnjEsperado, cnjEncontrado)) {
        return falha('IDENTIFIER_MISMATCH',
            'O identificador encontrado na página não corresponde ao processo informado.', {
                solicitado: norm.numeroCnj(opcoes.cnjEsperado),
                encontrado: cnjEncontrado
            });
    }

    // 3. DEMAIS CAMPOS, lidos no escopo da <dl> de detalhes quando existir.
    //
    // O bloco "Mais detalhes" é a origem de quase todo o cadastro (classe,
    // assunto, jurisdição, datas, valor) e das partes. Quando ele não está na
    // página, um aviso por campo vira ruído — são dez sintomas de uma causa só.
    // Aqui a causa é relatada uma vez, com o que fazer a respeito.
    const containerDetalhes = dom.porSeletores(raizEl, S._containerDetalhes.css);
    const escopo = containerDetalhes || raizEl;
    const avisosCampos = containerDetalhes ? avisos : [];

    if (!containerDetalhes) {
        avisos.push('O bloco "Mais detalhes" não está presente nesta página do PJe — é dele que vêm ' +
            'classe, assunto, jurisdição, datas, valor da causa e as partes. Recarregue a página do ' +
            'processo no PJe (F5) e refaça a extração; se o problema persistir, confirme que a URL ' +
            'informada é a da página de detalhe do processo.');
    }

    // Tribunal não é campo da <dl>: o <title> é o anúncio canônico.
    const tribunalTitulo = dom.aplicarPadrao(norm.texto(doc.title), S.tribunal.padrao);
    const tribunal = tribunalTitulo
        ? { valor: norm.texto(tribunalTitulo), estrategia: 'titulo' }
        : escalar(escopo, S.tribunal, 'tribunal', avisos);

    const orgaoJulgador    = escalar(escopo, S.orgaoJulgador, 'orgaoJulgador', avisosCampos);
    const cargoJudicial    = escalar(escopo, S.cargoJudicial, 'cargoJudicial', avisosCampos);
    const competencia      = escalar(escopo, S.competencia, 'competencia', avisosCampos);
    const jurisdicao       = escalar(escopo, S.jurisdicao, 'jurisdicao', avisosCampos);
    const dataAutuacao     = escalar(escopo, S.dataAutuacao, 'dataAutuacao', avisosCampos, norm.dataIsoExtensa);
    const dataDistribuicao = escalar(escopo, S.dataDistribuicao, 'dataDistribuicao', avisosCampos, norm.dataIsoExtensa);
    const valorCausa       = escalar(escopo, S.valorCausa, 'valorCausa', avisosCampos, norm.valorMonetario);
    const situacao         = escalar(escopo, S.situacao, 'situacao', avisosCampos);

    // Classe judicial: "CUMPRIMENTO DE SENTENÇA (156)" → descrição + código CNJ.
    const resClasse = escalar(escopo, S.classeJudicial, 'classeJudicial', avisosCampos);
    const classe = norm.descricaoComCodigo(resClasse.valor);

    // Sinalizadores tri-estado: null quando a página não os exibe.
    const segredoJustica  = booleano(escopo, S.segredoJustica);
    const justicaGratuita = booleano(escopo, S.justicaGratuita);
    const tutelaLiminar   = booleano(escopo, S.tutelaLiminar);
    const prioridade      = norm.booleanoComMotivo(dom.resolverCampo(escopo, S.prioridade).valor);

    const assuntos = extrairAssuntos(escopo, raizEl);
    const partes = extrairPartes(raizEl, avisos);
    const cronologia = extrairMovimentacoes(raizEl, avisos);
    const cabecalho = extrairCabecalho(raizEl);

    return {
        success: true,
        source: ORIGEM,
        extractedAt: norm.agoraIso(),
        extractorVersion: cfg.VERSAO_EXTRATOR,
        sourceUrl: opcoes.url || (doc.location ? doc.location.href : null),
        /**
         * A página foi lida, mas sem o bloco de onde vem quase todo o cadastro.
         * Acontece em abas antigas do PJe, cujo topo é descartado pelas
         * atualizações parciais do JSF. O service worker usa este sinal para
         * refazer a leitura em uma aba nova antes de desistir.
         */
        paginaIncompleta: !containerDetalhes,
        strategies: {
            numeroCNJ: resCnj.estrategia,
            tribunal: tribunal.estrategia,
            classeJudicial: resClasse.estrategia
        },
        data: {
            numeroCNJ: cnjEncontrado,
            classeAbreviada: cabecalho.classeAbreviada,
            resumoPartes: cabecalho.resumoPartes,
            tribunal: tribunal.valor,
            orgaoJulgador: orgaoJulgador.valor,
            cargoJudicial: cargoJudicial.valor,
            competencia: competencia.valor,
            jurisdicao: jurisdicao.valor,
            classeJudicial: classe.descricao,
            classeCodigo: classe.codigo,
            assuntoPrincipal: assuntos.principal,
            assuntoPrincipalCodigo: assuntos.codigoPrincipal,
            assuntosSecundarios: assuntos.secundarios,
            dataAutuacao: dataAutuacao.valor,
            dataDistribuicao: dataDistribuicao.valor,
            valorCausa: valorCausa.valor,
            situacao: situacao.valor,
            segredoJustica: segredoJustica,
            justicaGratuita: justicaGratuita,
            tutelaLiminar: tutelaLiminar,
            prioridade: prioridade.valor,
            prioridadeDescricao: prioridade.motivo,
            partes: partes,
            movimentacoes: cronologia.lista,
            historicoCompleto: cronologia.historicoCompleto,
            documentos: consolidarDocumentos(cronologia.lista),
            /** Preenchido pelo coletor quando a íntegra é solicitada. */
            integraSolicitada: false,
            documentoAberto: extrairDocumentoAberto(raizEl)
        },
        warnings: avisos
    };
}

const API = { extrair, paginacaoCronologia, partirDocumento, extrairDocumentoAberto, ORIGEM };
raiz.GJ.pjeExtractor = API;
if (typeof module !== 'undefined' && module.exports) { module.exports = API; }

})(typeof globalThis !== 'undefined' ? globalThis : self);
