/* =============================================================================
   sesjur.js — Dados dos processos gravados no SESJUR
   -----------------------------------------------------------------------------
   Único ponto de contato com a API do SESJUR. O expediente é vinculado a um
   Processo Administrativo; as propriedades do catálogo recebem os atributos
   dele e os do Processo Judicial e do Processo Extrajudicial vinculados que o
   assessor escolher.

   pacote = {
     processo        { id, protocolo, resumo }
     administrativo  { chave → valor }
     judiciais       [{ id, rotulo, detalhe, dados: { chave → valor } }]
     extrajudiciais  [{ id, rotulo, detalhe, dados: { chave → valor } }]
   }
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};
  var T = global.CI_TEMPLATES;
  var CFG = global.SESJUR_EXPEDIENTE || {};

  var MSG_FALHA = 'Não foi possível consultar o SESJUR. Verifique a conexão e tente novamente.';

  function endereco(base, caminho, params) {
    var qs = Object.keys(params || {}).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }).join('&');
    return String(base || '').replace(/\/$/, '') + '/' + caminho + (qs ? '?' + qs : '');
  }

  /* Devolve sempre { ok, ... }: a interface não precisa tratar exceções. */
  function pedir(caminho, params) {
    return fetch(endereco(CFG.apiUrl, caminho, params), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (resposta) {
      return resposta.json().then(function (dados) {
        if (dados && dados.success) return Object.assign({ ok: true }, dados);
        return { ok: false, mensagem: (dados && dados.error && dados.error.message) || MSG_FALHA };
      });
    }).catch(function () { return { ok: false, mensagem: MSG_FALHA }; });
  }

  /* Processos administrativos por protocolo ou objeto: [{ id, rotulo, detalhe }]. */
  function buscar(termo) {
    return pedir('pesquisar-processos-administrativos.php', { termo: termo }).then(function (r) {
      return r.ok ? { ok: true, itens: r.itens || [] } : r;
    });
  }

  function carregar(id) {
    return pedir('expediente-dados.php', { id: id }).then(function (r) {
      if (!r.ok) return r;
      return { ok: true, pacote: {
        processo: r.processo, administrativo: r.administrativo || {},
        judiciais: r.judiciais || [], extrajudiciais: r.extrajudiciais || []
      } };
    });
  }

  function relacionado(lista, id) {
    return (lista || []).filter(function (item) { return item.id === id; })[0] || null;
  }

  /* O vinculado em uso: o que já estava escolhido, se continua vinculado;
     senão, o primeiro. Sem vinculados, null. */
  function escolhido(lista, id) {
    var item = relacionado(lista, id) || (lista || [])[0];
    return item ? item.id : null;
  }

  /* Valores de TODAS as propriedades do catálogo, por chave. O que o processo
     não tem (ou sem processo) fica vazio. */
  function valores(pacote, judicialId, extrajudicialId) {
    var v = T.dadosVazios();
    if (!pacote) return v;
    var pj = relacionado(pacote.judiciais, judicialId);
    var pe = relacionado(pacote.extrajudiciais, extrajudicialId);
    [pacote.administrativo, pj && pj.dados, pe && pe.dados].forEach(function (dados) {
      Object.keys(dados || {}).forEach(function (chave) {
        if (chave in v) v[chave] = String(dados[chave] == null ? '' : dados[chave]);
      });
    });
    return v;
  }

  function urlFicha(id) {
    return endereco(CFG.baseUrl, 'processo-administrativo.php', { id: id });
  }

  CE.sesjur = {
    /* processo com que a página foi aberta (expediente.php?processo_id=) */
    processoInicial: CFG.processoId || null,
    buscar: buscar,
    carregar: carregar,
    relacionado: relacionado,
    escolhido: escolhido,
    valores: valores,
    urlFicha: urlFicha
  };
})(window);
