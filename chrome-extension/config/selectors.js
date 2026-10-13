/**
 * CENTRALIZAÇÃO DOS SELETORES
 * ===========================
 * Este é o ÚNICO arquivo do projeto que conhece a estrutura do DOM do SIGADOC
 * e do PJe. Nenhum seletor deve ser escrito fora daqui.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │ ESTADO ATUAL: SIGADOC e PJe mapeados contra o DOM real.              │
 * │   SIGADOC · 20-21/09/2026 · www.sigadoc.mt.gov.br (SIGA-DOC v11.2.0) │
 * │   PJe     · 27/09/2026    · pje.tjmt.jus.br (1º Grau, nova interface)│
 * │                                                                      │
 * │ Para remapear após uma mudança no sistema de origem:                 │
 * │   1. abrir a página real já autenticado;                            │
 * │   2. salvar o HTML e anonimizar (tests/fixtures/<sistema>/);        │
 * │   3. identificar id / name / data-* / aria-* estáveis;              │
 * │   4. atualizar `css` abaixo, do mais específico ao mais genérico;   │
 * │   5. incrementar VERSAO_EXTRATOR;                                   │
 * │   6. rodar tests/extractors/runner.html contra as fixtures.         │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * `css: []` em um campo NÃO significa campo não mapeado: significa que a
 * página não oferece seletor estável para ele e que a leitura correta é por
 * RÓTULO (`labels`) — procura um elemento cujo texto corresponda ao rótulo e
 * lê o valor associado (label→control, dt→dd, th→td, "Rótulo: valor"). É o
 * caso da <dl> de detalhes do PJe e das tabelas do documento do SIGADOC, cujos
 * campos não têm id nem classe própria. Cada caso está justificado no bloco do
 * respectivo sistema.
 *
 * Resiliência (seção 28): evite nth-child, posições fixas, classes geradas
 * dinamicamente e caminhos DOM longos. Prefira id, name, atributos data-,
 * atributos aria- e rótulos visíveis.
 */

/* Script clássico (não-módulo): publica no namespace global compartilhado
   pelos content scripts injetados, pelo runner de testes no navegador e
   pelo Node (via require), sem depender de bundler. */
