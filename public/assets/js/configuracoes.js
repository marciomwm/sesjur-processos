/**
 * Tela de Configurações: status da extensão e sincronização da allowlist.
 */
(function () {
    'use strict';

    const GJ = window.GJ;

    document.addEventListener('DOMContentLoaded', async function () {
        const status  = document.getElementById('statusExtensaoConfig');
        const versao  = document.getElementById('versaoExtensao');
        const botao   = document.getElementById('btnSincronizarExtensao');
        const saida   = document.getElementById('statusSincronizacao');

        if (status) {
            const ping = await GJ.extensao.ping();
            if (ping && ping.success) {
                status.innerHTML = '<span class="badge badge--verde">Conectada</span>';
                if (versao) versao.textContent = (ping.data && ping.data.version) || '—';
            } else {
                status.innerHTML = '<span class="badge badge--vermelho">Não detectada</span>';
            }
        }

        if (!botao) return;

        botao.addEventListener('click', async function () {
            saida.textContent = 'Sincronizando…';
            try {
                const cfg = await GJ.fetchJson('api/config-integracao.php');
                const r = await GJ.extensao.sincronizarConfig(cfg.config);
                if (r && r.success) {
                    const faltando = (r.data && r.data.semPermissao) || [];
                    saida.innerHTML = faltando.length
                        ? '<span class="badge badge--ambar">Sincronizado</span> Conceda a permissão para: ' + GJ.esc(faltando.join(', ')) +
                          ' (Detalhes → Opções da extensão).'
                        : '<span class="badge badge--verde">Allowlist sincronizada com a extensão.</span>';
                } else {
                    saida.innerHTML = '<span class="badge badge--vermelho">Falha:</span> ' +
                        GJ.esc(GJ.extracaoUI ? GJ.extracaoUI.mensagemDe(r) : 'extensão não respondeu.');
                }
            } catch (e) {
                saida.textContent = 'Falha: ' + e.message;
            }
        });
    });
})();
