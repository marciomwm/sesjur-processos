/* =============================================================================
   planilha.js — Entrada e saída de dados tabulares do lote
   -----------------------------------------------------------------------------
   lerTabela(texto, sep)      texto delimitado → matriz (aspas e quebras de linha
                              dentro da célula respeitadas); serve ao CSV e ao
                              que vem colado do Excel / Calc / Google Sheets
   lerArquivo(file)           .csv ou .xlsx → { cabecalhos, linhas }
   gerarXLSX(cabecalhos, ..)  planilha para preenchimento externo
   sugerirMapeamento(..)      coluna da planilha → propriedade do modelo

   O .xlsx é lido sem bibliotecas: é um ZIP de XML, descompactado com a
   DecompressionStream do navegador.
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};
  var MIME_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

  /* ======================================================= TEXTO DELIMITADO */
  function lerTabela(texto, sep) {
    var linhas = [], linha = [], celula = '', aspas = false;
    var s = String(texto).replace(/\r\n?/g, '\n');

    for (var i = 0; i < s.length; i++) {
      var c = s[i];
      if (aspas) {
        if (c === '"' && s[i + 1] === '"') { celula += '"'; i++; }
        else if (c === '"') aspas = false;
        else celula += c;
      } else if (c === '"' && celula === '') {
        aspas = true;
      } else if (c === sep) {
        linha.push(celula); celula = '';
      } else if (c === '\n') {
        linha.push(celula); linhas.push(linha);
        linha = []; celula = '';
      } else {
        celula += c;
      }
    }
    if (celula !== '' || linha.length) { linha.push(celula); linhas.push(linha); }
    return semLinhasVaziasNoFim(linhas);
  }

  function semLinhasVaziasNoFim(linhas) {
    while (linhas.length && linhas[linhas.length - 1].every(function (c) { return !String(c).trim(); })) linhas.pop();
    return linhas;
  }

  /* O Excel em português grava CSV com ";" — descobre pelo cabeçalho. */
  function separadorDoCSV(texto) {
    var primeira = String(texto).split(/\r?\n/)[0] || '';
    var melhor = ',', maior = 0;
    [';', ',', '\t'].forEach(function (sep) {
      var n = primeira.split(sep).length - 1;
      if (n > maior) { maior = n; melhor = sep; }
    });
    return melhor;
  }

  /* CSV do Excel costuma vir em Windows-1252; os demais, em UTF-8. */
  function decodificar(buffer) {
    try { return new TextDecoder('utf-8', { fatal: true }).decode(buffer).replace(/^﻿/, ''); }
    catch (e) { return new TextDecoder('windows-1252').decode(buffer); }
  }

  /* ================================================================== XLSX */
  function entradasDoZip(buffer) {
    var v = new DataView(buffer), bytes = new Uint8Array(buffer);
    var fim = -1;
    for (var i = bytes.length - 22; i >= 0 && i >= bytes.length - 65557; i--) {
      if (v.getUint32(i, true) === 0x06054b50) { fim = i; break; }
    }
    if (fim < 0) throw new Error('O arquivo não é uma planilha .xlsx válida.');

    var total = v.getUint16(fim + 10, true);
    var pos = v.getUint32(fim + 16, true);
    var decod = new TextDecoder();
    var entradas = {};

    for (var n = 0; n < total; n++) {
      if (v.getUint32(pos, true) !== 0x02014b50) break;
      var metodo = v.getUint16(pos + 10, true);
      var tamanho = v.getUint32(pos + 20, true);
      var tamNome = v.getUint16(pos + 28, true);
      var tamExtra = v.getUint16(pos + 30, true);
      var tamComentario = v.getUint16(pos + 32, true);
      var local = v.getUint32(pos + 42, true);
      var nome = decod.decode(bytes.subarray(pos + 46, pos + 46 + tamNome));

      var inicio = local + 30 + v.getUint16(local + 26, true) + v.getUint16(local + 28, true);
      entradas[nome] = { metodo: metodo, bytes: bytes.subarray(inicio, inicio + tamanho) };
      pos += 46 + tamNome + tamExtra + tamComentario;
    }
    return entradas;
  }

  function textoDaEntrada(entrada) {
    if (!entrada) return Promise.resolve('');
    if (entrada.metodo === 0) return Promise.resolve(new TextDecoder().decode(entrada.bytes));
    if (typeof DecompressionStream === 'undefined') {
      return Promise.reject(new Error('Este navegador não lê .xlsx. Salve a planilha como CSV e importe novamente.'));
    }
    var fluxo = new Blob([entrada.bytes]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
    return new Response(fluxo).text();
  }

  function xml(texto) { return new DOMParser().parseFromString(texto, 'application/xml'); }
  function filhos(no, nome) { return Array.prototype.slice.call(no.getElementsByTagName(nome)); }

  /* Formatos numéricos que representam data (embutidos e personalizados). */
  function estilosDeData(doc) {
    var personalizados = {};
    filhos(doc, 'numFmt').forEach(function (f) {
      var codigo = (f.getAttribute('formatCode') || '').replace(/\[[^\]]*\]|"[^"]*"/g, '');
      personalizados[f.getAttribute('numFmtId')] = /[dmy]/i.test(codigo);
    });
    var xfs = doc.getElementsByTagName('cellXfs')[0];
    return (xfs ? filhos(xfs, 'xf') : []).map(function (xf) {
      var id = +xf.getAttribute('numFmtId');
      return (id >= 14 && id <= 22) || (id >= 45 && id <= 47) || !!personalizados[id];
    });
  }

  function serialParaData(serial) {
    var d = new Date(Date.UTC(1899, 11, 30) + Math.round(serial) * 86400000);
    return d.toISOString().slice(0, 10);
  }

  function indiceDaColuna(ref) {
    var letras = /^[A-Z]+/.exec(ref || '');
    if (!letras) return -1;
    return letras[0].split('').reduce(function (n, l) { return n * 26 + l.charCodeAt(0) - 64; }, 0) - 1;
  }

  function lerXLSX(buffer) {
    var zip = entradasDoZip(buffer);
    var folha = Object.keys(zip).filter(function (n) { return /^xl\/worksheets\/sheet\d+\.xml$/.test(n); }).sort()[0];
    if (!folha) return Promise.reject(new Error('Nenhuma planilha encontrada no arquivo.'));

    return Promise.all([
      textoDaEntrada(zip['xl/sharedStrings.xml']),
      textoDaEntrada(zip['xl/styles.xml']),
      textoDaEntrada(zip[folha])
    ]).then(function (r) {
      var textos = r[0] ? filhos(xml(r[0]), 'si').map(function (si) {
        return filhos(si, 't').map(function (t) { return t.textContent; }).join('');
      }) : [];
      var ehData = r[1] ? estilosDeData(xml(r[1])) : [];

      var linhas = [];
      filhos(xml(r[2]), 'row').forEach(function (row) {
        var linha = [];
        filhos(row, 'c').forEach(function (c, ordem) {
          var col = indiceDaColuna(c.getAttribute('r'));
          if (col < 0) col = ordem;
          var tipo = c.getAttribute('t');
          var v = c.getElementsByTagName('v')[0];
          var bruto = v ? v.textContent : '';
          var valor;

          if (tipo === 's') valor = textos[+bruto] || '';
          else if (tipo === 'inlineStr') valor = filhos(c, 't').map(function (t) { return t.textContent; }).join('');
          else if (tipo === 'b') valor = bruto === '1' ? 'Sim' : 'Não';
          else if (tipo === 'str' || tipo === 'e') valor = bruto;
          else if (bruto !== '' && ehData[+c.getAttribute('s')]) valor = serialParaData(+bruto);
          else valor = bruto === '' ? '' : String(Number(bruto));

          while (linha.length < col) linha.push('');
          linha[col] = valor;
        });
        linhas.push(linha);
      });
      return semLinhasVaziasNoFim(linhas);
    });
  }

  /* → Promise<{ cabecalhos: [], linhas: [[]] }> */
  function lerArquivo(arquivo) {
    return arquivo.arrayBuffer().then(function (buffer) {
      if (/\.xlsx$/i.test(arquivo.name)) return lerXLSX(buffer);
      if (/\.xls$/i.test(arquivo.name)) {
        throw new Error('O formato .xls antigo não é suportado. Salve como .xlsx ou .csv.');
      }
      var texto = decodificar(buffer);
      return lerTabela(texto, separadorDoCSV(texto));
    }).then(function (matriz) {
      if (!matriz.length) throw new Error('A planilha está vazia.');
      var cabecalhos = matriz[0].map(function (c) { return String(c).trim(); });
      var linhas = matriz.slice(1).filter(function (l) { return l.some(function (c) { return String(c).trim(); }); });
      return { cabecalhos: cabecalhos, linhas: linhas };
    });
  }

  function escXml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function letraDaColuna(i) {
    var s = '';
    for (i += 1; i > 0; i = Math.floor((i - 1) / 26)) s = String.fromCharCode(65 + (i - 1) % 26) + s;
    return s;
  }

  /* Planilha de uma aba, só com texto: cabeçalhos e, se houver, linhas. */
  function gerarXLSX(cabecalhos, linhas) {
    var XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n';
    var PACOTE = 'http://schemas.openxmlformats.org/package/2006/';
    var DOC = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    var PLAN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    var dados = [cabecalhos].concat(linhas || []).map(function (linha, l) {
      return '<row r="' + (l + 1) + '">' + linha.map(function (valor, c) {
        return '<c r="' + letraDaColuna(c) + (l + 1) + '" t="inlineStr"><is><t xml:space="preserve">' +
               escXml(valor == null ? '' : valor) + '</t></is></c>';
      }).join('') + '</row>';
    }).join('');

    var larguras = '<cols>' + cabecalhos.map(function (c, i) {
      return '<col min="' + (i + 1) + '" max="' + (i + 1) + '" width="' + Math.max(14, String(c).length + 4) + '" customWidth="1"/>';
    }).join('') + '</cols>';

    return CE.exportar.zip([
      { nome: '[Content_Types].xml', conteudo: XML + '<Types xmlns="' + PACOTE + 'content-types">' +
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
        '<Default Extension="xml" ContentType="application/xml"/>' +
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' +
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' +
        '</Types>' },
      { nome: '_rels/.rels', conteudo: XML + '<Relationships xmlns="' + PACOTE + 'relationships">' +
        '<Relationship Id="rId1" Type="' + DOC + '/officeDocument" Target="xl/workbook.xml"/></Relationships>' },
      { nome: 'xl/workbook.xml', conteudo: XML + '<workbook xmlns="' + PLAN + '" xmlns:r="' + DOC + '">' +
        '<sheets><sheet name="Dados" sheetId="1" r:id="rId1"/></sheets></workbook>' },
      { nome: 'xl/_rels/workbook.xml.rels', conteudo: XML + '<Relationships xmlns="' + PACOTE + 'relationships">' +
        '<Relationship Id="rId1" Type="' + DOC + '/worksheet" Target="worksheets/sheet1.xml"/></Relationships>' },
      { nome: 'xl/worksheets/sheet1.xml', conteudo: XML + '<worksheet xmlns="' + PLAN + '">' + larguras +
        '<sheetData>' + dados + '</sheetData></worksheet>' }
    ], MIME_XLSX);
  }

  /* ========================================================== MAPEAMENTO */
  function normalizar(s) {
    return CE.ui.semAcento(s).replace(/[^a-z0-9]+/g, ' ').trim();
  }

  /* Coeficiente de Dice sobre pares de letras: 0 (nada a ver) a 1 (igual). */
  function semelhanca(a, b) {
    if (!a || !b) return 0;
    if (a === b) return 1;
    function pares(s) {
      var p = [];
      for (var i = 0; i < s.length - 1; i++) p.push(s.slice(i, i + 2));
      return p;
    }
    var pa = pares(a), pb = pares(b), comuns = 0;
    pa.forEach(function (par) {
      var i = pb.indexOf(par);
      if (i !== -1) { comuns++; pb.splice(i, 1); }
    });
    var total = pa.length + pares(b).length;
    return total ? 2 * comuns / total : 0;
  }

  /* 3 = idêntico · 2 = igual sem acentos/pontuação · 0..1 = semelhança */
  function pontuar(cabecalho, coluna) {
    var c = cabecalho.trim();
    if (c === coluna.token || c === coluna.titulo) return 3;
    var n = normalizar(c), titulo = normalizar(coluna.titulo), token = normalizar(coluna.token);
    if (n && (n === titulo || n === token)) return 2;
    return Math.max(semelhanca(n, titulo), semelhanca(n, token));
  }

  var LIMIAR_SEMELHANCA = 0.6;
  var FOLGA_PARA_DECIDIR = 0.15;

  /* → [{ cabecalho, token, confianca: 'exata' | 'sugerida' | 'duvida' }]
     Empate, semelhança fraca ou duas colunas disputando a mesma propriedade
     ficam como 'duvida' (token nulo): quem decide é o assessor. */
  function sugerirMapeamento(cabecalhos, colunas) {
    var sugestoes = cabecalhos.map(function (cab) {
      var notas = colunas.map(function (col) { return { token: col.token, nota: pontuar(cab, col) }; })
        .sort(function (a, b) { return b.nota - a.nota; });
      var melhor = notas[0], segundo = notas[1];
      var s = { cabecalho: cab, token: null, confianca: 'duvida', nota: 0 };
      if (!melhor || !cab.trim()) return s;

      var empate = segundo && segundo.nota === melhor.nota;
      if (melhor.nota >= 2 && !empate) {
        s.token = melhor.token; s.confianca = 'exata'; s.nota = melhor.nota;
      } else if (melhor.nota >= LIMIAR_SEMELHANCA && melhor.nota < 2 &&
                 (!segundo || melhor.nota - segundo.nota >= FOLGA_PARA_DECIDIR)) {
        s.token = melhor.token; s.confianca = 'sugerida'; s.nota = melhor.nota;
      }
      return s;
    });

    /* duas colunas da planilha para a mesma propriedade: fica a de maior nota */
    sugestoes.forEach(function (s) {
      if (!s.token) return;
      var rival = sugestoes.filter(function (o) { return o !== s && o.token === s.token; })[0];
      if (!rival) return;
      if (rival.nota === s.nota) { rival.token = null; rival.confianca = 'duvida'; s.token = null; s.confianca = 'duvida'; }
      else if (rival.nota > s.nota) { s.token = null; s.confianca = 'duvida'; }
    });
    return sugestoes;
  }

  CE.planilha = {
    lerTabela: lerTabela,
    lerArquivo: lerArquivo,
    lerXLSX: lerXLSX,
    gerarXLSX: gerarXLSX,
    sugerirMapeamento: sugerirMapeamento
  };
})(window);
