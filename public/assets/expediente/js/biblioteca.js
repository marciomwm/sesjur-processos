/* =============================================================================
   biblioteca.js — Biblioteca de Modelos
   -----------------------------------------------------------------------------
   Ponto central para localizar, visualizar, criar, editar, duplicar, usar,
   excluir e organizar modelos. Não tem editor próprio: criar e editar abrem o
   Criador de Expedientes com o modelo carregado.
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};
  var E = global.CI_ENGINE;
  var ui = CE.ui, M = CE.modelos;
  var esc = ui.esc, semAcento = ui.semAcento;

  var FAVORITOS = '__favoritos__';
  var ORDENACOES = [
    ['atualizado', 'Última atualização'],
    ['nome', 'Nome'],
    ['recentes', 'Mais recentes'],
    ['usados', 'Mais utilizados']
  ];

  var raiz = null;
  var filtro = { busca: '', tipo: null, ordem: 'atualizado' };

  /* ================================================================ DADOS */
  function colunas(m) { return CE.lotes.colunasDe(M.retrato(m.id)); }

  /* Tudo em que a busca procura: título, tipo, descrição, propriedades e o
     próprio texto do modelo. */
  function textoPesquisavel(m) {
    var props = colunas(m).map(function (c) { return c.titulo + ' ' + c.token; }).join(' ');
    return semAcento([m.nome, m.tipo, m.descricao, props, E.paraTexto(m.html || '')].join(' '));
  }

  function correspondeABusca(m) {
    var termos = semAcento(filtro.busca).split(/\s+/).filter(Boolean);
    if (!termos.length) return true;
    var texto = textoPesquisavel(m);
    return termos.every(function (t) { return texto.indexOf(t) !== -1; });
  }

  function correspondeAoTipo(m) {
    if (!filtro.tipo) return true;
    return filtro.tipo === FAVORITOS ? m.favorito : m.tipo === filtro.tipo;
  }

  var COMPARADORES = {
    nome: function (a, b) { return a.nome.localeCompare(b.nome, 'pt-BR'); },
    recentes: function (a, b) { return String(b.criadoEm || '').localeCompare(String(a.criadoEm || '')); },
    atualizado: function (a, b) { return String(b.atualizadoEm || '').localeCompare(String(a.atualizadoEm || '')); },
    usados: function (a, b) { return (b.usos || 0) - (a.usos || 0) || a.nome.localeCompare(b.nome, 'pt-BR'); }
  };

  function modelosVisiveis() {
    return M.salvos().filter(correspondeABusca).filter(correspondeAoTipo).sort(COMPARADORES[filtro.ordem]);
  }

  /* =============================================================== RENDER */
  function plural(n, um, varios) { return n + ' ' + (n === 1 ? um : varios); }

  function htmlChips() {
    var naBusca = M.salvos().filter(correspondeABusca);
    function chip(valor, rotulo, n) {
      var ativo = filtro.tipo === valor;
      return '<button type="button" class="chip' + (ativo ? ' ativo' : '') + '" data-tipo="' + esc(valor || '') +
             '" aria-pressed="' + ativo + '">' + esc(rotulo) + ' <span class="chip-n">' + n + '</span></button>';
    }
    var html = chip(null, 'Todos', naBusca.length);
    html += chip(FAVORITOS, '★ Favoritos', naBusca.filter(function (m) { return m.favorito; }).length);
    M.tipos().forEach(function (tipo) {
      var n = naBusca.filter(function (m) { return m.tipo === tipo; }).length;
      if (n || filtro.tipo === tipo) html += chip(tipo, tipo, n);
    });
    return html;
  }

  function htmlCartao(m) {
    var n = colunas(m).length;
    return '<article class="cartao-modelo" data-id="' + esc(m.id) + '">' +
      '<header><span class="cartao-tipo">' + esc(m.tipo) + '</span>' +
      '<button type="button" class="btn-estrela' + (m.favorito ? ' ativo' : '') + '" data-acao="favoritar" ' +
        'aria-pressed="' + !!m.favorito + '" title="' + (m.favorito ? 'Remover dos favoritos' : 'Marcar como favorito') + '">' +
        (m.favorito ? '★' : '☆') + '</button></header>' +
      '<h3>' + esc(m.nome) + '</h3>' +
      '<p class="cartao-desc">' + esc(m.descricao || 'Sem descrição.') + '</p>' +
      '<p class="cartao-meta">' + plural(n, 'propriedade', 'propriedades') +
        ' · Atualizado em ' + ui.dataCurta(m.atualizadoEm) +
        (m.usos ? ' · Utilizado ' + plural(m.usos, 'vez', 'vezes') : '') + '</p>' +
      '<footer><button type="button" class="btn btn-mini btn-principal" data-acao="usar">Usar modelo</button>' +
      '<details class="menu-cartao"><summary class="btn btn-mini" aria-label="Mais ações de ' + esc(m.nome) + '">⋮</summary>' +
        '<div class="menu">' +
          '<button type="button" data-acao="visualizar">Visualizar</button>' +
          '<button type="button" data-acao="editar">Editar</button>' +
          '<button type="button" data-acao="duplicar">Duplicar</button>' +
          '<button type="button" data-acao="lote">Criar documentos em lote</button>' +
          '<div class="menu-sep" role="separator"></div>' +
          '<button type="button" data-acao="excluir" class="item-perigo">Excluir</button>' +
        '</div></details></footer></article>';
  }

  function htmlLista() {
    var lista = modelosVisiveis();
    if (lista.length) return '<div class="grade-modelos">' + lista.map(htmlCartao).join('') + '</div>';
    if (!M.salvos().length) {
      return '<div class="vazio-props vazio-grande"><p class="vazio-titulo">A biblioteca ainda não tem modelos</p>' +
        '<p class="vazio-texto">Crie um modelo aqui, ou escreva um expediente no Criador e use “Salvar como modelo”.</p>' +
        '<button type="button" class="btn btn-principal" data-acao="novo">+ Novo modelo</button></div>';
    }
    return '<div class="vazio-props vazio-grande"><p class="vazio-titulo">Nenhum modelo encontrado</p>' +
      '<p class="vazio-texto">Ajuste a busca ou os filtros.</p>' +
      '<button type="button" class="btn" data-acao="limpar-filtros">Limpar filtros</button></div>';
  }

  /* Só a parte que muda com busca e filtros — o campo de busca mantém o foco. */
  function renderResultado() {
    raiz.querySelector('#bibChips').innerHTML = htmlChips();
    raiz.querySelector('#bibLista').innerHTML = htmlLista();
  }

  function render() {
    raiz.innerHTML =
      '<div class="pagina"><header class="pagina-topo"><h2>Biblioteca de Modelos</h2><span class="espaco"></span>' +
        '<button type="button" class="btn" data-acao="tipos">Gerenciar tipos</button>' +
        '<button type="button" class="btn btn-principal" data-acao="novo">+ Novo modelo</button></header>' +
      '<div class="bib-filtros">' +
        '<input type="search" id="bibBusca" class="busca busca-grande" placeholder="Buscar modelos..." ' +
          'aria-label="Buscar modelos" value="' + esc(filtro.busca) + '">' +
        '<label class="rotulo-linha" for="bibOrdem">Ordenar por</label>' +
        '<select id="bibOrdem" class="sel-fer">' + ORDENACOES.map(function (o) {
          return '<option value="' + o[0] + '"' + (o[0] === filtro.ordem ? ' selected' : '') + '>' + o[1] + '</option>';
        }).join('') + '</select></div>' +
      '<div id="bibChips" class="chips" role="group" aria-label="Filtrar por tipo"></div>' +
      '<div id="bibLista"></div></div>';
    renderResultado();
  }

  /* ================================================================ AÇÕES */
  function novoModelo() {
    ui.dialogo({
      titulo: 'Novo modelo',
      mensagem: 'O modelo é criado vazio e aberto no Criador de Expedientes para você escrever o texto e definir as propriedades.',
      campos: [
        { id: 'titulo', label: 'Título do modelo', placeholder: 'Ex.: Ofício de solicitação de informações' },
        { id: 'tipo', label: 'Tipo', valor: filtro.tipo && filtro.tipo !== FAVORITOS ? filtro.tipo : M.tipos()[0],
          opcoes: M.tipos().map(function (t) { return { valor: t, label: t }; }) },
        { id: 'descricao', label: 'Descrição', multilinha: true, placeholder: 'Quando usar este modelo' }
      ],
      okLabel: 'Criar e editar',
      validar: function (v) { return v.titulo ? null : 'Informe o título do modelo.'; }
    }).then(function (v) {
      if (!v) return;
      var r = M.salvarComo({ titulo: v.titulo, tipo: v.tipo, descricao: v.descricao, conteudo: '', definicoes: [], selecao: [] });
      if (!r.ok) { ui.toast(r.erro, 'erro'); return; }
      CE.criador.sincronizar();
      CE.nav.ir('#/criador?modelo=' + encodeURIComponent(r.id) + '&editar=1');
    });
  }

  function excluir(m) {
    ui.dialogo({
      titulo: 'Excluir modelo?',
      mensagem: '"' + m.nome + '" — Esta ação não excluirá documentos nem lotes anteriormente produzidos com este modelo.',
      okLabel: 'Excluir',
      perigo: true
    }).then(function (v) {
      if (!v) return;
      CE.criador.soltarModelo(m.id);
      M.excluir(m.id);
      CE.criador.sincronizar();
      renderResultado();
      ui.toast('Modelo excluído.');
    });
  }

  function htmlPropriedades(lista) {
    if (!lista.length) return '<p class="dica">Este modelo não tem propriedades.</p>';
    var tipos = {};
    CE.propriedades.TIPOS.forEach(function (t) { tipos[t.id] = t.rotulo; });
    return '<ul class="lista-props">' + lista.map(function (c) {
      return '<li><strong>' + esc(c.titulo) + '</strong> <code>{{' + esc(c.token) + '}}</code> <span class="dica">' +
        esc(tipos[c.tipo] || 'Texto') + (c.obrigatoria ? ' · obrigatória' : '') + '</span></li>';
    }).join('') + '</ul>';
  }

  /* Pré-visualização rápida, sem entrar no modo de edição. */
  function visualizar(m) {
    var r = M.retrato(m.id);
    var cols = CE.lotes.colunasDe(r);
    var previa = CE.lotes.htmlPrevia({ modelo: r, colunas: cols }, { values: {}, contentOverride: null });

    var dlg = document.createElement('dialog');
    dlg.className = 'dlg dlg-largo';
    dlg.innerHTML =
      '<header class="dlg-topo"><div><span class="cartao-tipo">' + esc(m.tipo) + '</span><h2>' + esc(m.nome) + '</h2></div>' +
        '<button type="button" class="btn-prop" data-acao="fechar" aria-label="Fechar" title="Fechar">✕</button></header>' +
      (m.descricao ? '<p class="dlg-msg">' + esc(m.descricao) + '</p>' : '') +
      '<div class="previa-modelo"><section><h3>Propriedades <span class="conta">' + cols.length + '</span></h3>' +
        htmlPropriedades(cols) + '</section>' +
      '<section><h3>Documento</h3><div class="folha folha-previa">' +
        (previa.trim() ? previa : '<p class="dica">O modelo ainda não tem texto.</p>') + '</div></section></div>' +
      '<div class="botoes dlg-botoes"><button type="button" class="btn" data-acao="lote">Criar documentos em lote</button>' +
        '<button type="button" class="btn btn-principal" data-acao="usar">Usar modelo</button></div>';

    function fechar() { try { dlg.close(); } catch (e) { /* já fechado */ } dlg.remove(); }
    dlg.addEventListener('cancel', function (ev) { ev.preventDefault(); fechar(); });
    dlg.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-acao]');
      if (!b) return;
      fechar();
      if (b.dataset.acao !== 'fechar') executar(b.dataset.acao, m);
    });
    CE.ui.raiz().appendChild(dlg);
    CE.expediente.aplicarFormato(dlg.querySelector('.folha'), r.formato);
    dlg.showModal();
  }

  /* Criar, renomear e excluir tipos de modelo. */
  function gerenciarTipos() {
    var dlg = document.createElement('dialog');
    dlg.className = 'dlg';
    dlg.innerHTML = '<h2>Tipos de modelo</h2>' +
      '<p class="dlg-msg">Renomear um tipo atualiza os modelos que o usam. Ao excluir, os modelos daquele tipo passam para “' +
        esc(M.TIPO_PADRAO) + '”.</p><div class="lista-opcoes"></div>' +
      '<button type="button" class="btn btn-mini" data-acao="mais">+ Novo tipo</button>' +
      '<div class="botoes dlg-botoes"><button type="button" class="btn btn-fantasma" data-acao="cancelar">Cancelar</button>' +
      '<button type="button" class="btn btn-principal" data-acao="salvar">Salvar</button></div>';
    var caixa = dlg.querySelector('.lista-opcoes');
    var emUso = {};
    M.salvos().forEach(function (m) { emUso[m.tipo] = (emUso[m.tipo] || 0) + 1; });

    function linha(original) {
      var fixo = original === M.TIPO_PADRAO;
      var r = document.createElement('div');
      r.className = 'linha-opcao';
      r.dataset.original = original || '';
      r.innerHTML = '<input type="text" aria-label="Nome do tipo" placeholder="Nome do tipo" value="' + esc(original || '') + '"' +
        (fixo ? ' disabled title="Tipo padrão: recebe os modelos de tipos excluídos"' : '') + '>' +
        '<span class="dica">' + (emUso[original] ? plural(emUso[original], 'modelo', 'modelos') : '') + '</span>' +
        (fixo ? '' : '<button type="button" class="btn-prop btn-prop-perigo" data-acao="remover" title="Excluir tipo" aria-label="Excluir tipo">✕</button>');
      caixa.appendChild(r);
      return r.querySelector('input');
    }
    M.tipos().forEach(linha);

    function fechar() { try { dlg.close(); } catch (e) { /* já fechado */ } dlg.remove(); }
    dlg.addEventListener('cancel', function (ev) { ev.preventDefault(); fechar(); });
    dlg.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-acao]');
      if (!b) return;
      if (b.dataset.acao === 'mais') linha('').focus();
      if (b.dataset.acao === 'remover') b.closest('.linha-opcao').remove();
      if (b.dataset.acao === 'cancelar') fechar();
      if (b.dataset.acao === 'salvar') {
        var edicoes = Array.prototype.map.call(caixa.children, function (r) {
          return { original: r.dataset.original || null, nome: r.querySelector('input').value };
        });
        M.salvarTipos(edicoes);
        fechar();
        if (filtro.tipo && filtro.tipo !== FAVORITOS && M.tipos().indexOf(filtro.tipo) === -1) filtro.tipo = null;
        CE.criador.sincronizar();
        renderResultado();
        ui.toast('Tipos de modelo atualizados.');
      }
    });
    CE.ui.raiz().appendChild(dlg);
    dlg.showModal();
  }

  function executar(acao, m) {
    switch (acao) {
      case 'usar': CE.nav.ir('#/criador?modelo=' + encodeURIComponent(m.id)); break;
      case 'editar': CE.nav.ir('#/criador?modelo=' + encodeURIComponent(m.id) + '&editar=1'); break;
      case 'lote': CE.nav.ir('#/lote?modelo=' + encodeURIComponent(m.id)); break;
      case 'visualizar': visualizar(m); break;
      case 'excluir': excluir(m); break;
      case 'favoritar': M.favoritar(m.id, !m.favorito); renderResultado(); break;
      case 'duplicar':
        var r = M.duplicar(m.id);
        if (!r.ok) { ui.toast(r.erro, 'erro'); return; }
        CE.criador.sincronizar();
        renderResultado();
        ui.toast('Modelo duplicado: "' + M.obter(r.id).nome + '".');
        break;
    }
  }

  function aoClicar(ev) {
    var chip = ev.target.closest('.chip');
    if (chip) { filtro.tipo = chip.dataset.tipo || null; renderResultado(); return; }

    var b = ev.target.closest('[data-acao]');
    if (!b) return;
    var acao = b.dataset.acao;
    if (acao === 'novo') { novoModelo(); return; }
    if (acao === 'tipos') { gerenciarTipos(); return; }
    if (acao === 'limpar-filtros') { filtro.busca = ''; filtro.tipo = null; render(); return; }

    var cartao = b.closest('.cartao-modelo');
    var m = cartao && M.obter(cartao.dataset.id);
    if (!m) return;
    var menu = b.closest('details');
    if (menu) menu.open = false;
    executar(acao, m);
  }

  function iniciar(elemento) {
    raiz = elemento;
    raiz.addEventListener('click', aoClicar);
    raiz.addEventListener('input', function (ev) {
      if (ev.target.id === 'bibBusca') { filtro.busca = ev.target.value; renderResultado(); }
    });
    raiz.addEventListener('change', function (ev) {
      if (ev.target.id === 'bibOrdem') { filtro.ordem = ev.target.value; renderResultado(); }
    });
    /* os menus "⋮" são <details>: fecham ao clicar fora */
    document.addEventListener('click', function (ev) {
      Array.prototype.forEach.call(document.querySelectorAll('details.menu-cartao[open]'), function (d) {
        if (!d.contains(ev.target)) d.open = false;
      });
    });
  }

  CE.biblioteca = { iniciar: iniciar, render: render };
})(window);
