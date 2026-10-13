/* =============================================================================
   properties.js — Propriedades criadas pelo assessor
   -----------------------------------------------------------------------------
   Separa DEFINIÇÃO de VALOR:

     PropertyDefinition { id, titulo, identificador, tipo, obrigatoria,
                          descricao, opcoes, grupo }      → pertence ao MODELO
     PropertyValue      { propertyId → valor }            → pertence ao EXPEDIENTE

   O motor (engine.js) continua recebendo o formato histórico
   { token, label, valor, ajuda, obrigatorio }; `paraMotor()` faz a ponte.
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};
  var T = global.CI_TEMPLATES;

  var TIPOS = [
    { id: 'texto', rotulo: 'Texto' },
    { id: 'texto_longo', rotulo: 'Texto longo' },
    { id: 'numero', rotulo: 'Número' },
    { id: 'data', rotulo: 'Data' },
    { id: 'lista', rotulo: 'Lista suspensa' }
  ];

  function tipoValido(tipo) {
    return TIPOS.some(function (t) { return t.id === tipo; });
  }

  function novoId() {
    return 'prop_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
  }

  function criarDefinicao(d) {
    d = d || {};
    return {
      id: d.id || novoId(),
      titulo: String(d.titulo || d.identificador || '').trim(),
      identificador: T.normalizarIdentificador(d.identificador || d.titulo),
      tipo: tipoValido(d.tipo) ? d.tipo : 'texto',
      obrigatoria: !!d.obrigatoria,
      descricao: String(d.descricao || '').trim(),
      opcoes: Array.isArray(d.opcoes) ? d.opcoes.slice() : [],
      grupo: String(d.grupo || '').trim()
    };
  }

  /* Formato antigo: { token, label, valor, ajuda, obrigatorio }. */
  function ehLegado(p) { return !!p && !p.identificador && !!p.token; }

  /* Aceita uma lista em qualquer dos dois formatos e devolve definições e
     valores já separados. */
  function separar(lista) {
    var definicoes = [], valores = {};
    (lista || []).forEach(function (p) {
      if (!p) return;
      var def = ehLegado(p)
        ? criarDefinicao({
            titulo: p.label, identificador: p.token, tipo: 'texto_longo',
            obrigatoria: p.obrigatorio, descricao: p.ajuda
          })
        : criarDefinicao(p);
      if (!def.identificador) return;
      definicoes.push(def);
      if (p.valor) valores[def.id] = String(p.valor);
    });
    return { definicoes: definicoes, valores: valores };
  }

  /* Cópia das definições, sem nenhum valor — é o que vai para o modelo. */
  function copiar(definicoes) {
    return (definicoes || []).map(function (d) { return criarDefinicao(d); });
  }

  /* Valor como deve aparecer no documento. */
  function formatarValor(def, valor) {
    var v = String(valor == null ? '' : valor);
    if (def.tipo === 'data') {
      var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v);
      if (m) return m[3] + '/' + m[2] + '/' + m[1];
    }
    return v;
  }

  function paraMotor(definicoes, valores) {
    valores = valores || {};
    return (definicoes || []).map(function (d) {
      return {
        token: d.identificador,
        label: d.titulo || d.identificador,
        valor: formatarValor(d, valores[d.id]),
        ajuda: d.descricao,
        obrigatorio: d.obrigatoria
      };
    });
  }

  /* "Nome do Interessado" → NOME_INTERESSADO: a sugestão dispensa as partículas.
     O que o assessor digitar no campo Identificador é respeitado como está. */
  var PARTICULAS = /^(A|O|AS|OS|DE|DO|DA|DOS|DAS|E|EM|NO|NA|NOS|NAS|PARA|POR)$/;

  function sugerirIdentificador(titulo) {
    var completo = T.normalizarIdentificador(titulo);
    var enxuto = completo.split('_').filter(function (p) { return !PARTICULAS.test(p); }).join('_');
    return enxuto || completo;
  }

  /* `outros` = identificadores já usados pelas demais propriedades. */
  function validar(d, outros) {
    if (!String(d.titulo || '').trim()) return 'Informe o título da propriedade.';
    var erro = T.validarIdentificador(T.normalizarIdentificador(d.identificador), outros);
    if (erro) return erro;
    if (d.tipo === 'lista' && !(d.opcoes || []).length) return 'Adicione ao menos uma opção à lista.';
    return null;
  }

  CE.propriedades = {
    TIPOS: TIPOS,
    criarDefinicao: criarDefinicao,
    separar: separar,
    copiar: copiar,
    formatarValor: formatarValor,
    paraMotor: paraMotor,
    sugerirIdentificador: sugerirIdentificador,
    validar: validar
  };
})(window);
