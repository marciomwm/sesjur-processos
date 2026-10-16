/* =============================================================================
   app.js — Criador de Expedientes UNIJUR/SES-MT: orquestração da interface
   -----------------------------------------------------------------------------
   MODELO → PROPRIEDADES → EDIÇÃO → RESOLUÇÃO DAS VARIÁVEIS → VISUALIZAÇÃO
          → EXPORTAÇÃO / ENVIO

   À esquerda preenche-se (processo, modelo, propriedades); à direita produz-se
   o documento. Este arquivo liga os módulos de /js e mantém o estado do
   expediente; as regras de cada assunto moram no respectivo módulo.
   ============================================================================= */
(function () {
  'use strict';

  var T = window.CI_TEMPLATES;
  var E = window.CI_ENGINE;
  var CE = window.CE;
  var ui = CE.ui, P = CE.propriedades, M = CE.modelos, EX = CE.expediente;
  var X = CE.exportar, ARM = CE.armazenamento, S = CE.sesjur;
  var ED = null;   /* instância do editor, criada no boot */
  var STATUS = EX.STATUS;
  var $ = ui.$, toast = ui.toast, dialogo = ui.dialogo;

  var TOKEN_PROTOCOLO = 'PROCESSO_SIGADOC';
  var COMANDOS_COM_ESTADO = ['bold', 'italic', 'underline', 'strikeThrough', 'justifyLeft', 'justifyCenter',
                             'justifyRight', 'justifyFull', 'insertUnorderedList', 'insertOrderedList'];
  var MINIMO_PARA_BUSCA = 6;
  var MINIMO_PARA_PESQUISAR_PROCESSO = 3;   /* o mesmo da API de pesquisa */

  var estado = EX.novo();
  var el = {};

  /* ============================================== ESTADO: ATALHOS DE LEITURA */
  function modelo() { return M.obter(estado.modeloId) || M.obter(M.ID_EM_BRANCO); }
  function emBranco() { return !!modelo().emBranco; }
  function editando() { return estado.status === STATUS.EDITANDO; }

  /* Dados entregues ao motor: o protocolo é a propriedade {{PROCESSO_SIGADOC}}. */
  function dados() {
    var d = Object.assign({}, estado.dados);
    d.processo_administrativo = estado.protocolo.trim();
    return d;
  }

  /* Propriedades próprias (criadas pelo assessor) do modelo em uso. */
  function definicoes() {
    if (!Array.isArray(estado.definicoes[estado.modeloId])) {
      var ini = M.definicoesIniciais(estado.modeloId);
      estado.definicoes[estado.modeloId] = ini.definicoes;
      Object.keys(ini.valores).forEach(function (id) {
        if (estado.valores[id] == null) estado.valores[id] = ini.valores[id];
      });
    }
    return estado.definicoes[estado.modeloId];
  }

  function definicaoPorToken(token) {
    return definicoes().filter(function (d) { return d.identificador === token; })[0] || null;
  }

  function tokensProprios() { return definicoes().map(function (d) { return d.identificador; }); }

  /* LISTA DO DOCUMENTO, na ordem dos campos: mistura propriedades do catálogo
     e propriedades próprias. Cada modelo tem a sua; nunca se misturam. */
  function selecionadas() {
    if (!Array.isArray(estado.selecao[estado.modeloId])) {
      estado.selecao[estado.modeloId] = M.selecaoInicial(estado.modeloId);
    }
    return estado.selecao[estado.modeloId];
  }

  function definirSelecao(tokens) {
    var vistos = {};
    estado.selecao[estado.modeloId] = tokens.filter(function (t) {
      if (vistos[t]) return false;
      vistos[t] = true;
      return !!T.propriedadePorToken(t) || !!definicaoPorToken(t);
    });
  }

  function ctx() { return E.contexto(selecionadas()); }
  function propriasParaMotor() { return P.paraMotor(definicoes(), estado.valores); }

  function textoInicial() { return M.textoInicial(estado.modeloId, dados(), ctx()); }
  function fonteAtual() { return estado.conteudo != null ? estado.conteudo : textoInicial(); }

  /* O expediente no formato de CE.documento — o mesmo resolvedor do lote. */
  function documentoAtual() {
    return { modeloId: estado.modeloId, fonte: fonteAtual(), dados: dados(),
             proprias: propriasParaMotor(), selecao: selecionadas() };
  }
  function props() { return CE.documento.propriedades(documentoAtual()); }

  function rotuloDoCatalogo(def) {
    var rot = modelo().rotulos || {};
    return (def.chave && rot[def.chave]) || def.label;
  }

  function rotuloDoToken(token) {
    var propria = definicaoPorToken(token);
    if (propria) return propria.titulo;
    var def = T.propriedadePorToken(token);
    return def ? rotuloDoCatalogo(def) : token;
  }

  /* ============================================================= MÁSCARAS */
  function mascaraData(v) {
    var d = v.replace(/\D/g, '').slice(0, 8);
    if (d.length <= 2) return d;
    if (d.length <= 4) return d.slice(0, 2) + '/' + d.slice(2);
    return d.slice(0, 2) + '/' + d.slice(2, 4) + '/' + d.slice(4);
  }
  function mascaraCNJ(v) {
    var d = v.replace(/\D/g, '').slice(0, 20);
    var out = d.slice(0, 7);
    if (d.length > 7) out += '-' + d.slice(7, 9);
    if (d.length > 9) out += '.' + d.slice(9, 13);
    if (d.length > 13) out += '.' + d.slice(13, 14);
    if (d.length > 14) out += '.' + d.slice(14, 16);
    if (d.length > 16) out += '.' + d.slice(16, 20);
    return out;
  }
  function mascaraCNS(v) {
    var d = v.replace(/\D/g, '').slice(0, 15);
    return d.replace(/^(\d{3})(\d{0,4})(\d{0,4})(\d{0,4})$/, function (t, a, b, c, e2) {
      return [a, b, c, e2].filter(Boolean).join(' ');
    });
  }
  function mascaraTelefone(v) {
    var d = v.replace(/\D/g, '').slice(0, 11);
    if (d.length <= 2) return d.length ? '(' + d : d;
    if (d.length <= 6) return '(' + d.slice(0, 2) + ') ' + d.slice(2);
    if (d.length <= 10) return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6);
    return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
  }
  function aplicarMascara(def, valor) {
    if (def.tipo === 'data') return mascaraData(valor);
    if (def.mascara === 'cnj') return mascaraCNJ(valor);
    if (def.mascara === 'cns') return mascaraCNS(valor);
    if (def.mascara === 'telefone') return mascaraTelefone(valor);
    return valor;
  }

  /* ============================================================ AUTOSAVE */
  var timerSalvar = null;

  function hora(iso) {
    var d = iso ? new Date(iso) : new Date();
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  }

  function indicar(texto, falha) {
    el.indicadorSalvo.textContent = texto;
    el.indicadorSalvo.classList.toggle('falha', !!falha);
  }

  function salvarAgora() {
    clearTimeout(timerSalvar);
    timerSalvar = null;
    estado.updatedAt = new Date().toISOString();
    if (ARM.salvar(EX.serializar(estado))) indicar('Última alteração salva às ' + hora(estado.updatedAt));
    else indicar('Não foi possível salvar neste navegador', true);
  }

  function agendarSalvar() {
    indicar('Salvando…');
    clearTimeout(timerSalvar);
    timerSalvar = setTimeout(salvarAgora, 500);
  }

  /* ============================================================= PROCESSO
     O expediente é vinculado a um Processo Administrativo do SESJUR. As
     propriedades do catálogo recebem os atributos dele e os do processo
     judicial e do processo extrajudicial vinculados escolhidos aqui.          */
  var pacote = null;   /* dados do processo vinculado, como vieram do SESJUR */

  function preencherRelacionados(select, lista, id, vazio) {
    select.innerHTML = '';
    (lista.length ? lista : [{ id: '', rotulo: vazio }]).forEach(function (item) {
      var o = document.createElement('option');
      o.value = item.id;
      o.textContent = item.rotulo;
      if (item.detalhe) o.title = item.detalhe;
      select.appendChild(o);
    });
    select.disabled = lista.length < 2;
    if (id) select.value = String(id);
  }

  function renderProcesso() {
    var p = estado.protocolo.trim();
    var id = estado.processo.id;

    el.processoBusca.hidden = !!p;
    el.processoAtual.hidden = !p;
    el.chipProtocolo.hidden = !p;
    el.chipProtocolo.textContent = p ? 'Protocolo ' + p : '';
    el.linkProcesso.textContent = p;
    [el.chipProtocolo, el.linkProcesso].forEach(function (a) {
      if (id) a.href = S.urlFicha(id); else a.removeAttribute('href');
    });

    el.processoResumo.textContent = pacote ? pacote.processo.resumo : '';
    el.processoResumo.hidden = !el.processoResumo.textContent;
    el.processoRelacionados.hidden = !pacote;
    el.btnAtualizarProcesso.hidden = !id;
    if (!pacote) return;
    preencherRelacionados(el.selJudicial, pacote.judiciais, estado.processo.judicialId,
                          'Nenhum processo judicial vinculado');
    preencherRelacionados(el.selExtrajudicial, pacote.extrajudiciais, estado.processo.extrajudicialId,
                          'Nenhum processo extrajudicial vinculado');
  }

  function valoresDoProcesso() {
    return S.valores(pacote, estado.processo.judicialId, estado.processo.extrajudicialId);
  }

  /* Os valores vindos do SESJUR substituem os do expediente. Sem processo,
     as propriedades do catálogo ficam vazias. */
  function aplicarDadosDoProcesso() {
    var v = valoresDoProcesso();
    estado.protocolo = v.processo_administrativo;
    v.processo_administrativo = '';
    Object.assign(estado.dados, v);
  }

  /* Reabertura do rascunho: os valores do expediente são mantidos (podem ter
     sido ajustados à mão), mas o assessor fica sabendo que o SESJUR mudou. */
  function avisarSeDesatualizado() {
    var v = valoresDoProcesso();
    var difere = estado.protocolo.trim() !== v.processo_administrativo ||
      Object.keys(v).some(function (chave) {
        return chave !== 'processo_administrativo' && String(estado.dados[chave] || '') !== v[chave];
      });
    if (difere) {
      toast('Os dados deste processo no SESJUR diferem dos que estão no expediente. ' +
            'Use "Atualizar dados do SESJUR" para trazê-los.');
    }
  }

  function aposMudarProcesso() {
    renderProcesso();
    renderCampos();
    aoAlterarDados();
  }

  /* opcoes.manterValores: só reata o vínculo, sem trocar os valores. */
  function vincularProcesso(id, opcoes) {
    opcoes = opcoes || {};
    return S.carregar(id).then(function (r) {
      if (!r.ok) { toast(r.mensagem, 'erro'); return false; }
      var mesmo = estado.processo.id === r.pacote.processo.id;
      pacote = r.pacote;
      estado.processo = {
        id: pacote.processo.id,
        judicialId: S.escolhido(pacote.judiciais, mesmo ? estado.processo.judicialId : null),
        extrajudicialId: S.escolhido(pacote.extrajudiciais, mesmo ? estado.processo.extrajudicialId : null)
      };
      if (opcoes.manterValores) avisarSeDesatualizado();
      else aplicarDadosDoProcesso();
      aposMudarProcesso();
      return true;
    });
  }

  function desvincularProcesso() {
    pacote = null;
    estado.processo = EX.semProcesso();
    aplicarDadosDoProcesso();
    aposMudarProcesso();
    el.buscaProcesso.value = '';
    el.buscaProcesso.focus();
  }

  /* Troca o processo judicial (ou extrajudicial) que alimenta as propriedades. */
  function trocarRelacionado(campo, lista, select) {
    var item = S.relacionado(lista, +select.value);
    if (!item) return;
    estado.processo[campo] = item.id;
    Object.assign(estado.dados, item.dados);
    renderCampos();
    aoAlterarDados();
  }

  /* Protocolo informado pela API, sem vínculo com um processo do SESJUR. */
  function definirProtocolo(valor) {
    estado.protocolo = String(valor || '');
    renderProcesso();
    aoAlterarDados();
  }

  /* ------------------------------------------------- busca de processos */
  var timerBusca = null, pedidoDeBusca = 0;

  function fecharBusca() {
    el.resultadosProcesso.hidden = true;
    el.buscaProcesso.setAttribute('aria-expanded', 'false');
  }

  function mostrarResultados(itens, mensagem) {
    el.resultadosProcesso.innerHTML = '';
    if (!itens.length) el.resultadosProcesso.appendChild(tituloDeMenu(mensagem));
    itens.forEach(function (item) {
      var b = itemDeMenu(item.rotulo, item.detalhe, '');
      b.dataset.id = item.id;
      b.setAttribute('role', 'option');
      el.resultadosProcesso.appendChild(b);
    });
    el.resultadosProcesso.hidden = false;
    el.buscaProcesso.setAttribute('aria-expanded', 'true');
  }

  function buscarProcessos() {
    var termo = el.buscaProcesso.value.trim();
    var pedido = ++pedidoDeBusca;
    clearTimeout(timerBusca);
    if (termo.length < MINIMO_PARA_PESQUISAR_PROCESSO) { fecharBusca(); return; }
    timerBusca = setTimeout(function () {
      S.buscar(termo).then(function (r) {
        if (pedido !== pedidoDeBusca) return;   /* já há uma busca mais nova */
        mostrarResultados(r.ok ? r.itens : [], r.ok ? 'Nenhum processo administrativo encontrado.' : r.mensagem);
      });
    }, 250);
  }

  function escolherResultado(botao) {
    fecharBusca();
    vincularProcesso(+botao.dataset.id).then(function (ok) {
      if (ok) toast('Expediente vinculado ao processo ' + estado.protocolo + '.');
    });
  }

  /* Setas percorrem os resultados; Enter no campo escolhe o primeiro. */
  function teclaNaBusca(ev) {
    var botoes = Array.prototype.slice.call(el.resultadosProcesso.querySelectorAll('[data-id]'));
    var i = botoes.indexOf(document.activeElement);
    if (ev.key === 'Escape') { fecharBusca(); el.buscaProcesso.focus(); return; }
    if (el.resultadosProcesso.hidden || !botoes.length) return;
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      ev.preventDefault();
      var j = i + (ev.key === 'ArrowDown' ? 1 : -1);
      if (j < 0) el.buscaProcesso.focus(); else botoes[Math.min(j, botoes.length - 1)].focus();
    } else if (ev.key === 'Enter' && i === -1) {
      ev.preventDefault();
      escolherResultado(botoes[0]);
    }
  }

  /* Ao abrir: o processo pedido pela ficha ("Gerar expediente") ou, na falta
     dele, o do rascunho. */
  function reatarProcesso() {
    var pedido = S.processoInicial;
    if (pedido) history.replaceState(null, '', location.pathname + location.hash);

    if (pedido && pedido !== estado.processo.id) {
      var haviaTexto = estado.conteudo != null;
      vincularProcesso(pedido).then(function (ok) {
        if (ok && haviaTexto) toast('Expediente vinculado ao processo ' + estado.protocolo + '. O texto em elaboração foi mantido.');
      });
    } else if (estado.processo.id) {
      vincularProcesso(estado.processo.id, { manterValores: true });
    }
  }
  /* =============================================================== MODELO */
  function renderSeletor() {
    el.selModelo.innerHTML = '';
    M.grupos().forEach(function (g) {
      var destino = el.selModelo;
      if (g.titulo) {
        destino = document.createElement('optgroup');
        destino.label = g.titulo;
        el.selModelo.appendChild(destino);
      }
      g.modelos.forEach(function (m) {
        var o = document.createElement('option');
        o.value = m.id;
        o.textContent = m.nome;
        destino.appendChild(o);
      });
    });
    if (!M.obter(estado.modeloId)) estado.modeloId = M.ID_EM_BRANCO;
    el.selModelo.value = estado.modeloId;
    renderIdentidadeModelo();
  }

  function renderIdentidadeModelo() {
    var m = modelo();
    el.modeloDesc.textContent = (m.salvo ? m.tipo + (m.compartilhado ? ' · Compartilhado' : '') + (m.descricao ? ' · ' : '') : '') +
                                m.descricao;
    /* compartilhado: só Administrador e Gestor regravam ou excluem */
    el.btnExcluirModelo.hidden = !M.podeAlterar(m.id);
    el.btnAtualizarModelo.hidden = !M.podeAlterar(m.id);
    el.btnSelPadrao.hidden = emBranco();
  }

  /* Recarrega tudo o que depende do modelo em uso. */
  function aposTrocarModelo() {
    edicaoDeModelo = false;
    renderFaixaModelo();
    estado.conteudo = null;
    estado.status = STATUS.EDITANDO;
    aplicarPadroesDoModelo();
    mostrarErros({});
    renderSeletor();
    renderCampos();
    renderEditor();
    renderModo();
    aoAlterarDados();
  }

  /* Valores sugeridos pelo modelo, aplicados apenas onde o campo está vazio. */
  function aplicarPadroesDoModelo() {
    var m = modelo();
    var padroes = m.padroes || {};
    Object.keys(padroes).forEach(function (chave) {
      if (!String(estado.dados[chave] || '').trim()) estado.dados[chave] = padroes[chave];
    });
    if (m.salvo && m.formato) {
      estado.formato = EX.normalizarFormato(m.formato);
      aplicarFormato();
    }
  }

  function confirmarDescarte(titulo, mensagem, okLabel) {
    if (estado.conteudo == null) return Promise.resolve(true);
    return dialogo({ titulo: titulo, mensagem: mensagem, okLabel: okLabel, perigo: true })
      .then(function (v) { return !!v; });
  }

  function trocarModelo() {
    var novo = el.selModelo.value;
    confirmarDescarte(
      'Trocar de modelo',
      'O texto atual foi editado e não está salvo como modelo. Trocar de modelo descarta essas alterações. Deseja continuar?',
      'Trocar modelo'
    ).then(function (ok) {
      if (!ok) { el.selModelo.value = estado.modeloId; return; }
      estado.modeloId = novo;
      M.registrarUso(novo);
      aposTrocarModelo();
    });
  }

  function camposDoModelo(m, okLabel) {
    return {
      campos: [
        { id: 'titulo', label: 'Título do modelo', valor: m ? m.nome : '',
          placeholder: 'Ex.: Ofício de solicitação de informações' },
        { id: 'tipo', label: 'Tipo', valor: m ? m.tipo : M.tipos()[0],
          opcoes: M.tipos().map(function (t) { return { valor: t, label: t }; }) },
        { id: 'descricao', label: 'Descrição', valor: m ? m.descricao : '', multilinha: true,
          placeholder: 'Ex.: Modelo utilizado para solicitar informações complementares às unidades administrativas.' },
        M.campoVisibilidade(m)
      ].filter(Boolean),
      okLabel: okLabel,
      validar: function (v) { return v.titulo ? null : 'Informe o título do modelo.'; }
    };
  }

  /* O que o modelo guarda: texto, formatação e propriedades. Nunca o protocolo
     nem os valores preenchidos. */
  function retratoParaModelo(v) {
    return {
      titulo: v.titulo, tipo: v.tipo, descricao: v.descricao, visibilidade: v.visibilidade,
      baseId: estado.modeloId,
      conteudo: fonteAtual(),
      definicoes: definicoes(),
      selecao: selecionadas(),
      formato: estado.formato,
      assunto: estado.dados.assunto || modelo().assuntoPadrao
    };
  }

  function salvarComoModelo() {
    var op = camposDoModelo(null, 'Salvar modelo');
    op.titulo = 'Salvar como modelo';
    op.mensagem = 'O texto, a formatação e as propriedades ficam guardados como um novo modelo. ' +
                  (M.podeCompartilhar ? 'Individual: só você o vê; compartilhado: fica disponível para todos os usuários. '
                                      : 'O modelo é individual: só você o vê. ') +
                  'O protocolo e os valores preenchidos não fazem parte do modelo.';
    var retrato;
    dialogo(op).then(function (v) {
      if (!v) return null;
      retrato = retratoParaModelo(v);
      return M.salvarComo(retrato);
    }).then(function (r) {
      if (!r) return;
      if (!r.ok) { toast(r.erro, 'erro'); return; }

      /* o expediente passa a usar o modelo novo, levando as propriedades atuais */
      estado.selecao[r.id] = selecionadas().slice();
      estado.definicoes[r.id] = P.copiar(definicoes());
      estado.modeloId = r.id;
      aposTrocarModelo();
      toast('Modelo "' + retrato.titulo + '" salvo.');
    });
  }

  function atualizarModeloAtual() {
    var m = modelo();
    if (!M.podeAlterar(m.id)) return;
    var op = camposDoModelo(m, 'Atualizar');
    op.titulo = 'Atualizar modelo';
    op.mensagem = 'Regrava este modelo com o texto, a formatação e as propriedades atuais.' +
                  (m.compartilhado ? ' O modelo é compartilhado: a alteração vale para todos os usuários.' : '');
    var titulo;
    dialogo(op).then(function (v) {
      if (!v) return null;
      titulo = v.titulo;
      return M.atualizar(m.id, retratoParaModelo(v));
    }).then(function (r) {
      if (!r) return;
      if (!r.ok) { toast(r.erro, 'erro'); return; }
      estado.conteudo = null;   /* o texto atual passou a SER o do modelo */
      renderSeletor();
      renderEditor();
      aoAlterarDados();
      toast('Modelo "' + titulo + '" atualizado.');
    });
  }

  function excluirModeloAtual() {
    var m = modelo();
    if (!M.podeAlterar(m.id)) return;
    dialogo({
      titulo: 'Excluir modelo',
      mensagem: 'Excluir o modelo "' + m.nome + '"? Esta ação não pode ser desfeita.' +
                (m.compartilhado ? ' O modelo é compartilhado e deixará de existir para todos os usuários.' : ''),
      okLabel: 'Excluir',
      perigo: true
    }).then(function (v) {
      return v ? M.excluir(m.id) : null;
    }).then(function (r) {
      if (!r) return;
      if (!r.ok) { toast(r.erro, 'erro'); return; }
      delete estado.selecao[m.id];
      delete estado.definicoes[m.id];
      estado.modeloId = M.ID_EM_BRANCO;
      aposTrocarModelo();
      toast('Modelo excluído.');
    });
  }

  function restaurarTexto() {
    if (estado.conteudo == null) { toast('O texto já é o original do modelo.'); return; }
    dialogo({
      titulo: 'Restaurar texto original',
      mensagem: 'Restaurar o texto original deste modelo? As alterações feitas no texto serão perdidas. ' +
                'As propriedades preenchidas são mantidas.',
      okLabel: 'Restaurar',
      perigo: true
    }).then(function (v) {
      if (!v) return;
      estado.conteudo = null;
      renderEditor();
      aoAlterarDados();
      toast('Texto original do modelo restaurado.');
    });
  }

  function limparDocumento() {
    dialogo({
      titulo: 'Limpar documento',
      mensagem: 'Desvincular o processo e apagar o texto e os valores preenchidos, voltando ao Expediente em Branco? ' +
                'Os modelos salvos são mantidos.',
      okLabel: 'Limpar',
      perigo: true
    }).then(function (v) {
      if (!v) return;
      estado = EX.novo();
      pacote = null;
      ARM.apagar();
      aplicarFormato();
      renderProcesso();
      aposTrocarModelo();
      toast('Documento limpo.');
    });
  }

  /* ========================================================= PROPRIEDADES */
  function aposMudarPropriedades() {
    renderCampos();
    renderEditor();
    aoAlterarDados();
  }

  /* Propriedades do catálogo (atributos do processo e dos vinculados) que
     ainda não fazem parte deste expediente. O protocolo fica de fora: ele tem
     a própria seção. */
  function disponiveisNoCatalogo() {
    var atual = selecionadas();
    return T.catalogo().filter(function (def) {
      return def.token !== TOKEN_PROTOCOLO && atual.indexOf(def.token) === -1;
    });
  }

  function acrescentarDoCatalogo(token) {
    if (selecionadas().indexOf(token) !== -1) return;
    definirSelecao(selecionadas().concat([token]));
    aposMudarPropriedades();
  }

  function retirarPropriedade(token) {
    var propria = definicaoPorToken(token);

    function aplicar() {
      definirSelecao(selecionadas().filter(function (t) { return t !== token; }));
      if (propria) {
        var lista = definicoes();
        lista.splice(lista.indexOf(propria), 1);
        delete estado.valores[propria.id];
      }
      aposMudarPropriedades();
    }

    /* Retirar uma propriedade do catálogo é reversível; excluir uma própria, não. */
    if (!propria) { aplicar(); return; }

    var usada = E.tokensDe(fonteAtual()).indexOf(token) !== -1;
    dialogo({
      titulo: 'Excluir propriedade',
      mensagem: usada
        ? 'A propriedade "' + propria.titulo + '" está sendo usada no texto. Excluindo-a, {{' + token + '}} ficará pendente.'
        : 'Excluir a propriedade "' + propria.titulo + '"?',
      okLabel: 'Excluir',
      perigo: true
    }).then(function (v) {
      if (!v) return;
      aplicar();
      toast('Propriedade excluída.');
    });
  }

  function moverPropriedade(token, direcao) {
    var lista = selecionadas().slice();
    var i = lista.indexOf(token);
    var j = i + direcao;
    if (i === -1 || j < 0 || j >= lista.length) return;
    lista[i] = lista[j];
    lista[j] = token;
    definirSelecao(lista);
    aposMudarPropriedades();
  }

  /* Cria uma propriedade própria e já a coloca no documento. */
  function criarPropriedade(d, valor) {
    var erro = P.validar(d, tokensProprios());
    if (erro) return { ok: false, erro: erro };
    var def = P.criarDefinicao(d);
    definicoes().push(def);
    if (valor) estado.valores[def.id] = valor;
    definirSelecao(selecionadas().concat([def.identificador]));
    aposMudarPropriedades();
    return { ok: true, token: def.identificador, id: def.id };
  }

  /* Formulário de criação/edição. `def` nulo = nova propriedade. */
  function dialogoPropriedade(def) {
    var outros = tokensProprios().filter(function (t) { return !def || t !== def.identificador; });
    var tipoDeEntrada = { numero: 'number', data: 'date' };

    return dialogo({
      titulo: def ? 'Editar propriedade' : 'Nova propriedade',
      mensagem: def ? 'Se o identificador mudar, os usos no texto do expediente são atualizados automaticamente.' : null,
      campos: [
        { id: 'titulo', label: 'Título', valor: def ? def.titulo : '', placeholder: 'Ex.: Nome do interessado' },
        { id: 'tipo', label: 'Tipo', valor: def ? def.tipo : 'texto',
          opcoes: P.TIPOS.map(function (t) { return { valor: t.id, label: t.rotulo }; }) },
        { id: 'identificador', label: 'Identificador', valor: def ? def.identificador : '',
          placeholder: 'NOME_PROPRIEDADE', ajuda: 'Usado no texto como {{NOME_PROPRIEDADE}}' },
        { id: 'opcoes', label: 'Opções', lista: true, valor: def ? def.opcoes : [],
          visivelSe: function (v) { return v.tipo === 'lista'; } },
        { id: 'valor', label: 'Valor', valor: def ? (estado.valores[def.id] || '') : '',
          visivelSe: function (v) { return v.tipo !== 'lista'; } },
        { id: 'descricao', label: 'Descrição / orientação', valor: def ? def.descricao : '', multilinha: true,
          placeholder: 'Texto de apoio exibido abaixo do campo' },
        { id: 'grupo', label: 'Grupo (opcional)', valor: def ? def.grupo : '', placeholder: 'Ex.: Interessado' },
        { id: 'obrigatoria', label: 'Obrigatória', checkbox: true, valor: def ? def.obrigatoria : false }
      ],
      okLabel: def ? 'Salvar' : 'Criar propriedade',
      aoMontar: function (ent, linhas) {
        var tocado = !!def;
        var ajuda = linhas.identificador.querySelector('.ajuda');
        function previa() { ajuda.textContent = 'Usado no texto como {{' + (ent.identificador.value || 'NOME_PROPRIEDADE') + '}}'; }

        ent.identificador.spellcheck = false;
        ent.titulo.addEventListener('input', function () {
          if (tocado) return;
          ent.identificador.value = P.sugerirIdentificador(ent.titulo.value);
          previa();
        });
        ent.identificador.addEventListener('input', function () {
          tocado = true;
          /* durante a digitação o "_" final é mantido; a limpeza completa vem ao salvar */
          ent.identificador.value = ent.identificador.value.normalize('NFD').replace(/[̀-ͯ]/g, '')
            .toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+/, '');
          previa();
        });
        function ajustarValor() { ent.valor.type = tipoDeEntrada[ent.tipo.value] || 'text'; }
        ent.tipo.addEventListener('change', ajustarValor);
        ajustarValor();
        ent.valor.value = def ? (estado.valores[def.id] || '') : '';
        previa();
      },
      validar: function (v) { return P.validar(v, outros); }
    });
  }

  function novaPropriedade() {
    dialogoPropriedade(null).then(function (v) {
      if (!v) return;
      var r = criarPropriedade(v, v.tipo === 'lista' ? '' : v.valor);
      if (!r.ok) { toast(r.erro, 'erro'); return; }
      toast('Propriedade criada. Clique em {{' + r.token + '}} ou use "Propriedade" na barra para inseri-la no texto.');
    });
  }

  function editarPropriedade(token) {
    var def = definicaoPorToken(token);
    if (!def) return;
    dialogoPropriedade(def).then(function (v) {
      if (!v) return;
      var novo = T.normalizarIdentificador(v.identificador);
      if (novo !== def.identificador) {
        estado.conteudo = fonteAtual().split('{{' + def.identificador + '}}').join('{{' + novo + '}}');
        var lista = selecionadas().map(function (t) { return t === def.identificador ? novo : t; });
        def.identificador = novo;
        definirSelecao(lista);
      }
      def.titulo = v.titulo;
      def.tipo = v.tipo;
      def.opcoes = v.opcoes;
      def.descricao = v.descricao;
      def.grupo = v.grupo;
      def.obrigatoria = v.obrigatoria;
      if (v.tipo === 'lista') {
        if (v.opcoes.indexOf(estado.valores[def.id]) === -1) delete estado.valores[def.id];
      } else {
        estado.valores[def.id] = v.valor;
      }
      aposMudarPropriedades();
      toast('Propriedade atualizada.');
    });
  }

  /* ----------------------------------------------------- cartões de campo */
  function botaoMini(texto, titulo, nome, aoClicar, desabilitado, classe) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn-prop' + (classe ? ' ' + classe : '');
    b.textContent = texto;
    b.title = titulo;
    b.setAttribute('aria-label', titulo + ': ' + nome);
    b.disabled = !!desabilitado;
    b.addEventListener('click', aoClicar);
    return b;
  }

  function criarControle(c) {
    var campo;
    if (c.tipo === 'lista') {
      campo = document.createElement('select');
      [''].concat(c.opcoes || []).forEach(function (o) {
        var item = document.createElement('option');
        item.value = o;
        item.textContent = o || 'Selecione…';
        campo.appendChild(item);
      });
    } else if (c.tipo === 'textarea' || c.tipo === 'texto_longo') {
      campo = document.createElement('textarea');
      campo.rows = 3;
    } else {
      campo = document.createElement('input');
      campo.type = !c.propria ? 'text' : c.tipo === 'numero' ? 'number' : c.tipo === 'data' ? 'date' : 'text';
      if (!c.propria && c.tipo === 'data') { campo.inputMode = 'numeric'; campo.maxLength = 10; }
    }
    campo.id = 'f_' + c.chave;
    campo.value = c.valor || '';
    if (c.placeholder && campo.tagName !== 'SELECT') campo.placeholder = c.placeholder;
    campo.autocomplete = 'off';
    if (c.obrigatoria) campo.setAttribute('aria-required', 'true');

    campo.addEventListener('input', function () {
      var v = c.mascara ? c.mascara(campo.value) : campo.value;
      if (v !== campo.value) campo.value = v;
      c.aoMudar(v);
      limparErro(c.chave);
      aoAlterarDados();
    });
    return campo;
  }

  /* c = { token, chave, rotulo, tipo, opcoes, valor, placeholder, ajuda, grupo,
           mascara, obrigatoria, propria, primeira, ultima, aoMudar, aoEditar } */
  function criarCartao(c) {
    var wrap = document.createElement('div');
    wrap.className = 'campo campo-prop' + (c.propria ? ' campo-proprio' : '');
    wrap.dataset.chave = c.chave;
    wrap.dataset.busca = (c.rotulo + ' ' + c.token + ' ' + (c.grupo || '')).toLowerCase();

    var cab = document.createElement('div');
    cab.className = 'prop-cab';

    var lab = document.createElement('label');
    lab.setAttribute('for', 'f_' + c.chave);
    lab.textContent = c.rotulo;
    cab.appendChild(lab);

    var acoes = document.createElement('span');
    acoes.className = 'prop-acoes';
    acoes.appendChild(botaoMini('↑', 'Mover para cima', c.rotulo, function () { moverPropriedade(c.token, -1); }, c.primeira));
    acoes.appendChild(botaoMini('↓', 'Mover para baixo', c.rotulo, function () { moverPropriedade(c.token, 1); }, c.ultima));
    if (c.aoEditar) acoes.appendChild(botaoMini('✎', 'Editar propriedade', c.rotulo, c.aoEditar));
    acoes.appendChild(botaoMini('✕', c.propria ? 'Excluir propriedade' : 'Retirar do expediente', c.rotulo,
      function () { retirarPropriedade(c.token); }, false, 'btn-prop-perigo'));
    cab.appendChild(acoes);
    wrap.appendChild(cab);

    var meta = document.createElement('div');
    meta.className = 'prop-meta';
    var tok = document.createElement('button');
    tok.type = 'button';
    tok.className = 'tok-etiqueta';
    tok.textContent = '{{' + c.token + '}}';
    tok.title = 'Inserir esta propriedade no texto';
    tok.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
    tok.addEventListener('click', function () { inserirToken(c.token); });
    meta.appendChild(tok);

    [[c.obrigatoria, 'op op-obrig', 'obrigatória'], [c.grupo, 'op', c.grupo]].forEach(function (s) {
      if (!s[0]) return;
      var e = document.createElement('span');
      e.className = s[1];
      e.textContent = s[2];
      meta.appendChild(e);
    });
    wrap.appendChild(meta);

    wrap.appendChild(criarControle(c));

    if (c.ajuda) {
      var a = document.createElement('div');
      a.className = 'ajuda';
      a.textContent = c.ajuda;
      wrap.appendChild(a);
    }
    var erro = document.createElement('div');
    erro.className = 'erro';
    erro.id = 'e_' + c.chave;
    erro.hidden = true;
    erro.setAttribute('role', 'alert');
    wrap.appendChild(erro);
    return wrap;
  }

  function cartaoDoCatalogo(def, posicao) {
    var m = modelo();
    var rot = m.rotulos || {};
    var chave = def.chave;
    return criarCartao(Object.assign(posicao, {
      token: def.token, chave: chave, rotulo: rot[chave] || def.label,
      tipo: def.tipo, valor: estado.dados[chave], ajuda: def.ajuda,
      placeholder: (chave === 'assunto' ? m.assuntoPadrao : null) || rot[chave + '_placeholder'] || def.placeholder,
      mascara: function (v) { return aplicarMascara(def, v); },
      aoMudar: function (v) { estado.dados[chave] = v; }
    }));
  }

  function cartaoProprio(def, posicao) {
    return criarCartao(Object.assign(posicao, {
      token: def.identificador, chave: def.identificador, rotulo: def.titulo,
      tipo: def.tipo, opcoes: def.opcoes, valor: estado.valores[def.id],
      ajuda: def.descricao, grupo: def.grupo, placeholder: 'Valor da propriedade',
      obrigatoria: def.obrigatoria, propria: true,
      aoMudar: function (v) { estado.valores[def.id] = v; },
      aoEditar: function () { editarPropriedade(def.identificador); }
    }));
  }

  /* Os campos seguem exatamente a ordem da lista do expediente. */
  function renderCampos() {
    var lista = selecionadas();
    var visiveis = lista.filter(function (token) {
      if (definicaoPorToken(token)) return true;
      var def = T.propriedadePorToken(token);
      return !!def && !def.auto && !!def.chave && def.token !== TOKEN_PROTOCOLO;
    });

    el.campos.innerHTML = '';
    visiveis.forEach(function (token, i) {
      var posicao = { primeira: i === 0, ultima: i === visiveis.length - 1 };
      var propria = definicaoPorToken(token);
      el.campos.appendChild(propria
        ? cartaoProprio(propria, posicao)
        : cartaoDoCatalogo(T.propriedadePorToken(token), posicao));
    });

    el.vazioProps.hidden = visiveis.length > 0;
    el.contaSel.textContent = String(visiveis.length);
    el.buscaProps.hidden = visiveis.length < MINIMO_PARA_BUSCA;
    if (el.buscaProps.hidden) el.buscaProps.value = '';
    filtrarCampos();
    mostrarErros(estado.erros);
  }

  function filtrarCampos() {
    var termo = el.buscaProps.value.trim().toLowerCase();
    Array.prototype.forEach.call(el.campos.children, function (c) {
      c.hidden = !!termo && c.dataset.busca.indexOf(termo) === -1;
    });
  }

  function montarMenuCatalogo() {
    el.menuCatalogo.innerHTML = '';
    var livres = disponiveisNoCatalogo();
    if (!livres.length) {
      el.menuCatalogo.appendChild(tituloDeMenu('Todas as propriedades do catálogo já estão neste expediente.'));
      return;
    }
    var mapa = props();
    T.ORDEM_GRUPOS.forEach(function (grupo) {
      var itens = livres.filter(function (def) { return def.grupo === grupo; });
      if (!itens.length) return;
      el.menuCatalogo.appendChild(tituloDeMenu(T.tituloDoGrupo(grupo)));
      itens.forEach(function (def) {
        var valor = mapa[def.token].valor;
        el.menuCatalogo.appendChild(itemDeMenu(
          rotuloDoCatalogo(def),
          '{{' + def.token + '}}' + (valor ? ' · ' + resumir(valor) : ' · sem valor'),
          def.token, !valor));
      });
    });
  }

  function focarPropriedade(token) {
    abrirPainel(true);
    var def = T.propriedadePorToken(token);
    var alvo = (def && def.token === TOKEN_PROTOCOLO)
             ? (estado.protocolo.trim() ? el.linkProcesso : el.buscaProcesso)
             : $('f_' + (def && def.chave ? def.chave : token));
    if (!alvo) return;
    el.buscaProps.value = '';
    filtrarCampos();
    alvo.scrollIntoView({ block: 'center', behavior: 'smooth' });
    alvo.focus();
  }

  /* ==================================================== ERROS DE CAMPO */
  function limparErro(chave) {
    if (estado.erros[chave]) delete estado.erros[chave];
    var wrap = el.campos.querySelector('[data-chave="' + chave + '"]');
    if (wrap) wrap.classList.remove('invalido');
    var e = $('e_' + chave);
    if (e) { e.hidden = true; e.textContent = ''; }
  }

  function mostrarErros(erros) {
    Array.prototype.forEach.call(el.campos.querySelectorAll('.campo.invalido'), function (w) { w.classList.remove('invalido'); });
    Array.prototype.forEach.call(el.campos.querySelectorAll('.erro'), function (e) { e.hidden = true; e.textContent = ''; });
    estado.erros = erros || {};
    Object.keys(estado.erros).forEach(function (chave) {
      var wrap = el.campos.querySelector('[data-chave="' + chave + '"]');
      if (wrap) wrap.classList.add('invalido');
      var e = $('e_' + chave);
      if (e) { e.textContent = estado.erros[chave]; e.hidden = false; }
    });
  }

  /* ========================================================== ITENS DE MENU */
  function tituloDeMenu(texto) {
    var h = document.createElement('div');
    h.className = 'menu-titulo';
    h.textContent = texto;
    return h;
  }

  function itemDeMenu(nome, detalhe, token, semValor) {
    var b = document.createElement('button');
    b.type = 'button';
    b.dataset.token = token;
    var n = document.createElement('span');
    n.textContent = nome;
    b.appendChild(n);
    if (detalhe) {
      var d = document.createElement('small');
      d.textContent = detalhe;
      if (semValor) d.className = 'sem-valor';
      b.appendChild(d);
    }
    return b;
  }

  function resumir(s) { return s.length > 42 ? s.slice(0, 40) + '…' : s; }

  /* "Inserir propriedade": as deste expediente, o protocolo e as automáticas. */
  function montarMenuProps() {
    el.menuProps.innerHTML = '';
    var mapa = props();
    var doExpediente = selecionadas()
      .filter(function (t) { return t !== TOKEN_PROTOCOLO; })
      .map(function (t) { return mapa[t]; }).filter(Boolean);
    var doSistema = [TOKEN_PROTOCOLO].concat(
      Object.keys(T.PROPRIEDADES).filter(function (t) { return T.PROPRIEDADES[t].auto; })
    ).map(function (t) { return mapa[t]; }).filter(Boolean);

    [{ titulo: 'Propriedades deste expediente', itens: doExpediente },
     { titulo: 'Processo e automáticas', itens: doSistema }].forEach(function (g) {
      if (!g.itens.length) return;
      el.menuProps.appendChild(tituloDeMenu(g.titulo));
      g.itens.forEach(function (p) {
        el.menuProps.appendChild(itemDeMenu(
          rotuloDoToken(p.token),
          '{{' + p.token + '}}' + (p.valor ? ' · ' + resumir(p.valor) : ' · sem valor'),
          p.token, !p.valor));
      });
    });
  }

  /* =============================================================== EDITOR */
  function renderEditor() {
    ED.render(fonteAtual());
    el.btnRestaurar.disabled = estado.conteudo == null;
  }

  /* O texto ainda é o do modelo: acompanha as mudanças nos dados. */
  function renderEditorSeIntocado() {
    if (estado.conteudo == null && !emBranco()) renderEditor();
  }

  /* Chamado a cada alteração no editor. */
  function aoEditarTexto() {
    var lido = ED.ler();
    var eraDoModelo = estado.conteudo == null;
    /* Expediente em Branco vazio continua "intocado": nada a preservar. */
    estado.conteudo = (lido === '' && emBranco()) ? null : lido;
    if (eraDoModelo && estado.conteudo != null && !emBranco()) {
      toast('Texto agora é personalizado. Use "Restaurar texto" para voltar ao modelo.');
    }
    el.btnRestaurar.disabled = estado.conteudo == null;
    agendarAtualizacao();
  }

  var timerTexto = null;
  function agendarAtualizacao() {
    clearTimeout(timerTexto);
    timerTexto = setTimeout(function () { renderPreview(); atualizarFerramentas(); }, 150);
    agendarSalvar();
  }

  function inserirToken(token) {
    if (!editando()) definirModo(STATUS.EDITANDO);
    ED.inserirToken(token);
    toast('{{' + token + '}} inserida no texto.');
  }

  function atualizarFerramentas() {
    if (!editando()) return;
    var e = ED.estadoDosComandos(COMANDOS_COM_ESTADO);
    Array.prototype.forEach.call(el.ferramentas.querySelectorAll('.btn-fer[data-cmd]'), function (b) {
      if (!(b.dataset.cmd in e.comandos)) return;
      b.classList.toggle('ativo', e.comandos[b.dataset.cmd]);
      b.setAttribute('aria-pressed', String(e.comandos[b.dataset.cmd]));
    });
    el.selBloco.value = ['h1', 'h2', 'blockquote'].indexOf(e.bloco) !== -1 ? e.bloco : 'p';
  }

  function dialogoTabela() {
    ED.guardarSelecao();
    dialogo({
      titulo: 'Inserir tabela',
      campos: [
        { id: 'linhas', label: 'Linhas', valor: '2', tipo: 'number' },
        { id: 'colunas', label: 'Colunas', valor: '2', tipo: 'number' }
      ],
      okLabel: 'Inserir',
      validar: function (v) {
        var l = +v.linhas, c = +v.colunas;
        return (l >= 1 && l <= 30 && c >= 1 && c <= 8) ? null : 'Use de 1 a 30 linhas e de 1 a 8 colunas.';
      }
    }).then(function (v) { if (v) ED.inserirTabela(+v.linhas, +v.colunas); });
  }

  function dialogoLink() {
    ED.guardarSelecao();
    dialogo({
      titulo: 'Inserir link',
      mensagem: 'Com um trecho selecionado, o link é aplicado a ele; sem seleção, o endereço é inserido no cursor.',
      campos: [{ id: 'url', label: 'Endereço', valor: 'https://', placeholder: 'https://' }],
      okLabel: 'Inserir',
      validar: function (v) { return /^(https?:\/\/|mailto:)\S+$/i.test(v.url) ? null : 'Informe um endereço iniciado por https:// ou mailto:.'; }
    }).then(function (v) { if (v) ED.inserirLink(v.url); });
  }

  var ACOES_DE_FERRAMENTA = {
    recuar: function () { ED.recuar(1); },
    'recuar-menos': function () { ED.recuar(-1); },
    'primeira-linha': function () { ED.alternarRecuoPrimeiraLinha(); },
    sobe: function () { ED.moverParagrafo(-1); },
    desce: function () { ED.moverParagrafo(1); },
    tabela: dialogoTabela,
    link: dialogoLink,
    linha: function () { ED.inserirLinhaHorizontal(); },
    quebra: function () { ED.inserirQuebraDePagina(); }
  };

  /* ============================================================= FORMATO */
  function margensAtuais() { return EX.MARGENS[estado.formato.margens]; }

  /* Entrelinha, espaçamento e margens valem para o documento inteiro. */
  function aplicarFormato() {
    var f = estado.formato, m = margensAtuais();
    EX.aplicarFormato(el.mesa, f);
    el.estiloPagina.textContent = '@page { size: A4; margin: ' +
      [m.sup, m.dir, m.inf, m.esq].map(function (v) { return v + 'cm'; }).join(' ') + '; }';
    el.selEntrelinha.value = f.entrelinha;
    el.selEspaco.value = f.espacoParagrafo;
    el.selMargens.value = f.margens;
  }

  function mudarFormato(chave, valor) {
    estado.formato[chave] = valor;
    estado.formato = EX.normalizarFormato(estado.formato);
    aplicarFormato();
    agendarSalvar();
  }

  /* ==================================================== PRÉ-VISUALIZAÇÃO */
  function renderPreview() {
    var sub = CE.documento.resolver(documentoAtual(), { marcarVariaveis: true, marcarPendentes: true });
    var fonte = sub.fonte, mapa = sub.propriedades;

    el.folha.innerHTML = sub.html;
    el.folha.classList.toggle('destacar', estado.destacar);

    estado.ultimo = {
      modeloId: estado.modeloId, protocolo: estado.protocolo.trim(), fonte: fonte,
      html: sub.html, usados: sub.usados, pendentes: sub.pendentes, propriedades: mapa
    };

    renderPendentes(sub.pendentes);
    return sub;
  }

  function obrigatorias(pendentes) { return pendentes.filter(function (p) { return p.obrigatoria; }); }

  function plural(n, um, varios) { return n + ' ' + (n === 1 ? um : varios); }

  function renderPendentes(pendentes) {
    var obrig = obrigatorias(pendentes);
    var total = E.tokensDe(fonteAtual()).length;

    el.btnPendentes.hidden = obrig.length === 0;
    el.btnPendentes.textContent = '⚠ ' + (obrig.length === 1
      ? '1 propriedade obrigatória ainda não foi preenchida'
      : obrig.length + ' propriedades obrigatórias ainda não foram preenchidas');
    if (!obrig.length) el.menuPendentes.hidden = true;

    el.menuPendentes.innerHTML = '';
    obrig.forEach(function (p) {
      el.menuPendentes.appendChild(itemDeMenu(
        rotuloDoToken(p.token),
        p.motivo === 'inexistente' ? '{{' + p.token + '}} · propriedade não cadastrada' : '{{' + p.token + '}}',
        p.token, true));
    });

    var opcionais = pendentes.length - obrig.length;
    el.contaTokens.textContent = total
      ? plural(total, 'propriedade no texto', 'propriedades no texto') +
        (opcionais ? ' · ' + plural(opcionais, 'opcional sem valor', 'opcionais sem valor') : '')
      : '';
  }

  function aviso(tipo, texto) {
    var d = document.createElement('div');
    d.className = 'aviso aviso-' + tipo;
    d.textContent = texto;
    el.avisos.appendChild(d);
  }

  function renderAuditoria(auditoria) {
    el.painelAud.hidden = !auditoria;
    el.auditoria.innerHTML = '';
    if (!auditoria) return;

    auditoria.itens.forEach(function (i) {
      var linha = document.createElement('div');
      linha.className = 'aud-linha aud-' + i.status;

      var marca = document.createElement('span');
      marca.className = 'marca-status';
      marca.textContent = i.status === 'ok' ? '✓' : (i.status === 'falha' ? '✕' : '–');

      var rot = document.createElement('span');
      rot.className = 'rot';
      rot.textContent = i.label;

      var val = document.createElement('span');
      val.className = 'val' + (i.valor ? '' : ' vazio');
      val.textContent = i.valor || 'sem valor';

      linha.appendChild(marca);
      linha.appendChild(rot);
      linha.appendChild(val);

      if (i.status !== 'ok') {
        var nota = document.createElement('span');
        nota.className = 'nota';
        nota.textContent = i.nota;
        linha.appendChild(nota);
      }
      el.auditoria.appendChild(linha);
    });
  }

  /* Confere o expediente e decide entre PRONTO e apenas VISUALIZANDO. É o
     antigo "Gerar expediente": valida, resolve, sinaliza pendências e audita. */
  function avaliar() {
    var fonte = fonteAtual();
    var proprias = propriasParaMotor();
    el.avisos.innerHTML = '';
    estado.status = STATUS.VISUALIZANDO;

    var v = E.validar(estado.modeloId, dados(), fonte, proprias, { contexto: ctx() });
    mostrarErros(v.ok ? {} : v.erros);

    var sub = renderPreview();
    var obrig = obrigatorias(sub.pendentes);
    var auditoria = null;

    if (!fonte.trim()) {
      aviso('alerta', 'O expediente está vazio. Volte ao modo Editar para escrever o texto ou selecione um modelo.');
    } else if (obrig.length) {
      aviso('erro', 'Existem propriedades obrigatórias pendentes: ' +
        obrig.map(function (p) { return rotuloDoToken(p.token); }).join(' · '));
    } else if (!v.ok) {
      aviso('erro', 'Existem propriedades com valor inválido. Revise os campos destacados no painel de propriedades.');
    } else {
      if (sub.pendentes.length) {
        aviso('alerta', 'Propriedades opcionais sem valor permanecem marcadas no texto: ' +
          sub.pendentes.map(function (p) { return p.label; }).join(' · ') +
          '. Preencha-as ou remova-as do texto.');
      }
      auditoria = E.auditar(props(), fonte, { html: sub.html, usados: sub.usados });
      if (auditoria.ok) {
        estado.status = STATUS.PRONTO;
        aviso('info', 'Conferência automática: todas as propriedades usadas no texto foram inseridas exatamente como informadas.');
      } else {
        aviso('erro', 'A conferência automática apontou divergência. Revise os itens marcados em "Conferência das propriedades".');
      }
    }

    estado.ultimo.auditoria = auditoria;
    renderAuditoria(auditoria);
  }

  /* ================================================================ MODOS */
  var ROTULO_DO_STATUS = {};
  ROTULO_DO_STATUS[STATUS.EDITANDO] = 'Em edição';
  ROTULO_DO_STATUS[STATUS.VISUALIZANDO] = 'Visualização · conferir avisos';
  ROTULO_DO_STATUS[STATUS.PRONTO] = 'Pronto para exportar';

  function renderModo() {
    var ed = editando();
    el.editor.hidden = !ed;
    el.ferramentas.hidden = !ed;
    el.folha.hidden = ed;
    el.faixaPreview.hidden = ed;
    if (ed) el.painelAud.hidden = true;

    el.btnModoEditar.classList.toggle('ativo', ed);
    el.btnModoEditar.setAttribute('aria-pressed', String(ed));
    el.btnModoVisualizar.classList.toggle('ativo', !ed);
    el.btnModoVisualizar.setAttribute('aria-pressed', String(!ed));

    el.seloStatus.textContent = ROTULO_DO_STATUS[estado.status];
    el.seloStatus.className = 'selo selo-' + estado.status;
    document.body.dataset.status = estado.status;
  }

  function definirModo(status) {
    if (status === STATUS.EDITANDO) {
      estado.status = STATUS.EDITANDO;
      renderModo();
      ED.focar();
    } else {
      avaliar();
      renderModo();
      el.mesa.scrollTop = 0;
    }
    agendarSalvar();
  }

  /* Qualquer mudança em protocolo, valores ou lista de propriedades. */
  function aoAlterarDados() {
    renderEditorSeIntocado();
    if (editando()) renderPreview();
    else { avaliar(); renderModo(); }
    agendarSalvar();
  }

  /* ============================================================ SAÍDAS */
  function nomeDoArquivo() { return M.nomeArquivo(estado.modeloId, dados(), estado.protocolo.trim()); }

  /* Documento final: propriedades resolvidas e HTML simplificado. */
  function htmlFinal() {
    return CE.documento.htmlFinal(documentoAtual());
  }

  function formatoParaExportar() {
    return {
      entrelinha: estado.formato.entrelinha,
      espacoParagrafo: estado.formato.espacoParagrafo,
      margens: margensAtuais()
    };
  }

  /* Exportar com obrigatórias em aberto pede confirmação expressa. */
  function prontoParaSair() {
    if (!fonteAtual().trim()) {
      toast('O expediente está vazio.', 'erro');
      return Promise.resolve(false);
    }
    var obrig = obrigatorias(renderPreview().pendentes);
    if (!obrig.length) return Promise.resolve(true);
    return dialogo({
      titulo: 'Propriedades obrigatórias pendentes',
      mensagem: 'Ainda não foram preenchidas: ' +
        obrig.map(function (p) { return rotuloDoToken(p.token); }).join(' · ') +
        '. Deseja continuar mesmo assim?',
      okLabel: 'Continuar mesmo assim',
      perigo: true
    }).then(function (v) { return !!v; });
  }

  function baixarMarkdown() {
    X.baixar(new Blob([X.paraMarkdown(htmlFinal())], { type: 'text/markdown;charset=utf-8' }), nomeDoArquivo() + '.md');
    toast('Arquivo Markdown gerado.');
  }

  function baixarDocx() {
    X.baixar(X.gerarDocx(htmlFinal(), formatoParaExportar()), nomeDoArquivo() + '.docx');
    toast('Arquivo Word (.docx) gerado.');
  }

  /* PDF pela impressão do navegador: a folha de visualização é o que se imprime. */
  function exportarPdf() {
    if (editando()) definirModo(STATUS.VISUALIZANDO);
    var titulo = document.title;
    document.title = nomeDoArquivo();
    setTimeout(function () {
      window.print();
      document.title = titulo;
    }, 60);
  }

  function copiarParaSigadoc() {
    var html = htmlFinal();
    CE.sigadoc.copiarParaSigadoc(html, E.paraTexto(html)).then(function (r) {
      if (!r.ok) { toast('Não foi possível copiar. Abra Visualizar e selecione o texto manualmente.', 'erro'); return; }
      toast(r.modo === 'html'
        ? 'Expediente copiado com formatação. Cole no editor de expedientes do SIGADOC.'
        : 'O navegador só permitiu copiar o texto simples, sem formatação.');
    });
  }

  function copiarTextoSimples() {
    CE.sigadoc.copiarTexto(E.paraTexto(htmlFinal())).then(function (ok) {
      toast(ok ? 'Texto do expediente copiado.' : 'Não foi possível copiar automaticamente.', ok ? null : 'erro');
    });
  }

  var SAIDAS = {
    md: baixarMarkdown,
    docx: baixarDocx,
    pdf: exportarPdf,
    'copiar-texto': copiarTextoSimples,
    'copiar-sigadoc': copiarParaSigadoc,
    email: enviarPorEmail,
    sigadoc: enviarParaSigadoc
  };

  function executarSaida(acao) {
    if (!SAIDAS[acao]) return;
    prontoParaSair().then(function (ok) {
      if (!ok) return;
      try { SAIDAS[acao](); }
      catch (e) { toast('Não foi possível concluir a exportação: ' + e.message, 'erro'); }
    });
  }

  function montarAnexos(v) {
    var html = htmlFinal();
    var nome = nomeDoArquivo();
    var anexos = [];
    if (v.anexarDocx) anexos.push({ nome: nome + '.docx', blob: X.gerarDocx(html, formatoParaExportar()) });
    if (!v.anexarPdf) return Promise.resolve(anexos);
    /* sem gerador de PDF configurado, o anexo segue declarado, porém sem conteúdo */
    return X.gerarPdf(html, formatoParaExportar()).then(function (blob) {
      anexos.push({ nome: nome + '.pdf', blob: blob });
      return anexos;
    });
  }

  function enviarPorEmail() {
    var p = estado.protocolo.trim();
    var titulo = modelo().emBranco ? 'Expediente' : modelo().nome;
    dialogo({
      titulo: 'Enviar por e-mail',
      mensagem: CE.email.ativo() ? null
        : 'O serviço de e-mail ainda não está configurado: o envio abaixo será apenas simulado.',
      campos: [
        { id: 'para', label: 'Para', valor: '', placeholder: 'nome@orgao.mt.gov.br', ajuda: 'Separe vários endereços com ponto e vírgula.' },
        { id: 'assunto', label: 'Assunto', valor: titulo + (p ? ' — ' + p : '') },
        { id: 'mensagem', label: 'Mensagem', valor: 'Prezados,\n\nSegue o expediente em anexo.\n\nAtenciosamente,', multilinha: true },
        { id: 'anexarPdf', label: 'Anexar PDF', checkbox: true, valor: true },
        { id: 'anexarDocx', label: 'Anexar DOCX', checkbox: true, valor: false }
      ],
      okLabel: 'Enviar',
      validar: function (v) { return CE.email.validar(v); }
    }).then(function (v) {
      if (!v) return;
      montarAnexos(v)
        .then(function (anexos) {
          return CE.email.sendExpedienteByEmail({ para: v.para, assunto: v.assunto, mensagem: v.mensagem, anexos: anexos });
        })
        .then(function (r) {
          if (!r.ok) { toast(r.mensagem || 'Não foi possível enviar o e-mail.', 'erro'); return; }
          toast(r.simulado ? r.mensagem : 'Expediente enviado por e-mail.');
        })
        .catch(function () { toast('Não foi possível enviar o e-mail.', 'erro'); });
    });
  }

  function enviarParaSigadoc() {
    var html = htmlFinal();
    CE.sigadoc.sendToSigadoc({
      protocolo: estado.protocolo.trim(), titulo: nomeDoArquivo(), html: html, texto: E.paraTexto(html)
    }).then(function (r) {
      if (r.ok) { toast('Expediente enviado ao SIGADOC.'); return; }
      toast(r.mensagem + (r.codigo === 'NAO_CONFIGURADO' ? ' Use "Copiar para SIGADOC" e cole no editor do sistema.' : ''), 'erro');
    });
  }

  /* ==================================================== PONTE COM AS OUTRAS TELAS
     A Biblioteca e a produção em lote conversam com o Criador por aqui.      */
  var edicaoDeModelo = false;

  /* Faixa "Editando o modelo…": lembra que as mudanças só entram no modelo ao
     salvar — até lá, o que se edita é o expediente. */
  function renderFaixaModelo() {
    var m = modelo();
    el.faixaModelo.hidden = !(edicaoDeModelo && M.podeAlterar(m.id));
    if (!el.faixaModelo.hidden) el.faixaModeloNome.textContent = m.nome;
  }

  /* opcoes.editar: abre para manutenção do modelo (não conta como uso). */
  function abrirModelo(id, opcoes) {
    opcoes = opcoes || {};
    if (!M.obter(id)) { toast('Modelo não encontrado.', 'erro'); return Promise.resolve(false); }
    var mesmo = id === estado.modeloId;
    var pode = mesmo ? Promise.resolve(true) : confirmarDescarte(
      'Abrir modelo',
      'O texto atual do Criador foi editado e não está salvo como modelo. Abrir outro modelo descarta essas alterações. Deseja continuar?',
      'Abrir modelo');

    return pode.then(function (ok) {
      if (!ok) return false;
      if (!mesmo) { estado.modeloId = id; aposTrocarModelo(); }
      /* compartilhado sem permissão de alterar: abre só para uso */
      edicaoDeModelo = !!opcoes.editar && M.podeAlterar(id);
      if (!edicaoDeModelo) M.registrarUso(id);
      renderFaixaModelo();
      return true;
    });
  }

  /* O documento aberto, como um modelo avulso: é o que o lote usa quando parte
     do Criador sem passar pela Biblioteca. */
  function retratoAtual() {
    var m = modelo();
    return {
      id: m.salvo ? m.id : null,
      titulo: m.salvo ? m.nome : 'Documento aberto no Criador',
      tipo: m.salvo ? m.tipo : '',
      conteudo: fonteAtual(),
      definicoes: P.copiar(definicoes()),
      selecao: selecionadas().slice(),
      formato: EX.normalizarFormato(estado.formato)
    };
  }

  /* Chamado ANTES de a Biblioteca excluir um modelo: se for o que está aberto,
     o expediente fica com o texto e as propriedades, agora sem modelo. */
  function soltarModelo(id) {
    if (estado.modeloId !== id) { delete estado.selecao[id]; delete estado.definicoes[id]; return; }
    estado.conteudo = fonteAtual() || null;
    estado.definicoes[M.ID_EM_BRANCO] = definicoes();
    estado.selecao[M.ID_EM_BRANCO] = selecionadas();
    delete estado.selecao[id];
    delete estado.definicoes[id];
    estado.modeloId = M.ID_EM_BRANCO;
    edicaoDeModelo = false;
  }

  /* Depois de qualquer mudança na Biblioteca (nomes, tipos, exclusões). */
  function sincronizarComBiblioteca() {
    renderSeletor();
    renderFaixaModelo();
    renderCampos();
    agendarSalvar();
  }

  CE.criador = {
    abrirModelo: abrirModelo,
    retratoAtual: retratoAtual,
    soltarModelo: soltarModelo,
    sincronizar: sincronizarComBiblioteca,
    temConteudo: function () { return !!fonteAtual().trim(); }
  };

  /* ================================================== PAINEL (TELA ESTREITA) */
  function abrirPainel(abrir) {
    el.painelLateral.classList.toggle('aberto', abrir);
    el.btnPainel.setAttribute('aria-expanded', String(abrir));
  }

  /* ================================================================== API
     Contrato mantido da versão autônoma. No SESJUR não há a ponte por
     postMessage: os dados chegam pelo processo vinculado (CE.sesjur).        */
  window.CI_API = {
    modelos: function () {
      return T.ORDEM.map(function (id) {
        return { id: id, nome: T.MODELOS[id].nome, campos: T.MODELOS[id].campos,
                 propriedades: T.propriedadesDoModelo(id) };
      }).concat(T.salvos().map(function (m) {
        return { id: m.id, nome: m.nome, tipo: m.tipo, campos: m.campos, salvo: true,
                 propriedades: T.propriedadesDoModelo(m.id) };
      }));
    },
    getExpediente: function () { return JSON.parse(JSON.stringify(EX.serializar(estado))); },
    getProtocolo: function () { return estado.protocolo.trim(); },
    setProtocolo: function (p) { definirProtocolo(p); return { ok: true }; },
    getDados: function () { return dados(); },
    getSelecao: function () { return selecionadas().slice(); },
    setSelecao: function (tokens) {
      if (!Array.isArray(tokens)) return { ok: false, erro: 'Lista de propriedades inválida.' };
      definirSelecao(tokens);
      aposMudarPropriedades();
      return { ok: true, selecao: selecionadas().slice() };
    },
    propriedades: function () {
      var mapa = props();
      return Object.keys(mapa)
        .filter(function (t) { return mapa[t].token === t; })
        .map(function (t) {
          return { token: t, label: mapa[t].label, valor: mapa[t].valor, origem: mapa[t].origem,
                   obrigatorio: mapa[t].obrigatorio, selecionada: mapa[t].selecionada };
        });
    },
    adicionarPropriedade: function (nome, identificador, valor, obrigatoria, ajuda) {
      return criarPropriedade({
        titulo: nome, identificador: identificador || nome, tipo: 'texto_longo',
        obrigatoria: obrigatoria, descricao: ajuda
      }, valor);
    },
    getTexto: function () { return fonteAtual(); },
    setTexto: function (html) {
      estado.conteudo = E.normalizarTokens(String(html));
      renderEditor();
      aoAlterarDados();
      return { ok: true };
    },
    restaurarTexto: function () {
      estado.conteudo = null;
      renderEditor();
      aoAlterarDados();
      return { ok: true };
    },
    preencher: function (entrada, opcoes) {
      opcoes = opcoes || {};
      if (!entrada || typeof entrada !== 'object') return { ok: false, erro: 'Dados inválidos.' };

      if (opcoes.modelo && M.obter(opcoes.modelo)) {
        estado.modeloId = opcoes.modelo;
        estado.conteudo = null;
        aplicarPadroesDoModelo();
      }

      var aceitos = [];
      Object.keys(T.dadosVazios()).forEach(function (chave) {
        if (typeof entrada[chave] !== 'string') return;
        if (chave === 'processo_administrativo') estado.protocolo = entrada[chave];
        else estado.dados[chave] = entrada[chave];
        aceitos.push(chave);
      });

      (Array.isArray(opcoes.propriedades) ? opcoes.propriedades : []).forEach(function (p) {
        if (!p || !p.identificador) return;
        var id = T.normalizarIdentificador(p.identificador);
        var existente = definicaoPorToken(id);
        if (existente) {
          estado.valores[existente.id] = p.valor || '';
          if (p.nome) existente.titulo = p.nome;
          return;
        }
        var d = { titulo: p.nome || id, identificador: id, tipo: 'texto_longo', obrigatoria: p.obrigatorio, descricao: p.ajuda };
        if (P.validar(d, tokensProprios())) return;
        var def = P.criarDefinicao(d);
        definicoes().push(def);
        estado.valores[def.id] = p.valor || '';
        definirSelecao(selecionadas().concat([id]));
      });

      if (Array.isArray(opcoes.selecao)) definirSelecao(opcoes.selecao);

      /* Acrescenta as propriedades do modelo que vieram preenchidas — exceto no
         Expediente em Branco, que fica só com o que o assessor definiu. */
      if (opcoes.marcarPreenchidas !== false && !emBranco()) {
        var atual = selecionadas().slice();
        T.propriedadesDoModelo(estado.modeloId).forEach(function (p) {
          var def = T.propriedadePorToken(p.token);
          if (!def || !def.chave || !String(dados()[def.chave] || '').trim()) return;
          if (atual.indexOf(p.token) === -1) atual.push(p.token);
        });
        definirSelecao(atual);
      }

      if (typeof opcoes.texto === 'string' && opcoes.texto.trim()) {
        estado.conteudo = E.normalizarTokens(opcoes.texto);
      }

      renderProcesso();
      renderSeletor();
      renderCampos();
      renderEditor();
      aoAlterarDados();
      if (opcoes.gerar) definirModo(STATUS.VISUALIZANDO);
      return { ok: true, aplicados: aceitos };
    },
    gerar: function () { definirModo(STATUS.VISUALIZANDO); return estado.ultimo; },
    renderizar: function (modeloId, d, opcoes) { return E.gerar(modeloId, d, opcoes); }
  };

  /* ================================================================= BOOT */
  var IDS = ['processoBusca', 'buscaProcesso', 'resultadosProcesso', 'processoAtual', 'linkProcesso',
    'processoResumo', 'processoRelacionados', 'selJudicial', 'selExtrajudicial',
    'btnDesvincular', 'btnAtualizarProcesso', 'chipProtocolo',
    'selModelo', 'modeloDesc', 'btnSalvarModelo', 'btnAtualizarModelo', 'btnExcluirModelo',
    'contaSel', 'btnNovaProp', 'btnVazioAdd', 'btnCatalogo', 'menuCatalogo', 'btnSelPadrao',
    'buscaProps', 'campos', 'vazioProps',
    'btnModoEditar', 'btnModoVisualizar', 'btnPendentes', 'menuPendentes', 'contaTokens',
    'btnBaixar', 'menuBaixar', 'btnEnviar', 'menuEnviar',
    'ferramentas', 'selBloco', 'selEntrelinha', 'btnPagina', 'menuPagina', 'selEspaco', 'selMargens',
    'btnInserir', 'menuInserir', 'btnInserirProp', 'menuProps', 'btnRestaurar', 'btnLimpar',
    'faixaPreview', 'avisos', 'btnDestacar', 'painelAud', 'auditoria',
    'mesa', 'editor', 'folha', 'indicadorSalvo', 'seloStatus', 'btnPainel', 'painelLateral',
    'faixaModelo', 'faixaModeloNome', 'btnFaixaSalvar'];

  function preencherSelect(select, itens) {
    itens.forEach(function (i) {
      var o = document.createElement('option');
      o.value = i[0];
      o.textContent = i[1];
      select.appendChild(o);
    });
  }

  function ligarProcesso() {
    el.buscaProcesso.addEventListener('input', buscarProcessos);
    el.processoBusca.addEventListener('keydown', teclaNaBusca);
    el.resultadosProcesso.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-id]');
      if (b) escolherResultado(b);
    });
    document.addEventListener('click', function (ev) {
      if (!el.processoBusca.contains(ev.target)) fecharBusca();
    });

    el.btnDesvincular.addEventListener('click', desvincularProcesso);
    el.btnAtualizarProcesso.addEventListener('click', function () {
      vincularProcesso(estado.processo.id).then(function (ok) {
        if (ok) toast('Dados do processo atualizados com os do SESJUR.');
      });
    });
    el.selJudicial.addEventListener('change', function () {
      trocarRelacionado('judicialId', pacote.judiciais, el.selJudicial);
    });
    el.selExtrajudicial.addEventListener('change', function () {
      trocarRelacionado('extrajudicialId', pacote.extrajudiciais, el.selExtrajudicial);
    });
  }

  function ligarModelo() {

    el.selModelo.addEventListener('change', trocarModelo);
    el.btnSalvarModelo.addEventListener('click', salvarComoModelo);
    el.btnAtualizarModelo.addEventListener('click', atualizarModeloAtual);
    el.btnExcluirModelo.addEventListener('click', excluirModeloAtual);
    el.btnFaixaSalvar.addEventListener('click', atualizarModeloAtual);
  }

  function ligarPropriedades() {
    el.btnNovaProp.addEventListener('click', novaPropriedade);
    el.btnVazioAdd.addEventListener('click', novaPropriedade);
    el.buscaProps.addEventListener('input', filtrarCampos);

    ui.registrarMenu(el.btnCatalogo, el.menuCatalogo, { aoAbrir: montarMenuCatalogo });
    el.menuCatalogo.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-token]');
      if (!b) return;
      ui.fecharMenus();
      acrescentarDoCatalogo(b.dataset.token);
      toast('Propriedade acrescentada ao expediente.');
    });

    el.btnSelPadrao.addEventListener('click', function () {
      var ini = M.definicoesIniciais(estado.modeloId);
      estado.definicoes[estado.modeloId] = ini.definicoes;
      estado.selecao[estado.modeloId] = M.selecaoInicial(estado.modeloId);
      aposMudarPropriedades();
      toast('Propriedades deste modelo restauradas.');
    });
  }

  function ligarFerramentas() {
    Array.prototype.forEach.call(el.ferramentas.querySelectorAll('.btn-fer'), function (b) {
      b.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
      b.addEventListener('click', function () {
        if (b.dataset.cmd) ED.executar(b.dataset.cmd);
        else if (ACOES_DE_FERRAMENTA[b.dataset.acao]) ACOES_DE_FERRAMENTA[b.dataset.acao]();
        atualizarFerramentas();
      });
    });

    el.selBloco.addEventListener('change', function () { ED.definirBloco(el.selBloco.value); });
    el.selEntrelinha.addEventListener('change', function () { mudarFormato('entrelinha', el.selEntrelinha.value); });
    el.selEspaco.addEventListener('change', function () { mudarFormato('espacoParagrafo', el.selEspaco.value); });
    el.selMargens.addEventListener('change', function () { mudarFormato('margens', el.selMargens.value); });

    ui.registrarMenu(el.btnPagina, el.menuPagina);
    ui.registrarMenu(el.btnInserir, el.menuInserir, { manterSelecao: true });
    el.menuInserir.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-acao]');
      if (!b) return;
      ui.fecharMenus();
      ACOES_DE_FERRAMENTA[b.dataset.acao]();
    });

    ui.registrarMenu(el.btnInserirProp, el.menuProps, { manterSelecao: true, aoAbrir: montarMenuProps });
    el.menuProps.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-token]');
      if (!b) return;
      ui.fecharMenus();
      inserirToken(b.dataset.token);
    });

    el.btnRestaurar.addEventListener('click', restaurarTexto);
    el.btnLimpar.addEventListener('click', limparDocumento);
  }

  function ligarAcoes() {
    el.btnModoEditar.addEventListener('click', function () { definirModo(STATUS.EDITANDO); });
    el.btnModoVisualizar.addEventListener('click', function () { definirModo(STATUS.VISUALIZANDO); });

    ui.registrarMenu(el.btnPendentes, el.menuPendentes);
    el.menuPendentes.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-token]');
      if (!b) return;
      ui.fecharMenus();
      focarPropriedade(b.dataset.token);
    });

    [[el.btnBaixar, el.menuBaixar], [el.btnEnviar, el.menuEnviar]].forEach(function (par) {
      ui.registrarMenu(par[0], par[1]);
      par[1].addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-acao]');
        if (!b) return;
        ui.fecharMenus();
        executarSaida(b.dataset.acao);
      });
    });

    el.btnDestacar.addEventListener('click', function () {
      estado.destacar = !estado.destacar;
      el.btnDestacar.classList.toggle('ativo', estado.destacar);
      el.btnDestacar.setAttribute('aria-pressed', String(estado.destacar));
      el.folha.classList.toggle('destacar', estado.destacar);
    });

    el.btnPainel.addEventListener('click', function () {
      abrirPainel(!el.painelLateral.classList.contains('aberto'));
    });
  }

  function ligarAtalhos() {
    document.addEventListener('keydown', function (ev) {
      if (!(ev.ctrlKey || ev.metaKey) || ev.altKey) return;
      if (document.body.dataset.visao !== 'criador' || document.querySelector('dialog[open]')) return;
      var tecla = ev.key.toLowerCase();
      if (tecla === 'enter') {
        ev.preventDefault();
        definirModo(editando() ? STATUS.VISUALIZANDO : STATUS.EDITANDO);
      } else if (tecla === 's') {
        ev.preventDefault();
        salvarAgora();
        toast('Rascunho salvo.');
      } else if (tecla === 'p') {
        ev.preventDefault();
        executarSaida('pdf');
      }
    });
    window.addEventListener('beforeunload', function () { if (timerSalvar) salvarAgora(); });
  }

  function iniciar() {
    IDS.forEach(function (id) { el[id] = $(id); });
    el.estiloPagina = document.createElement('style');
    document.head.appendChild(el.estiloPagina);

    preencherSelect(el.selEntrelinha, EX.ENTRELINHAS.map(function (v) { return [v, v.replace('.', ',') + (v.length === 1 ? ',0' : '')]; }));
    preencherSelect(el.selEspaco, EX.ESPACOS_PARAGRAFO.map(function (v) { return [v, v + ' pt']; }));
    preencherSelect(el.selMargens, Object.keys(EX.MARGENS).map(function (k) { return [k, EX.MARGENS[k].rotulo]; }));

    var rascunho = ARM.ler();
    estado = EX.restaurar(rascunho);

    ED = CE.editor.criar(el.editor, { aoMudar: aoEditarTexto, aoSelecionar: atualizarFerramentas, rotuloDe: rotuloDoToken });

    ligarProcesso();
    ligarModelo();
    ligarPropriedades();
    ligarFerramentas();
    ligarAcoes();
    ligarAtalhos();

    el.btnDestacar.classList.toggle('ativo', estado.destacar);

    aplicarFormato();
    renderProcesso();
    renderSeletor();
    renderCampos();
    renderEditor();
    if (editando()) renderPreview(); else avaliar();
    renderModo();
    reatarProcesso();
    indicar(rascunho ? 'Última alteração salva às ' + hora(estado.updatedAt) : 'Rascunho salvo automaticamente neste navegador');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
