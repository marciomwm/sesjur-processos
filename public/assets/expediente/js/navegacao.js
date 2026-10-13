/* =============================================================================
   navegacao.js — Telas e rotas
   -----------------------------------------------------------------------------
   #/criador                       Criador, documento individual
   #/criador?modelo=<id>           abre o modelo no Criador ("Usar modelo")
   #/criador?modelo=<id>&editar=1  abre o modelo para manutenção
   #/lote                          produção em lote (lote aberto ou tela inicial)
   #/lote?modelo=<id>              novo lote a partir de um modelo
   #/lote?id=<id>                  continua um lote salvo
   #/biblioteca                    Biblioteca de Modelos
   #/lotes                         lotes salvos

   Rotas por "#": a aplicação abre por duplo clique, sem servidor.
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};
  var VISOES = { criador: 'visaoCriador', lote: 'visaoLote', biblioteca: 'visaoBiblioteca', lotes: 'visaoLotes' };

  function rotaAtual() {
    var partes = global.location.hash.replace(/^#\/?/, '').split('?');
    var params = {};
    (partes[1] || '').split('&').forEach(function (par) {
      if (!par) return;
      var i = par.indexOf('=');
      params[decodeURIComponent(i < 0 ? par : par.slice(0, i))] = i < 0 ? '1' : decodeURIComponent(par.slice(i + 1));
    });
    return { nome: VISOES[partes[0]] ? partes[0] : 'criador', params: params };
  }

  function mostrar(nome) {
    document.body.dataset.visao = nome;
    Object.keys(VISOES).forEach(function (v) { document.getElementById(VISOES[v]).hidden = v !== nome; });

    /* "Criador de Expedientes" cobre os dois modos de produção */
    var secao = nome === 'lote' ? 'criador' : nome;
    Array.prototype.forEach.call(document.querySelectorAll('[data-nav]'), function (a) {
      if (a.dataset.nav === secao) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-modo]'), function (a) {
      a.classList.toggle('ativo', a.dataset.modo === nome);
      a.setAttribute('aria-pressed', String(a.dataset.modo === nome));
    });
    document.getElementById('modoProducao').hidden = secao !== 'criador';
    CE.ui.fecharMenus();
  }

  function aoMudarRota() {
    var r = rotaAtual();
    mostrar(r.nome);

    if (r.nome === 'biblioteca') CE.biblioteca.render();
    else if (r.nome === 'lotes') CE.loteUI.renderLista();
    else if (r.nome === 'lote') CE.loteUI.abrirRota(r.params);
    else if (r.params.modelo) {
      CE.criador.abrirModelo(r.params.modelo, { editar: r.params.editar === '1' }).then(function () {
        global.history.replaceState(null, '', '#/criador');
      });
    }
  }

  function ir(hash) {
    if (global.location.hash === hash) aoMudarRota();
    else global.location.hash = hash;
  }

  function iniciar() {
    CE.biblioteca.iniciar(document.getElementById('visaoBiblioteca'));
    CE.loteUI.iniciar(document.getElementById('visaoLote'), document.getElementById('visaoLotes'));
    global.addEventListener('hashchange', aoMudarRota);
    aoMudarRota();
  }

  CE.nav = { ir: ir };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})(window);
