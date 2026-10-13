/**
 * Widget de vinculação N:N: autocomplete + lista de processos vinculados.
 * Funciona nos dois sentidos (administrativo -> judicial/extrajudicial e
 * judicial/extrajudicial -> administrativo).
 */
(function () {
    'use strict';

    const GJ = window.GJ;

    function inicializar(raiz) {
        const tipo    = raiz.dataset.tipo;               // 'judicial' | 'administrativo' | 'extrajudicial'
        const campo   = raiz.dataset.campo;              // nome do input hidden
        const busca   = raiz.querySelector('[data-vinculo-busca]');
        const painel  = raiz.querySelector('[data-vinculo-resultados]');
        const lista   = raiz.querySelector('[data-vinculo-lista]');
        const vazio   = raiz.querySelector('[data-vinculo-vazio]');
        const endpoint = {
            judicial: 'api/pesquisar-processos-judiciais.php',
            extrajudicial: 'api/pesquisar-processos-extrajudiciais.php'
        }[tipo] || 'api/pesquisar-processos-administrativos.php';
        // Atalho de cadastro quando a pesquisa nao encontra nada.
        const cadastroNovo = {
            judicial: { rotulo: 'Processo Judicial', pagina: 'novo-processo-judicial.php', parametro: 'numero_cnj' },
            extrajudicial: { rotulo: 'Processo Extrajudicial', pagina: 'novo-processo-extrajudicial.php', parametro: 'protocolo' }
        }[tipo] || null;

        let indiceAtivo = -1;

        function atualizarVazio() {
            vazio.hidden = lista.children.length > 0;
        }

        function jaSelecionado(id) {
            return !!lista.querySelector('[data-id="' + CSS.escape(String(id)) + '"]');
        }

        function adicionar(item) {
            if (jaSelecionado(item.id)) {
                fecharPainel();
                busca.value = '';
                return;
            }
            const li = document.createElement('li');
            li.className = 'vinculos__item';
            li.dataset.id = item.id;
            li.innerHTML =
                '<input type="hidden" name="' + GJ.esc(campo) + '" value="' + GJ.esc(item.id) + '">' +
                '<span class="vinculos__rotulo mono">' + GJ.esc(item.rotulo) + '</span>' +
                '<span class="vinculos__detalhe">' + GJ.esc(item.detalhe || '') + '</span>' +
                '<button type="button" class="vinculos__remover" data-vinculo-remover aria-label="Remover vínculo">×</button>';
            lista.appendChild(li);
            atualizarVazio();
            busca.value = '';
            fecharPainel();
        }

        function fecharPainel() {
            painel.hidden = true;
            painel.innerHTML = '';
            indiceAtivo = -1;
        }

        function renderizar(itens, termo) {
            painel.innerHTML = '';

            if (itens.length === 0) {
                const vazioEl = document.createElement('div');
                vazioEl.className = 'vinculos__resultado vinculos__resultado--vazio';
                vazioEl.textContent = 'Nenhum processo encontrado para "' + termo + '".';
                painel.appendChild(vazioEl);

                if (cadastroNovo) {
                    const acao = document.createElement('div');
                    acao.className = 'vinculos__resultado vinculos__resultado--acao';
                    acao.textContent = '＋ Cadastrar novo ' + cadastroNovo.rotulo;
                    acao.addEventListener('mousedown', function (ev) {
                        ev.preventDefault();
                        const url = GJ.url(cadastroNovo.pagina) + '?' + cadastroNovo.parametro + '=' + encodeURIComponent(termo);
                        window.open(url, '_blank', 'noopener');
                    });
                    painel.appendChild(acao);
                }
            } else {
                itens.forEach(function (item, i) {
                    const div = document.createElement('div');
                    div.className = 'vinculos__resultado';
                    div.dataset.indice = i;
                    div.innerHTML = '<strong>' + GJ.esc(item.rotulo) + '</strong><span>' + GJ.esc(item.detalhe || '') + '</span>';
                    div.addEventListener('mousedown', function (ev) { ev.preventDefault(); adicionar(item); });
                    painel.appendChild(div);
                });
            }
            painel.hidden = false;
        }

        const pesquisar = GJ.debounce(async function () {
            const termo = busca.value.trim();
            if (termo.length < 3) { fecharPainel(); return; }
            try {
                const resposta = await GJ.fetchJson(endpoint + '?termo=' + encodeURIComponent(termo));
                renderizar(resposta.itens || [], termo);
            } catch (e) {
                fecharPainel();
            }
        }, 220);

        busca.addEventListener('input', pesquisar);
        busca.addEventListener('focus', function () { if (busca.value.trim().length >= 3) pesquisar(); });
        busca.addEventListener('blur', function () { setTimeout(fecharPainel, 150); });

        busca.addEventListener('keydown', function (ev) {
            const opcoes = painel.querySelectorAll('.vinculos__resultado:not(.vinculos__resultado--vazio)');
            if (painel.hidden || opcoes.length === 0) {
                if (ev.key === 'Enter') ev.preventDefault();
                return;
            }
            if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                ev.preventDefault();
                indiceAtivo += (ev.key === 'ArrowDown' ? 1 : -1);
                if (indiceAtivo < 0) indiceAtivo = opcoes.length - 1;
                if (indiceAtivo >= opcoes.length) indiceAtivo = 0;
                opcoes.forEach(function (o, i) { o.classList.toggle('is-ativo', i === indiceAtivo); });
            } else if (ev.key === 'Enter') {
                ev.preventDefault();
                const alvo = opcoes[indiceAtivo >= 0 ? indiceAtivo : 0];
                if (alvo) alvo.dispatchEvent(new MouseEvent('mousedown'));
            } else if (ev.key === 'Escape') {
                fecharPainel();
            }
        });

        lista.addEventListener('click', function (ev) {
            if (ev.target.matches('[data-vinculo-remover]')) {
                ev.target.closest('.vinculos__item').remove();
                atualizarVazio();
            }
        });

        // Permite que outros módulos adicionem vínculos programaticamente.
        raiz.adicionarVinculo = adicionar;
        atualizarVazio();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-vinculos]').forEach(inicializar);
    });
})();
