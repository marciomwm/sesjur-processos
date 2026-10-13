/* =============================================================================
   documento.js — Resolução de um documento: TEMPLATE + DADOS → HTML
   -----------------------------------------------------------------------------
   Ponto único por onde passam o documento individual e cada documento de um
   lote. Não existem dois motores:

                         MODELO (template com {{TOKENS}})
                                      │
                           CE.documento.resolver()
                          ┌───────────┴───────────┐
                 1 conjunto de dados         N conjuntos de dados
                    1 documento                 N documentos

   d = {
     modeloId   modelo de origem (define padrões como o assunto)
     fonte      HTML com {{TOKENS}}
     dados      valores das propriedades do catálogo, por chave
     proprias   propriedades do assessor: [{ token, label, valor, obrigatorio }]
     selecao    tokens que fazem parte do documento
   }
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};
  var E = global.CI_ENGINE;

  function contexto(d) { return E.contexto(d.selecao || []); }

  /* Mapa token → propriedade, com valores e obrigatoriedade. */
  function propriedades(d) {
    return E.montarPropriedades(d.modeloId, d.dados, d.proprias, { contexto: contexto(d) });
  }

  /* opcoes: { marcarVariaveis, marcarPendentes } — as mesmas de E.substituir. */
  function resolver(d, opcoes) {
    var mapa = propriedades(d);
    var sub = E.substituir(d.fonte, mapa, opcoes || {});
    return { fonte: d.fonte, propriedades: mapa, html: sub.html, pendentes: sub.pendentes, usados: sub.usados };
  }

  /* Documento final: valores resolvidos, sem marcações, HTML simplificado. */
  function htmlFinal(d) {
    return CE.exportar.sanitizeForSigadoc(resolver(d, { marcarPendentes: false }).html);
  }

  CE.documento = {
    contexto: contexto,
    propriedades: propriedades,
    resolver: resolver,
    htmlFinal: htmlFinal
  };
})(window);
