/**
 * Formulário de Unidade Administrativa:
 *  - "Adicionar novo nível" mostra o campo de texto do nível;
 *  - a unidade superior lista só as unidades da instituição escolhida;
 *  - linhas do lotacionograma podem ser adicionadas e removidas.
 * Sem JavaScript o formulário funciona com o campo de nível visível,
 * todas as unidades superiores e três linhas em branco.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.querySelector('[data-form-unidade]');
        if (!form) return;

        // Nível novo
        const nivel = form.querySelector('[data-nivel]');
        const nivelNovo = form.querySelector('[data-nivel-novo]');
        if (nivel && nivelNovo) {
            const campo = nivelNovo.querySelector('input');
            const atualizarNivel = function (focar) {
                const novo = nivel.value === 'novo';
                nivelNovo.hidden = !novo;
                if (!novo) campo.value = '';
                else if (focar) campo.focus();
            };
            nivel.addEventListener('change', function () { atualizarNivel(true); });
            atualizarNivel(false);
        }

        // Unidade superior da mesma instituição
        const instituicao = form.querySelector('[data-instituicao]');
        const superior = form.querySelector('[data-superior]');
        if (instituicao && superior) {
            const filtrar = function () {
                const escolhida = instituicao.value;
                Array.from(superior.options).forEach(function (op) {
                    if (!op.value) return;
                    const visivel = escolhida !== '' && op.dataset.instituicao === escolhida;
                    op.hidden = !visivel;
                    op.disabled = !visivel;
                    if (!visivel && op.selected) superior.value = '';
                });
            };
            instituicao.addEventListener('change', filtrar);
            filtrar();
        }

        // Lotacionograma
        const corpo = form.querySelector('[data-servidores]');
        const adicionar = form.querySelector('[data-servidor-adicionar]');
        if (corpo && adicionar) {
            let proximo = corpo.querySelectorAll('[data-servidor]').length;
            const limpar = function (linha) {
                linha.querySelectorAll('input').forEach(function (i) { i.value = ''; });
            };
            adicionar.hidden = false;
            corpo.querySelectorAll('[data-servidor-remover]').forEach(function (b) { b.hidden = false; });

            adicionar.addEventListener('click', function () {
                const modelo = corpo.querySelector('[data-servidor]');
                const nova = modelo.cloneNode(true);
                nova.querySelectorAll('input').forEach(function (i) {
                    i.name = i.name.replace(/servidores\[\d+\]/, 'servidores[' + proximo + ']');
                });
                proximo++;
                limpar(nova);
                corpo.appendChild(nova);
                nova.querySelector('input').focus();
            });

            corpo.addEventListener('click', function (ev) {
                const botao = ev.target.closest('[data-servidor-remover]');
                if (!botao) return;
                const linha = botao.closest('[data-servidor]');
                if (corpo.querySelectorAll('[data-servidor]').length > 1) linha.remove();
                else limpar(linha);
            });
        }
    });
})();
