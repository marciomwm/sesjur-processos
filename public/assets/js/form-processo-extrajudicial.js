/**
 * Formulário de Processo Extrajudicial: o seletor de órgão mostra só os
 * órgãos da instituição escolhida, e "Outro órgão" abre o campo para
 * cadastrar um novo. As mesmas regras valem no servidor.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const instituicao = document.querySelector('[data-instituicao-extrajudicial]');
        const orgao = document.querySelector('[data-orgao-extrajudicial]');
        const novo = document.querySelector('[data-orgao-novo]');
        if (!instituicao || !orgao) return;

        function filtrar() {
            const escolhida = instituicao.value;
            Array.from(orgao.options).forEach(function (opcao) {
                if (!opcao.dataset.instituicao) return; // "não informado" e "outro órgão"
                const visivel = opcao.dataset.instituicao === escolhida;
                opcao.hidden = !visivel;
                opcao.disabled = !visivel;
                if (!visivel && opcao.selected) orgao.value = '';
            });
        }

        function alternarNovo() {
            if (!novo) return;
            const aberto = orgao.value === '__novo';
            novo.hidden = !aberto;
            if (aberto) novo.querySelector('input').focus();
        }

        instituicao.addEventListener('change', filtrar);
        orgao.addEventListener('change', alternarNovo);
        filtrar();
    });
})();
