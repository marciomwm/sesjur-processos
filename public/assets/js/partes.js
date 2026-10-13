/**
 * Partes processuais no formulário do Processo Judicial, agrupadas como as
 * abas do PJe: polo ativo, polo passivo e outros interessados.
 *
 * Cada grupo tem seu corpo de tabela ([data-partes-corpo="POLO"]); o polo de
 * cada linha vai num campo oculto. Os índices partes[i] são globais, para
 * que as linhas dos três grupos cheguem ao servidor como uma lista única.
 */
(function () {
    'use strict';

    const GJ = window.GJ;

    /** Grupo que exibe cada polo (OUTRO, de cadastros antigos, fica com os interessados). */
    const GRUPO_DO_POLO = { ATIVO: 'ATIVO', PASSIVO: 'PASSIVO', TERCEIRO: 'TERCEIRO', OUTRO: 'TERCEIRO' };

    document.addEventListener('DOMContentLoaded', function () {
        const modelo = document.getElementById('modeloParte');
        const corpos = document.querySelectorAll('[data-partes-corpo]');
        if (!modelo || corpos.length === 0) return;

        const corpoDo = function (polo) {
            return document.querySelector('[data-partes-corpo="' + (GRUPO_DO_POLO[polo] || 'TERCEIRO') + '"]');
        };

        let indice = document.querySelectorAll('[data-partes-corpo] tr').length;

        function novaLinha(polo, dados) {
            dados = dados || {};
            const html = modelo.innerHTML
                .replace(/__INDICE__/g, String(indice++))
                .replace(/__POLO__/g, polo);
            const tmp = document.createElement('tbody');
            tmp.innerHTML = html.trim();
            const tr = tmp.firstElementChild;

            ['nome', 'documento', 'tipo', 'representacao'].forEach(function (campo) {
                const el = tr.querySelector('[data-campo="' + campo + '"]');
                if (el && dados[campo] != null && dados[campo] !== '') {
                    el.value = dados[campo];
                    el.classList.add('is-preenchido-extracao');
                }
            });

            corpoDo(polo).appendChild(tr);
            atualizar();
            return tr;
        }

        document.querySelectorAll('[data-adicionar-parte]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                const tr = novaLinha(botao.getAttribute('data-adicionar-parte'));
                const primeiro = tr.querySelector('input[type="text"]');
                if (primeiro) primeiro.focus();
            });
        });

        document.addEventListener('click', function (ev) {
            if (ev.target.matches('[data-remover-parte]')) {
                ev.target.closest('tr').remove();
                atualizar();
            }
        });

        /** Contadores por polo, aviso de grupo vazio e contador da navegação. */
        function atualizar() {
            let total = 0;
            document.querySelectorAll('[data-polo-bloco]').forEach(function (bloco) {
                const n = bloco.querySelectorAll('[data-partes-corpo] tr').length;
                total += n;
                const contador = bloco.querySelector('[data-contador-polo]');
                if (contador) contador.textContent = String(n);
                const vazio = bloco.querySelector('[data-partes-vazio]');
                if (vazio) vazio.hidden = n > 0;
            });
            if (GJ.atualizarContador) GJ.atualizarContador('partes', total);
        }

        /** Substitui todas as partes pelas extraídas do PJe (após revisão humana). */
        GJ.definirPartes = function (partes) {
            if (!Array.isArray(partes) || partes.length === 0) return 0;
            corpos.forEach(function (c) { c.innerHTML = ''; });
            indice = 0;
            partes.forEach(function (p) { novaLinha(GRUPO_DO_POLO[p.polo] ? p.polo : 'OUTRO', p); });
            atualizar();
            return partes.length;
        };

        GJ.adicionarParte = novaLinha;
        atualizar();
    });
})();
