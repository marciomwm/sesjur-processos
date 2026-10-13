/* =============================================================================
   templates.js — Propriedades e modelos do Criador de Expedientes (UNIJUR/SES-MT)
   -----------------------------------------------------------------------------
   PRINCÍPIO: o MODELO É UM PONTO DE PARTIDA, não um texto bloqueado.

   O modelo produz o texto inicial do expediente. A partir daí o assessor edita à
   vontade: apaga, reescreve, reordena parágrafos, cria propriedades novas e
   insere-as em qualquer ponto. O sistema só garante a substituição correta das
   propriedades e avisa sobre as que ficaram sem valor.

   Camadas deste arquivo:
     1. PROPRIEDADES — catálogo vindo do SESJUR (atributos do Processo
                       Administrativo e dos processos vinculados) e automáticas
     2. MODELOS      — cada modelo declara as PRÓPRIAS propriedades e o texto
     3. MODELOS SALVOS — versões personalizadas gravadas pelo próprio assessor

   As propriedades NÃO são fixas para toda a aplicação: o catálogo é apenas o
   dicionário. Quem define quais propriedades existem — e em que ordem — é cada
   modelo, através da sua lista `propriedades`. O assessor ainda escolhe, dentro
   dessa lista, quais quer efetivamente utilizar no documento.
   ============================================================================= */
