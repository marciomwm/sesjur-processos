/* =============================================================================
   expediente.js — O expediente em elaboração
   -----------------------------------------------------------------------------
   EXPEDIENTE                         MODELO
   ├── processo (vínculo SESJUR)      │
   ├── protocolo                      ├── texto
   ├── modelo utilizado               ├── propriedades (definições)
   ├── valores das propriedades       ├── formatação
   └── conteúdo editado               └── metadados

   `conteudo === null` significa "ainda é o texto do modelo": ele é recalculado a
   cada mudança nos dados. Assim que o assessor edita, passa a ser uma string.
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};
  var T = global.CI_TEMPLATES;

  var STATUS = { EDITANDO: 'editing', VISUALIZANDO: 'preview', PRONTO: 'ready' };

  /* Margens em centímetros. "oficial" é o padrão dos expedientes da Unidade. */
  var MARGENS = {
    oficial: { rotulo: 'Oficial (3 / 2 / 2,5 / 2 cm)', sup: 2.5, dir: 2, inf: 2, esq: 3 },
    normal: { rotulo: 'Normal (2,5 cm)', sup: 2.5, dir: 2.5, inf: 2.5, esq: 2.5 },
    estreita: { rotulo: 'Estreita (1,5 cm)', sup: 1.5, dir: 1.5, inf: 1.5, esq: 1.5 }
  };

  var ENTRELINHAS = ['1', '1.15', '1.5', '2'];
  var ESPACOS_PARAGRAFO = ['0', '6', '12'];   /* pontos, depois do parágrafo */

  function formatoPadrao() {
    return { entrelinha: '1.5', espacoParagrafo: '0', margens: 'oficial' };
  }

  function normalizarFormato(f) {
    var p = formatoPadrao();
    f = f || {};
    return {
      entrelinha: ENTRELINHAS.indexOf(String(f.entrelinha)) !== -1 ? String(f.entrelinha) : p.entrelinha,
      espacoParagrafo: ESPACOS_PARAGRAFO.indexOf(String(f.espacoParagrafo)) !== -1
        ? String(f.espacoParagrafo) : p.espacoParagrafo,
      margens: MARGENS[f.margens] ? f.margens : p.margens
    };
  }

  /* Entrelinha, espaçamento e margens como variáveis CSS de uma folha (ou do
     contêiner dela): editor, visualização, prévia do lote e da Biblioteca. */
  function aplicarFormato(elemento, formato) {
    var f = normalizarFormato(formato), m = MARGENS[f.margens];
    var st = elemento.style;
    st.setProperty('--entrelinha', f.entrelinha);
    st.setProperty('--esp-par', f.espacoParagrafo + 'pt');
    st.setProperty('--mg-sup', m.sup + 'cm');
    st.setProperty('--mg-dir', m.dir + 'cm');
    st.setProperty('--mg-inf', m.inf + 'cm');
    st.setProperty('--mg-esq', m.esq + 'cm');
  }

  /* Vínculo com o SESJUR: o Processo Administrativo do expediente e qual dos
     processos vinculados a ele alimenta as propriedades PJ_ e PE_. */
  function semProcesso() { return { id: null, judicialId: null, extrajudicialId: null }; }

  function normalizarProcesso(p) {
    function id(v) { return typeof v === 'number' && v > 0 ? v : null; }
    p = p || {};
    return { id: id(p.id), judicialId: id(p.judicialId), extrajudicialId: id(p.extrajudicialId) };
  }

  function novo() {
    var agora = new Date().toISOString();
    return {
      id: 'exp_' + Date.now().toString(36),
      processo: semProcesso(),
      protocolo: '',
      modeloId: CE.modelos.ID_EM_BRANCO,
      conteudo: null,
      dados: T.dadosVazios(),   /* valores das propriedades do catálogo        */
      selecao: {},              /* modeloId -> [tokens do catálogo e próprios]  */
      definicoes: {},           /* modeloId -> [PropertyDefinition]             */
      valores: {},              /* propertyId -> valor                          */
      formato: formatoPadrao(),
      status: STATUS.EDITANDO,
      destacar: true,
      createdAt: agora,
      updatedAt: agora,
      /* transitórios — não são persistidos */
      ultimo: null,
      erros: {}
    };
  }

  function serializar(e) {
    return {
      versao: 3,
      id: e.id,
      processo: e.processo,
      protocolo: e.protocolo,
      modeloId: e.modeloId,
      conteudo: e.conteudo,
      propriedades: {
        dados: e.dados,
        selecao: e.selecao,
        definicoes: e.definicoes,
        valores: e.valores
      },
      formato: e.formato,
      modo: e.status,
      createdAt: e.createdAt,
      updatedAt: e.updatedAt
    };
  }

  /* Reconstrói o expediente a partir do rascunho gravado. */
  function restaurar(r) {
    var e = novo();
    if (!r || !CE.modelos.obter(r.modeloId)) return e;
    var p = r.propriedades || {};

    e.id = r.id || e.id;
    e.processo = normalizarProcesso(r.processo);
    e.protocolo = String(r.protocolo || '');
    e.modeloId = r.modeloId;
    e.conteudo = typeof r.conteudo === 'string' ? r.conteudo : null;
    Object.keys(e.dados).forEach(function (k) {
      if (p.dados && typeof p.dados[k] === 'string') e.dados[k] = p.dados[k];
    });
    if (p.selecao && typeof p.selecao === 'object') e.selecao = p.selecao;
    if (p.definicoes && typeof p.definicoes === 'object') e.definicoes = p.definicoes;
    if (p.valores && typeof p.valores === 'object') e.valores = p.valores;
    e.formato = normalizarFormato(r.formato);
    e.status = r.modo === STATUS.EDITANDO ? STATUS.EDITANDO : STATUS.VISUALIZANDO;
    if (!r.modo) e.status = STATUS.EDITANDO;
    e.createdAt = r.createdAt || e.createdAt;
    e.updatedAt = r.dataUltimaEdicao || r.updatedAt || e.updatedAt;
    return e;
  }

  CE.expediente = {
    STATUS: STATUS,
    MARGENS: MARGENS,
    ENTRELINHAS: ENTRELINHAS,
    ESPACOS_PARAGRAFO: ESPACOS_PARAGRAFO,
    formatoPadrao: formatoPadrao,
    normalizarFormato: normalizarFormato,
    aplicarFormato: aplicarFormato,
    semProcesso: semProcesso,
    novo: novo,
    serializar: serializar,
    restaurar: restaurar
  };
})(window);