(function (raiz) {
'use strict';

const VERSAO_EXTRATOR = '1.4.0'; // 1.4.0: PJe — vara e comarca retiradas do contrato (não fazem parte do cadastro)
                                 // 1.3.0: PJe — cabeçalho (sigla e resumo das partes), outros interessados,
                                 //        origem do evento, N documentos por evento, cronologia completa
                                 //        e íntegra de sentenças/decisões (Recursos/pje-elementos-dom.json)
                                 // 1.2.2: lê conteúdo sob visibility:hidden (bloco de detalhes do PJe)
                                 // 1.2.1: detecta aba do PJe sem o bloco de detalhes
                                 // 1.2.0: DOM do PJe mapeado (detalhes, polos e cronologia)

/**
 * SIGADOC — MAPEADO em 20/09/2026 contra o DOM real de
 * www.sigadoc.mt.gov.br (SIGA-DOC v11.2.0), página
 * /sigaex/app/expediente/doc/exibir?sigla=<SIGLA>
 *
 * Observação estrutural relevante: o corpo do processo é HTML gerado a partir
 * do modelo do documento. As tabelas NÃO possuem id nem classe, e o padrão de
 * rótulo/valor alterna entre duas formas:
 *
 *   horizontal:  <tr><td>Data de abertura</td><td>07/04/2026</td></tr>
 *   vertical:    <tr><td><b>OBJETO</b></td></tr>
 *                <tr><td>texto do objeto…</td></tr>
 *
 * Por isso NÃO há seletor CSS estável para data e objeto — ancorá-los em
 * `table:nth-of-type(5) td` violaria a regra de resiliência (§28) e quebraria
 * a cada alteração do modelo. A estratégia correta aqui é por RÓTULO, com a
 * busca restrita ao container do documento (`_containerDocumento`), que é
 * identificável e único.
 */
const SIGADOC_SELECTORS = {
    /**
     * Container do documento renderizado. Restringe a busca por rótulo,
     * evitando colisão com a barra lateral (Volumes, Documentos Relacionados,
     * Tramitação), que também cita siglas de processo.
     */
    _containerDocumento: {
        css: [
            '#page .card-sidebar.border-alert .card-body',
            'div.card-sidebar.border-alert > div.card-body',
            '.border-alert .card-body'
        ],
        escopo: true
    },

    /** Protocolo (sigla) do processo administrativo. Ex.: SES-PRO-2026/23509 */
    protocolo: {
        // O <h2> do cabeçalho é o anúncio canônico da sigla na página e fica
        // FORA do container do documento — por isso é resolvido no escopo da
        // página inteira (ver sigadoc-extractor.js).
        css: [
            '#divBody #page h2',
            '#page h2'
        ],
        labels: ['Processo Nº', 'Processo No', 'Processo N', 'Protocolo', 'Número do protocolo'],
        // Aplicado também ao resultado do CSS, para descartar o texto do link
        // "Voltar" que acompanha o <h2>.
        padrao: /\b[A-Z0-9]{2,20}-[A-Z]{2,10}-\d{4}\/\d{1,10}\b/,
        obrigatorio: true,
        escopoPagina: true
    },

    /** Data de abertura. Padrão horizontal (td rótulo → td valor). */
    dataAbertura: {
        css: [],
        labels: ['Data de abertura', 'Data de Abertura', 'Abertura', 'Data de autuação'],
        tipo: 'data'
    },

    /** Objeto. Padrão vertical (tr rótulo → tr seguinte). */
    objeto: {
        css: [],
        labels: ['OBJETO', 'Objeto', 'Descrição', 'Assunto', 'Resumo'],
        tipo: 'textoLongo'
    },

    /**
     * Situação (marcadores) do volume atual — MAPEADO em 21/09/2026.
     *   <div id="collapseSituacaoDoc"><p class="font-weight-bold">
     *     1º Volume - Aguardando Andamento [<span title="NOME">SES000000</span>],
     *     Aguardando Andamento [UNIJUR, [<span title="NOME">SES000000</span>]]
     *   </p></div>
     * O mesmo texto é repetido no <h3> do cabeçalho (fallback).
     * Rótulos oficiais observados no filtro "ultMovIdEstadoDoc" do SIGA-DOC:
     * "Aguardando Andamento", "Sobrestado", "Arquivo Corrente",
     * "Caixa de Entrada (Digital)", "A Receber (Físico)", "Transferido"…
     */
    situacao: {
        css: ['#collapseSituacaoDoc p', '#page h3'],
        labels: ['Situação do Documento']
    },

    /**
     * Volumes (barra lateral) — cada linha: [volume, situação, pessoa, lotação].
     *   <div id="collapseOutrosMob"><table><tr>
     *     <td><i><b>V01</b></i></td><td>Aguardando Andamento</td>
     *     <td><a><span title="NOME COMPLETO - SES000000">Nome</span></a></td>
     *     <td><a><span title="UNIDADE JURIDICA">UNIJUR</span></a></td> …
     * A última linha "Vnn" indica quem está com o processo agora.
     */
    volumes: {
        css: ['#collapseOutrosMob table', '#collapseOutrosMob'],
        colunas: { volume: 0, situacao: 1, pessoa: 2, lotacao: 3 }
    },

    /**
     * Movimentações.
     *
     *  completa — visão "Ver Mais" (/sigaex/app/expediente/doc/exibirAntigo):
     *    <table class="table … mov"> Data | Evento | Cadastrante (Lotação, Pessoa)
     *    | Atendente (Lotação, Pessoa) | Descrição | Duração
     *    A data só aparece na 1ª linha de cada grupo; as seguintes herdam.
     *    Há uma tabela por volume e uma final "Geral" (assinaturas/vínculos).
     *
     *  resumida — visão padrão (/exibir): só juntadas e anotações.
     *    <table class="table … table-striped"> Data | Lotação | Evento | Descrição
     *    <td title="NOME - LOTAÇÃO POR EXTENSO">SIGLA</td>
     *
     * A classe da <tr> indica o tipo (juntada, anotacao, transferencia,
     * recebimento, apensacao, desentranhamento, criacao); Sobrestar,
     * Dessobrestar e arquivamentos vêm SEM classe — o tipo é lido do texto.
     */
    eventos: {
        completa: {
            css: ['#page table.mov', 'table.mov'],
            colunas: { data: 0, evento: 1, cadLotacao: 2, cadPessoa: 3, atLotacao: 4, atPessoa: 5, descricao: 6 },
            cabecalhoEsperado: ['Evento', 'Atendente']
        },
        resumida: {
            css: ['#page .gt-content-box table.table-striped', '#page table.table-striped'],
            colunas: { data: 0, lotacao: 1, evento: 2, descricao: 3 },
            cabecalhoEsperado: ['Evento', 'Descrição']
        },
        /** Link "Ver Mais" que leva à visão completa (mesma origem). */
        linkCompleta: { css: ['#page a#ver-mais', 'a#ver-mais'] },
        /** Ruído de interface dentro da célula de descrição. */
        ruidoDescricao: 'button, .gt-separator, .d-none, .link-tag-hidden, script, style',
        /** Cabeçalho de tabela que NÃO é volume (eventos do documento em geral). */
        cabecalhoIgnorado: /^\s*geral\s*$/i,
        limite: 1000
    },

    /**
     * Campos adicionais disponíveis na página, ainda não persistidos.
     *   documentosRelacionados → '#outputRelacaoDocs'  apensados e "veja também"
     *   classificacaoDocumental → rótulo 'Classif. documental'
     *   cadastrante / espécie   → '#collapseDocDetalhes p' ("<b>Cadastrante:</b> …")
     */
    _futuros: {
        documentosRelacionados: { css: ['#outputRelacaoDocs'], labels: ['Documentos Relacionados'], colecao: true },
        classificacaoDocumental: { css: [], labels: ['Classif. documental', 'Classificação documental'] },
        detalhesDocumento: { css: ['#collapseDocDetalhes'] }
    }
};

/**
 * NAVEGAÇÃO NO SIGADOC — padrão de URL observado no sistema real
 * (www.sigadoc.mt.gov.br, SIGA-DOC v11.2.0), verificado em 20/09/2026.
 *
 *   /sigaex/app/expediente/doc/exibir?sigla=<SIGLA>
 *
 * Confirmado que o sufixo de volume (-V01) é opcional: a consulta pela sigla
 * sem volume devolve o processo. O host NÃO está embutido: vem da allowlist
 * configurada pelo usuário na aplicação.
 *
 * Este template é apenas um ATALHO. Se falhar, o fluxo continua funcionando
 * pela busca entre as abas já abertas — e a autenticação permanece sendo
 * responsabilidade do usuário em ambos os casos.
 *
 * Pode ser sobrescrito em Sistema › Configurações (chave sigadoc_url_template).
 */
const SIGADOC_NAVEGACAO = {
    urlTemplate: 'https://{host}/sigaex/app/expediente/doc/exibir?sigla={protocolo}',
    // Substituições aceitas: {host} e {protocolo} (este último já codificado).
    observadoEm: '2026-09-20',
    sistemaObservado: 'SIGA-DOC v11.2.0'
};

/**
 * PJe — MAPEADO em 27/09/2026 contra o DOM real de pje.tjmt.jus.br (1º Grau,
 * interface nova), página
 * /pje/Processo/ConsultaProcesso/Detalhe/listProcessoCompletoAdvogado.seam
 *
 * Stack: JSF/RichFaces + Bootstrap. Três observações estruturais determinam a
 * estratégia adotada aqui:
 *
 * 1. Os dados cadastrais ficam em uma <dl class="dl-horizontal"> dentro de
 *    #maisDetalhes, em pares <dt>/<dd> SEM id ou classe por campo. Não existe
 *    seletor CSS estável por campo — ancorá-los em nth-child violaria §28. A
 *    leitura correta é por RÓTULO (dt→dd), que o motor já suporta e que
 *    sobrevive a reordenação de campos. O bloco está sempre no DOM: fica
 *    oculto pelo dropdown, mas não exige clique para ser lido.
 *
 * 2. Os ids gerados pelo JSF (`divTimeLine:j_id424:0:j_id426:5:j_id437`) mudam
 *    a cada renderização — jamais usar como âncora. Já os containers
 *    (#maisDetalhes, #poloAtivo, #poloPassivo, #divTimeLine:divEventosTimeLine)
 *    são estáveis e nomeados pela aplicação.
 *
 * 3. Vários controles carregam o token de sessão (`ca=`) em href/onclick.
 *    Os únicos `onclick` lidos aqui são os dos ícones "copiar número"
 *    (`copyToClipboard(event, '<número>')`), que carregam apenas o número do
 *    processo ou do documento — e mesmo assim só o trecho que casa com o
 *    padrão esperado é aproveitado. Links de documento e `window.open(...)`
 *    nunca são lidos nem persistidos.
 *
 * Revisão de 28/09/2026 (Recursos/pje-elementos-dom.json): inspeção ao vivo +
 * validação sobre o HTML completo salvo. Acrescentou a sigla da classe e o
 * resumo das partes do cabeçalho, a aba "Outros interessados", a origem de
 * cada evento da cronologia, N documentos por evento, o painel do documento
 * (#detalheDocumento:tituloDocumento / :quadroDocumento) e a íntegra dos
 * documentos via iframe#frameHtml, de mesma origem.
 */
const PJE_SELECTORS = {
    /**
     * Bloco "Mais detalhes": dados cadastrais do processo. Serve de escopo para
     * a leitura por rótulo, evitando que "Assunto" ou "Competência" colidam com
     * o texto de um documento aberto no visualizador à direita.
     *
     * O escopo é a DIV, não a primeira <dl>: "Cargo judicial" e "Competência"
     * vêm em <dl> próprias, irmãs da principal.
     */
    _containerDetalhes: {
        css: ['#maisDetalhes'],
        escopo: true
    },

    /**
     * Número único do processo (CNJ). O título do topo traz número e partes
     * concatenados ("CumSen 1001857-48.2023.8.11.0108 FULANO X BELTRANO"); o
     * `padrao` isola o número. Fora do dropdown, por isso escopo de página.
     */
    numeroProcesso: {
        css: [
            'a.titulo-topo-desktop',
            '.nav-topo .mais-detalhes .titulo-topo',
            '.nav-topo .titulo-topo',
            '.titulo-topo'
        ],
        labels: ['Número do processo', 'Número', 'Processo', 'Número único'],
        padrao: /\b\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}\b/,
        obrigatorio: true,
        escopoPagina: true
    },

    /**
     * Forma preferida de ler o número: o ícone "copiar" do cabeçalho já traz o
     * número limpo no onclick — `copyToClipboard(event, '1000034-53.2026…')` —
     * sem o texto misto do título. Só o trecho que casa com o `padrao` de
     * numeroProcesso é aproveitado.
     */
    numeroCopiavel: {
        css: ['a.titulo-topo-desktop i.fa-clipboard', '.titulo-topo i.fa-clipboard'],
        atributo: 'onclick'
    },

    /**
     * Cabeçalho do topo: "ProceComCiv 1000034-53.2026.8.11.0037" seguido de
     * <small>FULANO e outros X MUNICÍPIO e outros</small>.
     *   classeAbreviada  — prefixo antes do número (código curto da classe);
     *   resumoPartes — o <small>, visão rápida dos polos.
     */
    cabecalho: {
        css: ['a.titulo-topo-desktop', '.nav-topo .mais-detalhes .titulo-topo', '.titulo-topo'],
        classeAbreviada: /^\s*([A-Za-zÀ-ú]{2,30})\s+\d{7}-/,
        resumoPartes: { css: ['small'] }
    },

    /**
     * Tribunal. Não é campo da <dl>: vem do <title> da página
     * ("<CNJ> · Tribunal de Justiça de Mato Grosso - 1º Grau"), lido no escopo
     * do documento (ver pje-extractor.js).
     */
    tribunal: {
        css: [],
        labels: ['Tribunal', 'Órgão', 'Justiça'],
        padrao: /Tribunal[^·|]*/,
        escopoTitulo: true
    },

    /* --- Campos da <dl> "Mais detalhes": leitura por rótulo (dt→dd). --- */
    orgaoJulgador:    { css: [], labels: ['Órgão julgador', 'Orgão julgador', 'Órgão Julgador'] },
    cargoJudicial:    { css: [], labels: ['Cargo judicial', 'Cargo Judicial'] },
    competencia:      { css: [], labels: ['Competência', 'Competencia'] },
    jurisdicao:       { css: [], labels: ['Jurisdição', 'Jurisdicao'] },
    classeJudicial:   { css: [], labels: ['Classe judicial', 'Classe', 'Classe processual'] },
    /** Autuação e última distribuição são datas distintas e ambas exibidas. */
    dataAutuacao:     { css: [], labels: ['Autuação', 'Autuacao', 'Data de autuação', 'Autuado em'], tipo: 'data' },
    dataDistribuicao: { css: [], labels: ['Última distribuição', 'Ultima distribuicao', 'Data da distribuição', 'Distribuição'], tipo: 'data' },
    valorCausa:       { css: [], labels: ['Valor da causa', 'Valor da Causa'], tipo: 'moeda' },
    situacao:         { css: [], labels: ['Situação', 'Status', 'Fase'] },
    segredoJustica:   { css: [], labels: ['Segredo de justiça?', 'Segredo de justiça', 'Sigilo', 'Nível de sigilo'], tipo: 'booleano' },
    justicaGratuita:  { css: [], labels: ['Justiça gratuita?', 'Justiça gratuita', 'Gratuidade da justiça'], tipo: 'booleano' },
    tutelaLiminar:    { css: [], labels: ['Tutela/liminar?', 'Tutela/liminar', 'Tutela ou liminar', 'Liminar'], tipo: 'booleano' },
    /* "Prioridade?" nem sempre é SIM/NÃO: pode trazer o motivo ("Idoso").
       Nesse caso vale como SIM e o texto vai para prioridadeDescricao. */
    prioridade:       { css: [], labels: ['Prioridade?', 'Prioridade', 'Prioridade de tramitação'], tipo: 'booleanoComMotivo' },

    /**
     * Assuntos. O <dd> do rótulo "Assunto" contém <ul><li>…</li></ul>, um <li>
     * por assunto, no formato "Descrição (código)". O primeiro é o principal.
     */
    assuntos: {
        rotulo: ['Assunto', 'Assuntos'],
        itens: ['li'],
        labels: ['Assuntos secundários', 'Assuntos', 'Outros assuntos'],
        colecao: true
    },

    /**
     * Partes. Uma tabela por polo, em containers próprios e estáveis. O polo
     * vem do container — não do contexto — o que elimina a heurística de
     * cabeçalho e o risco de atribuir a parte ao polo errado.
     *
     *   <div id="poloAtivo"><table class="table"><tbody>
     *     <tr><td>
     *       <span><span>NOME - CPF: 000.000.000-00 (REQUERENTE)</span></span>
     *       <ul class="tree"><li><small>
     *         <i class="fa fa-users" title="Defensoria"></i>
     *         <span title="Defensoria">MT - DEFENSORIA PUBLICA…</span>
     *       </small></li></ul>
     *     </td></tr>
     *
     * A primeira <tr> de cada tabela pode ser o cabeçalho da seção
     * (<th>Polo ativo</th>) — sem <td>, a identificação vem vazia e a linha é
     * descartada. "Outros interessados" (#outrosInteressados) usa o mesmo
     * template e é gravado no polo TERCEIRO.
     */
    partes: {
        grupos: [
            { polo: 'ATIVO',    css: ['#poloAtivo'] },
            { polo: 'PASSIVO',  css: ['#poloPassivo'] },
            { polo: 'TERCEIRO', css: ['#outrosInteressados', '#poloOutros', '#terceiros'] }
        ],
        itens: ['table tbody tr', 'table tr'],
        campos: {
            /** "NOME - CPF: 000.000.000-00 (REQUERENTE)" — nome, documento e papel. */
            identificacao:  { css: ['td > span > span', 'td > span', 'td'] },
            /**
             * Advogado, Defensoria ou Procuradoria vinculados à parte: um <li>
             * por representante. O tipo está no `title` do ícone <i> — que o
             * Bootstrap move para `data-original-title` ao ativar o tooltip.
             */
            representantes: {
                item: ['ul.tree li'],
                nome: ['small span[title]', 'span[title]', 'span', 'small'],
                tipo: ['i[title]', 'i[data-original-title]', 'span[title]'],
                atributosTipo: ['title', 'data-original-title']
            }
        },
        labels: ['Partes', 'Polo ativo', 'Polo passivo', 'Partes do processo'],
        colecao: true,
        /** Rótulos de polo, para a estratégia genérica de fallback. */
        polos: {
            ATIVO:    ['Polo ativo', 'Polo Ativo', 'Autor', 'Exequente', 'Requerente', 'Impetrante'],
            PASSIVO:  ['Polo passivo', 'Polo Passivo', 'Réu', 'Executado', 'Requerido', 'Impetrado'],
            TERCEIRO: ['Terceiro', 'Terceiros', 'Terceiro interessado', 'Assistente']
        }
    },

    /**
     * Cronologia (timeline) — movimentações e documentos juntados.
     *
     * A lista alterna separadores de data e itens; a data vale para todos os
     * itens seguintes até o próximo separador (mesmo padrão do SIGADOC).
     *
     *   .media.data  > .media-body > span.data-interna   "18 set 2026"
     *   .media.interno.tipo-M                            movimento sem documento
     *   .media.interno.tipo-D                            item que traz documento
     *        (pode trazer TAMBÉM o texto do movimento)
     *
     * `documento` usa o filho DIRETO de .anexos: o seletor descendente
     * capturaria também o botão "Adicionar lembretes", que mora em .btn-group.
     *
     * A timeline é paginada (#totalPaginas / #paginaAtual). Só o que estiver
     * carregado é lido; quando #paginaAtual < #totalPaginas, `historicoCompleto`
     * é false. A extensão pode rolar o container antes da leitura para carregar
     * as páginas seguintes (ver `rolagem` e extractors/pje-documentos.js).
     *
     * Um evento pode juntar N documentos (petição + anexos): cada um é um
     * <a> filho DIRETO de .anexos. O número do documento também está no
     * onclick do ícone .copiar-clipboard que segue cada <a>.
     */
    movimentacoes: {
        container: { css: ['#divTimeLine\\:eventosTimeLineElement', '.eventos-timeline'] },
        /** O primeiro filho (.div-data-rolagem) é um marcador vazio de rolagem. */
        separadorData: { css: ['.media.data'], ignorar: '.div-data-rolagem', valor: ['span.data-interna', '.media-body span'] },
        itens: ['.media.interno'],
        campos: {
            descricao: { css: ['.texto-movimento'] },
            hora:      { css: ['.col-sm-12 small.text-muted.pull-right', 'small.text-muted.pull-right', 'small.text-muted'] },
            documentos: {
                link: ['.anexos > a'],
                rotulo: ['span[title]', 'span'],
                /** Ícone irmão do <a>: copyToClipboard(event, '249562011'). */
                copiarId: 'i.copiar-clipboard'
            },
            /** "Interno", "Externo", "Polo passivo", "Movimento processual lançado". */
            natureza:  { css: ['.media-left .icon-auto'], atributo: 'alt' },
            /** Mesmo ícone: o 2º token da classe diz quem originou o evento. */
            origem:    { css: ['.media-left .icon-auto', '.media-left span'] }
        },
        /** Token de classe do ícone → código de origem gravado no SESJUR. */
        origens: {
            sistema: 'SISTEMA',
            I:       'INTERNO',
            PA:      'POLO_ATIVO',
            PP:      'POLO_PASSIVO',
            E:       'EXTERNO',
            OU:      'OUTROS'
        },
        /** Marca o item que carrega documento; a ausência indica movimento puro. */
        classeDocumento: 'tipo-D',
        paginacao: { total: ['#totalPaginas'], atual: ['#paginaAtual'] },
        /** Container com barra de rolagem que dispara a carga das páginas seguintes. */
        rolagem: { css: ['#divTimeLine\\:divEventosTimeLine', '.eventos-timeline.scroll-y', '.eventos-timeline'] },
        limite: 1000
    },

    /**
     * Visualizador de documento (painel direito, #detalheDocumento). É
     * atualizado por postback parcial do JSF (A4J) quando um documento da
     * cronologia é clicado — não há URL que possa ser montada de antemão.
     */
    documentoAberto: {
        titulo: { css: [
            '#detalheDocumento\\:tituloDocumento h3.media-heading a span[title]',
            '#detalheDocumento .titulo-documento h3 a span[title]',
            '#detalheDocumento .titulo-documento h3 span'
        ] },
        /** "Juntado por NOME em 01/01/2026 10:00:00". */
        juntada: { css: [
            '#detalheDocumento\\:tituloDocumento div.titulo-documento > div',
            '#detalheDocumento .titulo-documento > div'
        ] },
        /** "97 de 97" — posição do documento na lista do processo. */
        contador: { css: ['#detalheDocumento\\:toolbarDocumento .contador-paginas', '#detalheDocumento .contador-paginas'] },
        /** Iframe de MESMA ORIGEM (documentoHTML.seam) com o corpo do documento. */
        iframe: { css: [
            '#detalheDocumento\\:quadroDocumento iframe#frameHtml',
            '#detalheDocumento iframe#frameHtml',
            'iframe#frameHtml'
        ] },
        /** Viewer de PDF embutido: sem texto legível pelo DOM. */
        visualizadorPdf: 'embed, object[type="application/pdf"], iframe[src*=".pdf"]'
    },

    /**
     * Íntegra dos documentos: só os tipos abaixo têm o texto completo extraído
     * (comparação por palavra-chave, sem acento e sem caixa, contra o rótulo
     * do documento — "249562011 - Sentença"). A ordem importa: a primeira
     * palavra-chave que casar define a classificação.
     */
    integra: {
        classificacao: [
            { codigo: 'EMBARGOS',  chaves: ['embargos de declaracao'] },
            { codigo: 'ACORDAO',   chaves: ['acordao'] },
            { codigo: 'SENTENCA',  chaves: ['sentenca'] },
            { codigo: 'DECISAO',   chaves: ['decisao'] },
            { codigo: 'DESPACHO',  chaves: ['despacho'] }
        ],
        /** Teto de documentos lidos por extração — cada um custa um postback. */
        limite: 40,
        /** Espera pela troca do documento no painel após o clique. */
        timeoutPainelMs: 15000,
        /** Espera pelo conteúdo do iframe após a troca do título. */
        timeoutIframeMs: 15000,
        /** Tamanho máximo de texto guardado por documento. */
        limiteCaracteres: 500000
    }
};

/**
 * NAVEGAÇÃO NO PJe — diferente do SIGADOC, NÃO há template de URL possível.
 *
 * A URL do processo exige o parâmetro `ca`, um código de acesso por sessão
 * (`…listProcessoCompletoAdvogado.seam?id=<interno>&ca=<token>`), que não pode
 * ser derivado do número CNJ nem versionado. Por isso o fluxo do PJe sempre
 * parte da URL que o usuário informa no formulário, já autenticado.
 */
const PJE_NAVEGACAO = {
    urlTemplate: null,
    observadoEm: '2026-09-27',
    sistemaObservado: 'PJe 1º Grau TJMT (interface nova)'
};

/** Indica se os seletores CSS de um sistema já foram mapeados. */
function seletoresConfigurados(mapa) {
    return Object.keys(mapa).some(function (nome) {
        if (nome.charAt(0) === '_') {
            return false; // chaves auxiliares (_containerDocumento, _futuros)
        }
        const campo = mapa[nome];
        return campo && Array.isArray(campo.css) && campo.css.length > 0;
    });
}

    const API = { VERSAO_EXTRATOR, SIGADOC_SELECTORS, SIGADOC_NAVEGACAO, PJE_SELECTORS, PJE_NAVEGACAO, seletoresConfigurados };
    raiz.GJ = raiz.GJ || {};
    raiz.GJ.selectors = API;
    if (typeof module !== 'undefined' && module.exports) { module.exports = API; }
})(typeof globalThis !== 'undefined' ? globalThis : self);
