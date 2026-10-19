/**
 * Guia Dados do Pagamento (ficha do processo administrativo):
 *  - Competência acompanha a Data de Execução (AAAA-MM), como o servidor grava;
 *  - Valor é reescrito no formato 1.234,56 ao sair do campo.
 */
(function () {
    'use strict';

    function centavos(texto) {
        const t = String(texto).replace(/R\$|\s| /g, '');
        let m = t.match(/^(\d{1,3}(?:\.\d{3})+)(?:,(\d{1,2}))?$/);
        let inteiro, fracao;
        if (m) {
            inteiro = m[1].replace(/\./g, ''); fracao = m[2] || '';
        } else if ((m = t.match(/^(\d+)(?:[,.](\d{1,2}))?$/))) {
            inteiro = m[1]; fracao = m[2] || '';
        } else {
            return null;
        }
        if (inteiro.replace(/^0+/, '').length > 13) return null;
        return Number(inteiro) * 100 + Number((fracao + '00').slice(0, 2));
    }

    function formatar(c) {
        const inteiro = Math.floor(c / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return inteiro + ',' + String(c % 100).padStart(2, '0');
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-dados-pagamento]').forEach(function (form) {
            const data = form.querySelector('[data-pagamento-data]');
            const competencia = form.querySelector('[data-pagamento-competencia]');
            const valor = form.querySelector('[data-pagamento-valor]');

            if (data && competencia) {
                const atualizar = function () {
                    competencia.textContent = /^\d{4}-\d{2}-\d{2}$/.test(data.value) ? data.value.slice(0, 7) : '—';
                };
                data.addEventListener('input', atualizar);
                data.addEventListener('change', atualizar);
            }
            if (valor) {
                valor.addEventListener('blur', function () {
                    const c = centavos(valor.value);
                    if (c !== null && c > 0) valor.value = formatar(c);
                });
            }
        });
    });
})();
