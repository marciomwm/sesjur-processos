/* =============================================================================
   lote-ui.js — Tela de produção em lote (mala direta) e lista de lotes
   -----------------------------------------------------------------------------
   Três abas: Planilha de dados · Pré-visualização · Resultado.
   As regras (colunas, validação, nomes, renderização) estão em lotes.js; aqui
   fica só a interface. A planilha é paginada e os documentos são produzidos sob
   demanda, em pequenos blocos — nunca centenas de folhas no DOM ao mesmo tempo.
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};
  var ui = CE.ui, L = CE.lotes, M = CE.modelos, X = CE.exportar, EX = CE.expediente, PL = CE.planilha;
  var esc = ui.esc, toast = ui.toast, dialogo = ui.dialogo;

  var POR_PAGINA = 50;
  var DOCUMENTO_ABERTO = '__atual__';

  var raiz = null, raizLista = null;
  var lote = null;
  var aba = 'planilha';
  var pagina = 0;
  var soProblemas = false;
  var selecionados = {};        /* itemId -> true */
  var validacao = null;
  var mostrarResumo = false;
  var indicePrevia = 0;
  var indiceProblema = -1;
  var timerSalvar = null, timerResumo = null;

  function plural(n, um, varios) { return n + ' ' + (n === 1 ? um : varios); }
  function $(seletor) { return raiz.querySelector(seletor); }

  /* ========================================================= PERSISTÊNCIA */
  function salvarAgora() {
    clearTimeout(timerSalvar);
    timerSalvar = null;
    if (!lote) return;
    var ok = L.salvar(lote);
    var ind = $('#loteSalvo');
    if (ind) {
      ind.textContent = ok ? 'Lote salvo às ' + new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
                           : 'Não foi possível salvar o lote neste navegador';
      ind.classList.toggle('falha', !ok);
    }
  }

  function salvarDepois() {
    clearTimeout(timerSalvar);
    timerSalvar = setTimeout(salvarAgora, 400);
  }

  function revalidar() { validacao = L.validar(lote); return validacao; }

  /* Toda alteração de dados passa por aqui. */
  function mudou(item) {
    if (item) item.generatedAt = null;
    salvarDepois();
    clearTimeout(timerResumo);
    timerResumo = setTimeout(function () { revalidar(); renderResumo(); renderRodape(); }, 200);
  }

  /* ============================================================= SELEÇÃO */
  function itensSelecionados() { return lote.items.filter(function (i) { return selecionados[i.id]; }); }

  function comProblema(item) {
    var s = validacao.porItem[item.id].status;
    return s === L.STATUS.ERRO || s === L.STATUS.INCOMPLETO;
  }

  function itensVisiveis() { return soProblemas ? lote.items.filter(comProblema) : lote.items; }

  /* =================================================== OPÇÕES DE MODELO */
  function opcoesDeModelo() {
    var opcoes = [];
    if (CE.criador.temConteudo()) {
      opcoes.push({ valor: DOCUMENTO_ABERTO, label: 'Documento aberto no Criador de Expedientes' });
    }
    M.salvos().forEach(function (m) { opcoes.push({ valor: m.id, label: m.tipo + ' · ' + m.nome }); });
    return opcoes;
  }

  function retratoDe(valor) {
    if (valor === DOCUMENTO_ABERTO) return CE.criador.retratoAtual();
    M.registrarUso(valor);
    return M.retrato(valor);
  }

  /* ============================================================= INÍCIO */
  function renderInicio() {
    var opcoes = opcoesDeModelo();
    var recentes = L.listar().slice(0, 5);
    raiz.innerHTML = '<div class="pagina pagina-estreita"><header class="pagina-topo"><h2>Documentos em lote</h2></header>' +
      '<p class="dica-pagina">Um mesmo modelo gera vários documentos: cada linha da planilha é um documento e cada coluna é uma propriedade do modelo.</p>' +
      (opcoes.length
        ? '<form id="loteNovo" class="cartao-form"><div class="campo"><label for="loteNovoModelo">Modelo</label>' +
            '<select id="loteNovoModelo">' + opcoes.map(function (o) {
              return '<option value="' + esc(o.valor) + '">' + esc(o.label) + '</option>';
            }).join('') + '</select></div>' +
            '<div class="campo"><label for="loteNovoTitulo">Título do lote</label>' +
            '<input type="text" id="loteNovoTitulo" placeholder="Ex.: Ofícios às Unidades — Outubro/2026" autocomplete="off">' +
            '<div class="ajuda">Em branco, o título é gerado automaticamente.</div></div>' +
            '<div class="botoes"><button type="submit" class="btn btn-principal">Iniciar lote</button></div></form>'
        : '<div class="vazio-props vazio-grande"><p class="vazio-titulo">Ainda não há modelo para o lote</p>' +
            '<p class="vazio-texto">Escreva um expediente no Criador ou crie um modelo na Biblioteca; depois volte aqui.</p>' +
            '<a class="btn btn-principal" href="#/biblioteca">Abrir a Biblioteca de Modelos</a></div>') +
      (recentes.length ? '<h3 class="sub-pagina">Lotes recentes</h3>' + htmlListaDeLotes(recentes) : '') + '</div>';
  }

  function iniciarLote(ev) {
    ev.preventDefault();
    abrir(L.criar({ titulo: $('#loteNovoTitulo').value, modelo: retratoDe($('#loteNovoModelo').value) }));
    salvarAgora();
    history.replaceState(null, '', '#/lote?id=' + encodeURIComponent(lote.id));
  }

  function abrir(novo) {
    lote = novo;
    aba = 'planilha';
    pagina = 0;
    soProblemas = false;
    selecionados = {};
    mostrarResumo = false;
    indicePrevia = 0;
    indiceProblema = -1;
    if (lote) revalidar();
    render();
  }

  /* ============================================================ ESTRUTURA */
  function render() {
    if (!lote) { renderInicio(); return; }
    raiz.innerHTML =
      '<div class="lote"><div class="lote-topo">' +
        '<span class="lote-modelo">Modelo: <strong>' + esc(lote.modelo.titulo) + '</strong></span>' +
        '<button type="button" class="btn btn-mini" data-acao="trocar-modelo">Trocar</button>' +
        '<label class="so-leitor" for="loteTitulo">Título do lote</label>' +
        '<input type="text" id="loteTitulo" class="lote-titulo" value="' + esc(lote.titulo) + '" title="Título do lote">' +
        '<span id="loteSalvo" class="indicador-salvo"></span></div>' +
      '<div class="abas" role="tablist">' +
        [['planilha', 'Planilha de dados'], ['previa', 'Pré-visualização'], ['resultado', 'Resultado']].map(function (a) {
          return '<button type="button" role="tab" data-aba="' + a[0] + '" aria-selected="' + (aba === a[0]) + '"' +
                 (aba === a[0] ? ' class="ativo"' : '') + '>' + a[1] + '</button>';
        }).join('') + '</div>' +
      '<div id="loteCorpo" class="lote-corpo"></div>' +
      '<div id="loteRodape" class="lote-rodape"></div></div>' +
      '<input type="file" id="loteArquivo" accept=".csv,.xlsx" hidden>';
    renderAba();
    renderRodape();
  }

  function renderAba() {
    if (aba === 'planilha') renderPlanilha();
    else if (aba === 'previa') renderPrevia();
    else renderResultado();
  }

  function renderRodape() {
    var r = validacao.resumo;
    $('#loteRodape').innerHTML =
      '<span class="dica">' + plural(r.total, 'registro', 'registros') + ' · ' + r.prontos + ' válidos · ' + r.gerados + ' gerados</span>' +
      '<span class="espaco"></span>' +
      '<button type="button" class="btn" data-acao="validar">Validar</button>' +
      '<button type="button" class="btn btn-principal" data-acao="gerar">Gerar documentos</button>';
  }

  /* ============================================================= PLANILHA */
  function htmlControle(item, c, problema) {
    var valor = String(item.values[c.token] || '');
    var comum = ' data-token="' + esc(c.token) + '" aria-label="' + esc(c.titulo) + '"' +
      ' class="cel' + (problema ? ' cel-' + problema.tipo : '') + '"' +
      (problema ? ' title="' + esc(problema.msg) + '"' : '');

    if (c.tipo === 'lista') {
      var fora = valor && c.opcoes.indexOf(valor) === -1;
      return '<select' + comum + '><option value=""></option>' +
        (fora ? '<option value="' + esc(valor) + '" selected>' + esc(valor) + ' (inexistente)</option>' : '') +
        c.opcoes.map(function (o) {
          return '<option value="' + esc(o) + '"' + (o === valor ? ' selected' : '') + '>' + esc(o) + '</option>';
        }).join('') + '</select>';
    }
    /* data não reconhecida fica em campo de texto, para o assessor ver e corrigir */
    var ehData = c.tipo === 'data' && (!valor || /^\d{4}-\d{2}-\d{2}$/.test(valor));
    return '<input type="' + (ehData ? 'date' : 'text') + '"' + (c.tipo === 'numero' ? ' inputmode="decimal"' : '') +
           comum + ' value="' + esc(valor) + '">';
  }

  function htmlStatus(item, r) {
    return '<span class="status status-' + r.status + '">' + L.ROTULOS[r.status] + '</span>' +
      (r.duplicado ? '<span class="status status-aviso" title="Há outro registro com os mesmos dados">Duplicado</span>' : '') +
      (item.contentOverride != null ? '<span class="status status-aviso" title="Este documento tem texto próprio">Texto próprio</span>' : '');
  }

  function htmlLinha(item) {
    var r = validacao.porItem[item.id];
    var n = lote.items.indexOf(item) + 1;
    return '<tr data-id="' + item.id + '"' + (selecionados[item.id] ? ' class="selecionada"' : '') + '>' +
      '<td class="col-fixa"><input type="checkbox" class="sel" aria-label="Selecionar linha ' + n + '"' +
        (selecionados[item.id] ? ' checked' : '') + '></td>' +
      '<td class="col-num">' + n + '</td>' +
      '<td class="col-status">' + htmlStatus(item, r) + '</td>' +
      lote.colunas.map(function (c) { return '<td>' + htmlControle(item, c, r.celulas[c.token]) + '</td>'; }).join('') +
      '<td class="col-acoes">' +
        '<button type="button" class="btn-prop" data-acao="ver" title="Visualizar este documento" aria-label="Visualizar documento da linha ' + n + '">👁</button>' +
        '<button type="button" class="btn-prop" data-acao="duplicar" title="Duplicar linha" aria-label="Duplicar linha ' + n + '">⧉</button>' +
        '<button type="button" class="btn-prop" data-acao="limpar" title="Limpar linha" aria-label="Limpar linha ' + n + '">⌫</button>' +
        '<button type="button" class="btn-prop btn-prop-perigo" data-acao="excluir" title="Excluir linha" aria-label="Excluir linha ' + n + '">✕</button>' +
      '</td></tr>';
  }

  function htmlResumo() {
    if (!mostrarResumo) return '';
    var r = validacao.resumo, total = validacao.problemas.length;
    var naveg = total
      ? '<span class="naveg-erros"><button type="button" class="btn btn-mini" data-acao="erro-anterior">← Anterior</button>' +
        '<span>' + (indiceProblema >= 0 ? 'Erro ' + (indiceProblema + 1) + ' de ' + total : plural(total, 'pendência', 'pendências')) + '</span>' +
        '<button type="button" class="btn btn-mini" data-acao="erro-proximo">Próximo →</button></span>'
      : '';
    return '<div class="resumo-validacao" role="status"><strong>Validação do lote</strong> ' +
      '<span>' + plural(r.total, 'registro analisado', 'registros analisados') + '</span>' +
      '<span class="rv-ok">✓ ' + plural(r.prontos, 'pronto', 'prontos') + '</span>' +
      '<span class="rv-aviso">⚠ ' + plural(r.incompletos, 'incompleto', 'incompletos') + '</span>' +
      '<span class="rv-erro">✕ ' + r.erros + ' com erros</span>' +
      (r.duplicados ? '<span class="rv-aviso">' + plural(r.duplicados, 'duplicado', 'duplicados') + '</span>' : '') +
      '<span class="espaco"></span>' + naveg + '</div>';
  }

  function renderResumo() {
    var caixa = $('#loteResumo');
    if (caixa) caixa.innerHTML = htmlResumo();
  }

  function htmlBarraDeSelecao() {
    var n = itensSelecionados().length;
    if (!n) return '';
    return '<span class="grupo-sel"><strong>' + plural(n, 'selecionada', 'selecionadas') + '</strong>' +
      '<button type="button" class="btn btn-mini" data-acao="sel-duplicar">Duplicar</button>' +
      '<button type="button" class="btn btn-mini" data-acao="sel-gerar">Gerar selecionadas</button>' +
      '<button type="button" class="btn btn-mini btn-perigo" data-acao="sel-excluir">Excluir</button></span>';
  }

  function renderPlanilha() {
    var visiveis = itensVisiveis();
    var paginas = Math.max(1, Math.ceil(visiveis.length / POR_PAGINA));
    pagina = Math.min(pagina, paginas - 1);
    var daPagina = visiveis.slice(pagina * POR_PAGINA, (pagina + 1) * POR_PAGINA);
    var todasMarcadas = daPagina.length && daPagina.every(function (i) { return selecionados[i.id]; });

    $('#loteCorpo').innerHTML =
      '<div class="lote-barra">' +
        '<button type="button" class="btn btn-mini btn-principal" data-acao="adicionar">+ Adicionar linha</button>' +
        '<button type="button" class="btn btn-mini" data-acao="importar">Importar planilha</button>' +
        '<button type="button" class="btn btn-mini" data-acao="baixar-planilha">Baixar planilha para preenchimento</button>' +
        '<button type="button" class="btn btn-mini" data-acao="preencher">Preencher coluna…</button>' +
        htmlBarraDeSelecao() +
        '<span class="espaco"></span>' +
        '<label class="linha-check"><input type="checkbox" id="loteSoProblemas"' + (soProblemas ? ' checked' : '') +
          '><span>Mostrar apenas registros com erro</span></label></div>' +
      '<div id="loteResumo">' + htmlResumo() + '</div>' +
      (lote.colunas.length ? '' : '<div class="aviso aviso-alerta">O modelo não tem propriedades: todos os documentos sairiam iguais. ' +
        'Acrescente propriedades ao modelo e use “Trocar” para recarregá-lo.</div>') +
      '<div class="grade-rolagem"><table class="grade"><thead><tr>' +
        '<th class="col-fixa"><input type="checkbox" id="loteSelTodas" aria-label="Selecionar as linhas desta página"' +
          (todasMarcadas ? ' checked' : '') + '></th><th class="col-num">#</th><th class="col-status">Status</th>' +
        lote.colunas.map(function (c) {
          return '<th title="{{' + esc(c.token) + '}}' + (c.descricao ? ' — ' + esc(c.descricao) : '') + '">' + esc(c.titulo) +
                 (c.obrigatoria ? ' <span class="obrig" title="Obrigatória">*</span>' : '') + '</th>';
        }).join('') + '<th class="col-acoes"><span class="so-leitor">Ações</span></th></tr></thead>' +
        '<tbody>' + (daPagina.length ? daPagina.map(htmlLinha).join('')
          : '<tr><td class="grade-vazia" colspan="' + (lote.colunas.length + 4) + '">' +
            (soProblemas ? 'Nenhum registro com erro ou incompleto.' : 'Nenhuma linha. Use “+ Adicionar linha”, cole do Excel ou importe uma planilha.') + '</td></tr>') +
        '</tbody></table></div>' +
      '<div class="paginacao"><span class="dica">Cole células copiadas do Excel, Calc ou Google Sheets diretamente na grade (Ctrl+V).</span>' +
        '<span class="espaco"></span>' +
        (paginas > 1
          ? '<button type="button" class="btn btn-mini" data-acao="pag-anterior"' + (pagina === 0 ? ' disabled' : '') + '>←</button>' +
            '<span>Página ' + (pagina + 1) + ' de ' + paginas + '</span>' +
            '<button type="button" class="btn btn-mini" data-acao="pag-proxima"' + (pagina >= paginas - 1 ? ' disabled' : '') + '>→</button>'
          : '') + '</div>';
  }

  /* Atualiza uma linha sem redesenhar a grade (o foco continua na célula). */
  function atualizarLinha(tr, item) {
    var r = L.validarItem(lote, item);
    validacao.porItem[item.id] = r;
    tr.querySelector('.col-status').innerHTML = htmlStatus(item, r);
    Array.prototype.forEach.call(tr.querySelectorAll('[data-token]'), function (campo) {
      var p = r.celulas[campo.dataset.token];
      campo.classList.toggle('cel-erro', !!p && p.tipo === 'erro');
      campo.classList.toggle('cel-falta', !!p && p.tipo === 'falta');
      campo.classList.remove('cel-foco');
      if (p) campo.title = p.msg; else campo.removeAttribute('title');
    });
  }

  function itemDaLinha(no) {
    var tr = no.closest('tr[data-id]');
    return tr ? lote.items.filter(function (i) { return i.id === tr.dataset.id; })[0] : null;
  }

  function coluna(token) { return lote.colunas.filter(function (c) { return c.token === token; })[0]; }

  function aoEditarCelula(campo) {
    var item = itemDaLinha(campo);
    var c = coluna(campo.dataset.token);
    if (!item || !c) return;
    var valor = campo.type === 'date' || campo.tagName === 'SELECT' ? campo.value : L.coagir(c, campo.value);
    item.values[c.token] = valor;
    mudou(item);
    atualizarLinha(campo.closest('tr'), item);
  }

  /* ----------------------------------------------------------- colagem */
  function pareceCabecalho(linha) {
    var iguais = linha.filter(function (cel) {
      return lote.colunas.some(function (c) { return cel.trim() === c.titulo || cel.trim() === c.token; });
    }).length;
    return iguais >= 2 || (iguais === 1 && linha.length === 1 && lote.colunas.length === 1);
  }

  /* Grava uma matriz de valores a partir de (linha, coluna), criando linhas. */
  function despejar(matriz, itens, linhaInicial, colunaInicial) {
    var celulas = 0;
    matriz.forEach(function (valores, l) {
      var item = itens[linhaInicial + l];
      if (!item) { item = L.novoItem(); lote.items.push(item); itens.push(item); }
      valores.forEach(function (valor, c) {
        var col = lote.colunas[colunaInicial + c];
        if (!col) return;
        item.values[col.token] = L.coagir(col, valor);
        celulas++;
      });
      item.generatedAt = null;
    });
    return celulas;
  }

  function aoColar(ev) {
    var campo = ev.target.closest('[data-token]');
    var texto = ev.clipboardData && ev.clipboardData.getData('text/plain');
    if (!campo || !texto) return;
    var limpo = texto.replace(/\r?\n$/, '');
    if (limpo.indexOf('\t') === -1 && limpo.indexOf('\n') === -1) return;   /* um valor só: colagem normal */

    ev.preventDefault();
    var matriz = PL.lerTabela(texto, '\t');
    if (matriz.length > 1 && pareceCabecalho(matriz[0])) matriz.shift();

    var itens = itensVisiveis().slice();
    var item = itemDaLinha(campo);
    var n = despejar(matriz, itens, itens.indexOf(item), lote.colunas.indexOf(coluna(campo.dataset.token)));
    revalidar();
    mudou();
    renderPlanilha();
    toast(plural(n, 'célula colada', 'células coladas') + ' em ' + plural(matriz.length, 'linha', 'linhas') + '.');
  }

  /* -------------------------------------------------------- importação */
  var AJUDA_DA_CONFIANCA = {
    exata: 'Correspondência identificada pelo nome.',
    sugerida: 'Sugestão por semelhança — confira.',
    duvida: 'Não foi possível determinar a propriedade correspondente. Selecione.'
  };

  function importar(arquivo) {
    PL.lerArquivo(arquivo).then(function (planilha) {
      var sugestoes = PL.sugerirMapeamento(planilha.cabecalhos, lote.colunas);
      var opcoes = [{ valor: '', label: '— selecione —' }, { valor: '__ignorar__', label: 'Ignorar esta coluna' }]
        .concat(lote.colunas.map(function (c) { return { valor: c.token, label: c.titulo }; }));
      var temDados = lote.items.some(function (i) { return !validacao.porItem[i.id].vazio; });

      var campos = sugestoes.map(function (s, i) {
        return { id: 'c' + i, label: 'Coluna da planilha: "' + (s.cabecalho || '(sem título)') + '"',
                 valor: s.token || (s.cabecalho ? '' : '__ignorar__'), opcoes: opcoes, ajuda: AJUDA_DA_CONFIANCA[s.confianca] };
      });
      campos.push({ id: 'modo', label: 'Registros existentes', valor: 'acrescentar', opcoes: [
        { valor: 'acrescentar', label: 'Manter e acrescentar os importados' },
        { valor: 'substituir', label: 'Substituir pelos importados' }
      ], visivelSe: function () { return temDados; } });

      return dialogo({
        titulo: 'Importar planilha',
        mensagem: plural(planilha.linhas.length, 'registro encontrado', 'registros encontrados') + ' em "' + arquivo.name +
                  '". Confira a que propriedade do modelo corresponde cada coluna.',
        campos: campos,
        okLabel: 'Importar',
        validar: function (v) {
          var usados = {};
          for (var i = 0; i < sugestoes.length; i++) {
            var t = v['c' + i];
            if (!t) return 'Indique a propriedade da coluna "' + sugestoes[i].cabecalho + '" ou escolha ignorá-la.';
            if (t === '__ignorar__') continue;
            if (usados[t]) return 'Duas colunas apontam para a mesma propriedade: ' + coluna(t).titulo + '.';
            usados[t] = true;
          }
          return Object.keys(usados).length ? null : 'Nenhuma coluna foi associada a uma propriedade.';
        }
      }).then(function (v) {
        if (!v) return;
        var novos = planilha.linhas.map(function (linha) {
          var valores = {};
          sugestoes.forEach(function (s, i) {
            var c = coluna(v['c' + i]);
            if (c) valores[c.token] = L.coagir(c, linha[i]);
          });
          return L.novoItem(valores);
        });
        var manter = temDados && v.modo === 'acrescentar'
          ? lote.items.filter(function (i) { return !validacao.porItem[i.id].vazio; }) : [];
        lote.items = manter.concat(novos);
        selecionados = {};
        pagina = 0;
        revalidar();
        mostrarResumo = true;
        mudou();
        renderPlanilha();
        renderRodape();
        toast(plural(novos.length, 'registro importado', 'registros importados') + '.');
      });
    }).catch(function (e) {
      toast(e.message || 'Não foi possível ler a planilha.', 'erro');
    });
  }

  function baixarPlanilha() {
    var cabecalhos = lote.colunas.map(function (c) { return c.titulo; });
    X.baixar(PL.gerarXLSX(cabecalhos, []), L.sanitizarNome(lote.titulo) + '_planilha.xlsx');
    toast('Planilha gerada. Preencha uma linha por documento e use “Importar planilha”.');
  }

  /* ------------------------------------------------ preenchimento em massa */
  function preencher() {
    if (!lote.colunas.length) return;
    var sel = itensSelecionados();
    dialogo({
      titulo: 'Preencher coluna',
      mensagem: 'Atribui o mesmo valor a vários registros de uma vez.',
      campos: [
        { id: 'coluna', label: 'Propriedade', valor: lote.colunas[0].token,
          opcoes: lote.colunas.map(function (c) { return { valor: c.token, label: c.titulo }; }) },
        { id: 'valor', label: 'Valor', placeholder: 'Datas no formato DD/MM/AAAA' },
        { id: 'alcance', label: 'Aplicar em', valor: sel.length ? 'selecionadas' : 'todas', opcoes:
          (sel.length ? [{ valor: 'selecionadas', label: plural(sel.length, 'registro selecionado', 'registros selecionados') }] : [])
            .concat([{ valor: 'todas', label: 'Toda a coluna (' + plural(lote.items.length, 'registro', 'registros') + ')' },
                     { valor: 'vazias', label: 'Somente as células vazias da coluna' }]) }
      ],
      okLabel: 'Aplicar'
    }).then(function (v) {
      if (!v) return;
      var c = coluna(v.coluna);
      var alvo = v.alcance === 'selecionadas' ? sel : lote.items;
      var n = 0;
      alvo.forEach(function (item) {
        if (v.alcance === 'vazias' && String(item.values[c.token] || '').trim()) return;
        item.values[c.token] = L.coagir(c, v.valor);
        item.generatedAt = null;
        n++;
      });
      revalidar();
      mudou();
      renderPlanilha();
      toast('Coluna "' + c.titulo + '" preenchida em ' + plural(n, 'registro', 'registros') + '.');
    });
  }

  /* ------------------------------------------------------ linhas */
  function redesenhar() {
    revalidar();
    mudou();
    renderPlanilha();
    renderRodape();
  }

  function duplicar(itens) {
    itens.forEach(function (item) {
      var copia = L.novoItem(JSON.parse(JSON.stringify(item.values)));
      copia.contentOverride = item.contentOverride;
      lote.items.splice(lote.items.indexOf(item) + 1, 0, copia);
    });
    redesenhar();
  }

  function excluirItens(itens) {
    lote.items = lote.items.filter(function (i) { return itens.indexOf(i) === -1; });
    itens.forEach(function (i) { delete selecionados[i.id]; });
    redesenhar();
  }

  function confirmarExclusao(itens) {
    dialogo({
      titulo: 'Excluir registros',
      mensagem: 'Excluir ' + plural(itens.length, 'registro', 'registros') + ' do lote?',
      okLabel: 'Excluir', perigo: true
    }).then(function (v) { if (v) excluirItens(itens); });
  }

  /* --------------------------------------------- navegação entre erros */
  function irAoProblema(passo) {
    var total = validacao.problemas.length;
    if (!total) return;
    indiceProblema = ((indiceProblema < 0 && passo < 0 ? 0 : indiceProblema) + passo + total) % total;
    var p = validacao.problemas[indiceProblema];
    var visiveis = itensVisiveis();
    var pos = visiveis.map(function (i) { return i.id; }).indexOf(p.itemId);
    if (pos === -1) { soProblemas = false; pos = lote.items.map(function (i) { return i.id; }).indexOf(p.itemId); }
    pagina = Math.floor(pos / POR_PAGINA);
    renderPlanilha();

    var campo = $('tr[data-id="' + p.itemId + '"] [data-token="' + p.token + '"]');
    if (!campo) return;
    campo.classList.add('cel-foco');
    campo.scrollIntoView({ block: 'center', inline: 'center' });
    campo.focus();
  }

  function validarLote() {
    revalidar();
    mostrarResumo = true;
    indiceProblema = -1;
    if (aba !== 'planilha') { aba = 'planilha'; render(); } else { renderPlanilha(); renderRodape(); }
    var r = validacao.resumo;
    toast(r.erros || r.incompletos
      ? 'Validação concluída: ' + (r.erros + r.incompletos) + ' registro(s) pedem atenção.'
      : 'Validação concluída: todos os registros estão prontos.');
  }

  /* ================================================================ GERAÇÃO */
  function formatoDoLote() {
    var f = EX.normalizarFormato(lote.modelo.formato);
    return { entrelinha: f.entrelinha, espacoParagrafo: f.espacoParagrafo, margens: EX.MARGENS[f.margens] };
  }

  function gerar(itens) {
    var validos = itens.filter(function (i) { return L.valido(lote, i); });
    var fora = itens.length - validos.length;
    if (!validos.length) {
      toast('Nenhum registro válido para gerar. Use “Validar” para ver as pendências.', 'erro');
      return;
    }
    var agora = new Date().toISOString();
    ui.emBlocos('Gerando documentos', validos, function (item) {
      L.htmlFinal(lote, item);          /* resolve o documento: falha aqui, falha cedo */
      item.generatedAt = agora;
    }).then(function (r) {
      revalidar();
      salvarAgora();
      aba = 'resultado';
      render();
      toast((r.cancelado ? 'Geração cancelada: ' : '') + plural(r.feitos, 'documento gerado', 'documentos gerados') +
            (fora ? ' · ' + plural(fora, 'registro ficou de fora por pendência', 'registros ficaram de fora por pendências') : '') + '.');
    }).catch(function (e) { toast('Falha na geração: ' + e.message, 'erro'); });
  }

  function escolherEGerar() {
    var sel = itensSelecionados();
    dialogo({
      titulo: 'Gerar documentos',
      mensagem: 'Registros incompletos ou com erro não são gerados.',
      campos: [{ id: 'alcance', label: 'Gerar', valor: 'validos', opcoes: [
        { valor: 'validos', label: 'Todos os registros válidos' },
        { valor: 'selecionados', label: 'Somente registros selecionados (' + sel.length + ')' },
        { valor: 'pendentes', label: 'Somente registros ainda não gerados' }
      ] }],
      okLabel: 'Gerar documentos',
      validar: function (v) { return v.alcance === 'selecionados' && !sel.length ? 'Nenhum registro está selecionado.' : null; }
    }).then(function (v) {
      if (!v) return;
      gerar(v.alcance === 'selecionados' ? sel
        : v.alcance === 'pendentes' ? lote.items.filter(function (i) { return !i.generatedAt; })
        : lote.items);
    });
  }

  /* ============================================================ RESULTADO */
  function gerados() {
    return lote.items.filter(function (i) { return validacao.porItem[i.id].status === L.STATUS.GERADO; });
  }

  function renderResultado() {
    var docs = gerados();
    var nomes = L.nomesDosArquivos(lote, docs);
    var temPdf = false;
    var tokens = ['N', 'TIPO', 'MODELO'].concat(lote.colunas.map(function (c) { return c.token; }));

    $('#loteCorpo').innerHTML =
      '<div class="resultado"><div class="cartao-form"><div class="campo"><label for="lotePadrao">Nome dos arquivos</label>' +
        '<input type="text" id="lotePadrao" value="' + esc(lote.padraoNome || L.PADRAO_NOME) + '" spellcheck="false" autocomplete="off">' +
        '<div class="ajuda">Use as propriedades do modelo: ' + tokens.map(function (t) { return '<code>{{' + esc(t) + '}}</code>'; }).join(' ') +
        '. Acentos e caracteres inválidos são removidos.</div>' +
        '<div class="ajuda" id="loteExemploNome">' + (docs.length ? 'Exemplo: ' + esc(nomes[0]) + '.docx' : '') + '</div></div></div>' +
      (docs.length
        ? '<p class="resultado-conta"><strong>' + plural(docs.length, 'documento gerado', 'documentos gerados') + '</strong> de ' +
            plural(lote.items.length, 'registro', 'registros') + '.</p>' +
          '<div class="botoes"><button type="button" class="btn btn-principal" data-acao="zip-docx">Baixar ZIP · Word (.docx)</button>' +
            '<button type="button" class="btn" data-acao="zip-md">Baixar ZIP · Markdown (.md)</button>' +
            '<button type="button" class="btn" data-acao="pdf-unico">Gerar PDF único</button>' +
            '<button type="button" class="btn" data-acao="zip-pdf"' + (temPdf ? '' : ' disabled') +
              ' title="PDFs individuais dependem de um gerador de PDF configurado (CE.exportar.configurarPdf)">ZIP · PDF individuais</button></div>' +
          '<p class="dica">“PDF único” abre a impressão do navegador com um documento por página — escolha “Salvar como PDF”.</p>' +
          '<div class="grade-rolagem"><table class="grade grade-leitura"><thead><tr><th class="col-num">#</th><th>Arquivo</th><th>Gerado em</th><th></th></tr></thead><tbody>' +
            docs.slice(0, POR_PAGINA).map(function (item, i) {
              return '<tr data-id="' + item.id + '"><td class="col-num">' + (lote.items.indexOf(item) + 1) + '</td><td>' + esc(nomes[i]) + '</td>' +
                '<td>' + new Date(item.generatedAt).toLocaleString('pt-BR') + '</td>' +
                '<td class="col-acoes"><button type="button" class="btn btn-mini" data-acao="ver">Visualizar</button>' +
                '<button type="button" class="btn btn-mini" data-acao="um-docx">.docx</button></td></tr>';
            }).join('') + '</tbody></table></div>' +
          (docs.length > POR_PAGINA ? '<p class="dica">Mostrando os primeiros ' + POR_PAGINA + '. O ZIP contém todos os ' + docs.length + '.</p>' : '')
        : '<div class="vazio-props vazio-grande"><p class="vazio-titulo">Nenhum documento gerado ainda</p>' +
          '<p class="vazio-texto">Preencha a planilha e use “Gerar documentos”.</p></div>') + '</div>';
  }

  var EXTENSOES = {
    docx: function (html) { return { bytes: X.gerarDocxBytes(html, formatoDoLote()) }; },
    md: function (html) { return { conteudo: X.paraMarkdown(html) }; }
  };

  function baixarZip(extensao) {
    var docs = gerados();
    var nomes = L.nomesDosArquivos(lote, docs);
    var arquivos = [];
    ui.emBlocos('Preparando arquivos', docs, function (item, i) {
      var arquivo = EXTENSOES[extensao](L.htmlFinal(lote, item));
      arquivo.nome = nomes[i] + '.' + extensao;
      arquivos.push(arquivo);
    }).then(function (r) {
      if (r.cancelado) { toast('Exportação cancelada.'); return; }
      X.baixar(X.zip(arquivos, 'application/zip'), (L.sanitizarNome(lote.titulo) || 'documentos') + '.zip');
      toast('ZIP com ' + plural(arquivos.length, 'arquivo', 'arquivos') + ' gerado.');
    }).catch(function (e) { toast('Falha na exportação: ' + e.message, 'erro'); });
  }

  function baixarUmDocx(item) {
    var nome = L.nomesDosArquivos(lote, gerados())[gerados().indexOf(item)];
    X.baixar(X.gerarDocx(L.htmlFinal(lote, item), formatoDoLote()), nome + '.docx');
  }

  /* PDF único: todos os documentos numa área de impressão, um por página. */
  function pdfUnico() {
    var docs = gerados();
    var partes = [];
    ui.emBlocos('Montando PDF único', docs, function (item) {
      partes.push('<section class="doc-lote">' + L.htmlFinal(lote, item) + '</section>');
    }).then(function (r) {
      if (r.cancelado) return;
      var area = document.getElementById('impressaoLote');
      var estilo = document.createElement('style');
      var m = formatoDoLote().margens;
      estilo.textContent = '@page { size: A4; margin: ' + [m.sup, m.dir, m.inf, m.esq].join('cm ') + 'cm; }';
      document.head.appendChild(estilo);
      EX.aplicarFormato(area, lote.modelo.formato);
      area.innerHTML = partes.join('');
      document.body.classList.add('imprimindo-lote');

      var titulo = document.title;
      document.title = L.sanitizarNome(lote.titulo) || 'documentos';
      function limpar() {
        document.body.classList.remove('imprimindo-lote');
        area.innerHTML = '';
        estilo.remove();
        document.title = titulo;
        global.removeEventListener('afterprint', limpar);
      }
      global.addEventListener('afterprint', limpar);
      setTimeout(function () { global.print(); }, 80);
    });
  }

  /* ===================================================== PRÉ-VISUALIZAÇÃO */
  function renderPrevia() {
    if (!lote.items.length) {
      $('#loteCorpo').innerHTML = '<div class="vazio-props vazio-grande"><p class="vazio-titulo">Nenhum registro para visualizar</p></div>';
      return;
    }
    indicePrevia = Math.max(0, Math.min(indicePrevia, lote.items.length - 1));
    var item = lote.items[indicePrevia];
    var r = validacao.porItem[item.id] || L.validarItem(lote, item);
    var destaque = lote.colunas.slice(0, 3).map(function (c) {
      var v = CE.propriedades.formatarValor(c, String(item.values[c.token] || ''));
      return '<span><span class="dica">' + esc(c.titulo) + ':</span> ' + (v ? esc(v) : '—') + '</span>';
    }).join('');

    $('#loteCorpo').innerHTML =
      '<div class="previa-topo"><button type="button" class="btn btn-mini" data-acao="previa-anterior" aria-label="Registro anterior"' +
          (indicePrevia === 0 ? ' disabled' : '') + '>←</button>' +
        '<strong>Registro ' + (indicePrevia + 1) + ' de ' + lote.items.length + '</strong>' +
        '<button type="button" class="btn btn-mini" data-acao="previa-proxima" aria-label="Próximo registro"' +
          (indicePrevia >= lote.items.length - 1 ? ' disabled' : '') + '>→</button>' +
        htmlStatus(item, r) + '<span class="previa-dados">' + destaque + '</span><span class="espaco"></span>' +
        (item.contentOverride != null
          ? '<button type="button" class="btn btn-mini btn-fantasma" data-acao="voltar-ao-modelo">Voltar ao texto do modelo</button>' : '') +
        '<button type="button" class="btn btn-mini" data-acao="editar-documento">Editar apenas este documento</button>' +
        '<button type="button" class="btn btn-mini" data-acao="ir-para-linha">Corrigir dados na planilha</button></div>' +
      '<div class="mesa-lote"><article class="folha destacar" id="folhaLote" aria-label="Pré-visualização do documento">' +
        L.htmlPrevia(lote, item) + '</article></div>';
    EX.aplicarFormato($('#folhaLote'), lote.modelo.formato);
  }

  function verItem(item) {
    indicePrevia = lote.items.indexOf(item);
    aba = 'previa';
    render();
  }

  /* "Editar apenas este documento": o texto vira um conteúdo próprio do item
     (contentOverride). O modelo e as demais linhas não são tocados, e as
     propriedades continuam sendo resolvidas com os dados da linha. */
  function editarDocumento(item) {
    var titulos = {};
    lote.colunas.forEach(function (c) { titulos[c.token] = c.titulo; });

    var dlg = document.createElement('dialog');
    dlg.className = 'dlg dlg-editor';
    dlg.innerHTML =
      '<h2>Editar apenas este documento</h2>' +
      '<p class="dlg-msg">Registro ' + (lote.items.indexOf(item) + 1) + '. As alterações valem só para este documento: o modelo e os demais registros não mudam.</p>' +
      '<div class="ferramentas"><div class="grupo-fer">' +
        [['bold', '<b>N</b>', 'Negrito'], ['italic', '<i>I</i>', 'Itálico'], ['underline', '<u>S</u>', 'Sublinhado']].map(function (b) {
          return '<button type="button" class="btn-fer" data-cmd="' + b[0] + '" title="' + b[2] + '" aria-label="' + b[2] + '">' + b[1] + '</button>';
        }).join('') + '</div><div class="grupo-fer">' +
        [['justifyLeft', 'Esq.', 'Alinhar à esquerda'], ['justifyCenter', 'Centro', 'Centralizar'], ['justifyRight', 'Dir.', 'Alinhar à direita'], ['justifyFull', 'Just.', 'Justificar']].map(function (b) {
          return '<button type="button" class="btn-fer btn-fer-texto" data-cmd="' + b[0] + '" title="' + b[2] + '">' + b[1] + '</button>';
        }).join('') + '</div><div class="grupo-fer">' +
        '<button type="button" class="btn-fer" data-cmd="undo" title="Desfazer" aria-label="Desfazer">↶</button>' +
        '<button type="button" class="btn-fer" data-cmd="redo" title="Refazer" aria-label="Refazer">↷</button></div>' +
        '<select class="sel-fer" id="dlgInserirProp" aria-label="Inserir propriedade"><option value="">+ Propriedade…</option>' +
          lote.colunas.map(function (c) { return '<option value="' + esc(c.token) + '">' + esc(c.titulo) + '</option>'; }).join('') + '</select></div>' +
      '<div class="mesa-dialogo"><div class="editor folha" contenteditable="true" spellcheck="true" lang="pt-BR" role="textbox" ' +
        'aria-multiline="true" aria-label="Texto deste documento"></div></div>' +
      '<div class="botoes dlg-botoes"><button type="button" class="btn btn-fantasma" data-acao="cancelar">Cancelar</button>' +
        '<button type="button" class="btn btn-principal" data-acao="salvar">Salvar neste documento</button></div>';
    CE.ui.raiz().appendChild(dlg);

    var area = dlg.querySelector('.editor');
    EX.aplicarFormato(area, lote.modelo.formato);
    var editor = CE.editor.criar(area, { rotuloDe: function (t) { return titulos[t] || t; } });
    editor.render(item.contentOverride != null ? item.contentOverride : lote.modelo.conteudo);

    function fechar() { editor.destruir(); try { dlg.close(); } catch (e) { /* já fechado */ } dlg.remove(); }

    dlg.addEventListener('cancel', function (ev) { ev.preventDefault(); fechar(); });
    dlg.addEventListener('mousedown', function (ev) { if (ev.target.closest('.btn-fer')) ev.preventDefault(); });
    dlg.addEventListener('click', function (ev) {
      var cmd = ev.target.closest('[data-cmd]');
      if (cmd) { editor.executar(cmd.dataset.cmd); return; }
      var b = ev.target.closest('[data-acao]');
      if (!b) return;
      if (b.dataset.acao === 'salvar') {
        var texto = editor.ler();
        item.contentOverride = texto === (lote.modelo.conteudo || '') ? null : texto;
        revalidar();
        mudou(item);
        fechar();
        render();
        toast(item.contentOverride != null ? 'Texto próprio salvo neste documento.' : 'O texto é igual ao do modelo: nada foi personalizado.');
      } else {
        fechar();
      }
    });
    dlg.querySelector('#dlgInserirProp').addEventListener('change', function (ev) {
      if (ev.target.value) editor.inserirToken(ev.target.value);
      ev.target.value = '';
    });
    dlg.showModal();
    editor.focar();
  }

  /* ========================================================== TROCAR MODELO */
  function trocarModelo() {
    var opcoes = opcoesDeModelo();
    if (!opcoes.length) { toast('Não há outro modelo disponível.', 'erro'); return; }
    dialogo({
      titulo: 'Trocar o modelo do lote',
      mensagem: 'As colunas são refeitas a partir do novo modelo. Os valores das propriedades que continuarem existindo são mantidos; os documentos voltam a “não gerado”.',
      campos: [{ id: 'modelo', label: 'Modelo', valor: lote.modeloId && M.obter(lote.modeloId) ? lote.modeloId : opcoes[0].valor, opcoes: opcoes }],
      okLabel: 'Trocar modelo'
    }).then(function (v) {
      if (!v) return;
      L.trocarModelo(lote, retratoDe(v.modelo));
      revalidar();
      mudou();
      render();
      toast('Modelo do lote atualizado.');
    });
  }

  /* ============================================================== EVENTOS */
  var ACOES = {
    'trocar-modelo': trocarModelo,
    adicionar: function () {
      lote.items.push(L.novoItem());
      revalidar();
      soProblemas = false;
      pagina = Math.floor((lote.items.length - 1) / POR_PAGINA);
      mudou();
      renderPlanilha();
      renderRodape();
      var ultima = raiz.querySelector('tbody tr:last-child [data-token]');
      if (ultima) ultima.focus();
    },
    importar: function () { $('#loteArquivo').click(); },
    'baixar-planilha': baixarPlanilha,
    preencher: preencher,
    validar: validarLote,
    gerar: escolherEGerar,
    'sel-gerar': function () { gerar(itensSelecionados()); },
    'sel-duplicar': function () { duplicar(itensSelecionados()); },
    'sel-excluir': function () { confirmarExclusao(itensSelecionados()); },
    'pag-anterior': function () { pagina--; renderPlanilha(); },
    'pag-proxima': function () { pagina++; renderPlanilha(); },
    'erro-anterior': function () { irAoProblema(-1); },
    'erro-proximo': function () { irAoProblema(1); },
    'previa-anterior': function () { indicePrevia--; renderPrevia(); },
    'previa-proxima': function () { indicePrevia++; renderPrevia(); },
    'zip-docx': function () { baixarZip('docx'); },
    'zip-md': function () { baixarZip('md'); },
    'pdf-unico': pdfUnico
  };

  var ACOES_DE_ITEM = {
    ver: verItem,
    duplicar: function (item) { duplicar([item]); },
    limpar: function (item) { item.values = {}; item.contentOverride = null; item.generatedAt = null; redesenhar(); },
    excluir: function (item) { excluirItens([item]); },
    'um-docx': baixarUmDocx
  };

  var ACOES_DA_PREVIA = {
    'editar-documento': editarDocumento,
    'voltar-ao-modelo': function (item) { item.contentOverride = null; revalidar(); mudou(item); renderPrevia(); },
    'ir-para-linha': function (item) {
      aba = 'planilha';
      soProblemas = false;
      pagina = Math.floor(lote.items.indexOf(item) / POR_PAGINA);
      render();
      var campo = $('tr[data-id="' + item.id + '"] [data-token]');
      if (campo) { campo.scrollIntoView({ block: 'center' }); campo.focus(); }
    }
  };

  function aoClicar(ev) {
    var abaClicada = ev.target.closest('[data-aba]');
    if (abaClicada) { aba = abaClicada.dataset.aba; revalidar(); render(); return; }

    var b = ev.target.closest('[data-acao]');
    if (!b || !lote) return;
    var acao = b.dataset.acao;
    if (ACOES[acao]) { ACOES[acao](); return; }
    if (ACOES_DA_PREVIA[acao]) { ACOES_DA_PREVIA[acao](lote.items[indicePrevia]); return; }
    var item = itemDaLinha(b);
    if (item && ACOES_DE_ITEM[acao]) ACOES_DE_ITEM[acao](item);
  }

  function aoMudarCampo(ev) {
    var alvo = ev.target;
    if (!lote) return;
    if (alvo.matches('[data-token]')) { aoEditarCelula(alvo); return; }
    if (alvo.id === 'loteTitulo') { lote.titulo = alvo.value.trim() || lote.titulo; salvarDepois(); return; }
    if (alvo.id === 'lotePadrao') {
      lote.padraoNome = alvo.value.trim() || L.PADRAO_NOME;
      salvarDepois();
      var docs = gerados();
      $('#loteExemploNome').textContent = docs.length ? 'Exemplo: ' + L.nomesDosArquivos(lote, docs)[0] + '.docx' : '';
    }
  }

  function aoAlternar(ev) {
    var alvo = ev.target;
    if (!lote) return;
    if (alvo.id === 'loteSoProblemas') { soProblemas = alvo.checked; pagina = 0; revalidar(); renderPlanilha(); return; }
    if (alvo.id === 'loteSelTodas') {
      itensVisiveis().slice(pagina * POR_PAGINA, (pagina + 1) * POR_PAGINA).forEach(function (i) {
        if (alvo.checked) selecionados[i.id] = true; else delete selecionados[i.id];
      });
      renderPlanilha();
      return;
    }
    if (alvo.classList.contains('sel')) {
      var item = itemDaLinha(alvo);
      if (alvo.checked) selecionados[item.id] = true; else delete selecionados[item.id];
      renderPlanilha();
      return;
    }
    if (alvo.id === 'loteArquivo' && alvo.files[0]) { importar(alvo.files[0]); alvo.value = ''; return; }
    if (alvo.id === 'lotePadrao') renderResultado();
    /* data digitada em campo de texto que passou a ser reconhecida: vira seletor */
    if (alvo.matches('input[type="text"][data-token]') && coluna(alvo.dataset.token).tipo === 'data') renderPlanilha();
  }

  /* ================================================================= ROTA */
  function abrirRota(params) {
    if (params.modelo) {
      var retrato = retratoDe(params.modelo);
      if (!retrato) { toast('Modelo não encontrado.', 'erro'); abrir(null); return; }
      abrir(L.criar({ modelo: retrato }));
      salvarAgora();
      history.replaceState(null, '', '#/lote?id=' + encodeURIComponent(lote.id));
    } else if (params.id) {
      if (!lote || lote.id !== params.id) {
        var achado = L.obter(params.id);
        if (!achado) toast('Lote não encontrado.', 'erro');
        abrir(achado);
      } else {
        revalidar();
        render();
      }
    } else if (params.novo) {
      abrir(null);
    } else if (lote) {
      history.replaceState(null, '', '#/lote?id=' + encodeURIComponent(lote.id));
      revalidar();
      render();
    } else {
      abrir(null);
    }
  }

  /* ======================================================= LISTA DE LOTES */
  function htmlListaDeLotes(lotes) {
    return '<div class="lista-lotes">' + lotes.map(function (l) {
      var geradosN = l.items.filter(function (i) { return !!i.generatedAt; }).length;
      return '<article class="cartao-lote" data-id="' + esc(l.id) + '"><div><h3>' + esc(l.titulo) + '</h3>' +
        '<p class="cartao-meta">' + esc(l.modelo.titulo) + ' · ' + plural(l.items.length, 'documento', 'documentos') + ' · ' +
        geradosN + ' gerados · Última edição: ' + ui.dataCurta(l.updatedAt) + '</p></div>' +
        '<span class="espaco"></span><a class="btn btn-mini btn-principal" href="#/lote?id=' + encodeURIComponent(l.id) + '">Abrir</a>' +
        '<button type="button" class="btn btn-mini btn-fantasma btn-perigo" data-acao="excluir-lote">Excluir</button></article>';
    }).join('') + '</div>';
  }

  function renderLista() {
    var lotes = L.listar();
    raizLista.innerHTML = '<div class="pagina pagina-estreita"><header class="pagina-topo"><h2>Lotes</h2><span class="espaco"></span>' +
      '<a class="btn btn-principal" href="#/lote?novo=1">+ Novo lote</a></header>' +
      (lotes.length ? htmlListaDeLotes(lotes)
        : '<div class="vazio-props vazio-grande"><p class="vazio-titulo">Nenhum lote salvo</p>' +
          '<p class="vazio-texto">Os lotes ficam guardados aqui para você continuar depois.</p></div>') + '</div>';
  }

  function aoExcluirLote(ev) {
    var b = ev.target.closest('[data-acao="excluir-lote"]');
    if (!b) return;
    var id = b.closest('.cartao-lote').dataset.id;
    var alvo = L.obter(id);
    dialogo({
      titulo: 'Excluir lote?',
      mensagem: '"' + alvo.titulo + '" — os dados da planilha deste lote serão apagados. Arquivos já baixados não são afetados.',
      okLabel: 'Excluir', perigo: true
    }).then(function (v) {
      if (!v) return;
      L.excluir(id);
      if (lote && lote.id === id) lote = null;
      if (document.body.dataset.visao === 'lotes') renderLista(); else abrir(null);
      toast('Lote excluído.');
    });
  }

  function iniciar(elementoLote, elementoLista) {
    raiz = elementoLote;
    raizLista = elementoLista;
    raiz.addEventListener('click', aoClicar);
    raiz.addEventListener('click', aoExcluirLote);
    raiz.addEventListener('input', aoMudarCampo);
    raiz.addEventListener('change', aoAlternar);
    raiz.addEventListener('paste', aoColar);
    raiz.addEventListener('submit', function (ev) { if (ev.target.id === 'loteNovo') iniciarLote(ev); });
    raizLista.addEventListener('click', aoExcluirLote);
    global.addEventListener('beforeunload', function () { if (timerSalvar) salvarAgora(); });
  }

  CE.loteUI = { iniciar: iniciar, abrirRota: abrirRota, renderLista: renderLista };
})(window);
