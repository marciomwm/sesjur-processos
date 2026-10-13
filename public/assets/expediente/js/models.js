/* =============================================================================
   models.js — Modelos de expediente e seus tipos
   -----------------------------------------------------------------------------
   Fachada sobre templates.js. Um MODELO guarda texto, formatação, propriedades
   e metadados (título, tipo, descrição, favorito, uso). O PROTOCOLO nunca faz
   parte dele.

   Os TIPOS (Ofício, Despacho...) são uma lista mantida pelo assessor.
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};
  var T = global.CI_TEMPLATES;
  var E = global.CI_ENGINE;
  var P = CE.propriedades;

  var ID_EM_BRANCO = 'expediente-livre';
  var TIPO_PADRAO = 'Outro';
  var CHAVE_TIPOS = 'unijur_tipos_modelo_v1';
  var TIPOS_INICIAIS = ['Ofício', 'Comunicação Interna', 'Despacho', 'Certidão',
                        'Informação', 'Termo', 'Manifestação', TIPO_PADRAO];

  function obter(id) { return T.modelo(id); }
  function salvos() { return T.salvos(); }

  /* ================================================================ TIPOS */
  function tiposGravados() {
    try {
      var lista = JSON.parse(localStorage.getItem(CHAVE_TIPOS) || 'null');
      return Array.isArray(lista) && lista.length ? lista : TIPOS_INICIAIS.slice();
    } catch (e) { return TIPOS_INICIAIS.slice(); }
  }

  /* Lista de tipos: os cadastrados mais os que algum modelo ainda use. */
  function tipos() {
    var lista = tiposGravados();
    salvos().forEach(function (m) { if (lista.indexOf(m.tipo) === -1) lista.push(m.tipo); });
    if (lista.indexOf(TIPO_PADRAO) === -1) lista.push(TIPO_PADRAO);
    return lista;
  }

  /* edicoes = [{ original, nome }] — `original` nulo é tipo novo; tipos ausentes
     da lista foram excluídos, e seus modelos passam para "Outro". */
  function salvarTipos(edicoes) {
    var nomes = [], visto = {};
    edicoes.forEach(function (e) {
      var nome = String(e.nome || '').trim();
      if (!nome || visto[nome.toLowerCase()]) return;
      visto[nome.toLowerCase()] = true;
      nomes.push(nome);
    });
    if (nomes.indexOf(TIPO_PADRAO) === -1) nomes.push(TIPO_PADRAO);

    var destino = {};
    edicoes.forEach(function (e) { if (e.original) destino[e.original] = String(e.nome || '').trim(); });

    salvos().forEach(function (m) {
      var novo = Object.prototype.hasOwnProperty.call(destino, m.tipo) ? destino[m.tipo] : null;
      if (!novo || nomes.indexOf(novo) === -1) novo = nomes.indexOf(m.tipo) !== -1 ? m.tipo : TIPO_PADRAO;
      if (novo !== m.tipo) T.atualizarModelo(m.id, { tipo: novo });
    });

    try { localStorage.setItem(CHAVE_TIPOS, JSON.stringify(nomes)); return true; }
    catch (e) { return false; }
  }

  /* ============================================================== SELETOR */
  /* Expediente em Branco primeiro; depois os modelos do assessor, por tipo. */
  function grupos() {
    var lista = [{ titulo: null, modelos: [{ id: ID_EM_BRANCO, nome: T.MODELOS[ID_EM_BRANCO].nome }] }];
    var porTipo = {};
    salvos().forEach(function (m) {
      (porTipo[m.tipo] = porTipo[m.tipo] || []).push({ id: m.id, nome: m.nome });
    });
    tipos().forEach(function (tipo) {
      if (porTipo[tipo]) lista.push({ titulo: tipo, modelos: porTipo[tipo] });
    });
    return lista;
  }

  /* Propriedades do catálogo que já vêm no expediente ao escolher o modelo. */
  function selecaoInicial(id) {
    var m = obter(id);
    if (m && m.salvo && Array.isArray(m.selecao)) return m.selecao.slice();
    return T.selecaoPadrao(id);
  }

  /* Propriedades próprias do modelo: { definicoes, valores }. */
  function definicoesIniciais(id) {
    var m = obter(id);
    return P.separar(m && m.salvo ? m.personalizadas : []);
  }

  /* Texto de partida do editor. O Expediente em Branco começa realmente vazio. */
  function textoInicial(id, dados, contexto) {
    var m = obter(id);
    if (!m) return '';
    if (m.salvo) return m.html || '';
    if (m.emBranco) return '';
    return E.documentoBase(id, dados, { contexto: contexto });
  }

  /* Tudo o que define um modelo, num objeto só e desligado do armazenamento:
     é o que a pré-visualização da Biblioteca e a produção em lote consomem. */
  function retrato(id) {
    var m = obter(id);
    if (!m || !m.salvo) return null;
    return {
      id: m.id, titulo: m.nome, tipo: m.tipo, descricao: m.descricao,
      conteudo: m.html || '',
      definicoes: definicoesIniciais(id).definicoes,
      selecao: selecaoInicial(id),
      formato: CE.expediente.normalizarFormato(m.formato)
    };
  }

  /* ================================================================= CRUD */
  /* dados = { titulo, tipo, descricao, baseId, conteudo, definicoes, selecao,
               formato, assunto } */
  function salvarComo(dados) {
    var base = obter(dados.baseId);
    return T.salvarModelo(
      dados.titulo,
      base && base.salvo ? base.base : (dados.baseId || ID_EM_BRANCO),
      dados.conteudo || '',
      P.copiar(dados.definicoes),
      dados.assunto,
      dados.descricao,
      dados.selecao || [],
      { tipo: dados.tipo, formato: dados.formato }
    );
  }

  function atualizar(id, dados) {
    return T.atualizarModelo(id, {
      nome: dados.titulo,
      tipo: dados.tipo,
      descricao: dados.descricao,
      html: dados.conteudo,
      selecao: dados.selecao,
      personalizadas: P.copiar(dados.definicoes),
      formato: dados.formato
    });
  }

  function nomeArquivo(id, dados, protocolo) {
    var m = obter(id);
    var nome = m && m.salvo ? m.nome : 'Expediente';
    if (protocolo) nome += ' - ' + protocolo;
    return nome.replace(/[\\/:*?"<>|]/g, '-').replace(/\s+/g, ' ').trim();
  }

  CE.modelos = {
    ID_EM_BRANCO: ID_EM_BRANCO,
    TIPO_PADRAO: TIPO_PADRAO,
    tipos: tipos,
    salvarTipos: salvarTipos,
    obter: obter,
    salvos: salvos,
    grupos: grupos,
    selecaoInicial: selecaoInicial,
    definicoesIniciais: definicoesIniciais,
    textoInicial: textoInicial,
    retrato: retrato,
    salvarComo: salvarComo,
    atualizar: atualizar,
    duplicar: function (id) { return T.duplicarModelo(id); },
    excluir: function (id) { return T.excluirModelo(id); },
    favoritar: function (id, sim) { return T.marcarFavorito(id, sim); },
    registrarUso: function (id) { return T.registrarUso(id); },
    nomeArquivo: nomeArquivo
  };
})(window);