(function (global) {
  'use strict';

  /* ---------------------------------------------------------------------------
     Validação de data (DD/MM/AAAA, data real do calendário)
     --------------------------------------------------------------------------- */
  function checarData(valor, permitirFuturo) {
    var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(String(valor || '').trim());
    if (!m) return 'Use o formato DD/MM/AAAA.';
    var dia = +m[1], mes = +m[2], ano = +m[3];
    if (mes < 1 || mes > 12) return 'Mês inválido.';
    var d = new Date(ano, mes - 1, dia);
    if (d.getDate() !== dia || d.getMonth() !== mes - 1 || d.getFullYear() !== ano) {
      return 'Data inexistente no calendário.';
    }
    if (ano < 1990) return 'Ano improvável. Confira a data informada.';
    if (!permitirFuturo) {
      var limite = new Date(); limite.setHours(23, 59, 59, 999);
      if (d > limite) return 'A data não pode ser futura.';
    }
    return null;
  }

  function validarData(valor) { return checarData(valor, false); }
  function validarDataLivre(valor) { return checarData(valor, true); }

  /* ---------------------------------------------------------------------------
     1. CATÁLOGO DE PROPRIEDADES
     -----------------------------------------------------------------------------
     No SESJUR o catálogo não é escrito neste arquivo: vem do servidor
     (App\Services\ExpedienteService, entregue por js/config.js) e reúne os
     atributos do Processo Administrativo selecionado e dos Processos Judiciais
     e Extrajudiciais vinculados a ele. Os valores chegam por CE.sesjur.

     token       identificador usado no texto como {{TOKEN}}
     chave       nome do valor no pacote de dados do processo
     grupo       'administrativo' | 'judicial' | 'extrajudicial' (organiza o menu)
     auto        calculada pelo sistema, não editável

     As propriedades do catálogo são sempre opcionais: valor ausente no banco
     fica sinalizado no texto como pendência, sem impedir a geração.
     --------------------------------------------------------------------------- */
  var SESJUR = global.SESJUR_EXPEDIENTE || {};
  var PROPRIEDADES = {};

  (SESJUR.catalogo || []).forEach(function (p) {
    PROPRIEDADES[p.token] = {
      token: p.token,
      chave: p.chave,
      label: p.label,
      grupo: p.grupo,
      tipo: p.tipo,
      obrigatorio: false,
      placeholder: 'Preenchido com os dados do processo selecionado',
      ajuda: p.ajuda,
      validar: null
    };
  });

  /* Automáticas — calculadas pelo sistema. */
  PROPRIEDADES.DATA_HOJE = {
    token: 'DATA_HOJE',
    label: 'Data de hoje por extenso',
    auto: true,
    ajuda: 'Preenchida pelo sistema.'
  };
  PROPRIEDADES.ANO = {
    token: 'ANO',
    label: 'Ano corrente',
    auto: true,
    ajuda: 'Preenchida pelo sistema.'
  };

  /* token (inclusive alias) -> definição canônica */
  var PORTOKEN = {};
  Object.keys(PROPRIEDADES).forEach(function (t) {
    var p = PROPRIEDADES[t];
    PORTOKEN[t] = p;
    (p.aliases || []).forEach(function (a) { PORTOKEN[a] = p; });
  });

  /* chave da estrutura de dados -> definição */
  var PORCHAVE = {};
  Object.keys(PROPRIEDADES).forEach(function (t) {
    if (PROPRIEDADES[t].chave) PORCHAVE[PROPRIEDADES[t].chave] = PROPRIEDADES[t];
  });

  function propriedadePorToken(token) { return PORTOKEN[token] || null; }
  function propriedadePorChave(chave) { return PORCHAVE[chave] || null; }

  /* Identificador de propriedade personalizada: A-Z, 0-9 e _ */
  function normalizarIdentificador(texto) {
    return String(texto || '')
      .normalize ? String(texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '')
      : String(texto || '').toUpperCase().replace(/[^A-Z0-9]+/g, '_');
  }

  function validarIdentificador(id, jaExistentes) {
    if (!id) return 'Informe o identificador.';
    if (!/^[A-Z][A-Z0-9_]{1,39}$/.test(id)) {
      return 'Use apenas letras maiúsculas, números e _ (começando por letra).';
    }
    if (PORTOKEN[id]) return 'Já existe uma propriedade do sistema com este identificador.';
    if (jaExistentes && jaExistentes.indexOf(id) !== -1) return 'Já existe uma propriedade com este identificador.';
    return null;
  }

  /* ---------------------------------------------------------------------------
     2. MODELOS
     -----------------------------------------------------------------------------
     Cada modelo declara:

       propriedades  lista ORDENADA das propriedades daquele modelo
                     { token, obrigatorio, padrao }
                     - obrigatorio: impede a geração enquanto não preenchida
                     - padrao: já vem marcada quando o modelo é selecionado
       padroes       valores sugeridos ao selecionar o modelo (campos vazios)
       blocos        documento completo (cabeçalho + corpo + fecho)
       corpo         [LEGADO] só o corpo; o cabeçalho de CI é montado pelo motor

     `blocos`/`corpo` são o PONTO DE PARTIDA do editor. Blocos com `quando` só
     entram no texto inicial se a condição for satisfeita; depois que o assessor
     edita o texto, nada mais é recalculado.
     --------------------------------------------------------------------------- */

  /* Atalho para declarar uma propriedade de modelo.

     As propriedades de expediente são SEMPRE OPCIONAIS: quem decide o que o
     documento precisa conter é o assessor, caso a caso. `padrao` diz apenas se
     a propriedade já vem incluída ao selecionar o modelo — ela pode ser
     retirada, e qualquer outra do catálogo pode ser acrescentada.

     Só as propriedades PERSONALIZADAS, criadas pelo próprio assessor, podem ser
     marcadas por ele como obrigatórias. */
  function P(token, padrao) {
    return { token: token, obrigatorio: false, padrao: padrao !== false };
  }

  /* Condições de bloco baseadas nas propriedades ESCOLHIDAS pelo assessor.
     Sem informação de escolha (uso headless), o bloco entra normalmente. */
  function seUsa() {
    var toks = Array.prototype.slice.call(arguments);
    return function (d, ctx) {
      if (!ctx || !ctx.usa) return true;
      for (var i = 0; i < toks.length; i++) { if (!ctx.usa(toks[i])) return false; }
      return true;
    };
  }
  function seUsaAlgum() {
    var toks = Array.prototype.slice.call(arguments);
    return function (d, ctx) {
      if (!ctx || !ctx.usa) return true;
      for (var i = 0; i < toks.length; i++) { if (ctx.usa(toks[i])) return true; }
      return false;
    };
  }

  var MODELOS = {};

  /* ============================================= 2.3 EXPEDIENTE EM BRANCO

     Construtor livre: começa com ZERO propriedades. O assessor monta a estrutura
     do documento propriedade por propriedade, conforme a necessidade daquele
     expediente (manifestação em ação indenizatória, informação, memorando...).

     `propriedades: []` é uma declaração explícita de "nenhuma propriedade" — não
     é o mesmo que não declarar nada, caso em que o modelo herdaria o catálogo.

     Os blocos do esqueleto são todos condicionados às propriedades escolhidas:
     enquanto nenhuma existir, o documento traz apenas a data, um parágrafo em
     branco para o corpo e o fecho. Nenhum texto jurídico é sugerido.
     ---------------------------------------------------------------------------- */
  MODELOS['expediente-livre'] = {
    nome: 'Expediente em Branco',
    descricao: 'Escrita livre: começa sem texto e sem propriedades. Crie as propriedades necessárias e escreva o expediente do zero.',
    emBranco: true,
    assuntoPadrao: '',
    propriedades: [],
    blocos: [
      { id: 'data', classe: 'ci-dir', texto: 'Cuiabá/MT, {{DATA_HOJE}}.', negrito: ['DATA_HOJE'] },

      { id: 'ref_judicial', classe: 'ci-esq', texto: 'REF.: Processo Judicial nº {{PROCESSO_JUDICIAL}}', negritoLiteral: ['REF.:'], semEspaco: true, quando: seUsa('PROCESSO_JUDICIAL') },
      { id: 'ref_extrajudicial', classe: 'ci-esq', texto: 'REF.: {{PE_INSTITUICAO_SIGLA}} nº {{PROCESSO_EXTRAJUDICIAL}}', negritoLiteral: ['REF.:'], semEspaco: true, quando: seUsa('PROCESSO_EXTRAJUDICIAL') },
      { id: 'ref_sigadoc', classe: 'ci-esq', texto: 'SIGADOC: {{PROCESSO_SIGADOC}}', negritoLiteral: ['SIGADOC:'], semEspaco: true, quando: seUsa('PROCESSO_SIGADOC') },
      { id: 'ref_objeto', classe: 'ci-esq', texto: 'Objeto: {{PA_OBJETO}}', semEspaco: true, quando: seUsa('PA_OBJETO') },
      { id: 'ref_fim', espacador: true },

      /* Corpo: em branco, para ser escrito no editor. */
      { id: 'corpo', vazio: true },

      { id: 'fecho', classe: 'ci-esq', texto: 'Atenciosamente,' }
    ],
    arquivo: function (d) {
      return 'Expediente - ' + (d.processo_administrativo || 'sem identificacao');
    }
  };

  /* --------------------------------------------------------------------------
     Agrupamento do menu "Modelo de Expediente"
     -------------------------------------------------------------------------- */
  /* A aplicação não traz mais modelos de fábrica além do Expediente em Branco:
     os modelos são criados pelo assessor na Biblioteca de Modelos. */
  var GRUPOS = [
    {
      id: 'outros',
      titulo: 'Outros expedientes',
      modelos: ['expediente-livre']
    }
  ];

  var ORDEM = [];
  GRUPOS.forEach(function (g) { ORDEM = ORDEM.concat(g.modelos); });

  /* Normaliza uma lista declarada de propriedades (aceita string ou objeto). */
  function normalizarLista(lista) {
    /* Lista declarada — inclusive a lista VAZIA, que significa "modelo sem
       nenhuma propriedade" (Expediente em branco) e não deve cair no padrão. */
    if (Array.isArray(lista)) {
      return lista
        .map(function (p) {
          return typeof p === 'string'
            ? P(p)
            : { token: p.token, obrigatorio: false, padrao: p.padrao !== false };
        })
        .filter(function (p) { return !!PORTOKEN[p.token]; });
    }
    /* Modelo sem declaração (legado): usa o catálogo inteiro. */
    return catalogo().map(function (def) { return P(def.token); });
  }

  /* Catálogo completo de propriedades preenchíveis, na ordem da interface:
     o processo administrativo (SIGADOC) sempre primeiro. Alimenta a lista
     suspensa "Propriedade do expediente". */
  var ORDEM_GRUPOS = Object.keys(SESJUR.grupos || {});

  function catalogo() {
    return Object.keys(PROPRIEDADES)
      .map(function (t) { return PROPRIEDADES[t]; })
      .filter(function (def) { return !def.auto && def.chave; })
      .sort(function (a, b) {
        if (a.token === 'PROCESSO_SIGADOC') return -1;
        if (b.token === 'PROCESSO_SIGADOC') return 1;
        return ORDEM_GRUPOS.indexOf(a.grupo) - ORDEM_GRUPOS.indexOf(b.grupo);
      });
  }

  var TITULOS_GRUPO = SESJUR.grupos || {};

  /* Lista de propriedades de um modelo (sempre um array novo). */
  function propriedadesDoModelo(id) {
    var m = modelo(id);
    return m ? normalizarLista(m.propriedades) : [];
  }

  /* Tokens que já vêm marcados ao selecionar o modelo. */
  function selecaoPadrao(id) {
    return propriedadesDoModelo(id)
      .filter(function (p) { return p.padrao; })
      .map(function (p) { return p.token; });
  }

  function chavesDaLista(lista) {
    var vistos = {}, chaves = [];
    normalizarLista(lista).forEach(function (p) {
      var def = PORTOKEN[p.token];
      if (!def || !def.chave || vistos[def.chave]) return;
      vistos[def.chave] = true;
      chaves.push(def.chave);
    });
    return chaves;
  }

  /* Campos (chaves) de um modelo — mantém o contrato antigo `modelo.campos`. */
  function camposDoModelo(id) {
    var m = modelo(id);
    return m ? chavesDaLista(m.propriedades) : [];
  }

  /* `modelo.campos` continua respondendo como antes, agora derivado da lista. */
  function definirCampos(m) {
    Object.defineProperty(m, 'campos', {
      get: function () { return chavesDaLista(m.propriedades); },
      enumerable: false, configurable: true
    });
  }
  ORDEM.forEach(function (id) { definirCampos(MODELOS[id]); });

  /* Acrescenta um grupo de modelos declarados em código (usado em testes do
     motor). */
  function registrarGrupo(grupo, definicoes) {
    grupo.modelos.forEach(function (id) {
      MODELOS[id] = definicoes[id];
      definirCampos(MODELOS[id]);
      ORDEM.push(id);
    });
    GRUPOS.push(grupo);
  }

  /* ---------------------------------------------------------------------------
     3. MODELOS SALVOS PELO ASSESSOR
     Guardam o texto já editado (HTML com tokens), as propriedades criadas e a
     seleção de propriedades do documento.
     --------------------------------------------------------------------------- */
  var CHAVE_SALVOS = 'unijur_ci_modelos_v1';
  var SALVOS = {};      /* id -> registro */
  var ORDEM_SALVOS = [];

  function lerSalvos() {
    SALVOS = {}; ORDEM_SALVOS = [];
    var lista;
    try {
      lista = JSON.parse(localStorage.getItem(CHAVE_SALVOS) || '[]');
    } catch (e) { lista = []; }
    if (!Array.isArray(lista)) lista = [];

    lista.forEach(function (reg) {
      if (!reg || !reg.id || !reg.nome || typeof reg.html !== 'string') return;
      var base = MODELOS[reg.base] || MODELOS[ORDEM[0]];
      SALVOS[reg.id] = {
        id: reg.id,
        nome: reg.nome,
        base: MODELOS[reg.base] ? reg.base : ORDEM[0],
        salvo: true,
        html: reg.html,
        propriedades: base.propriedades,
        personalizadas: Array.isArray(reg.personalizadas) ? reg.personalizadas
                      : (Array.isArray(reg.propriedades) ? reg.propriedades : []),
        selecao: Array.isArray(reg.selecao) ? reg.selecao : null,
        tipo: reg.tipo || 'Outro',
        formato: reg.formato || null,
        criadoEm: reg.criadoEm || null,
        atualizadoEm: reg.atualizadoEm || null,
        favorito: !!reg.favorito,
        usos: reg.usos || 0,
        ultimoUsoEm: reg.ultimoUsoEm || null,
        autor: reg.autor || null,
        descricao: reg.descricao || '',
        assuntoPadrao: reg.assuntoPadrao || base.assuntoPadrao,
        padroes: base.padroes,
        rotulos: base.rotulos,
        blocos: base.blocos,
        corpo: base.corpo,
        arquivo: base.arquivo
      };
      definirCampos(SALVOS[reg.id]);
      ORDEM_SALVOS.push(reg.id);
    });
    return ORDEM_SALVOS;
  }

  function gravarSalvos() {
    var lista = ORDEM_SALVOS.map(function (id) {
      var m = SALVOS[id];
      return {
        id: m.id, nome: m.nome, base: m.base, html: m.html,
        personalizadas: m.personalizadas,
        /* mantém a chave antiga para quem já tem modelos gravados */
        propriedades: m.personalizadas,
        selecao: m.selecao,
        tipo: m.tipo,
        formato: m.formato,
        criadoEm: m.criadoEm,
        atualizadoEm: m.atualizadoEm,
        favorito: m.favorito,
        usos: m.usos,
        ultimoUsoEm: m.ultimoUsoEm,
        autor: m.autor,
        descricao: m.descricao,
        assuntoPadrao: m.assuntoPadrao
      };
    });
    try {
      localStorage.setItem(CHAVE_SALVOS, JSON.stringify(lista));
      return true;
    } catch (e) { return false; }
  }

  /* `extras` = { tipo, formato } — metadados do modelo. O protocolo do processo
     NUNCA entra aqui: ele pertence ao expediente, não ao modelo. */
  function salvarModelo(nome, baseId, html, personalizadas, assuntoPadrao, descricao, selecao, extras) {
    extras = extras || {};
    var agora = new Date().toISOString();
    var limpo = String(nome || '').trim();
    if (!limpo) return { ok: false, erro: 'Informe um nome para o modelo.' };
    if (ORDEM_SALVOS.some(function (id) { return SALVOS[id].nome.toLowerCase() === limpo.toLowerCase(); })) {
      return { ok: false, erro: 'Já existe um modelo salvo com este nome.' };
    }
    if (ORDEM.some(function (id) { return MODELOS[id].nome.toLowerCase() === limpo.toLowerCase(); })) {
      return { ok: false, erro: 'Este nome é de um modelo do sistema. Escolha outro.' };
    }
    var base = MODELOS[baseId] ? baseId : ORDEM[0];
    if (SALVOS[base]) base = SALVOS[base].base;   /* salvar sobre um salvo aponta para o original */

    var id = 'meu:' + Date.now().toString(36) + Math.random().toString(36).slice(2, 5);
    SALVOS[id] = {
      id: id, nome: limpo, base: base, salvo: true, html: html,
      propriedades: MODELOS[base].propriedades,
      personalizadas: personalizadas || [],
      selecao: Array.isArray(selecao) ? selecao.slice() : null,
      tipo: String(extras.tipo || '').trim() || 'Outro',
      formato: extras.formato || null,
      criadoEm: agora,
      atualizadoEm: agora,
      favorito: false,
      usos: 0,
      ultimoUsoEm: null,
      autor: null,   /* reservado: ainda não há autenticação */
      descricao: String(descricao || '').trim(),
      assuntoPadrao: assuntoPadrao || MODELOS[base].assuntoPadrao,
      padroes: MODELOS[base].padroes,
      rotulos: MODELOS[base].rotulos,
      blocos: MODELOS[base].blocos,
      corpo: MODELOS[base].corpo,
      arquivo: MODELOS[base].arquivo
    };
    definirCampos(SALVOS[id]);
    ORDEM_SALVOS.push(id);
    if (!gravarSalvos()) {
      delete SALVOS[id];
      ORDEM_SALVOS.pop();
      return { ok: false, erro: 'Não foi possível gravar o modelo neste navegador.' };
    }
    return { ok: true, id: id };
  }

  /* Regrava um modelo personalizado já existente (usado por "Atualizar modelo").
     Só os campos informados são alterados. */
  function atualizarModelo(id, mudancas) {
    var m = SALVOS[id];
    if (!m) return { ok: false, erro: 'Modelo personalizado não encontrado.' };
    mudancas = mudancas || {};

    if (typeof mudancas.nome === 'string') {
      var limpo = mudancas.nome.trim();
      if (!limpo) return { ok: false, erro: 'Informe um nome para o modelo.' };
      var repetido = ORDEM_SALVOS.some(function (outro) {
        return outro !== id && SALVOS[outro].nome.toLowerCase() === limpo.toLowerCase();
      }) || ORDEM.some(function (f) { return MODELOS[f].nome.toLowerCase() === limpo.toLowerCase(); });
      if (repetido) return { ok: false, erro: 'Já existe um modelo com este nome.' };
      m.nome = limpo;
    }
    if (typeof mudancas.descricao === 'string') m.descricao = mudancas.descricao.trim();
    if (typeof mudancas.html === 'string') m.html = mudancas.html;
    if (Array.isArray(mudancas.personalizadas)) m.personalizadas = mudancas.personalizadas;
    if (Array.isArray(mudancas.selecao)) m.selecao = mudancas.selecao.slice();
    if (typeof mudancas.assuntoPadrao === 'string') m.assuntoPadrao = mudancas.assuntoPadrao;
    if (typeof mudancas.tipo === 'string' && mudancas.tipo.trim()) m.tipo = mudancas.tipo.trim();
    if (mudancas.formato && typeof mudancas.formato === 'object') m.formato = mudancas.formato;
    m.atualizadoEm = new Date().toISOString();

    if (!gravarSalvos()) return { ok: false, erro: 'Não foi possível gravar o modelo neste navegador.' };
    return { ok: true, id: id };
  }

  function excluirModelo(id) {
    if (!SALVOS[id]) return false;
    delete SALVOS[id];
    ORDEM_SALVOS = ORDEM_SALVOS.filter(function (x) { return x !== id; });
    gravarSalvos();
    return true;
  }

  /* Favorito e contagem de uso não são "edição": não mexem em atualizadoEm. */
  function marcarFavorito(id, favorito) {
    if (!SALVOS[id]) return false;
    SALVOS[id].favorito = !!favorito;
    return gravarSalvos();
  }

  function registrarUso(id) {
    if (!SALVOS[id]) return false;
    SALVOS[id].usos = (SALVOS[id].usos || 0) + 1;
    SALVOS[id].ultimoUsoEm = new Date().toISOString();
    return gravarSalvos();
  }

  /* "Cópia de X", com id novo; o original não é tocado. */
  function duplicarModelo(id) {
    var m = SALVOS[id];
    if (!m) return { ok: false, erro: 'Modelo não encontrado.' };
    var nome = 'Cópia de ' + m.nome, n = 2;
    while (ORDEM_SALVOS.some(function (x) { return SALVOS[x].nome.toLowerCase() === nome.toLowerCase(); })) {
      nome = 'Cópia de ' + m.nome + ' (' + (n++) + ')';
    }
    return salvarModelo(nome, m.base, m.html, JSON.parse(JSON.stringify(m.personalizadas || [])),
                        m.assuntoPadrao, m.descricao, m.selecao, { tipo: m.tipo, formato: m.formato });
  }

  function modelo(id) { return SALVOS[id] || MODELOS[id] || null; }

  lerSalvos();

  /* ---------------------------------------------------------------------------
     ESTRUTURA DE DADOS CANÔNICA (contrato com o extrator do SIGADoc)
     Toda propriedade do catálogo com `chave` entra aqui, de modo que novos
     modelos não exigem alteração no contrato.
     --------------------------------------------------------------------------- */
  function dadosVazios() {
    var d = {};
    Object.keys(PROPRIEDADES).forEach(function (t) {
      if (PROPRIEDADES[t].chave) d[PROPRIEDADES[t].chave] = '';
    });
    return d;
  }

  global.CI_TEMPLATES = {
    PROPRIEDADES: PROPRIEDADES,
    MODELOS: MODELOS,
    ORDEM: ORDEM,
    GRUPOS: GRUPOS,
    modelo: modelo,
    propriedadesDoModelo: propriedadesDoModelo,
    selecaoPadrao: selecaoPadrao,
    camposDoModelo: camposDoModelo,
    catalogo: catalogo,
    tituloDoGrupo: function (g) { return TITULOS_GRUPO[g] || 'Outras propriedades'; },
    ORDEM_GRUPOS: ORDEM_GRUPOS,
    propriedadePorToken: propriedadePorToken,
    propriedadePorChave: propriedadePorChave,
    dadosVazios: dadosVazios,
    validarData: validarData,
    validarDataLivre: validarDataLivre,
    normalizarIdentificador: normalizarIdentificador,
    validarIdentificador: validarIdentificador,
    /* modelos salvos */
    salvos: function () { return ORDEM_SALVOS.map(function (id) { return SALVOS[id]; }); },
    P: P,
    seUsa: seUsa,
    seUsaAlgum: seUsaAlgum,
    registrarGrupo: registrarGrupo,
    marcarFavorito: marcarFavorito,
    registrarUso: registrarUso,
    duplicarModelo: duplicarModelo,
    salvarModelo: salvarModelo,
    atualizarModelo: atualizarModelo,
    excluirModelo: excluirModelo,
    recarregarSalvos: lerSalvos
  };
})(window);
