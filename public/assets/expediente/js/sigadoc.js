/* =============================================================================
   sigadoc.js — Camada de integração com o SIGADOC
   -----------------------------------------------------------------------------
   Não existe API do SIGADOC disponível. Este arquivo define o CONTRATO que a
   interface usa; quando houver integração, basta registrar um conector com
   `configurar({ enviar, buscarDados })` — nada mais precisa mudar.

   O que funciona hoje, de verdade, é `copiarParaSigadoc()`: copia o expediente
   em HTML simplificado para ser colado no editor de expedientes do SIGADOC.
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};

  var MSG_NAO_CONFIGURADO = 'Integração com SIGADOC ainda não configurada.';
  var conector = null;

  function configurar(novo) { conector = novo || null; }
  function ativo() { return !!conector; }

  function naoConfigurado() {
    return Promise.resolve({ ok: false, codigo: 'NAO_CONFIGURADO', mensagem: MSG_NAO_CONFIGURADO });
  }

  /* expediente = { protocolo, titulo, html, texto } */
  function sendToSigadoc(expediente) {
    if (!conector || !conector.enviar) return naoConfigurado();
    return Promise.resolve(conector.enviar(expediente));
  }

  /* Futuro "Buscar dados no SIGADOC": devolve { ok, dados } com as chaves da
     estrutura canônica (ver CI_TEMPLATES.dadosVazios). */
  function buscarDados(protocolo) {
    if (!conector || !conector.buscarDados) return naoConfigurado();
    return Promise.resolve(conector.buscarDados(protocolo));
  }

  function copiarComTextarea(texto) {
    var ta = document.createElement('textarea');
    ta.value = texto;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    ta.remove();
    return ok;
  }

  function copiarTexto(texto) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(texto).then(
        function () { return true; },
        function () { return copiarComTextarea(texto); }
      );
    }
    return Promise.resolve(copiarComTextarea(texto));
  }

  /* Sem a API de área de transferência: copia uma seleção do HTML renderizado,
     o que também preserva a formatação. */
  function copiarPorSelecao(html) {
    var caixa = document.createElement('div');
    caixa.innerHTML = html;
    caixa.style.cssText = 'position:fixed;left:-9999px;top:0;font:12pt "Times New Roman",serif;color:#000;background:#fff';
    document.body.appendChild(caixa);
    var sel = global.getSelection();
    var range = document.createRange();
    range.selectNodeContents(caixa);
    sel.removeAllRanges();
    sel.addRange(range);
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    sel.removeAllRanges();
    caixa.remove();
    return ok;
  }

  /* Copia HTML formatado; se o navegador não permitir, cai para texto simples.
     Devolve { ok, modo: 'html' | 'texto' }. */
  function copiarParaSigadoc(html, texto) {
    function soTexto() {
      if (copiarPorSelecao(html)) return Promise.resolve({ ok: true, modo: 'html' });
      return copiarTexto(texto).then(function (ok) { return { ok: ok, modo: 'texto' }; });
    }
    if (global.ClipboardItem && navigator.clipboard && navigator.clipboard.write) {
      var item = new ClipboardItem({
        'text/html': new Blob([html], { type: 'text/html' }),
        'text/plain': new Blob([texto], { type: 'text/plain' })
      });
      return navigator.clipboard.write([item]).then(
        function () { return { ok: true, modo: 'html' }; },
        soTexto
      );
    }
    return soTexto();
  }

  CE.sigadoc = {
    MSG_NAO_CONFIGURADO: MSG_NAO_CONFIGURADO,
    configurar: configurar,
    ativo: ativo,
    sendToSigadoc: sendToSigadoc,
    buscarDados: buscarDados,
    copiarParaSigadoc: copiarParaSigadoc,
    copiarTexto: copiarTexto
  };
})(window);
