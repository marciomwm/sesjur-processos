/**
 * Central de Recursos: agrupa os cartões (renderizados uma vez pelo
 * servidor) por Área ou por Pasta, filtra pela busca e pelas fichas e
 * completa o modal de cadastro/edição (categorias, autoria e exclusão).
 *
 * Um item aparece em todas as Áreas cujas categorias tiver, mas em uma só
 * Pasta. Na aba Áreas existe um balde a mais: "Sem área definida".
 */
(function () {
    'use strict';

    const GJ = window.GJ;

    function semAcento(s) {
        return String(s == null ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function ligarModal() {
        const dialogo = document.getElementById('modalRecurso');
        if (!dialogo) return;
        const form = dialogo.querySelector('form');
        const excluir = dialogo.querySelector('[data-recurso-excluir]');
        const excluirId = document.querySelector('[data-excluir-id]');
        const autoria = dialogo.querySelector('[data-recurso-autoria]');

        form.addEventListener('modal:aberto', function (ev) {
            const gatilho = ev.detail && ev.detail.gatilho;
            const marcadas = gatilho && gatilho.dataset.categorias ? gatilho.dataset.categorias.split(',') : [];
            form.querySelectorAll('[data-categoria]').forEach(function (c) {
                c.checked = marcadas.indexOf(c.value) >= 0;
            });
            const podeExcluir = !!gatilho && gatilho.dataset.podeExcluir === '1';
            excluir.hidden = !podeExcluir;
            excluirId.value = podeExcluir ? form.elements.id.value : '';
            const texto = gatilho && gatilho.dataset.autoria;
            autoria.hidden = !texto;
            autoria.textContent = texto || '';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        ligarModal();

        const barra = document.querySelector('[data-recursos-barra]');
        const todos = document.querySelector('[data-recursos-todos]');
        const destino = document.querySelector('[data-recursos-secoes]');
        const fonte = document.getElementById('dadosRecursos');
        if (!barra || !todos || !destino || !fonte) return;

        let dados;
        try { dados = JSON.parse(fonte.textContent); } catch (e) { return; }

        barra.querySelectorAll('[data-so-js]').forEach(function (el) { el.hidden = false; });
        todos.hidden = true;

        const vazio = document.querySelector('[data-recursos-vazio]');
        const fichas = barra.querySelector('[data-fichas]');
        const campoBusca = barra.querySelector('[data-recursos-busca]');
        const abas = Array.from(barra.querySelectorAll('[data-aba]'));
        const nomeTipo = new Map(dados.tipos.map(function (t) { return [String(t.id), t.nome]; }));

        const itens = Array.from(todos.querySelectorAll('[data-recurso]')).map(function (li) {
            const textos = Array.from(li.querySelectorAll('[data-busca-texto]')).map(function (el) { return el.textContent; });
            return {
                el: li,
                tipo: li.dataset.tipo,
                categorias: li.dataset.categorias ? li.dataset.categorias.split(',') : [],
                busca: semAcento(textos.join(' ') + ' ' + (nomeTipo.get(li.dataset.tipo) || ''))
            };
        });

        const inicialGrupo = barra.dataset.inicialGrupo !== '0' ? barra.dataset.inicialGrupo : 'todos';
        const estado = { aba: barra.dataset.inicialAba || 'areas', grupo: inicialGrupo, termo: '' };

        function grupos() { return estado.aba === 'areas' ? dados.categorias : dados.tipos; }

        function visiveis() {
            const t = semAcento(estado.termo).trim();
            return itens.filter(function (i) { return !t || i.busca.indexOf(t) >= 0; });
        }

        function doGrupo(lista, id) {
            if (id === 'sem-area') return lista.filter(function (i) { return i.categorias.length === 0; });
            return lista.filter(function (i) {
                return estado.aba === 'areas' ? i.categorias.indexOf(String(id)) >= 0 : i.tipo === String(id);
            });
        }

        function ficha(id, rotulo, total, cor) {
            const ativa = String(estado.grupo) === String(id);
            return '<button type="button" class="ficha' + (ativa ? ' is-ativa' : '') + '" aria-pressed="' + ativa + '" data-grupo="' + GJ.esc(id) + '">' +
                (cor && !ativa ? '<span class="ficha__ponto" style="background:' + GJ.esc(cor) + '" aria-hidden="true"></span>' : '') +
                GJ.esc(rotulo) + '<span class="ficha__total">' + total + '</span></button>';
        }

        function secao(id, nome, cor, lista) {
            const sec = document.createElement('section');
            sec.className = 'secao-recursos';
            sec.innerHTML = '<div class="secao-recursos__cabecalho">' +
                '<span class="secao-recursos__marca" style="background:' + GJ.esc(cor) + '" aria-hidden="true"></span>' +
                '<h3 class="secao-recursos__titulo">' + GJ.esc(nome) + '</h3>' +
                '<span class="secao-recursos__total">' + lista.length + '</span>' +
                '<span class="faixa-nivel__linha" aria-hidden="true"></span></div>';
            const ul = document.createElement('ul');
            ul.className = 'grade-recursos';
            lista.forEach(function (i) { ul.appendChild(i.el.cloneNode(true)); });
            sec.appendChild(ul);
            return sec;
        }

        function render() {
            const lista = visiveis();
            const gs = grupos();
            const semArea = estado.aba === 'areas' ? doGrupo(lista, 'sem-area') : [];

            // Um grupo que deixou de existir (troca de aba) volta para "Todos".
            if (estado.grupo !== 'todos' && estado.grupo !== 'sem-area' && !gs.some(function (g) { return String(g.id) === String(estado.grupo); })) {
                estado.grupo = 'todos';
            }
            if (estado.grupo === 'sem-area' && estado.aba !== 'areas') estado.grupo = 'todos';

            fichas.innerHTML = ficha('todos', 'Todos', lista.length) +
                gs.map(function (g) { return ficha(g.id, g.nome, doGrupo(lista, g.id).length, g.cor); }).join('') +
                (semArea.length || estado.grupo === 'sem-area' ? ficha('sem-area', 'Sem área', semArea.length) : '');

            let secoes;
            if (estado.grupo === 'todos') {
                secoes = gs.map(function (g) { return { id: g.id, nome: g.nome, cor: g.cor, lista: doGrupo(lista, g.id) }; });
                if (semArea.length) secoes.push({ id: 'sem-area', nome: 'Sem área definida', cor: '#8a94a6', lista: semArea });
                secoes = secoes.filter(function (s) { return s.lista.length > 0; });
            } else {
                const g = gs.find(function (x) { return String(x.id) === String(estado.grupo); });
                secoes = [{
                    id: estado.grupo,
                    nome: g ? g.nome : 'Sem área definida',
                    cor: g ? g.cor : '#8a94a6',
                    lista: estado.grupo === 'sem-area' ? semArea : doGrupo(lista, estado.grupo)
                }].filter(function (s) { return s.lista.length > 0; });
            }

            destino.innerHTML = '';
            secoes.forEach(function (s) { destino.appendChild(secao(s.id, s.nome, s.cor, s.lista)); });

            vazio.hidden = secoes.length > 0;
            if (!secoes.length) {
                const termo = estado.termo.trim();
                vazio.querySelector('[data-vazio-titulo]').textContent = termo ? 'Nenhum item encontrado' : 'Nenhum item aqui ainda';
                vazio.querySelector('[data-vazio-texto]').textContent = termo
                    ? 'Não há recursos correspondentes a “' + termo + '”.'
                    : estado.aba === 'areas'
                        ? 'Um item aparece em todas as Áreas cujas categorias ele tiver.'
                        : 'Cada item fica em uma única Pasta, definida pelo seu Tipo.';
            }

            abas.forEach(function (b) {
                const ativa = b.dataset.aba === estado.aba;
                b.setAttribute('aria-selected', String(ativa));
                b.tabIndex = ativa ? 0 : -1;
            });
            document.getElementById('palcoRecursos').setAttribute('aria-label', estado.aba === 'areas' ? 'Recursos por Área' : 'Recursos por Pasta');
        }

        function escolherAba(aba) {
            estado.aba = aba;
            estado.grupo = 'todos';
            render();
        }
        abas.forEach(function (b, i) {
            b.addEventListener('click', function () { escolherAba(b.dataset.aba); });
            b.addEventListener('keydown', function (ev) {
                const passo = ev.key === 'ArrowRight' ? 1 : ev.key === 'ArrowLeft' ? -1 : 0;
                if (!passo) return;
                ev.preventDefault();
                const prox = abas[(i + passo + abas.length) % abas.length];
                escolherAba(prox.dataset.aba);
                prox.focus();
            });
        });
        fichas.addEventListener('click', function (ev) {
            const b = ev.target.closest('[data-grupo]');
            if (!b) return;
            estado.grupo = b.dataset.grupo;
            render();
            const mesma = fichas.querySelector('[data-grupo="' + b.dataset.grupo + '"]');
            if (mesma) mesma.focus();
        });
        campoBusca.addEventListener('input', GJ.debounce(function () {
            estado.termo = campoBusca.value;
            render();
        }, 180));

        render();
    });
})();
