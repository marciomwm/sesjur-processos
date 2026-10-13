/* =============================================================================
   email.js — Camada de serviço para envio do expediente por e-mail
   -----------------------------------------------------------------------------
   Ainda não há backend de envio. `sendExpedienteByEmail()` valida a mensagem e,
   sem transporte configurado, devolve um resultado SIMULADO — nenhuma mensagem
   sai de fato. Para ligar um backend:

     CE.email.configurar(function (mensagem) {
       return fetch('/api/email', { method: 'POST', body: ... })
         .then(function () { return { ok: true }; });
     });
   ============================================================================= */
(function (global) {
  'use strict';

  var CE = global.CE = global.CE || {};

  var transporte = null;

  function configurar(fn) { transporte = typeof fn === 'function' ? fn : null; }
  function ativo() { return !!transporte; }

  function destinatarios(para) {
    return String(para || '').split(/[;,]/).map(function (s) { return s.trim(); }).filter(Boolean);
  }

  function validar(mensagem) {
    var lista = destinatarios(mensagem.para);
    if (!lista.length) return 'Informe ao menos um destinatário.';
    var invalido = lista.filter(function (e) { return !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e); })[0];
    if (invalido) return 'Endereço de e-mail inválido: ' + invalido;
    if (!String(mensagem.assunto || '').trim()) return 'Informe o assunto.';
    return null;
  }

  /* mensagem = { para, assunto, mensagem, anexos: [{ nome, blob }] }
     Devolve { ok, simulado, mensagem }. */
  function sendExpedienteByEmail(mensagem) {
    var erro = validar(mensagem);
    if (erro) return Promise.resolve({ ok: false, mensagem: erro });

    var pacote = {
      para: destinatarios(mensagem.para),
      assunto: mensagem.assunto.trim(),
      mensagem: mensagem.mensagem || '',
      anexos: mensagem.anexos || []
    };

    if (transporte) return Promise.resolve(transporte(pacote));

    return new Promise(function (resolve) {
      setTimeout(function () {
        resolve({
          ok: true,
          simulado: true,
          mensagem: 'Envio simulado: o serviço de e-mail ainda não está configurado. Nenhuma mensagem foi enviada.'
        });
      }, 300);
    });
  }

  CE.email = {
    configurar: configurar,
    ativo: ativo,
    validar: validar,
    sendExpedienteByEmail: sendExpedienteByEmail
  };
})(window);
