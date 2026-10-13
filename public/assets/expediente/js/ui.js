/* =============================================================================
   ui.js — Componentes de interface reutilizáveis
   -----------------------------------------------------------------------------
   toast()         aviso passageiro
   dialogo()       modal que substitui confirm()/prompt() e devolve uma Promise
   registrarMenu() menu suspenso acessível (clique, Escape, setas)
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};

  function $(id) { return document.getElementById(id); }

  /* ================================================================ TOAST */
  function toast(msg, tipo) {
    var area = $('toasts');
    if (!area) return;
    var d = document.createElement('div');
    d.className = 'toast' + (tipo === 'erro' ? ' erro' : '');
    d.textContent = msg;
    area.appendChild(d);
    setTimeout(function () { d.remove(); }, 4200);
  }

  /* ============================================================== DIÁLOGO
     op = { titulo, mensagem, campos, okLabel, cancelarLabel, perigo, validar,
            aoMontar(entradas, linhas) }

     Cada campo: { id, label, valor, placeholder, ajuda } mais UM dos formatos:
       opcoes: [{valor, label}]   lista suspensa
       multilinha: true           área de texto
       checkbox: true             caixa de seleção (valor booleano)
       lista: true                lista editável de opções (valor = array)
     `visivelSe(valores)` esconde o campo conforme os demais.

     Devolve uma Promise: null = cancelado; senão, { id: valor }.            */
  function dialogo(op) {
    return new Promise(function (resolve) {
      var dlg = document.createElement('dialog');
      dlg.className = 'dlg';

      var h = document.createElement('h2');
      h.textContent = op.titulo;
      h.id = 'dlgCabecalho';   /* os campos usam "dlg_<id>": não pode colidir */
      dlg.setAttribute('aria-labelledby', h.id);
      dlg.appendChild(h);

      if (op.mensagem) {
        var p = document.createElement('p');
        p.className = 'dlg-msg';
        p.textContent = op.mensagem;
        dlg.appendChild(p);
      }

      var campos = op.campos || [];
      var entradas = {}, linhas = {};

      campos.forEach(function (c) {
        var wrap = document.createElement('div');
        wrap.className = 'campo';
        linhas[c.id] = wrap;

        if (c.checkbox) {
          var lc = document.createElement('label');
          lc.className = 'linha-check';
          var cb = document.createElement('input');
          cb.type = 'checkbox';
          cb.id = 'dlg_' + c.id;
          cb.checked = !!c.valor;
          var st = document.createElement('span');
          st.textContent = c.label;
          lc.appendChild(cb);
          lc.appendChild(st);
          wrap.appendChild(lc);
          entradas[c.id] = cb;
          dlg.appendChild(wrap);
          return;
        }

        var lab = document.createElement('label');
        lab.textContent = c.label;
        lab.setAttribute('for', 'dlg_' + c.id);
        wrap.appendChild(lab);

        if (c.lista) {
          entradas[c.id] = montarLista(wrap, c);
        } else {
          var inp;
          if (c.opcoes) {
            inp = document.createElement('select');
            c.opcoes.forEach(function (o) {
              var item = document.createElement('option');
              item.value = o.valor;
              item.textContent = o.label;
              inp.appendChild(item);
            });
          } else if (c.multilinha) {
            inp = document.createElement('textarea');
            inp.rows = 3;
          } else {
            inp = document.createElement('input');
            inp.type = c.tipo || 'text';
          }
          inp.id = 'dlg_' + c.id;
          inp.value = c.valor || '';
          if (c.placeholder) inp.placeholder = c.placeholder;
          inp.autocomplete = 'off';
          wrap.appendChild(inp);
          entradas[c.id] = inp;
        }

        if (c.ajuda) {
          var a = document.createElement('div');
          a.className = 'ajuda';
          a.textContent = c.ajuda;
          wrap.appendChild(a);
        }
        dlg.appendChild(wrap);
      });

      var erroEl = document.createElement('div');
      erroEl.className = 'erro';
      erroEl.hidden = true;
      erroEl.setAttribute('role', 'alert');
      dlg.appendChild(erroEl);

      var botoes = document.createElement('div');
      botoes.className = 'botoes dlg-botoes';

      var cancelar = document.createElement('button');
      cancelar.type = 'button';
      cancelar.className = 'btn btn-fantasma';
      cancelar.textContent = op.cancelarLabel || 'Cancelar';

      var ok = document.createElement('button');
      ok.type = 'button';
      ok.className = 'btn btn-principal' + (op.perigo ? ' btn-perigo-cheio' : '');
      ok.textContent = op.okLabel || 'Confirmar';

      botoes.appendChild(cancelar);
      botoes.appendChild(ok);
      dlg.appendChild(botoes);
      CE.ui.raiz().appendChild(dlg);

      function valores() {
        var v = {};
        Object.keys(entradas).forEach(function (k) {
          var e = entradas[k];
          if (e.ler) v[k] = e.ler();
          else if (e.type === 'checkbox') v[k] = e.checked;
          else v[k] = e.value.trim();
        });
        return v;
      }
      function atualizarVisibilidade() {
        var v = valores();
        campos.forEach(function (c) {
          if (c.visivelSe) linhas[c.id].hidden = !c.visivelSe(v);
        });
      }
      function fechar(valor) {
        try { dlg.close(); } catch (e) { /* já fechado */ }
        dlg.remove();
        resolve(valor);
      }
      function confirmar() {
        var v = valores();
        if (op.validar) {
          var msg = op.validar(v);
          if (msg) { erroEl.textContent = msg; erroEl.hidden = false; return; }
        }
        fechar(v);
      }

      if (op.aoMontar) op.aoMontar(entradas, linhas);
      atualizarVisibilidade();

      dlg.addEventListener('input', function () { erroEl.hidden = true; atualizarVisibilidade(); });
      dlg.addEventListener('change', atualizarVisibilidade);
      cancelar.addEventListener('click', function () { fechar(null); });
      ok.addEventListener('click', confirmar);
      dlg.addEventListener('cancel', function (ev) { ev.preventDefault(); fechar(null); });
      dlg.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter' || ev.target.tagName !== 'INPUT') return;
        if (ev.target.closest('.lista-opcoes')) return;   /* a lista trata o próprio Enter */
        ev.preventDefault();
        confirmar();
      });

      if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
      var primeiro = dlg.querySelector('input:not([type="checkbox"]), textarea, select');
      if (primeiro) { primeiro.focus(); if (primeiro.select) primeiro.select(); } else { ok.focus(); }
    });
  }

  /* Lista editável de opções (usada pela propriedade do tipo "lista suspensa"). */
  function montarLista(wrap, c) {
    var caixa = document.createElement('div');
    caixa.className = 'lista-opcoes';

    function linha(valor) {
      var r = document.createElement('div');
      r.className = 'linha-opcao';
      var i = document.createElement('input');
      i.type = 'text';
      i.value = valor || '';
      i.placeholder = 'Opção';
      i.setAttribute('aria-label', 'Opção');
      i.autocomplete = 'off';
      i.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter') return;
        ev.preventDefault();
        linha('').focus();
      });
      var x = document.createElement('button');
      x.type = 'button';
      x.className = 'btn-prop btn-prop-perigo';
      x.textContent = '✕';
      x.title = 'Remover opção';
      x.setAttribute('aria-label', 'Remover opção');
      x.addEventListener('click', function () { r.remove(); });
      r.appendChild(i);
      r.appendChild(x);
      caixa.appendChild(r);
      return i;
    }

    (c.valor || []).forEach(linha);
    wrap.appendChild(caixa);

    var mais = document.createElement('button');
    mais.type = 'button';
    mais.className = 'btn btn-mini';
    mais.textContent = '+ Adicionar opção';
    mais.addEventListener('click', function () { linha('').focus(); });
    wrap.appendChild(mais);

    return {
      ler: function () {
        return Array.prototype.map.call(caixa.querySelectorAll('input'), function (i) {
          return i.value.trim();
        }).filter(Boolean);
      }
    };
  }

  /* ============================================================ PROGRESSO
     Barra de progresso com cancelamento, para operações em blocos.
     var p = progresso('Gerando documentos', 500);
     p.avancar(n); if (p.cancelado()) ...; p.fechar();                        */
  function progresso(titulo, total) {
    var cancelado = false;
    var dlg = document.createElement('dialog');
    dlg.className = 'dlg dlg-progresso';
    dlg.innerHTML = '<h2></h2><div class="barra-progresso" role="progressbar" aria-valuemin="0">' +
      '<div class="barra-progresso-cheio"></div></div><p class="dlg-msg"></p>' +
      '<div class="botoes dlg-botoes"><button type="button" class="btn">Cancelar</button></div>';
    dlg.querySelector('h2').textContent = titulo;
    var barra = dlg.querySelector('.barra-progresso');
    var cheio = dlg.querySelector('.barra-progresso-cheio');
    var texto = dlg.querySelector('.dlg-msg');
    barra.setAttribute('aria-valuemax', String(total));
    dlg.querySelector('button').addEventListener('click', function () { cancelado = true; });
    dlg.addEventListener('cancel', function (ev) { ev.preventDefault(); cancelado = true; });
    CE.ui.raiz().appendChild(dlg);
    if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');

    function avancar(n) {
      var pct = total ? Math.round(n / total * 100) : 100;
      cheio.style.width = pct + '%';
      barra.setAttribute('aria-valuenow', String(n));
      texto.textContent = pct + '% · ' + n + ' de ' + total;
    }
    avancar(0);

    return {
      avancar: avancar,
      cancelado: function () { return cancelado; },
      fechar: function () { try { dlg.close(); } catch (e) { /* já fechado */ } dlg.remove(); }
    };
  }

  /* Percorre `itens` em pequenos blocos, cedendo a vez à interface entre eles.
     Resolve com { feitos, cancelado }. */
  function emBlocos(titulo, itens, passo, tamanho) {
    tamanho = tamanho || 20;
    var p = progresso(titulo, itens.length);
    return new Promise(function (resolve, reject) {
      var i = 0;
      function bloco() {
        try {
          var fim = Math.min(i + tamanho, itens.length);
          for (; i < fim; i++) passo(itens[i], i);
        } catch (e) { p.fechar(); reject(e); return; }
        p.avancar(i);
        if (p.cancelado() || i >= itens.length) {
          var cancelado = p.cancelado() && i < itens.length;
          p.fechar();
          resolve({ feitos: i, cancelado: cancelado });
          return;
        }
        setTimeout(bloco, 0);
      }
      setTimeout(bloco, 0);
    });
  }

  /* Texto seguro para interpolar em HTML e em atributos. */
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* Comparação que ignora maiúsculas e acentos (busca, correspondência). */
  function semAcento(s) {
    return String(s == null ? '' : s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  }

  function dataCurta(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    return isNaN(d) ? '—' : d.toLocaleDateString('pt-BR');
  }

  /* ================================================================ MENUS
     op.manterSelecao  não rouba o foco do editor (menus que inserem no cursor)
     op.aoAbrir        chamado antes de exibir (para montar itens dinâmicos)   */
  var menus = [];

  function fecharMenus(exceto) {
    menus.forEach(function (m) {
      if (m.menu === exceto) return;
      m.menu.hidden = true;
      m.botao.setAttribute('aria-expanded', 'false');
    });
  }

  function registrarMenu(botao, menu, op) {
    op = op || {};
    menus.push({ botao: botao, menu: menu });
    botao.setAttribute('aria-haspopup', 'true');
    botao.setAttribute('aria-expanded', 'false');

    if (op.manterSelecao) {
      botao.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
      menu.addEventListener('mousedown', function (ev) {
        if (!ev.target.closest('select, input')) ev.preventDefault();
      });
    }

    botao.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var abrir = menu.hidden;
      fecharMenus(menu);
      if (abrir && op.aoAbrir) op.aoAbrir();
      menu.hidden = !abrir;
      botao.setAttribute('aria-expanded', String(abrir));
    });

    menu.addEventListener('click', function (ev) { ev.stopPropagation(); });
    menu.addEventListener('keydown', function (ev) {
      if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp') return;
      var itens = Array.prototype.slice.call(menu.querySelectorAll('button:not(:disabled)'));
      if (!itens.length) return;
      ev.preventDefault();
      var i = itens.indexOf(document.activeElement);
      var prox = ev.key === 'ArrowDown' ? i + 1 : i - 1;
      itens[(prox + itens.length) % itens.length].focus();
    });
  }

  document.addEventListener('click', function () { fecharMenus(); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') fecharMenus(); });

  /* Contêiner do gerador na página do SESJUR: os diálogos nascem dentro dele
     para receber os estilos, todos escopados em .ge. */
  function raiz() { return document.getElementById('gerador') || document.body; }

  CE.ui = {
    $: $,
    raiz: raiz,
    toast: toast,
    dialogo: dialogo,
    progresso: progresso,
    emBlocos: emBlocos,
    esc: esc,
    semAcento: semAcento,
    dataCurta: dataCurta,
    registrarMenu: registrarMenu,
    fecharMenus: fecharMenus
  };
})(window);
