/**
 * Formulário de Ação: sugere protocolos cadastrados enquanto se digita
 * (datalist alimentado pela mesma API do widget de vínculos).
 */
(function () {
    'use strict';

    const GJ = window.GJ;

    document.addEventListener('DOMContentLoaded', function () {
        const campo = document.querySelector('[data-autocompletar-protocolo]');
        if (!campo || !campo.list) return;
        const lista = campo.list;
        let requisicao = 0;

        campo.addEventListener('input', GJ.debounce(async function () {
            const termo = campo.value.trim();
            if (termo.length < 3) return;
            const minha = ++requisicao;
            try {
                const r = await GJ.fetchJson('api/pesquisar-processos-administrativos.php?termo=' + encodeURIComponent(termo));
                if (minha !== requisicao || !r.success) return;
                lista.innerHTML = (r.itens || []).map(function (item) {
                    return '<option value="' + GJ.esc(item.rotulo) + '">' + GJ.esc(item.detalhe || '') + '</option>';
                }).join('');
            } catch (e) { /* sugestão é opcional */ }
        }, 250));
    });
})();
