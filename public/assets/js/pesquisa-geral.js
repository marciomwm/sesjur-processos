/**
 * Pesquisa geral da barra superior: sugestões agrupadas enquanto se digita.
 * Enter sem sugestão escolhida envia o formulário (pesquisa.php), que abre
 * direto a ficha quando o termo é um protocolo ou número CNJ exato.
 * Atalho: "/" coloca o cursor no campo.
 */
(function () {
    'use strict';

    const GJ = window.GJ;
    const MINIMO = 2;

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.querySelector('[data-pesquisa-geral]');
        if (!form) return;
        const campo = form.querySelector('input[name="q"]');
        const caixa = form.querySelector('.pesquisa-geral__sugestoes');
        let ativo = -1;
        let ultimoTermo = '';
        let requisicao = 0;

        function links() {
            return Array.prototype.slice.call(caixa.querySelectorAll('a'));
        }

        function fechar() {
            caixa.hidden = true;
            campo.setAttribute('aria-expanded', 'false');
            ativo = -1;
        }

        function marcar(indice) {
            const itens = links();
            if (!itens.length) return;
            ativo = (indice + itens.length) % itens.length;
            itens.forEach(function (a, i) {
                a.classList.toggle('is-ativo', i === ativo);
                a.setAttribute('aria-selected', i === ativo ? 'true' : 'false');
            });
            itens[ativo].scrollIntoView({ block: 'nearest' });
        }

        function renderizar(resposta, termo) {
            const esc = GJ.esc;
            const grupos = (resposta && resposta.grupos) || [];
            if (!grupos.length) {
                caixa.innerHTML = '<p class="pesquisa-geral__vazio">Nenhum resultado para “' + esc(termo) + '”.</p>';
            } else {
                caixa.innerHTML = grupos.map(function (g) {
                    return '<div class="pesquisa-geral__grupo">' +
                        '<p class="pesquisa-geral__titulo">' + esc(g.titulo) + ' <span>' + g.total + '</span></p>' +
                        g.itens.map(function (item) {
                            return '<a role="option" href="' + esc(item.url) + '">' +
                                '<strong>' + esc(item.rotulo) + '</strong>' +
                                (item.marca ? ' <em>' + esc(item.marca) + '</em>' : '') +
                                (item.detalhe ? '<span>' + esc(item.detalhe) + '</span>' : '') +
                                '</a>';
                        }).join('') +
                        '</div>';
                }).join('') +
                '<a class="pesquisa-geral__todos" role="option" href="' + esc(resposta.todosUrl) + '">Ver todos os resultados de “' + esc(termo) + '” →</a>';
            }
            caixa.hidden = false;
            campo.setAttribute('aria-expanded', 'true');
            ativo = -1;
        }

        const buscar = GJ.debounce(async function () {
            const termo = campo.value.trim();
            if (termo.length < MINIMO) { fechar(); return; }
            if (termo === ultimoTermo && !caixa.hidden) return;
            ultimoTermo = termo;
            const minha = ++requisicao;
            try {
                const resposta = await GJ.fetchJson('api/pesquisa-geral.php?q=' + encodeURIComponent(termo));
                if (minha !== requisicao) return; // chegou uma resposta mais nova
                if (resposta.success) renderizar(resposta, termo);
            } catch (e) {
                fechar(); // a pesquisa pela página continua disponível
            }
        }, 250);

        campo.addEventListener('input', buscar);
        campo.addEventListener('focus', function () {
            if (campo.value.trim().length >= MINIMO && caixa.innerHTML) {
                caixa.hidden = false;
                campo.setAttribute('aria-expanded', 'true');
            }
        });

        campo.addEventListener('keydown', function (ev) {
            if (ev.key === 'ArrowDown') { ev.preventDefault(); if (caixa.hidden) buscar(); else marcar(ativo + 1); }
            else if (ev.key === 'ArrowUp') { ev.preventDefault(); marcar(ativo - 1); }
            else if (ev.key === 'Escape') { fechar(); }
            else if (ev.key === 'Enter' && ativo >= 0 && !caixa.hidden) {
                ev.preventDefault();
                window.location.href = links()[ativo].href;
            }
        });

        document.addEventListener('click', function (ev) {
            if (!form.contains(ev.target)) fechar();
        });

        // "/" fora de campos de texto leva à pesquisa.
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== '/' || ev.ctrlKey || ev.metaKey || ev.altKey) return;
            const alvo = ev.target;
            if (alvo && (alvo.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(alvo.tagName))) return;
            ev.preventDefault();
            campo.focus();
            campo.select();
        });

        form.addEventListener('submit', function (ev) {
            if (campo.value.trim().length < MINIMO) ev.preventDefault();
        });
    });
})();
