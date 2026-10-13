/**
 * Utilitários gerais da interface.
 */
(function () {
    'use strict';

    const GJ = window.GJ || (window.GJ = {});

    GJ.baseUrl = (document.querySelector('meta[name="base-url"]') || {}).content || '';
    GJ.csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    GJ.apiUrl = (document.querySelector('meta[name="api-url"]') || {}).content || '';

    /** Monta uma URL da aplicação. Caminhos iniciados por "api/" vão para a pasta api/. */
    GJ.url = function (caminho) {
        caminho = String(caminho).replace(/^\//, '');
        if (caminho.indexOf('api/') === 0) {
            return GJ.apiUrl.replace(/\/$/, '') + '/' + caminho.slice(4);
        }
        return GJ.baseUrl.replace(/\/$/, '') + '/' + caminho;
    };

    /** POST/GET JSON com token CSRF. */
    GJ.fetchJson = async function (caminho, opcoes) {
        opcoes = opcoes || {};
        const cabecalhos = Object.assign({
            'Accept': 'application/json',
            'X-CSRF-Token': GJ.csrf
        }, opcoes.headers || {});

        if (opcoes.body && !(opcoes.body instanceof FormData)) {
            cabecalhos['Content-Type'] = 'application/json';
        }

        const resposta = await fetch(GJ.url(caminho), Object.assign({ credentials: 'same-origin' }, opcoes, { headers: cabecalhos }));
        const texto = await resposta.text();
        let dados;
        try {
            dados = texto ? JSON.parse(texto) : {};
        } catch (e) {
            throw new Error('Resposta inválida do servidor (' + resposta.status + ').');
        }
        if (!resposta.ok && dados.success !== false) {
            throw new Error((dados.error && dados.error.message) || ('Erro ' + resposta.status));
        }
        return dados;
    };

    /** Escapa texto para inserção segura em HTML. */
    GJ.esc = function (valor) {
        const div = document.createElement('div');
        div.textContent = valor == null ? '' : String(valor);
        return div.innerHTML;
    };

    /** debounce simples. */
    GJ.debounce = function (fn, ms) {
        let t = null;
        return function () {
            const args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, ms || 250);
        };
    };

    /** Overlay de carregamento. */
    GJ.overlay = {
        mostrar: function (texto) {
            const el = document.getElementById('overlay');
            if (!el) return;
            document.getElementById('overlayTexto').textContent = texto || 'Processando…';
            el.hidden = false;
        },
        ocultar: function () {
            const el = document.getElementById('overlay');
            if (el) el.hidden = true;
        }
    };

    /** Converte "2026-09-17" -> "17/09/2026". */
    GJ.dataBr = function (iso) {
        if (!iso) return '';
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
        return m ? m[3] + '/' + m[2] + '/' + m[1] : iso;
    };

    document.addEventListener('DOMContentLoaded', function () {
        // Menu lateral no modo compacto.
        const btnMenu = document.getElementById('btnMenu');
        const sidebar = document.getElementById('sidebar');
        if (btnMenu && sidebar) {
            btnMenu.addEventListener('click', function () { sidebar.classList.toggle('is-aberta'); });
        }

        // Densidade da interface (compacta/confortável): o servidor lê o cookie
        // e já renderiza <html data-density>; aqui só trocamos e gravamos.
        document.querySelectorAll('[data-densidade]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                const modo = botao.dataset.densidade === 'comfortable' ? 'comfortable' : 'compact';
                document.documentElement.dataset.density = modo;
                document.cookie = 'sesjur_densidade=' + modo + '; path=/; max-age=31536000; SameSite=Lax';
                document.querySelectorAll('[data-densidade]').forEach(function (b) {
                    b.setAttribute('aria-pressed', String(b.dataset.densidade === modo));
                });
            });
        });

        // Fechar alertas.
        document.addEventListener('click', function (ev) {
            if (ev.target.classList.contains('alert__fechar')) {
                ev.target.closest('.alert').remove();
            }
        });

        // Confirmação antes de exclusões.
        document.querySelectorAll('form[data-confirmar]').forEach(function (form) {
            form.addEventListener('submit', function (ev) {
                if (!window.confirm(form.dataset.confirmar)) {
                    ev.preventDefault();
                }
            });
        });

        // Máscara leve para número CNJ.
        document.querySelectorAll('[data-mascara="cnj"]').forEach(function (input) {
            input.addEventListener('input', function () {
                const d = input.value.replace(/\D/g, '').slice(0, 20);
                let saida = d;
                if (d.length > 7)  saida = d.slice(0, 7) + '-' + d.slice(7);
                if (d.length > 9)  saida = d.slice(0, 7) + '-' + d.slice(7, 9) + '.' + d.slice(9);
                if (d.length > 13) saida = d.slice(0, 7) + '-' + d.slice(7, 9) + '.' + d.slice(9, 13) + '.' + d.slice(13);
                if (d.length > 14) saida = d.slice(0, 7) + '-' + d.slice(7, 9) + '.' + d.slice(9, 13) + '.' + d.slice(13, 14) + '.' + d.slice(14);
                if (d.length > 16) saida = d.slice(0, 7) + '-' + d.slice(7, 9) + '.' + d.slice(9, 13) + '.' + d.slice(13, 14) + '.' + d.slice(14, 16) + '.' + d.slice(16);
                input.value = saida;
            });
        });

        // Máscaras leves de CPF e telefone (o servidor grava só os dígitos).
        document.querySelectorAll('[data-mascara="cpf"]').forEach(function (input) {
            input.addEventListener('input', function () {
                const d = input.value.replace(/\D/g, '').slice(0, 11);
                input.value = d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
                    .replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2');
            });
        });
        document.querySelectorAll('[data-mascara="telefone"]').forEach(function (input) {
            input.addEventListener('input', function () {
                const d = input.value.replace(/\D/g, '').slice(0, 11);
                if (d.length <= 2) { input.value = d ? '(' + d : ''; return; }
                const meio = d.length === 11 ? 5 : 4;
                input.value = '(' + d.slice(0, 2) + ') ' + d.slice(2, 2 + meio) + (d.length > 2 + meio ? '-' + d.slice(2 + meio) : '');
            });
        });

        // Seletor que filtra as opções de outro (ex.: Órgão -> Unidade): as
        // opções do alvo trazem data-instituicao; vazio mostra todas.
        document.querySelectorAll('select[data-filtro-instituicao]').forEach(function (filtro) {
            const alvo = document.getElementById(filtro.dataset.filtroInstituicao);
            if (!alvo) return;
            function aplicar() {
                Array.from(alvo.options).forEach(function (opcao) {
                    if (!opcao.dataset.instituicao) return;
                    const visivel = !filtro.value || opcao.dataset.instituicao === filtro.value;
                    opcao.hidden = !visivel;
                    opcao.disabled = !visivel;
                    if (!visivel && opcao.selected) alvo.value = '';
                });
            }
            filtro.addEventListener('change', aplicar);
            aplicar();
        });
    });
})();
