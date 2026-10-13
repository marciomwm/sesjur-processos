/**
 * Modal de Produto de Saúde: sugere procedimentos SIGTAP já cadastrados
 * enquanto se digita o código ou a descrição e, ao escolher um, preenche a
 * descrição oficial e a estrutura (grupo, subgrupo, forma de organização).
 * Código novo continua aceito: o servidor o cadastra com a descrição digitada.
 */
(function () {
    'use strict';

    const GJ = window.GJ;

    document.addEventListener('DOMContentLoaded', function () {
        const codigo = document.querySelector('[data-sigtap-codigo]');
        if (!codigo || !codigo.list) return;
        const form = codigo.form;
        const lista = codigo.list;
        let itens = [];
        let requisicao = 0;

        function aplicar(item) {
            ['nome', 'grupo_nome', 'subgrupo_nome', 'forma_organizacao_nome'].forEach(function (campo) {
                const alvo = form.elements['sigtap_' + campo];
                if (alvo && item[campo]) alvo.value = item[campo];
            });
        }

        codigo.addEventListener('input', GJ.debounce(async function () {
            const termo = codigo.value.trim();
            const escolhido = itens.find(function (i) { return i.codigo === termo; });
            if (escolhido) { aplicar(escolhido); return; }
            if (termo.length < 2) return;
            const minha = ++requisicao;
            try {
                const r = await GJ.fetchJson('api/pesquisar-sigtap.php?termo=' + encodeURIComponent(termo));
                if (minha !== requisicao || !r.success) return;
                itens = r.itens || [];
                lista.innerHTML = itens.map(function (i) {
                    return '<option value="' + GJ.esc(i.codigo) + '">' + GJ.esc(i.nome) + '</option>';
                }).join('');
            } catch (e) { /* sugestão é opcional */ }
        }, 250));

        codigo.addEventListener('change', function () {
            const escolhido = itens.find(function (i) { return i.codigo === codigo.value.trim(); });
            if (escolhido) aplicar(escolhido);
        });

        // A estrutura fica recolhida até ser necessária (edição com dados ou erro nela).
        form.addEventListener('modal:aberto', function () {
            const detalhes = form.querySelector('[data-sigtap-estrutura]');
            if (!detalhes) return;
            detalhes.open = ['grupo_nome', 'subgrupo_nome', 'forma_organizacao_nome'].some(function (c) {
                const campo = form.elements['sigtap_' + c];
                return campo && campo.value !== '';
            });
        });
    });
})();
