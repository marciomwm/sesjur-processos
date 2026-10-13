/* =============================================================================
   engine.js — Motor do Criador de Expedientes
   -----------------------------------------------------------------------------
   Pipeline:

     MODELO → PROPRIEDADES DO MODELO → ESCOLHA DAS PROPRIEDADES → VALORES
       → TEXTO EDITÁVEL (HTML com {{TOKENS}}) → SUBSTITUIÇÃO
       → PRÉ-VISUALIZAÇÃO → GERAÇÃO FINAL

   O documento não é derivado do modelo a cada render: ele é um HTML editável que
   apenas NASCE do modelo. Este arquivo cuida de:

     • documentoBase()    texto inicial a partir do modelo (o ponto de partida)
     • normalizarTokens() conserta {{TOKENS}} partidos por tags do editor
     • substituir()       troca tokens por valores, marcando os sem valor
     • auditar()          confere se cada valor chegou ao documento
     • paraTexto()        versão em texto puro, para a área de transferência

   Não depende do DOM: serve à interface, ao uso em lote e ao extrator do SIGADoc.
   ============================================================================= */
(function (global) {
  'use strict';

  var T = global.CI_TEMPLATES;

  var MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
    'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

  function dataExtenso(d) {
    return String(d.getDate()).padStart(2, '0') + ' de ' + MESES[d.getMonth()] + ' de ' + d.getFullYear();
  }

  function escapar(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function desescapar(s) {
    return String(s)
      .replace(/&nbsp;/g, ' ')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')
      .replace(/&#0?39;/g, "'")
      .replace(/&#x27;/gi, "'")
      .replace(/&amp;/g, '&');
  }

  function normalizar(valor) {
    var v = String(valor == null ? '' : valor).replace(/\s+/g, ' ').trim();
    return v.replace(/\.+$/, '');
  }

  /* ===========================================================================
     CONTEXTO DE ESCOLHA
     `selecionadas` é a lista de tokens que o assessor marcou para este
     documento. Sem ela (uso headless, testes, lote), TUDO é considerado em uso —
     é o comportamento histórico do motor.
     =========================================================================== */
  function contexto(selecionadas) {
    if (!Array.isArray(selecionadas)) return null;
    var set = {};
    selecionadas.forEach(function (t) {
      var def = T.propriedadePorToken(t);
      set[def ? def.token : t] = true;
      if (def) (def.aliases || []).forEach(function (a) { set[a] = true; });
    });
    return {
      selecionadas: selecionadas.slice(),
      usa: function (token) {
        var def = T.propriedadePorToken(token);
        return !!set[def ? def.token : token];
      }
    };
  }

  /* Obrigatoriedade declarada pelo MODELO.

     Hoje nenhum modelo declara propriedade obrigatória — as propriedades de
     expediente são todas opcionais. O mecanismo permanece porque as
     propriedades PERSONALIZADAS podem ser marcadas como obrigatórias por quem
     as cria, e porque um modelo futuro pode voltar a exigir alguma. */
  function exigenciasDoModelo(modeloId, ctx) {
    var mapa = {};
    T.propriedadesDoModelo(modeloId).forEach(function (p) {
      if (!p.obrigatorio) return;
      if (ctx && !ctx.usa(p.token)) return;   /* propriedade não escolhida não é cobrada */
      mapa[p.token] = true;
    });
    return mapa;
  }

  /* ===========================================================================
     PROPRIEDADES EM TEMPO DE EXECUÇÃO
     Junta as propriedades do catálogo (ligadas aos valores preenchidos), as
     automáticas e as personalizadas criadas pelo assessor.
     Retorna um mapa token -> { token, label, valor, ... }, incluindo aliases.

     O mapa continua trazendo TODO o catálogo: um token digitado à mão no editor
     precisa resolver mesmo que a propriedade não esteja marcada na interface.
     =========================================================================== */
  function montarPropriedades(modeloId, dados, personalizadas, opcoes) {
    opcoes = opcoes || {};
    var hoje = opcoes.hoje || new Date();
    var modelo = T.modelo(modeloId);
    var ctx = opcoes.contexto || contexto(opcoes.selecionadas);
    var exige = exigenciasDoModelo(modeloId, ctx);
    var mapa = {};

    function registrar(p) {
      mapa[p.token] = p;
      (p.aliases || []).forEach(function (a) { if (!mapa[a]) mapa[a] = p; });
    }

    Object.keys(T.PROPRIEDADES).forEach(function (token) {
      var def = T.PROPRIEDADES[token];
      var valor;

      if (def.auto) {
        valor = token === 'DATA_HOJE' ? dataExtenso(hoje)
              : token === 'ANO' ? String(hoje.getFullYear())
              : '';
      } else {
        valor = normalizar(dados[def.chave]);
        if (!valor && def.usaPadraoDoModelo && modelo) valor = modelo.assuntoPadrao;
      }

      registrar({
        token: def.token,
        aliases: def.aliases || [],
        chave: def.chave || null,
        label: def.label,
        valor: valor,
        obrigatorio: !!exige[def.token],
        selecionada: !ctx || ctx.usa(def.token),
        vazioComo: def.vazioComo || null,
        auto: !!def.auto,
        origem: def.auto ? 'auto' : 'campo',
        grupo: def.grupo || null
      });
    });

    (personalizadas || []).forEach(function (p) {
      if (!p || !p.token) return;
      registrar({
        token: p.token,
        aliases: [],
        chave: null,
        label: p.label || p.token,
        valor: normalizar(p.valor),
        obrigatorio: !!p.obrigatorio,
        selecionada: true,
        vazioComo: null,
        auto: false,
        origem: 'personalizada'
      });
    });

    return mapa;
  }

  /* ===========================================================================
     TEXTO INICIAL DO MODELO — o ponto de partida do editor
     =========================================================================== */
  function documentoBase(modeloId, dados, opcoes) {
    opcoes = opcoes || {};
    var modelo = T.modelo(modeloId);
    if (!modelo) return '';

    /* Modelo salvo pelo assessor já guarda o próprio texto. */
    if (modelo.salvo && typeof modelo.html === 'string' && modelo.html.trim()) {
      return modelo.html;
    }

    var d = dados || T.dadosVazios();
    var ctx = opcoes.contexto || contexto(opcoes.selecionadas);

    /* Modelo com documento completo declarado (ofícios e novos expedientes). */
    if (Array.isArray(modelo.blocos) && modelo.blocos.length) {
      return montarBlocos(modelo.blocos, d, ctx);
    }

    /* --- Cabeçalho institucional da Comunicação Interna (modelos originais) --- */
    var p = [];
    function par(classe, html) { p.push('<p class="' + classe + '">' + html + '</p>'); }
    function branco() { p.push('<p class="ci-vazio"><br></p>'); }
    function usa(token) { return !ctx || ctx.usa(token); }

    par('ci-hdr', '<strong>CI Nº {{NUMERO_CI}}/{{ANO}}/UNIJUR/SES</strong>');
    branco();
    par('ci-esq', 'Cuiabá/MT, {{DATA_HOJE}}.');
    branco();

    if (usa('DESTINATARIO') && normalizar(d.destinatario)) {
      par('ci-esq', 'À/Ao {{DESTINATARIO}}');
      branco();
    }

    par('ci-esq', '<strong>Assunto: {{ASSUNTO}}</strong>');

    var ref = [];
    if (usa('PROCESSO_JUDICIAL') && normalizar(d.processo_judicial)) {
      ref.push('Processo judicial nº <strong>{{PROCESSO_JUDICIAL}}</strong>');
    }
    if (usa('PROCESSO_SIGADOC') && normalizar(d.processo_administrativo)) {
      ref.push('Processo SIGADoc nº <strong>{{PROCESSO_SIGADOC}}</strong>');
    }
    if (ref.length) par('ci-esq', '<strong>Referência: </strong>' + ref.join(' — '));

    branco();
    par('ci-esq', 'Prezados,');
    branco();

    /* --- Corpo do modelo --- */
    modelo.corpo.forEach(function (bloco) {
      if (bloco.quando && !bloco.quando(d, ctx)) return;
      par(bloco.classe || 'ci-just', comNegrito(bloco));
      branco();
    });

    /* --- Encerramento --- */
    par('ci-esq', 'Cordialmente.');

    return p.join('\n');
  }

  /* Documento completo declarado por blocos. */
  function montarBlocos(blocos, d, ctx) {
    var p = [];
    function branco() { p.push('<p class="ci-vazio"><br></p>'); }

    blocos.forEach(function (bloco) {
      if (bloco.quando && !bloco.quando(d, ctx)) return;
      if (bloco.espacador) { branco(); return; }
      /* Parágrafo vazio de conteúdo: espaço reservado para o assessor escrever. */
      if (bloco.vazio) {
        p.push('<p class="' + (bloco.classe || 'ci-just') + '"><br></p>');
        if (!bloco.semEspaco) branco();
        return;
      }
      p.push('<p class="' + (bloco.classe || 'ci-just') + '">' + comNegrito(bloco) + '</p>');
      if (!bloco.semEspaco) branco();
    });

    /* Espaçadores sobrando no fim não acrescentam nada ao documento. */
    while (p.length && p[p.length - 1].indexOf('ci-vazio') !== -1) p.pop();

    return p.join('\n');
  }

  /* Aplica negrito aos tokens e aos trechos literais declarados no bloco. */
  function comNegrito(bloco) {
    var html = escapar(bloco.texto);

    (bloco.negrito || []).forEach(function (token) {
      html = html.split('{{' + token + '}}').join('<strong>{{' + token + '}}</strong>');
    });
    (bloco.negritoLiteral || []).forEach(function (lit) {
      var alvo = escapar(lit);
      html = html.split(alvo).join('<strong>' + alvo + '</strong>');
    });
    return html;
  }

  /* ===========================================================================
     TOKENS
     =========================================================================== */
  var RE_TOKEN = /\{\{([A-Z][A-Z0-9_]*)\}\}/g;

  /* O editor pode partir um token ao aplicar negrito no meio dele
     ({{NOME_<strong>PACIENTE</strong>}}). Aqui o token é remontado. */
  function normalizarTokens(html) {
    return String(html).replace(/\{\{((?:[^{}]|<[^>]*>)*?)\}\}/g, function (todo, dentro) {
      var limpo = desescapar(dentro.replace(/<[^>]*>/g, ''))
        .replace(/\s+/g, '')
        .toUpperCase();
      if (!/^[A-Z][A-Z0-9_]*$/.test(limpo)) return todo;   /* não parece token: preserva */
      return '{{' + limpo + '}}';
    });
  }

  function tokensDe(html) {
    var achados = [], visto = {}, m;
    RE_TOKEN.lastIndex = 0;
    while ((m = RE_TOKEN.exec(String(html))) !== null) {
      if (!visto[m[1]]) { visto[m[1]] = true; achados.push(m[1]); }
    }
    return achados;
  }

  /* ===========================================================================
     SUBSTITUIÇÃO
     opcoes.marcarVariaveis  envolve o valor em <span class="ci-var">
     opcoes.marcarPendentes  mostra [rótulo] em vez de apagar silenciosamente

     Cada pendência informa se a propriedade é OBRIGATÓRIA. Propriedade opcional
     vazia continua sendo sinalizada, mas não impede a geração do documento.
     =========================================================================== */
  function substituir(html, props, opcoes) {
    opcoes = opcoes || {};
    var pendentes = [], vistoPendente = {}, usados = {};

    var saida = String(html).replace(RE_TOKEN, function (todo, token) {
      var p = props[token];

      /* Token que não corresponde a nenhuma propriedade conhecida. */
      if (!p) {
        if (!vistoPendente[token]) {
          vistoPendente[token] = true;
          pendentes.push({ token: token, label: token, motivo: 'inexistente', obrigatoria: true });
        }
        return opcoes.marcarPendentes
          ? '<span class="ci-falta" data-prop="' + token + '" title="Propriedade não cadastrada">' + escapar(token) + '</span>'
          : todo;
      }

      /* Propriedade sem valor. */
      if (!p.valor) {
        if (p.vazioComo) return escapar(p.vazioComo);
        if (!vistoPendente[p.token]) {
          vistoPendente[p.token] = true;
          pendentes.push({
            token: p.token, label: p.label, motivo: 'sem valor',
            obrigatoria: !!p.obrigatorio
          });
        }
        if (!opcoes.marcarPendentes) return '';
        return '<span class="ci-falta' + (p.obrigatorio ? '' : ' ci-falta-opcional') +
               '" data-prop="' + p.token + '">' + escapar(p.label) + '</span>';
      }

      usados[p.token] = usados[p.token] || [];
      usados[p.token].push(p.valor);

      var valor = escapar(p.valor);
      return opcoes.marcarVariaveis
        ? '<span class="ci-var" data-prop="' + p.token + '">' + valor + '</span>'
        : valor;
    });

    return { html: saida, pendentes: pendentes, usados: usados };
  }

  /* ===========================================================================
     MOTOR DE TEMPLATES NA FORMA MAIS SIMPLES

       renderTemplate('Prezado {{NOME}}, processo {{PROTOCOLO}}',
                      { NOME: 'João da Silva', PROTOCOLO: 'SES-PRO-2026/12345' })
       → 'Prezado João da Silva, processo SES-PRO-2026/12345'

     É a mesma substituir() usada pelo documento individual e pelo lote, sem o
     catálogo de propriedades. Token sem valor some; token desconhecido fica.
     opcoes.texto: devolve texto puro (sem escapar HTML) — nomes de arquivo.
     =========================================================================== */
  function renderTemplate(template, values, opcoes) {
    opcoes = opcoes || {};
    var props = {};
    Object.keys(values || {}).forEach(function (token) {
      props[token] = { token: token, label: token, valor: normalizar(values[token]), obrigatorio: false };
    });
    var html = substituir(normalizarTokens(template), props, { marcarPendentes: false }).html;
    return opcoes.texto ? desescapar(html) : html;
  }

  /* ===========================================================================
     TEXTO PURO
     =========================================================================== */
  function paraTexto(html) {
    var s = String(html)
      .replace(/<\s*br\s*\/?>/gi, '\n')
      .replace(/<\/\s*(p|div|h[1-6]|li)\s*>/gi, '\n')
      .replace(/<[^>]+>/g, '');
    return desescapar(s)
      .replace(/[ \t]+\n/g, '\n')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
  }

  /* ===========================================================================
     VALIDAÇÃO
     Só exige a propriedade OBRIGATÓRIA (segundo o modelo), ESCOLHIDA pelo
     assessor e que o texto realmente usa: se o texto não a menciona, não faz
     sentido cobrá-la. Valores preenchidos são sempre conferidos quanto ao
     formato, obrigatórios ou não.
     =========================================================================== */
  function validar(modeloId, dados, html, personalizadas, opcoes) {
    opcoes = opcoes || {};
    var erros = {}, faltando = [];
    var modelo = T.modelo(modeloId);
    if (!modelo) {
      return { ok: false, erros: { _modelo: 'Modelo de expediente não encontrado.' }, primeiro: '_modelo', faltando: [] };
    }

    var ctx = opcoes.contexto || contexto(opcoes.selecionadas);
    var props = montarPropriedades(modeloId, dados, personalizadas, { contexto: ctx, hoje: opcoes.hoje });
    var usados = {};
    tokensDe(html == null ? documentoBase(modeloId, dados, { contexto: ctx }) : html).forEach(function (t) {
      if (props[t]) usados[props[t].token] = true;
    });

    Object.keys(T.PROPRIEDADES).forEach(function (token) {
      var def = T.PROPRIEDADES[token];
      if (def.auto || !def.chave) return;
      var valor = String(dados[def.chave] == null ? '' : dados[def.chave]).trim();
      var p = props[token];

      if (!valor) {
        if (p && p.obrigatorio && usados[token]) {
          erros[def.chave] = 'Propriedade obrigatória. ' + def.label + ' não informado.';
          faltando.push({ token: token, chave: def.chave, label: def.label });
        }
        return;
      }
      if (def.validar) {
        var msg = def.validar(valor);
        if (msg) erros[def.chave] = msg;
      }
    });

    /* Propriedades personalizadas marcadas como obrigatórias pelo assessor. */
    (personalizadas || []).forEach(function (p) {
      if (!p || !p.token || !p.obrigatorio) return;
      if (!usados[p.token]) return;
      if (String(p.valor == null ? '' : p.valor).trim()) return;
      erros[p.token] = 'Propriedade obrigatória. ' + (p.label || p.token) + ' não informada.';
      faltando.push({ token: p.token, chave: p.token, label: p.label || p.token });
    });

    var chaves = Object.keys(erros);
    return { ok: chaves.length === 0, erros: erros, primeiro: chaves[0] || null, faltando: faltando };
  }

  /* ===========================================================================
     AUDITORIA — confere se cada propriedade usada no texto chegou ao documento
     exatamente como informada, e se sobrou token sem substituir.
     =========================================================================== */
  function auditar(props, html, resultado) {
    var texto = paraTexto(resultado.html);
    var itens = [], falhas = 0;

    tokensDe(html).forEach(function (token) {
      var p = props[token];

      if (!p) {
        itens.push({
          token: token, label: token, valor: '', status: 'falha',
          nota: 'Propriedade não cadastrada. Crie-a em "Adicionar propriedade" ou remova o token do texto.'
        });
        falhas++;
        return;
      }
      if (itens.some(function (i) { return i.token === p.token; })) return;   /* já auditada */

      if (!p.valor) {
        if (p.vazioComo) {
          itens.push({ token: p.token, label: p.label, valor: p.vazioComo, status: 'na', nota: 'Sem valor: usa "' + p.vazioComo + '".' });
        } else if (p.obrigatorio) {
          itens.push({ token: p.token, label: p.label, valor: '', status: 'falha', nota: 'Propriedade OBRIGATÓRIA usada no texto e sem valor preenchido.' });
          falhas++;
        } else {
          /* Opcional vazia: fica sinalizada no documento, mas não reprova. */
          itens.push({
            token: p.token, label: p.label, valor: '', status: 'na',
            nota: 'Propriedade opcional sem valor: o marcador permanece no texto. Preencha-a ou remova o token.'
          });
        }
        return;
      }

      var usos = resultado.usados[p.token] || [];
      var inserido = usos.length > 0 && usos.every(function (u) { return u === p.valor; });
      var presente = texto.indexOf(p.valor) !== -1;

      if (inserido && presente) {
        itens.push({
          token: p.token, label: p.label, valor: p.valor, status: 'ok',
          nota: usos.length + (usos.length === 1 ? ' ocorrência no documento.' : ' ocorrências no documento.')
        });
      } else {
        itens.push({
          token: p.token, label: p.label, valor: p.valor, status: 'falha',
          nota: usos.length ? 'Valor inserido difere do valor informado.' : 'Valor informado não chegou ao documento.'
        });
        falhas++;
      }
    });

    /* Propriedades preenchidas que não aparecem no texto: informativo, não é erro. */
    Object.keys(props).forEach(function (token) {
      var p = props[token];
      if (p.token !== token || p.auto) return;
      if (itens.some(function (i) { return i.token === p.token; })) return;
      if (!p.valor) return;
      itens.push({
        token: p.token, label: p.label, valor: p.valor, status: 'na',
        nota: 'Preenchida, mas não utilizada no texto deste expediente.'
      });
    });

    /* regex sem /g: `test` com flag global guarda lastIndex entre chamadas */
    var residuo = /\{\{[A-Z][A-Z0-9_]*\}\}/.test(paraTexto(resultado.html));
    if (residuo) falhas++;

    return { itens: itens, falhas: falhas, marcadorResidual: residuo, ok: falhas === 0 };
  }

  /* ===========================================================================
     GERAÇÃO COMPLETA (uso headless: lote, testes, extrator do SIGADoc)
     =========================================================================== */
  function gerar(modeloId, dados, opcoes) {
    opcoes = opcoes || {};
    var personalizadas = opcoes.personalizadas || [];
    var ctx = opcoes.contexto || contexto(opcoes.selecionadas);

    var html = normalizarTokens(
      typeof opcoes.html === 'string' && opcoes.html.trim()
        ? opcoes.html
        : documentoBase(modeloId, dados, { contexto: ctx })
    );

    var props = montarPropriedades(modeloId, dados, personalizadas, { contexto: ctx, hoje: opcoes.hoje });
    var sub = substituir(html, props, {
      marcarVariaveis: opcoes.marcarVariaveis,
      marcarPendentes: opcoes.marcarPendentes !== false
    });

    return {
      modeloId: modeloId,
      modelo: T.modelo(modeloId) ? T.modelo(modeloId).nome : '',
      fonte: html,
      html: sub.html,
      texto: paraTexto(sub.html),
      pendentes: sub.pendentes,
      pendentesObrigatorias: sub.pendentes.filter(function (p) { return p.obrigatoria; }),
      usados: sub.usados,
      propriedades: props,
      auditoria: auditar(props, html, sub)
    };
  }

  global.CI_ENGINE = {
    contexto: contexto,
    montarPropriedades: montarPropriedades,
    documentoBase: documentoBase,
    normalizarTokens: normalizarTokens,
    tokensDe: tokensDe,
    substituir: substituir,
    renderTemplate: renderTemplate,
    paraTexto: paraTexto,
    validar: validar,
    auditar: auditar,
    gerar: gerar,
    dataExtenso: dataExtenso,
    normalizar: normalizar,
    escapar: escapar
  };
})(window);
