/* =============================================================================
   storage.js — Persistência local do rascunho (localStorage)
   -----------------------------------------------------------------------------
   Único ponto de contato com o armazenamento do rascunho: trocar por IndexedDB
   ou por um backend exige alterar apenas este arquivo.
   Os modelos salvos continuam sob responsabilidade de templates.js.
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};

  var CHAVE = 'unijur_expediente_rascunho_v3';
  var CHAVE_V2 = 'unijur_ci_rascunho_v2';

  function lerJSON(chave) {
    try { return JSON.parse(localStorage.getItem(chave) || 'null'); }
    catch (e) { return null; }
  }

  /* Rascunho da versão anterior: { modelo, dados, custom, selecao, texto }.
     As propriedades próprias guardavam o valor junto da definição. */
  function migrarV2(o) {
    if (!o || !o.dados || !o.modelo) return null;
    var definicoes = {}, valores = {};
    var custom = Array.isArray(o.custom) ? (function () { var c = {}; c[o.modelo] = o.custom; return c; })()
               : (o.custom && typeof o.custom === 'object' ? o.custom : {});

    Object.keys(custom).forEach(function (modeloId) {
      var sep = CE.propriedades.separar(custom[modeloId]);
      definicoes[modeloId] = sep.definicoes;
      Object.keys(sep.valores).forEach(function (id) { valores[id] = sep.valores[id]; });
    });

    var dados = Object.assign({}, o.dados);
    var protocolo = dados.processo_administrativo || '';
    dados.processo_administrativo = '';

    return {
      versao: 3,
      protocolo: protocolo,
      modeloId: o.modelo,
      conteudo: typeof o.texto === 'string' ? o.texto : null,
      propriedades: { dados: dados, selecao: o.selecao || {}, definicoes: definicoes, valores: valores }
    };
  }

  function ler() {
    var atual = lerJSON(CHAVE);
    if (atual && atual.modeloId) return atual;
    return migrarV2(lerJSON(CHAVE_V2));
  }

  function salvar(rascunho) {
    try {
      localStorage.setItem(CHAVE, JSON.stringify(rascunho));
      return true;
    } catch (e) { return false; }
  }

  function apagar() {
    try {
      localStorage.removeItem(CHAVE);
      localStorage.removeItem(CHAVE_V2);
    } catch (e) { /* armazenamento indisponível */ }
  }

  CE.armazenamento = { ler: ler, salvar: salvar, apagar: apagar };
})(window);
