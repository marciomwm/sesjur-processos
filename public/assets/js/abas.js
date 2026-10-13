/**
 * Guias da ficha do processo (Informações, Providências, Judiciais,
 * Extrajudiciais, Produtos de Saúde, Prioridades, Movimentações, Histórico).
 *
 * Sem JavaScript os painéis ficam todos visíveis e as guias funcionam como
 * links de âncora. Com JavaScript, só a guia ativa aparece; a guia inicial
 * vem do endereço: #providencias abre a guia, e âncoras de itens internos
 * (#acoes, #acao-12, #solicitacao-3, #anotacao-5, #produto-7, #designacao...) abrem a
 * guia que os contém e rolam até eles.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const lista = document.querySelector('[data-abas]');
        if (!lista) return;
        const guias = Array.from(lista.querySelectorAll('[data-aba]'));
        const paineis = guias.map(function (g) { return document.getElementById(g.dataset.aba); });
        if (paineis.some(function (p) { return !p; })) return;

        lista.setAttribute('role', 'tablist');
        guias.forEach(function (guia, i) {
            guia.id = 'aba-' + guia.dataset.aba;
            guia.setAttribute('role', 'tab');
            guia.setAttribute('aria-controls', paineis[i].id);
            paineis[i].setAttribute('role', 'tabpanel');
            paineis[i].setAttribute('aria-labelledby', guia.id);
            paineis[i].tabIndex = -1;
        });

        function ativar(indice, focar) {
            guias.forEach(function (guia, i) {
                const ativa = i === indice;
                guia.classList.toggle('is-ativo', ativa);
                guia.setAttribute('aria-selected', ativa ? 'true' : 'false');
                guia.tabIndex = ativa ? 0 : -1;
                paineis[i].hidden = !ativa;
            });
            if (focar) guias[indice].focus();
        }

        /** Guia que contém o alvo do hash (painel ou item dentro dele); -1 se nenhuma. */
        function guiaDoHash() {
            const id = decodeURIComponent(location.hash.slice(1));
            if (!id) return { indice: -1, alvo: null };
            const alvo = document.getElementById(id);
            if (!alvo) return { indice: -1, alvo: null };
            const indice = paineis.findIndex(function (p) { return p === alvo || p.contains(alvo); });
            return { indice: indice, alvo: paineis[indice] === alvo ? null : alvo };
        }

        function seguirHash() {
            const r = guiaDoHash();
            if (r.indice < 0) return false;
            ativar(r.indice, false);
            // O navegador já tentou rolar enquanto o alvo estava oculto: rola de novo.
            if (r.alvo) {
                requestAnimationFrame(function () { r.alvo.scrollIntoView({ block: 'start' }); });
            }
            return true;
        }

        if (!seguirHash()) ativar(0, false);

        window.addEventListener('hashchange', seguirHash);

        guias.forEach(function (guia, i) {
            guia.addEventListener('click', function (ev) {
                ev.preventDefault();
                ativar(i, false);
                // Guarda a guia no endereço (recarregar mantém), sem rolar a página.
                history.replaceState(null, '', '#' + paineis[i].id);
            });
            guia.addEventListener('keydown', function (ev) {
                let alvo = null;
                if (ev.key === 'ArrowRight') alvo = (i + 1) % guias.length;
                else if (ev.key === 'ArrowLeft') alvo = (i - 1 + guias.length) % guias.length;
                else if (ev.key === 'Home') alvo = 0;
                else if (ev.key === 'End') alvo = guias.length - 1;
                if (alvo === null) return;
                ev.preventDefault();
                ativar(alvo, true);
                history.replaceState(null, '', '#' + paineis[alvo].id);
            });
        });
    });
})();
