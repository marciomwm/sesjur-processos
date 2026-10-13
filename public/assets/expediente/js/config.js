/* =============================================================================
   config.js — Configuração entregue pelo SESJUR
   -----------------------------------------------------------------------------
   A página public/expediente.php escreve, em <script id="sesjurExpediente">,
   o catálogo de propriedades (App\Services\ExpedienteService), os endereços da
   aplicação e o processo com que o gerador foi aberto. Este arquivo é o
   primeiro a carregar: templates.js monta o catálogo a partir dele.

   SESJUR_EXPEDIENTE = { catalogo, grupos, baseUrl, apiUrl, processoId }
   ============================================================================= */
(function (global) {
  'use strict';

  var cfg = {};
  try {
    cfg = JSON.parse(document.getElementById('sesjurExpediente').textContent) || {};
  } catch (e) { cfg = {}; }

  global.SESJUR_EXPEDIENTE = cfg;
})(window);
