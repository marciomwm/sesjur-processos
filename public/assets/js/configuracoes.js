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

        const distribuida = document.getElementById('versaoDistribuida');
        const situacao = document.getElementById('situacaoVersao');

        if (status) {
            const ping = await GJ.extensao.ping();
            if (ping && ping.success) {
                const instalada = (ping.data && ping.data.version) || '';
                status.innerHTML = '<span class="badge badge--verde">Conectada</span>';
                if (versao) versao.textContent = instalada || '—';
                if (situacao && distribuida && distribuida.dataset.versao && instalada) {
                    const cmp = compararVersoes(instalada, distribuida.dataset.versao);
                    situacao.innerHTML = cmp >= 0
                        ? '<span class="badge badge--verde">Atualizada</span>'
                        : '<span class="badge badge--ambar">Desatualizada</span> Recarregue a extensão em chrome://extensions.';
                }
            } else {
                status.innerHTML = '<span class="badge badge--vermelho">Não detectada</span>';
                if (situacao) situacao.textContent = 'Instale ou ative a extensão neste navegador.';
            }
        }

        /** -1, 0 ou 1 comparando "1.4.0" com "1.10.2" numericamente. */
        function compararVersoes(a, b) {
            const pa = String(a).split('.').map(Number);
            const pb = String(b).split('.').map(Number);
            for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
                const d = (pa[i] || 0) - (pb[i] || 0);
                if (d !== 0) return d > 0 ? 1 : -1;
            }
            return 0;
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
