/* =============================================================================
   export.js — Saídas do expediente
   -----------------------------------------------------------------------------
   sanitizeForSigadoc()  HTML interno → HTML simplificado (p, strong, em, u,
                         listas, tabelas), sem classes nem spans de apoio
   paraMarkdown()        HTML simplificado → Markdown
   gerarDocx()           HTML simplificado → .docx real (OOXML + ZIP), sem
                         bibliotecas externas
   baixar()              entrega um Blob como download

   Todas partem do HTML já sanitizado e com as propriedades RESOLVIDAS.
   O PDF é produzido pela impressão do navegador (A4, ver styles.css);
   `configurarPdf()` permite plugar uma biblioteca que devolva um Blob.
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};

  /* ====================================================== SANITIZAÇÃO */
  var PERMITIDAS = {
    P: 1, STRONG: 1, EM: 1, U: 1, S: 1, BR: 1, UL: 1, OL: 1, LI: 1,
    TABLE: 1, THEAD: 1, TBODY: 1, TR: 1, TD: 1, TH: 1,
    H1: 1, H2: 1, BLOCKQUOTE: 1, A: 1, HR: 1
  };
  var TROCAS = { B: 'STRONG', I: 'EM', STRIKE: 'S', DEL: 'S', H3: 'H2', H4: 'H2', H5: 'H2', H6: 'H2' };
  var DESCARTADAS = { SCRIPT: 1, STYLE: 1, META: 1, LINK: 1, TITLE: 1, IFRAME: 1, OBJECT: 1, IMG: 1 };
  var BLOCOS = { P: 1, H1: 1, H2: 1, LI: 1, TD: 1, TH: 1, BLOCKQUOTE: 1 };
  var TAGS_DE_BLOCO = /^(P|DIV|H[1-6]|UL|OL|LI|TABLE|BLOCKQUOTE|HR)$/;

  /* Classes de apoio do editor → estilo embutido, que sobrevive à colagem. */
  var ALINHAMENTO_DA_CLASSE = { 'ci-just': 'justify', 'ci-hdr': 'center', 'ci-dir': 'right' };

  function temFilhoDeBloco(no) {
    return Array.prototype.some.call(no.children, function (f) { return TAGS_DE_BLOCO.test(f.tagName); });
  }

  function estiloDoBloco(no) {
    var partes = [];
    var alinhamento = no.style.textAlign || no.getAttribute('align') || '';
    var recuo = no.style.textIndent;

    Object.keys(ALINHAMENTO_DA_CLASSE).forEach(function (classe) {
      if (!no.classList.contains(classe)) return;
      if (!alinhamento) alinhamento = ALINHAMENTO_DA_CLASSE[classe];
      if (!recuo && classe === 'ci-just') recuo = '1.9em';
    });

    if (/^(center|right|justify)$/.test(alinhamento)) partes.push('text-align:' + alinhamento);
    if (parseFloat(recuo) > 0) partes.push('text-indent:' + recuo);
    if (parseFloat(no.style.marginLeft) > 0) partes.push('margin-left:' + no.style.marginLeft);
    return partes.join(';');
  }

  /* <span>/<font> vindos de colagem: preserva só negrito, itálico e sublinhado. */
  function envolverPorEstilo(no, conteudo) {
    var st = no.style;
    var peso = st.fontWeight;
    var marcas = [];
    if (peso === 'bold' || parseInt(peso, 10) >= 600) marcas.push('strong');
    if (st.fontStyle === 'italic') marcas.push('em');
    if (/underline/.test(st.textDecoration || st.textDecorationLine || '')) marcas.push('u');

    return marcas.reduce(function (interno, tag) {
      var e = document.createElement(tag);
      e.appendChild(interno);
      return e;
    }, conteudo);
  }

  function copiarFilhos(origem, destino) {
    Array.prototype.forEach.call(origem.childNodes, function (no) {
      var r = converter(no);
      if (r) destino.appendChild(r);
    });
  }

  function converter(no) {
    if (no.nodeType === 3) return document.createTextNode(no.nodeValue.replace(/​/g, ''));
    if (no.nodeType !== 1) return null;

    var tag = no.tagName;
    if (DESCARTADAS[tag]) return null;

    if (tag === 'HR' && no.classList.contains('quebra-pagina')) {
      var quebra = document.createElement('p');
      quebra.setAttribute('style', 'page-break-before:always');
      quebra.appendChild(document.createElement('br'));
      return quebra;
    }

    tag = TROCAS[tag] || tag;
    if (tag === 'DIV') tag = temFilhoDeBloco(no) ? null : 'P';

    if (!tag || !PERMITIDAS[tag]) {
      var frag = document.createDocumentFragment();
      copiarFilhos(no, frag);
      return (no.tagName === 'SPAN' || no.tagName === 'FONT') ? envolverPorEstilo(no, frag) : frag;
    }

    var novo = document.createElement(tag.toLowerCase());

    if (BLOCOS[tag]) {
      var estilo = estiloDoBloco(no);
      if (estilo) novo.setAttribute('style', estilo);
    }
    if (tag === 'A') {
      var href = no.getAttribute('href') || '';
      if (/^(https?:|mailto:)/i.test(href)) novo.setAttribute('href', href);
    }
    if (tag === 'TD' || tag === 'TH') {
      ['colspan', 'rowspan'].forEach(function (a) {
        if (no.getAttribute(a)) novo.setAttribute(a, no.getAttribute(a));
      });
    }
    if (tag === 'TABLE') {
      novo.setAttribute('border', '1');
      novo.setAttribute('cellspacing', '0');
      novo.setAttribute('cellpadding', '4');
      novo.setAttribute('style', 'border-collapse:collapse;width:100%');
    }

    copiarFilhos(no, novo);

    /* parágrafo vazio = linha em branco: precisa de <br> para não colapsar */
    if (tag === 'P' && !novo.childNodes.length) novo.appendChild(document.createElement('br'));
    return novo;
  }

  function sanitizeForSigadoc(html) {
    var origem = document.createElement('div');
    origem.innerHTML = String(html == null ? '' : html);
    var saida = document.createElement('div');
    copiarFilhos(origem, saida);
    return saida.innerHTML;
  }

  /* ========================================================== MARKDOWN */
  function escaparMd(s) { return s.replace(/([\\*_`\[\]])/g, '\\$1'); }

  /* Marcadores não podem encostar em espaço: "** texto**" não é negrito. */
  function marcar(texto, marca) {
    var m = /^(\s*)([\s\S]*?)(\s*)$/.exec(texto);
    return m[2] ? m[1] + marca + m[2] + marca + m[3] : texto;
  }

  function mdInline(no) {
    var s = '';
    Array.prototype.forEach.call(no.childNodes, function (f) {
      if (f.nodeType === 3) { s += escaparMd(f.nodeValue.replace(/\s+/g, ' ')); return; }
      if (f.nodeType !== 1) return;
      switch (f.tagName) {
        case 'STRONG': s += marcar(mdInline(f), '**'); break;
        case 'EM': s += marcar(mdInline(f), '*'); break;
        case 'S': s += marcar(mdInline(f), '~~'); break;
        case 'BR': s += '  \n'; break;
        case 'UL': case 'OL': break;   /* listas aninhadas saem em mdLista */
        case 'A':
          s += f.getAttribute('href') ? '[' + mdInline(f) + '](' + f.getAttribute('href') + ')' : mdInline(f);
          break;
        default: s += mdInline(f);
      }
    });
    return s;
  }

  function mdLista(lista, nivel) {
    var linhas = [];
    var ordenada = lista.tagName === 'OL';
    var n = 0;
    Array.prototype.forEach.call(lista.children, function (li) {
      if (li.tagName !== 'LI') return;
      n++;
      var recuo = new Array(nivel + 1).join('    ');
      linhas.push(recuo + (ordenada ? n + '. ' : '- ') + mdInline(li).trim());
      Array.prototype.forEach.call(li.children, function (f) {
        if (f.tagName === 'UL' || f.tagName === 'OL') linhas.push(mdLista(f, nivel + 1));
      });
    });
    return linhas.join('\n');
  }

  function mdTabela(tabela) {
    var linhas = [];
    Array.prototype.forEach.call(tabela.querySelectorAll('tr'), function (tr, i) {
      var celulas = Array.prototype.map.call(tr.children, function (c) {
        return mdInline(c).replace(/\s*\n\s*/g, ' ').replace(/\|/g, '\\|').trim() || ' ';
      });
      linhas.push('| ' + celulas.join(' | ') + ' |');
      if (i === 0) linhas.push('|' + celulas.map(function () { return ' --- '; }).join('|') + '|');
    });
    return linhas.join('\n');
  }

  function mdBlocos(pai) {
    var blocos = [];
    Array.prototype.forEach.call(pai.childNodes, function (no) {
      if (no.nodeType === 3) {
        if (no.nodeValue.trim()) blocos.push(escaparMd(no.nodeValue.trim()));
        return;
      }
      if (no.nodeType !== 1) return;
      var texto;
      switch (no.tagName) {
        case 'H1': texto = '# ' + mdInline(no).trim(); break;
        case 'H2': texto = '## ' + mdInline(no).trim(); break;
        case 'UL': case 'OL': texto = mdLista(no, 0); break;
        case 'TABLE': texto = mdTabela(no); break;
        case 'HR': texto = '---'; break;
        case 'BLOCKQUOTE':
          texto = (temFilhoDeBloco(no) ? mdBlocos(no) : mdInline(no).trim())
            .split('\n').map(function (l) { return '> ' + l; }).join('\n');
          break;
        default: texto = mdInline(no).trim();
      }
      if (texto && texto.replace(/[>\s]/g, '')) blocos.push(texto);
    });
    return blocos.join('\n\n');
  }

  function paraMarkdown(html) {
    var caixa = document.createElement('div');
    caixa.innerHTML = html;
    return mdBlocos(caixa) + '\n';
  }

  /* ================================================================ ZIP
     Empacotamento sem compressão ("store") — o suficiente para um .docx. */
  var TABELA_CRC = (function () {
    var t = [], c, n, k;
    for (n = 0; n < 256; n++) {
      c = n;
      for (k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
      t[n] = c >>> 0;
    }
    return t;
  })();

  function crc32(bytes) {
    var c = 0xFFFFFFFF;
    for (var i = 0; i < bytes.length; i++) c = TABELA_CRC[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
  }

  /* arquivos = [{ nome, conteudo (texto) | bytes (Uint8Array) }] → Uint8Array */
  function zipBytes(arquivos) {
    var codificador = new TextEncoder();
    var partes = [], central = [], deslocamento = 0;

    arquivos.forEach(function (a) {
      var nome = codificador.encode(a.nome);
      var dados = a.bytes || codificador.encode(a.conteudo);
      var crc = crc32(dados);

      var local = new DataView(new ArrayBuffer(30));
      local.setUint32(0, 0x04034b50, true);
      local.setUint16(4, 20, true);        /* versão necessária */
      local.setUint16(6, 0x0800, true);    /* nomes em UTF-8 */
      local.setUint16(8, 0, true);         /* sem compressão */
      local.setUint16(10, 0, true);
      local.setUint16(12, 0x21, true);     /* 01/01/1980 */
      local.setUint32(14, crc, true);
      local.setUint32(18, dados.length, true);
      local.setUint32(22, dados.length, true);
      local.setUint16(26, nome.length, true);
      local.setUint16(28, 0, true);
      partes.push(new Uint8Array(local.buffer), nome, dados);

      var dir = new DataView(new ArrayBuffer(46));
      dir.setUint32(0, 0x02014b50, true);
      dir.setUint16(4, 20, true);
      dir.setUint16(6, 20, true);
      dir.setUint16(8, 0x0800, true);
      dir.setUint16(10, 0, true);
      dir.setUint16(12, 0, true);
      dir.setUint16(14, 0x21, true);
      dir.setUint32(16, crc, true);
      dir.setUint32(20, dados.length, true);
      dir.setUint32(24, dados.length, true);
      dir.setUint16(28, nome.length, true);
      dir.setUint32(42, deslocamento, true);
      central.push(new Uint8Array(dir.buffer), nome);

      deslocamento += 30 + nome.length + dados.length;
    });

    var tamanhoCentral = central.reduce(function (t, p) { return t + p.byteLength; }, 0);
    var fim = new DataView(new ArrayBuffer(22));
    fim.setUint32(0, 0x06054b50, true);
    fim.setUint16(8, arquivos.length, true);
    fim.setUint16(10, arquivos.length, true);
    fim.setUint32(12, tamanhoCentral, true);
    fim.setUint32(16, deslocamento, true);

    var todas = partes.concat(central, [new Uint8Array(fim.buffer)]);
    var saida = new Uint8Array(todas.reduce(function (n, p) { return n + p.byteLength; }, 0));
    var pos = 0;
    todas.forEach(function (p) { saida.set(p, pos); pos += p.byteLength; });
    return saida;
  }

  function zip(arquivos, mime) {
    return new Blob([zipBytes(arquivos)], { type: mime || 'application/zip' });
  }

  /* =============================================================== DOCX */
  var MIME_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
  var NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
  var NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  var XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n';
  var RECUO_CITACAO = 2268;   /* 4 cm, em twips */

  function xml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* Medida CSS → twips (1/20 de ponto). Base: 12 pt por em, 96 px por polegada. */
  function twips(medida) {
    var n = parseFloat(medida);
    if (!medida || isNaN(n)) return 0;
    var fator = /cm$/.test(medida) ? 567 : /mm$/.test(medida) ? 56.7 : /pt$/.test(medida) ? 20
              : /em$/.test(medida) ? 240 : /in$/.test(medida) ? 1440 : 15;
    return Math.round(n * fator);
  }

  function montadorDocx() {
    var links = [], listas = [];

    function propsDoRun(f) {
      var s = '';
      if (f.link) s += '<w:rStyle w:val="Hyperlink"/>';
      if (f.b) s += '<w:b/>';
      if (f.i) s += '<w:i/>';
      if (f.s) s += '<w:strike/>';
      if (f.u) s += '<w:u w:val="single"/>';
      return s ? '<w:rPr>' + s + '</w:rPr>' : '';
    }

    function com(f, chave) {
      var novo = { b: f.b, i: f.i, u: f.u, s: f.s, link: f.link };
      novo[chave] = true;
      return novo;
    }

    function runs(no, f, ignorar) {
      var s = '';
      Array.prototype.forEach.call(no.childNodes, function (filho) {
        if (filho === ignorar) return;
        if (filho.nodeType === 3) {
          var texto = filho.nodeValue.replace(/\s+/g, ' ');
          if (texto) s += '<w:r>' + propsDoRun(f) + '<w:t xml:space="preserve">' + xml(texto) + '</w:t></w:r>';
          return;
        }
        if (filho.nodeType !== 1) return;
        switch (filho.tagName) {
          case 'BR': s += '<w:r><w:br/></w:r>'; break;
          case 'STRONG': s += runs(filho, com(f, 'b')); break;
          case 'EM': s += runs(filho, com(f, 'i')); break;
          case 'U': s += runs(filho, com(f, 'u')); break;
          case 'S': s += runs(filho, com(f, 's')); break;
          case 'UL': case 'OL': break;   /* lista aninhada: tratada em lista() */
          case 'A':
            if (filho.getAttribute('href')) {
              links.push(filho.getAttribute('href'));
              s += '<w:hyperlink r:id="rIdLink' + links.length + '">' + runs(filho, com(f, 'link')) + '</w:hyperlink>';
            } else {
              s += runs(filho, f);
            }
            break;
          default: s += runs(filho, f);
        }
      });
      return s;
    }

    /* o = { estilo, num: {id, nivel}, recuoEsq, borda } */
    function propsDoParagrafo(no, o) {
      var s = '';
      var st = no.style || {};
      if (o.estilo) s += '<w:pStyle w:val="' + o.estilo + '"/>';
      if (o.num) s += '<w:numPr><w:ilvl w:val="' + o.num.nivel + '"/><w:numId w:val="' + o.num.id + '"/></w:numPr>';
      if (o.borda) s += '<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="000000"/></w:pBdr>';

      var esquerda = (o.recuoEsq || 0) + twips(st.marginLeft);
      var primeira = twips(st.textIndent);
      if (!o.num && (esquerda || primeira)) {
        s += '<w:ind w:left="' + esquerda + '"' + (primeira ? ' w:firstLine="' + primeira + '"' : '') + '/>';
      }
      var jc = { center: 'center', right: 'right', justify: 'both' }[st.textAlign];
      if (jc) s += '<w:jc w:val="' + jc + '"/>';
      return s ? '<w:pPr>' + s + '</w:pPr>' : '';
    }

    function paragrafo(no, o) {
      o = o || {};
      /* o <br> final só segura a linha em branco no HTML: não é quebra de linha */
      var ultimo = no.lastChild && no.lastChild.nodeType === 1 && no.lastChild.tagName === 'BR' ? no.lastChild : null;
      return '<w:p>' + propsDoParagrafo(no, o) + runs(no, {}, ultimo) + '</w:p>';
    }

    function lista(no, nivel) {
      listas.push(no.tagName === 'OL');
      var num = { id: listas.length, nivel: Math.min(nivel, 3) };
      var s = '';
      Array.prototype.forEach.call(no.children, function (li) {
        if (li.tagName !== 'LI') return;
        s += paragrafo(li, { num: num });
        Array.prototype.forEach.call(li.children, function (f) {
          if (f.tagName === 'UL' || f.tagName === 'OL') s += lista(f, nivel + 1);
        });
      });
      return s;
    }

    function tabela(no) {
      var linhas = Array.prototype.slice.call(no.querySelectorAll('tr'));
      var colunas = linhas.reduce(function (m, tr) { return Math.max(m, tr.children.length); }, 1);
      var borda = function (lado) { return '<w:' + lado + ' w:val="single" w:sz="4" w:space="0" w:color="000000"/>'; };

      var s = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>' +
        ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'].map(borda).join('') +
        '</w:tblBorders></w:tblPr><w:tblGrid>' +
        new Array(colunas + 1).join('<w:gridCol w:w="' + Math.floor(9000 / colunas) + '"/>') +
        '</w:tblGrid>';

      linhas.forEach(function (tr) {
        s += '<w:tr>';
        Array.prototype.forEach.call(tr.children, function (celula) {
          var vao = parseInt(celula.getAttribute('colspan'), 10) || 1;
          s += '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/>' +
               (vao > 1 ? '<w:gridSpan w:val="' + vao + '"/>' : '') + '</w:tcPr>' +
               (temFilhoDeBloco(celula) ? blocos(celula, {}) : paragrafo(celula, { estilo: celula.tagName === 'TH' ? 'Titulo2' : null })) +
               '</w:tc>';
        });
        s += '</w:tr>';
      });
      return s + '</w:tbl>';
    }

    function blocos(pai, o) {
      var s = '';
      Array.prototype.forEach.call(pai.childNodes, function (no) {
        if (no.nodeType === 3) {
          if (no.nodeValue.trim()) {
            var p = document.createElement('p');
            p.textContent = no.nodeValue;
            s += paragrafo(p, o);
          }
          return;
        }
        if (no.nodeType !== 1) return;
        switch (no.tagName) {
          case 'H1': s += paragrafo(no, { estilo: 'Titulo1', recuoEsq: o.recuoEsq }); break;
          case 'H2': s += paragrafo(no, { estilo: 'Titulo2', recuoEsq: o.recuoEsq }); break;
          case 'UL': case 'OL': s += lista(no, 0); break;
          case 'TABLE': s += tabela(no); break;
          case 'HR': s += '<w:p>' + propsDoParagrafo(no, { borda: true }) + '</w:p>'; break;
          case 'BLOCKQUOTE':
            var citacao = { estilo: 'Citacao', recuoEsq: (o.recuoEsq || 0) + RECUO_CITACAO };
            s += temFilhoDeBloco(no) ? blocos(no, citacao) : paragrafo(no, citacao);
            break;
          default:
            s += no.style.pageBreakBefore === 'always'
              ? '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'
              : paragrafo(no, o);
        }
      });
      return s;
    }

    return { blocos: blocos, links: links, listas: listas };
  }

  function estilosXml(formato) {
    var linha = Math.round(parseFloat(formato.entrelinha) * 240);
    var depois = Math.round(parseFloat(formato.espacoParagrafo) * 20);
    var fonte = '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="Times New Roman" w:cs="Times New Roman"/>';
    return XML + '<w:styles xmlns:w="' + NS_W + '">' +
      '<w:docDefaults><w:rPrDefault><w:rPr>' + fonte +
      '<w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="pt-BR"/></w:rPr></w:rPrDefault>' +
      '<w:pPrDefault><w:pPr><w:spacing w:after="' + depois + '" w:line="' + linha + '" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>' +
      '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>' +
      '<w:style w:type="paragraph" w:styleId="Titulo1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>' +
      '<w:pPr><w:keepNext/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr></w:style>' +
      '<w:style w:type="paragraph" w:styleId="Titulo2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>' +
      '<w:pPr><w:keepNext/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/></w:rPr></w:style>' +
      '<w:style w:type="paragraph" w:styleId="Citacao"><w:name w:val="Quote"/><w:basedOn w:val="Normal"/><w:qFormat/>' +
      '<w:pPr><w:spacing w:line="240" w:lineRule="auto"/><w:jc w:val="both"/></w:pPr><w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:style>' +
      '<w:style w:type="character" w:styleId="Hyperlink"><w:name w:val="Hyperlink"/><w:rPr><w:color w:val="0563C1"/><w:u w:val="single"/></w:rPr></w:style>' +
      '</w:styles>';
  }

  function numeracaoXml(listas) {
    function nivel(i, ordenada) {
      return '<w:lvl w:ilvl="' + i + '"><w:start w:val="1"/>' +
        '<w:numFmt w:val="' + (ordenada ? 'decimal' : 'bullet') + '"/>' +
        '<w:lvlText w:val="' + (ordenada ? '%' + (i + 1) + '.' : '•') + '"/><w:lvlJc w:val="left"/>' +
        '<w:pPr><w:ind w:left="' + (720 * (i + 1)) + '" w:hanging="360"/></w:pPr></w:lvl>';
    }
    function abstrata(id, ordenada) {
      return '<w:abstractNum w:abstractNumId="' + id + '"><w:multiLevelType w:val="hybridMultilevel"/>' +
        [0, 1, 2, 3].map(function (i) { return nivel(i, ordenada); }).join('') + '</w:abstractNum>';
    }
    return XML + '<w:numbering xmlns:w="' + NS_W + '">' + abstrata(0, false) + abstrata(1, true) +
      listas.map(function (ordenada, i) {
        return '<w:num w:numId="' + (i + 1) + '"><w:abstractNumId w:val="' + (ordenada ? 1 : 0) + '"/>' +
          '<w:lvlOverride w:ilvl="0"><w:startOverride w:val="1"/></w:lvlOverride></w:num>';
      }).join('') + '</w:numbering>';
  }

  /* formato = { entrelinha, espacoParagrafo, margens: {sup, dir, inf, esq} em cm } */
  function gerarDocxBytes(html, formato) {
    var caixa = document.createElement('div');
    caixa.innerHTML = html;

    var m = montadorDocx();
    var corpo = m.blocos(caixa, {});
    if (!corpo || /<\/w:tbl>$/.test(corpo)) corpo += '<w:p/>';   /* o corpo deve terminar em parágrafo */

    var cm = function (v) { return Math.round(v * 567); };
    var mg = formato.margens;
    var documento = XML + '<w:document xmlns:w="' + NS_W + '" xmlns:r="' + NS_R + '"><w:body>' + corpo +
      '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>' +
      '<w:pgMar w:top="' + cm(mg.sup) + '" w:right="' + cm(mg.dir) + '" w:bottom="' + cm(mg.inf) +
      '" w:left="' + cm(mg.esq) + '" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>' +
      '</w:body></w:document>';

    var PACOTE = 'http://schemas.openxmlformats.org/package/2006/';
    var tipos = XML + '<Types xmlns="' + PACOTE + 'content-types">' +
      '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
      '<Default Extension="xml" ContentType="application/xml"/>' +
      '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' +
      '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>' +
      '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>' +
      '</Types>';

    var raiz = XML + '<Relationships xmlns="' + PACOTE + 'relationships">' +
      '<Relationship Id="rId1" Type="' + NS_R + '/officeDocument" Target="word/document.xml"/></Relationships>';

    var relacoes = XML + '<Relationships xmlns="' + PACOTE + 'relationships">' +
      '<Relationship Id="rIdEstilos" Type="' + NS_R + '/styles" Target="styles.xml"/>' +
      '<Relationship Id="rIdNumeracao" Type="' + NS_R + '/numbering" Target="numbering.xml"/>' +
      m.links.map(function (href, i) {
        return '<Relationship Id="rIdLink' + (i + 1) + '" Type="' + NS_R + '/hyperlink" Target="' + xml(href) + '" TargetMode="External"/>';
      }).join('') + '</Relationships>';

    return zipBytes([
      { nome: '[Content_Types].xml', conteudo: tipos },
      { nome: '_rels/.rels', conteudo: raiz },
      { nome: 'word/document.xml', conteudo: documento },
      { nome: 'word/styles.xml', conteudo: estilosXml(formato) },
      { nome: 'word/numbering.xml', conteudo: numeracaoXml(m.listas) },
      { nome: 'word/_rels/document.xml.rels', conteudo: relacoes }
    ]);
  }

  function gerarDocx(html, formato) {
    return new Blob([gerarDocxBytes(html, formato)], { type: MIME_DOCX });
  }

  /* ================================================================ PDF
     Sem biblioteca, o PDF sai pela impressão do navegador ("Salvar como PDF").
     Um gerador registrado aqui passa a produzir o arquivo diretamente — é o
     que permite anexar o PDF a um e-mail. Assinatura: (html, formato) → Promise<Blob>. */
  var geradorPdf = null;
  function configurarPdf(fn) { geradorPdf = typeof fn === 'function' ? fn : null; }
  function gerarPdf(html, formato) {
    return geradorPdf ? Promise.resolve(geradorPdf(html, formato)) : Promise.resolve(null);
  }

  /* ============================================================ DOWNLOAD */
  function baixar(blob, nome) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = nome;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  }

  CE.exportar = {
    sanitizeForSigadoc: sanitizeForSigadoc,
    paraMarkdown: paraMarkdown,
    gerarDocx: gerarDocx,
    gerarDocxBytes: gerarDocxBytes,
    zip: zip,
    configurarPdf: configurarPdf,
    gerarPdf: gerarPdf,
    baixar: baixar
  };
})(window);
