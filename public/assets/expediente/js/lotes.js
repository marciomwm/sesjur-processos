/* =============================================================================
   lotes.js — Produção em lote (mala direta): entidade, validação e nomes
   -----------------------------------------------------------------------------
   UMA LINHA = UM DOCUMENTO · UMA COLUNA = UMA PROPRIEDADE DO MODELO

   Batch     { id, titulo, modelo (retrato), colunas, items, padraoNome,
               status, createdAt, updatedAt }
   BatchItem { id, values {TOKEN: valor}, contentOverride, generatedAt }

   O lote guarda um RETRATO do modelo (texto, propriedades, formatação): excluir
   ou alterar o modelo na Biblioteca não quebra os lotes já criados.

   Só lógica — nenhuma manipulação de tela. A renderização de cada documento
   passa por CE.documento, o mesmo caminho do documento individual.
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};
  var T = global.CI_TEMPLATES;
  var E = global.CI_ENGINE;
  var P = CE.propriedades;

  var CHAVE = 'unijur_lotes_v1';
  var PADRAO_NOME = 'expediente_{{N}}';
  var STATUS = { INCOMPLETO: 'incompleto', PRONTO: 'pronto', ERRO: 'erro', GERADO: 'gerado' };
  var ROTULOS = { incompleto: 'Incompleto', pronto: 'Pronto', erro: 'Com erro', gerado: 'Gerado' };

  function novoId(prefixo) {
    return prefixo + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
  }

  /* ========================================================= PERSISTÊNCIA */
  function lerTodos() {
    try {
      var lista = JSON.parse(localStorage.getItem(CHAVE) || '[]');
      return Array.isArray(lista) ? lista : [];
    } catch (e) { return []; }
  }

  function gravarTodos(lista) {
    try { localStorage.setItem(CHAVE, JSON.stringify(lista)); return true; }
    catch (e) { return false; }
  }

  /* Mais recentes primeiro. */
  function listar() {
    return lerTodos().sort(function (a, b) { return String(b.updatedAt).localeCompare(String(a.updatedAt)); });
  }

  function obter(id) {
    return lerTodos().filter(function (l) { return l.id === id; })[0] || null;
  }

  function salvar(lote) {
    lote.updatedAt = new Date().toISOString();
    lote.status = lote.items.length && lote.items.every(function (i) { return !!i.generatedAt; })
      ? 'gerado' : 'em-andamento';
    var lista = lerTodos().filter(function (l) { return l.id !== lote.id; });
    lista.push(lote);
    return gravarTodos(lista);
  }

  function excluir(id) {
    return gravarTodos(lerTodos().filter(function (l) { return l.id !== id; }));
  }

  /* ============================================================== COLUNAS
     Derivadas do modelo: primeiro as propriedades do painel, na ordem em que
     estão; depois as que só aparecem no texto. As automáticas (data de hoje,
     ano) não viram coluna.                                                    */
  function colunasDe(retrato) {
    var proprias = {};
    (retrato.definicoes || []).forEach(function (d) { proprias[d.identificador] = d; });

    var tokens = (retrato.selecao || [])
      .concat((retrato.definicoes || []).map(function (d) { return d.identificador; }))
      .concat(E.tokensDe(retrato.conteudo || ''));

    var vistos = {}, colunas = [];
    tokens.forEach(function (token) {
      var d = proprias[token];
      var def = d ? null : T.propriedadePorToken(token);
      var canonico = def ? def.token : token;
      if (vistos[canonico] || (def && def.auto)) return;
      vistos[canonico] = true;

      if (d) {
        colunas.push({ token: token, titulo: d.titulo, tipo: d.tipo, opcoes: d.opcoes || [],
                       obrigatoria: !!d.obrigatoria, descricao: d.descricao || '' });
      } else if (def) {
        colunas.push({
          token: def.token,
          titulo: def.token === 'PROCESSO_SIGADOC' ? 'Protocolo' : def.label,
          tipo: def.tipo === 'data' ? 'data' : def.tipo === 'textarea' ? 'texto_longo' : 'texto',
          opcoes: [], obrigatoria: false, descricao: def.ajuda || '', catalogo: true
        });
      } else {
        /* está no texto, mas não foi cadastrada como propriedade */
        colunas.push({ token: token, titulo: token, tipo: 'texto', opcoes: [], obrigatoria: false,
                       descricao: 'Propriedade usada no texto e não cadastrada no modelo.', ausente: true });
      }
    });
    return colunas;
  }

  /* ================================================================ LOTE */
  function novoItem(values) {
    return { id: novoId('item_'), values: values || {}, contentOverride: null, generatedAt: null };
  }

  function tituloAutomatico(retrato) {
    return (retrato.titulo || 'Expedientes') + ' — lote de ' + new Date().toLocaleDateString('pt-BR');
  }

  function criar(dados) {
    var agora = new Date().toISOString();
    return {
      id: novoId('lote_'),
      titulo: String(dados.titulo || '').trim() || tituloAutomatico(dados.modelo),
      modeloId: dados.modelo.id || null,
      modelo: dados.modelo,
      colunas: colunasDe(dados.modelo),
      items: [novoItem()],
      padraoNome: PADRAO_NOME,
      status: 'em-andamento',
      createdAt: agora,
      updatedAt: agora
    };
  }

  /* Troca o modelo do lote. Os valores das propriedades que continuam
     existindo são mantidos; tudo volta a "não gerado". */
  function trocarModelo(lote, retrato) {
    lote.modeloId = retrato.id || null;
    lote.modelo = retrato;
    lote.colunas = colunasDe(retrato);
    var validos = {};
    lote.colunas.forEach(function (c) { validos[c.token] = true; });
    lote.items.forEach(function (item) {
      Object.keys(item.values).forEach(function (t) { if (!validos[t]) delete item.values[t]; });
      item.generatedAt = null;
    });
  }

  /* ============================================================== VALORES */
  function dataValida(ano, mes, dia) {
    var d = new Date(ano, mes - 1, dia);
    return d.getFullYear() === ano && d.getMonth() === mes - 1 && d.getDate() === dia;
  }

  function iso(ano, mes, dia) {
    return ano + '-' + String(mes).padStart(2, '0') + '-' + String(dia).padStart(2, '0');
  }

  /* Valor vindo de fora (importação, colagem) → formato interno da célula.
     Datas ficam em AAAA-MM-DD; o que não for reconhecido fica como veio, para
     a validação acusar em vez de o dado sumir. */
  function coagir(coluna, bruto) {
    var v = String(bruto == null ? '' : bruto).replace(/\r/g, '').trim();
    if (coluna.tipo !== 'data' || !v) return v;

    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(v);
    if (m) return m[1] + '-' + m[2] + '-' + m[3];

    m = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/.exec(v);
    if (m) {
      var ano = +m[3] < 100 ? 2000 + +m[3] : +m[3];
      if (dataValida(ano, +m[2], +m[1])) return iso(ano, +m[2], +m[1]);
    }
    return v;
  }

  function numeroValido(v) {
    return /^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(v) || /^-?\d+([.,]\d+)?$/.test(v);
  }

  /* null = sem problema; senão, a mensagem do erro. */
  function erroDoValor(coluna, valor) {
    if (coluna.tipo === 'numero' && !numeroValido(valor)) return 'Número inválido.';
    if (coluna.tipo === 'data') {
      var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(valor);
      if (!m || !dataValida(+m[1], +m[2], +m[3])) return 'Data inválida. Use DD/MM/AAAA.';
    }
    if (coluna.tipo === 'lista' && coluna.opcoes.indexOf(valor) === -1) return 'Opção inexistente na lista.';
    if (coluna.catalogo) {
      var def = T.propriedadePorToken(coluna.token);
      if (def && def.validar) return def.validar(P.formatarValor(coluna, valor));
    }
    return null;
  }

  /* ============================================================ VALIDAÇÃO */
  function valorDe(item, coluna) { return String(item.values[coluna.token] || '').trim(); }

  /* → { status, vazio, celulas: { TOKEN: { tipo: 'erro' | 'falta', msg } } } */
  function validarItem(lote, item) {
    var celulas = {}, erros = 0, faltas = 0, preenchidas = 0;

    lote.colunas.forEach(function (c) {
      var v = valorDe(item, c);
      if (!v) {
        if (c.obrigatoria) { celulas[c.token] = { tipo: 'falta', msg: 'Propriedade obrigatória.' }; faltas++; }
        return;
      }
      preenchidas++;
      var msg = erroDoValor(c, v);
      if (msg) { celulas[c.token] = { tipo: 'erro', msg: msg }; erros++; }
    });

    var vazio = preenchidas === 0;
    var status = erros ? STATUS.ERRO
      : (vazio || faltas) ? STATUS.INCOMPLETO
      : item.generatedAt ? STATUS.GERADO : STATUS.PRONTO;
    return { status: status, vazio: vazio, celulas: celulas };
  }

  function assinatura(lote, item) {
    return lote.colunas.map(function (c) { return valorDe(item, c); }).join('\u0001');
  }

  /* → { porItem: {id: resultado}, problemas: [{itemId, token}], resumo } */
  function validar(lote) {
    var porItem = {}, problemas = [], vistas = {};
    var resumo = { total: lote.items.length, prontos: 0, incompletos: 0, erros: 0, gerados: 0, duplicados: 0 };

    lote.items.forEach(function (item) {
      var r = validarItem(lote, item);
      porItem[item.id] = r;

      if (r.status === STATUS.ERRO) resumo.erros++;
      else if (r.status === STATUS.INCOMPLETO) resumo.incompletos++;
      else { resumo.prontos++; if (r.status === STATUS.GERADO) resumo.gerados++; }

      var tokens = Object.keys(r.celulas);
      tokens.forEach(function (t) { problemas.push({ itemId: item.id, token: t }); });
      if (r.vazio && !tokens.length && lote.colunas.length) {
        problemas.push({ itemId: item.id, token: lote.colunas[0].token });
      }

      if (!r.vazio) {
        var a = assinatura(lote, item);
        if (vistas[a]) { r.duplicado = true; resumo.duplicados++; }
        vistas[a] = true;
      }
    });
    return { porItem: porItem, problemas: problemas, resumo: resumo };
  }

  /* Pode virar documento: sem erro e sem obrigatória em aberto. */
  function valido(lote, item) {
    var s = validarItem(lote, item).status;
    return s === STATUS.PRONTO || s === STATUS.GERADO;
  }

  /* ========================================================= RENDERIZAÇÃO */
  function fonteDoItem(lote, item) {
    return item.contentOverride != null ? item.contentOverride : (lote.modelo.conteudo || '');
  }

  /* Dados do item no formato que CE.documento entende. */
  function documentoDoItem(lote, item) {
    var dados = T.dadosVazios(), proprias = [];
    lote.colunas.forEach(function (c) {
      var valor = P.formatarValor(c, valorDe(item, c));
      if (c.catalogo) dados[T.propriedadePorToken(c.token).chave] = valor;
      else proprias.push({ token: c.token, label: c.titulo, valor: valor, obrigatorio: c.obrigatoria });
    });
    return {
      modeloId: CE.modelos.ID_EM_BRANCO,
      fonte: fonteDoItem(lote, item),
      dados: dados,
      proprias: proprias,
      selecao: lote.colunas.map(function (c) { return c.token; })
    };
  }

  function htmlFinal(lote, item) { return CE.documento.htmlFinal(documentoDoItem(lote, item)); }

  /* Pré-visualização na tela, com os valores realçados e as lacunas marcadas. */
  function htmlPrevia(lote, item) {
    return CE.documento.resolver(documentoDoItem(lote, item),
      { marcarVariaveis: true, marcarPendentes: true }).html;
  }

  /* ======================================================= NOME DE ARQUIVO */
  function sanitizarNome(nome) {
    return String(nome).normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^A-Za-z0-9_.\-]+/g, '-').replace(/-{2,}/g, '-').replace(/^[-.]+|[-.]+$/g, '');
  }

  /* Padrão com as propriedades do modelo e três extras: {{N}} (sequência),
     {{TIPO}} e {{MODELO}}. */
  function nomeDoArquivo(lote, item, posicao) {
    var sequencia = String(posicao + 1).padStart(3, '0');
    var valores = { N: sequencia, TIPO: lote.modelo.tipo || '', MODELO: lote.modelo.titulo || '' };
    lote.colunas.forEach(function (c) { valores[c.token] = P.formatarValor(c, valorDe(item, c)); });

    var nome = E.renderTemplate(lote.padraoNome || PADRAO_NOME, valores, { texto: true })
      .replace(/\{\{[^}]*\}\}/g, '');
    return sanitizarNome(nome) || 'expediente_' + sequencia;
  }

  /* Nomes de todos os itens, sem repetição (o segundo igual ganha _2). */
  function nomesDosArquivos(lote, itens) {
    var usados = {};
    return itens.map(function (item) {
      var base = nomeDoArquivo(lote, item, lote.items.indexOf(item));
      var nome = base, n = 2;
      while (usados[nome.toLowerCase()]) nome = base + '_' + (n++);
      usados[nome.toLowerCase()] = true;
      return nome;
    });
  }

  CE.lotes = {
    STATUS: STATUS,
    ROTULOS: ROTULOS,
    PADRAO_NOME: PADRAO_NOME,
    listar: listar,
    obter: obter,
    salvar: salvar,
    excluir: excluir,
    criar: criar,
    trocarModelo: trocarModelo,
    colunasDe: colunasDe,
    novoItem: novoItem,
    coagir: coagir,
    validarItem: validarItem,
    validar: validar,
    valido: valido,
    documentoDoItem: documentoDoItem,
    htmlFinal: htmlFinal,
    htmlPrevia: htmlPrevia,
    sanitizarNome: sanitizarNome,
    nomeDoArquivo: nomeDoArquivo,
    nomesDosArquivos: nomesDosArquivos
  };
})(window);
