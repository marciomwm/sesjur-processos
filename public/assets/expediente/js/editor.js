/* =============================================================================
   editor.js — Editor do expediente (contenteditable nativo)
   -----------------------------------------------------------------------------
   Decisão: manter contenteditable + execCommand. A aplicação abre por duplo
   clique, sem servidor nem dependências; um editor estruturado (TipTap,
   ProseMirror, Lexical) exigiria empacotamento. O que este módulo garante:

     • as propriedades aparecem como "chips" indivisíveis com o TÍTULO da
       propriedade; ao ler o texto, voltam a ser {{IDENTIFICADOR}};
     • o que é colado passa por sanitizeForSigadoc(), mantendo o HTML simples;
     • inserções usam insertHTML, preservando o desfazer/refazer do navegador.

   Não conhece modelos nem valores: recebe HTML com {{TOKENS}} e devolve HTML
   com {{TOKENS}}.

   CE.editor.criar(elemento, opcoes) devolve uma instância independente — a tela
   individual e a edição de um documento do lote usam o MESMO editor.
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};
  var E = global.CI_ENGINE;

  var RE_TOKEN = /\{\{([A-Z][A-Z0-9_]*)\}\}/g;
  var TAGS_DE_BLOCO = /^(P|DIV|H[1-6]|UL|OL|TABLE|BLOCKQUOTE|HR)$/;
  var PASSO_RECUO_CM = 1.25;
  var RECUO_PRIMEIRA_LINHA = '1.9em';

  /* opcoes = { aoMudar, aoSelecionar, rotuloDe(token) } */
  function criar(el, op) {
  op = op || {};
  var ultimoRange = null;

  /* ================================================================ CHIPS */
  function criarChip(token) {
    var s = document.createElement('span');
    s.className = 'tok';
    s.dataset.prop = token;
    s.contentEditable = 'false';
    s.title = '{{' + token + '}}';
    s.textContent = (op.rotuloDe && op.rotuloDe(token)) || token;
    return s;
  }

  /* Troca os {{TOKENS}} dos nós de texto por chips, sem tocar em atributos. */
  function aplicarChips(raiz) {
    var caminhante = document.createTreeWalker(raiz, NodeFilter.SHOW_TEXT);
    var alvos = [], no;
    while ((no = caminhante.nextNode())) {
      if (no.parentNode.closest && no.parentNode.closest('.tok')) continue;
      RE_TOKEN.lastIndex = 0;
      if (RE_TOKEN.test(no.nodeValue)) alvos.push(no);
    }
    alvos.forEach(function (texto) {
      var frag = document.createDocumentFragment();
      var resto = texto.nodeValue, ultimo = 0, m;
      RE_TOKEN.lastIndex = 0;
      while ((m = RE_TOKEN.exec(resto)) !== null) {
        if (m.index > ultimo) frag.appendChild(document.createTextNode(resto.slice(ultimo, m.index)));
        frag.appendChild(criarChip(m[1]));
        ultimo = m.index + m[0].length;
      }
      if (ultimo < resto.length) frag.appendChild(document.createTextNode(resto.slice(ultimo)));
      texto.parentNode.replaceChild(frag, texto);
    });
    return raiz;
  }

  function removerChips(raiz) {
    Array.prototype.forEach.call(raiz.querySelectorAll('span.tok'), function (s) {
      s.replaceWith(document.createTextNode(s.dataset.prop ? '{{' + s.dataset.prop + '}}' : s.textContent));
    });
    return raiz;
  }

  function comChips(html) {
    var caixa = document.createElement('div');
    caixa.innerHTML = html;
    return aplicarChips(caixa).innerHTML;
  }

  /* ====================================================== RENDER / LEITURA */
  function vazio() {
    return !el.textContent.replace(/​/g, '').trim() && !el.querySelector('table, hr, .tok');
  }

  function marcarVazio() { el.classList.toggle('vazio', vazio()); }

  function render(html) {
    el.innerHTML = String(html || '').trim() ? comChips(html) : '<p><br></p>';
    ultimoRange = null;
    marcarVazio();
  }

  /* Texto solto no nível raiz (o navegador cria ao digitar num editor vazio)
     vira parágrafo, para o documento ter sempre a mesma estrutura. */
  function embrulharSoltos(raiz) {
    var grupo = null;
    Array.prototype.slice.call(raiz.childNodes).forEach(function (no) {
      var bloco = no.nodeType === 1 && TAGS_DE_BLOCO.test(no.tagName);
      if (bloco) { grupo = null; return; }
      if (no.nodeType === 3 && !no.nodeValue.trim() && !grupo) { no.remove(); return; }
      if (!grupo) {
        grupo = document.createElement('p');
        raiz.insertBefore(grupo, no);
      }
      grupo.appendChild(no);
    });
  }

  /* HTML do editor, com {{TOKENS}} e sem marcações de apoio. '' = vazio. */
  function ler() {
    if (vazio()) return '';
    var clone = el.cloneNode(true);
    removerChips(clone);
    embrulharSoltos(clone);
    /* o navegador grava &nbsp; ao redor dos chips; no documento são espaços comuns */
    return E.normalizarTokens(clone.innerHTML.replace(/​/g, '').replace(/&nbsp;/g, ' '));
  }

  /* Tokens digitados à mão ainda não viraram chip? */
  function temTokenSolto() {
    var caminhante = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    var no;
    while ((no = caminhante.nextNode())) {
      if (no.parentNode.closest('.tok')) continue;
      RE_TOKEN.lastIndex = 0;
      if (RE_TOKEN.test(no.nodeValue)) return true;
    }
    return false;
  }

  /* ============================================================== SELEÇÃO */
  function dentro(no) { return !!no && el.contains(no); }

  function guardarSelecao() {
    var sel = global.getSelection();
    if (!sel || !sel.rangeCount) return;
    var r = sel.getRangeAt(0);
    if (dentro(r.commonAncestorContainer)) ultimoRange = r.cloneRange();
  }

  /* Devolve o cursor ao editor; sem posição guardada, vai para o fim do texto. */
  function restaurarSelecao() {
    el.focus();
    var sel = global.getSelection();
    var r = ultimoRange;
    if (!r || !dentro(r.commonAncestorContainer)) {
      var fim = el.lastElementChild || el;
      r = document.createRange();
      r.selectNodeContents(fim);
      r.collapse(!fim.textContent);   /* bloco vazio: antes do <br> que o sustenta */
    }
    sel.removeAllRanges();
    sel.addRange(r);
  }

  function rangeAtual() {
    var sel = global.getSelection();
    if (!sel.rangeCount) return null;
    var r = sel.getRangeAt(0);
    return dentro(r.commonAncestorContainer) ? r : null;
  }

  /* Blocos de primeiro nível tocados pela seleção. */
  function blocosSelecionados() {
    var r = rangeAtual();
    if (!r) return [];
    return Array.prototype.filter.call(el.children, function (b) { return r.intersectsNode(b); });
  }

  function noItemDeLista() {
    var r = rangeAtual();
    if (!r) return false;
    var no = r.startContainer.nodeType === 3 ? r.startContainer.parentNode : r.startContainer;
    return !!(no.closest && no.closest('li') && dentro(no.closest('li')));
  }

  /* ============================================================= COMANDOS */
  function mudou() {
    marcarVazio();
    guardarSelecao();
    if (op.aoMudar) op.aoMudar();
  }

  function executar(comando, valor) {
    restaurarSelecao();
    try { document.execCommand(comando, false, valor == null ? null : valor); } catch (e) { /* sem suporte */ }
    mudou();
  }

  function definirBloco(tag) { executar('formatBlock', '<' + tag + '>'); }

  /* Recuo do parágrafo inteiro. Em listas, aninha/desaninha o item. */
  function recuar(direcao) {
    restaurarSelecao();
    if (noItemDeLista()) { executar(direcao > 0 ? 'indent' : 'outdent'); return; }
    blocosSelecionados().forEach(function (b) {
      var atual = parseFloat(b.style.marginLeft) || 0;
      var novo = Math.max(0, atual + direcao * PASSO_RECUO_CM);
      b.style.marginLeft = novo ? novo + 'cm' : '';
    });
    mudou();
  }

  function alternarRecuoPrimeiraLinha() {
    restaurarSelecao();
    blocosSelecionados().forEach(function (b) {
      var tem = parseFloat(global.getComputedStyle(b).textIndent) > 0;
      b.style.textIndent = tem ? '0' : RECUO_PRIMEIRA_LINHA;
    });
    mudou();
  }

  function inserirHTML(html) {
    restaurarSelecao();
    try { document.execCommand('insertHTML', false, html); } catch (e) { /* sem suporte */ }
    mudou();
  }

  /* O chip é inserido direto no DOM: via insertHTML o navegador troca a classe
     e o data-prop por estilos embutidos, e a propriedade se perde. */
  function inserirToken(token) {
    restaurarSelecao();
    var r = rangeAtual();
    if (!r) return;
    var chip = criarChip(token);
    r.deleteContents();
    r.insertNode(chip);
    r.setStartAfter(chip);
    r.collapse(true);
    var sel = global.getSelection();
    sel.removeAllRanges();
    sel.addRange(r);
    mudou();
  }

  function inserirTabela(linhas, colunas) {
    var linha = '<tr>' + new Array(colunas + 1).join('<td><br></td>') + '</tr>';
    inserirHTML('<table><tbody>' + new Array(linhas + 1).join(linha) + '</tbody></table><p><br></p>');
  }

  function inserirLinhaHorizontal() { inserirHTML('<hr><p><br></p>'); }
  function inserirQuebraDePagina() { inserirHTML('<hr class="quebra-pagina"><p><br></p>'); }

  function inserirLink(url) {
    restaurarSelecao();
    var r = rangeAtual();
    if (r && !r.collapsed) { executar('createLink', url); return; }
    var a = document.createElement('a');
    a.href = url;
    a.textContent = url;
    inserirHTML(a.outerHTML);
  }

  /* Parágrafo usado apenas como espaçamento entre blocos. */
  function espacador(no) {
    return (no.classList && no.classList.contains('ci-vazio')) || !no.textContent.trim();
  }

  /* Move o parágrafo onde está o cursor, pulando as linhas em branco. */
  function moverParagrafo(direcao) {
    restaurarSelecao();
    var r = rangeAtual();
    if (!r) return;
    var no = r.startContainer;
    while (no && no.parentNode !== el) no = no.parentNode;
    if (!no || no.nodeType !== 1) return;

    var vizinho = direcao < 0 ? no.previousElementSibling : no.nextElementSibling;
    while (vizinho && espacador(vizinho)) {
      vizinho = direcao < 0 ? vizinho.previousElementSibling : vizinho.nextElementSibling;
    }
    if (!vizinho) return;

    var marca = document.createComment('');
    el.insertBefore(marca, no);
    el.insertBefore(no, vizinho);
    el.insertBefore(vizinho, marca);
    marca.remove();

    var novo = document.createRange();
    novo.selectNodeContents(no);
    novo.collapse(true);
    var sel = global.getSelection();
    sel.removeAllRanges();
    sel.addRange(novo);
    mudou();
  }

  /* Estado dos comandos no ponto do cursor, para a barra de ferramentas. */
  function estadoDosComandos(comandos) {
    var ativo = !!ultimoRange && dentro(ultimoRange.commonAncestorContainer);
    var estado = { bloco: 'p', comandos: {} };
    comandos.forEach(function (c) {
      var ligado = false;
      if (ativo) { try { ligado = document.queryCommandState(c); } catch (e) { ligado = false; } }
      estado.comandos[c] = !!ligado;
    });
    if (ativo) {
      try { estado.bloco = String(document.queryCommandValue('formatBlock') || 'p').toLowerCase(); }
      catch (e) { estado.bloco = 'p'; }
    }
    return estado;
  }

  /* ============================================================== COLAGEM */
  function aoColar(ev) {
    var dados = ev.clipboardData;
    if (!dados) return;
    ev.preventDefault();

    var html = dados.getData('text/html');
    if (html) {
      var caixa = document.createElement('div');
      caixa.innerHTML = html;
      removerChips(caixa);
      inserirHTML(CE.exportar.sanitizeForSigadoc(caixa.innerHTML));
      if (temTokenSolto()) aplicarChips(el);
      return;
    }
    restaurarSelecao();
    try { document.execCommand('insertText', false, dados.getData('text/plain')); } catch (e) { /* sem suporte */ }
    mudou();
  }

  function aoMudarSelecao() {
    guardarSelecao();
    if (op.aoSelecionar) op.aoSelecionar();
  }

  /* ================================================================= BOOT */
  (function () {
    try {
      document.execCommand('defaultParagraphSeparator', false, 'p');
      document.execCommand('styleWithCSS', false, false);
    } catch (e) { /* navegador sem suporte: o editor segue funcionando */ }

    el.addEventListener('input', mudou);
    el.addEventListener('paste', aoColar);
    el.addEventListener('blur', function () {
      /* tokens digitados à mão viram chips ao sair do editor */
      if (!temTokenSolto()) return;
      render(ler());
      if (op.aoMudar) op.aoMudar();
    });
    el.addEventListener('keydown', function (ev) {
      if (ev.altKey && (ev.key === 'ArrowUp' || ev.key === 'ArrowDown')) {
        ev.preventDefault();
        moverParagrafo(ev.key === 'ArrowUp' ? -1 : 1);
      }
    });
    document.addEventListener('selectionchange', aoMudarSelecao);
  })();

  return {
    destruir: function () { document.removeEventListener('selectionchange', aoMudarSelecao); },
    render: render,
    ler: ler,
    vazio: vazio,
    focar: restaurarSelecao,
    guardarSelecao: guardarSelecao,
    executar: executar,
    definirBloco: definirBloco,
    recuar: recuar,
    alternarRecuoPrimeiraLinha: alternarRecuoPrimeiraLinha,
    inserirToken: inserirToken,
    inserirTabela: inserirTabela,
    inserirLinhaHorizontal: inserirLinhaHorizontal,
    inserirQuebraDePagina: inserirQuebraDePagina,
    inserirLink: inserirLink,
    moverParagrafo: moverParagrafo,
    estadoDosComandos: estadoDosComandos
  };
  }

  CE.editor = { criar: criar };
})(window);
