/**
 * Unidades Administrativas — três visualizações sobre os mesmos dados:
 *
 *  - Lista:      árvore recolhível com guias tracejadas;
 *  - Hierarquia: organograma clássico, com zoom;
 *  - Nível:      uma faixa por nível organizacional, agrupada pela unidade
 *                superior, com conectores em SVG até o superior.
 *
 * A estrutura chega inteira em #dadosUnidades (JSON): expandir, focar,
 * buscar, filtrar e trocar de visualização acontecem só no navegador.
 * Todo texto vindo do banco passa por GJ.esc antes de virar HTML.
 * Sem JavaScript, a página mostra a tabela em árvore ([data-unidades-fallback]).
 */
(function () {
    'use strict';

    const GJ = window.GJ;
    const CHAVE_VISAO = 'sesjur.unidades.visao';
    const COR_SEM_INSTITUICAO = '#8a94a6';

    document.addEventListener('DOMContentLoaded', function () {
        const fonte = document.getElementById('dadosUnidades');
        const conteudo = document.querySelector('[data-unidades-conteudo]');
        if (!fonte || !conteudo) return;

        let dados;
        try { dados = JSON.parse(fonte.textContent); } catch (e) { return; }

        const barra = document.querySelector('[data-unidades-barra]');
        const palco = document.querySelector('[data-palco]');
        const trilha = document.querySelector('[data-trilha-foco]');
        const gaveta = document.querySelector('[data-gaveta]');
        const painel = document.querySelector('[data-gaveta-painel]');
        const fallback = document.querySelector('[data-unidades-fallback]');

        if (fallback) fallback.remove();
        barra.hidden = false;

        /* ------------------------------------------------------------ índice */
        const porId = new Map();
        const filhosDe = new Map();
        const instituicoes = dados.instituicoes;
        const instPorId = new Map(instituicoes.map(function (i) { return [i.id, i]; }));
        const nivelPorId = new Map(dados.niveis.map(function (n) { return [n.id, n]; }));

        dados.unidades.forEach(function (u) { porId.set(u.id, u); });
        dados.unidades
            .slice()
            .sort(function (a, b) { return a.nome.localeCompare(b.nome, 'pt-BR'); })
            .forEach(function (u) {
                const chave = u.superior_id === null ? 'raiz' : u.superior_id;
                if (!filhosDe.has(chave)) filhosDe.set(chave, []);
                filhosDe.get(chave).push(u);
            });

        function filhos(id) { return filhosDe.get(id) || []; }
        function raizes(instId) {
            return filhos('raiz').filter(function (u) { return u.instituicao_id === instId; });
        }

        const cacheDescendentes = new Map();
        /** Iterativo e com guarda: um vínculo torto nunca trava a página. */
        function descendentes(id) {
            if (cacheDescendentes.has(id)) return cacheDescendentes.get(id);
            const saida = [];
            const pilha = filhos(id).slice();
            const vistos = new Set([id]);
            while (pilha.length) {
                const atual = pilha.pop();
                if (vistos.has(atual.id)) continue;
                vistos.add(atual.id);
                saida.push(atual);
                Array.prototype.push.apply(pilha, filhos(atual.id));
            }
            cacheDescendentes.set(id, saida);
            return saida;
        }

        function ancestrais(id) {
            const saida = [];
            const vistos = new Set();
            let atual = porId.get(id);
            while (atual && atual.superior_id !== null && !vistos.has(atual.superior_id)) {
                vistos.add(atual.superior_id);
                atual = porId.get(atual.superior_id);
                if (atual) saida.unshift(atual);
            }
            return saida;
        }

        const profundidade = new Map();
        dados.unidades.forEach(function (u) { profundidade.set(u.id, ancestrais(u.id).length + 1); });

        /**
         * Ordem das faixas da visualização Nível: o nível que aparece mais
         * perto do topo da hierarquia vem primeiro (o nome desempata). Assim
         * "Nível X" não fica antes de "Nível II" só pela ordem alfabética.
         */
        const ordemNivel = new Map();
        dados.unidades.forEach(function (u) {
            const chave = u.nivel_id === null ? 'sem' : u.nivel_id;
            const p = profundidade.get(u.id);
            if (!ordemNivel.has(chave) || p < ordemNivel.get(chave)) ordemNivel.set(chave, p);
        });

        /* ------------------------------------------------------------ texto */
        function semAcento(s) {
            return String(s == null ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        }

        /** Realça o termo sem injetar HTML: cada pedaço é escapado. */
        function realcar(texto, termo) {
            texto = texto == null ? '' : String(texto);
            const t = semAcento(termo).trim();
            if (!t) return GJ.esc(texto);
            // Normaliza caractere a caractere para os índices baterem com o original.
            const chars = Array.from(texto);
            const norm = [];
            const origem = [];
            chars.forEach(function (c, i) {
                Array.from(semAcento(c)).forEach(function (n) { norm.push(n); origem.push(i); });
            });
            const alvo = norm.join('');
            let saida = '';
            let i = 0;
            let ultimo = 0;
            while (i <= alvo.length - t.length) {
                const k = alvo.indexOf(t, i);
                if (k < 0) break;
                const ini = origem[k];
                const fim = origem[k + t.length - 1] + 1;
                saida += GJ.esc(chars.slice(ultimo, ini).join('')) + '<mark class="realce">' + GJ.esc(chars.slice(ini, fim).join('')) + '</mark>';
                ultimo = fim;
                i = k + t.length;
            }
            return saida + GJ.esc(chars.slice(ultimo).join(''));
        }

        function iniciais(nome) {
            const partes = String(nome || '').trim().split(/\s+/).filter(function (p) { return p.length > 2; });
            if (!partes.length) return '—';
            return (partes[0][0] + (partes.length > 1 ? partes[partes.length - 1][0] : '')).toUpperCase();
        }

        /** "Nível II - Coordenação" -> "Nível II"; outros nomes ficam inteiros. */
        function nivelCurto(u) {
            const n = nivelPorId.get(u.nivel_id);
            if (!n) return null;
            const m = /^(n[íi]vel\s+[ivxlcdm\d]+)\b/i.exec(n.nome);
            return m ? m[1] : n.nome;
        }

        function corDe(instId) {
            const i = instPorId.get(instId);
            return i ? i.cor : COR_SEM_INSTITUICAO;
        }

        /** Escurece um hex (cabeçalho da gaveta). */
        function tom(hex, delta) {
            const n = parseInt(hex.replace('#', ''), 16);
            const lim = function (v) { return Math.max(0, Math.min(255, v)); };
            const r = lim((n >> 16) + delta), g = lim(((n >> 8) & 255) + delta), b = lim((n & 255) + delta);
            return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
        }

        function plural(n, um, varios) { return n + ' ' + (n === 1 ? um : varios); }

        /* ------------------------------------------------------------ estado */
        const filtrosIniciais = dados.filtros || {};
        let visaoSalva = null;
        try { visaoSalva = localStorage.getItem(CHAVE_VISAO); } catch (e) { /* armazenamento bloqueado */ }

        const estado = {
            visao: ['lista', 'hierarquia', 'nivel'].indexOf(visaoSalva) >= 0 ? visaoSalva : 'lista',
            termo: filtrosIniciais.busca || '',
            instituicao: filtrosIniciais.instituicao_id ? Number(filtrosIniciais.instituicao_id) : null,
            nivel: filtrosIniciais.nivel_id ? Number(filtrosIniciais.nivel_id) : null,
            status: filtrosIniciais.status || 'ativos',
            // Primeira carga: só as raízes abertas — abrir tudo vira uma parede de cartões.
            expandidos: new Set(filhos('raiz').map(function (u) { return u.id; })),
            // Ramos que o usuário recolheu à mão: vencem a abertura automática da busca.
            recolhidos: new Set(),
            focada: null,
            selecionada: null,
            zoom: 1,
            conexoes: true
        };

        const campoBusca = barra.querySelector('[data-unidades-busca]');
        const selInst = barra.querySelector('[data-unidades-instituicao]');
        const selNivel = barra.querySelector('[data-unidades-nivel]');
        const selStatus = barra.querySelector('[data-unidades-status]');
        campoBusca.value = estado.termo;
        selInst.value = estado.instituicao ? String(estado.instituicao) : '';
        selNivel.value = estado.nivel ? String(estado.nivel) : '';
        selStatus.value = estado.status;

        /* ---------------------------------------------------------- filtros */
        function casaTermo(u) {
            const t = semAcento(estado.termo).trim();
            if (!t) return true;
            return semAcento(u.nome).indexOf(t) >= 0 || semAcento(u.sigla).indexOf(t) >= 0 || semAcento(u.gestor).indexOf(t) >= 0;
        }

        function casaFiltros(u) {
            if (estado.status === 'ativos' && !u.ativo) return false;
            if (estado.status === 'inativos' && u.ativo) return false;
            if (estado.nivel !== null && u.nivel_id !== estado.nivel) return false;
            return casaTermo(u);
        }

        /**
         * Unidades que ficam na tela: as que passam nos filtros mais os seus
         * ancestrais — sem o caminho até a raiz, um resultado profundo
         * apareceria sem contexto. Ancestrais que não passam ficam esmaecidos.
         */
        let visiveis = null;
        let casam = new Set();
        function recalcularVisiveis() {
            casam = new Set();
            dados.unidades.forEach(function (u) { if (casaFiltros(u)) casam.add(u.id); });
            visiveis = new Set();
            casam.forEach(function (id) {
                visiveis.add(id);
                ancestrais(id).forEach(function (a) { visiveis.add(a.id); });
            });
        }

        function buscando() { return semAcento(estado.termo).trim() !== '' || estado.nivel !== null; }

        /** A busca abre os ramos que levam a um resultado, sem gravar no estado. */
        function aberto(id) {
            if (estado.expandidos.has(id)) return true;
            if (estado.recolhidos.has(id)) return false;
            return buscando() && visiveis.has(id) && filhosVisiveis(id).length > 0;
        }

        function filhosVisiveis(id) {
            return filhos(id).filter(function (f) { return visiveis.has(f.id); });
        }

        /* --------------------------------------------------------- blocos */
        function blocos() {
            if (estado.focada !== null && porId.has(estado.focada)) {
                const u = porId.get(estado.focada);
                return [{ inst: instPorId.get(u.instituicao_id) || null, instId: u.instituicao_id, raizes: [u] }];
            }
            const lista = instituicoes
                .filter(function (i) { return estado.instituicao === null || i.id === estado.instituicao; })
                .map(function (i) { return { inst: i, instId: i.id, raizes: raizes(i.id) }; });
            if (estado.instituicao === null) lista.push({ inst: null, instId: null, raizes: raizes(null) });
            return lista
                .map(function (b) {
                    b.raizes = b.raizes.filter(function (r) { return visiveis.has(r.id); });
                    return b;
                })
                .filter(function (b) { return b.raizes.length > 0; });
        }

        /* --------------------------------------------------- peças comuns */
        function botaoMini(acao, id, rotulo, simbolo, extra) {
            return '<button type="button" class="botao-mini' + (extra && extra.ativo ? ' is-ativo' : '') + '" data-acao="' + acao + '" data-id="' + id + '"' +
                ' title="' + GJ.esc(rotulo) + '" aria-label="' + GJ.esc(rotulo) + '"' +
                (extra && extra.expandido !== undefined ? ' aria-expanded="' + extra.expandido + '"' : '') + '>' + simbolo + '</button>';
        }

        function botaoFoco(u) {
            const emFoco = estado.focada === u.id;
            return botaoMini('focar', u.id, emFoco ? 'Remover foco' : 'Focar em ' + u.nome, '<span aria-hidden="true">⌖</span>', { ativo: emFoco });
        }

        function botaoExpandir(u, qtd) {
            if (!qtd) return '<span class="botao-mini botao-mini--vazio" aria-hidden="true"></span>';
            const ab = aberto(u.id);
            return botaoMini('alternar', u.id, (ab ? 'Recolher ' : 'Expandir ') + u.nome, ab ? '−' : '+', { expandido: ab });
        }

        function classeContexto(u) {
            return casam.has(u.id) ? '' : ' is-contexto';
        }

        /* --------------------------------------------------------- Lista */
        function noLista(u) {
            const fs = filhosVisiveis(u.id);
            const ab = fs.length > 0 && aberto(u.id);
            const total = descendentes(u.id).length;
            const nivel = nivelCurto(u);
            const sel = estado.selecionada === u.id;
            let html = '<li role="none">' +
                '<div class="no-lista' + (sel ? ' is-selecionada' : '') + classeContexto(u) + '" role="treeitem" aria-level="' + profundidade.get(u.id) + '"' +
                (fs.length ? ' aria-expanded="' + ab + '"' : '') + ' aria-selected="' + sel + '">' +
                botaoFoco(u) +
                (fs.length ? '' : '<span class="no-lista__folha" aria-hidden="true"></span>') +
                '<button type="button" class="no-lista__nome" data-acao="abrir" data-id="' + u.id + '">' + realcar(u.nome, estado.termo) +
                (u.sigla ? ' <span class="no-lista__sigla">' + realcar(u.sigla, estado.termo) + '</span>' : '') + '</button>' +
                (u.ativo ? '' : '<span class="etiqueta etiqueta--alerta">inativa</span>') +
                (u.gestor ? '<span class="no-lista__gestor">' + realcar(u.gestor, estado.termo) + '</span>' : '') +
                (total > 0 ? '<span class="contador" title="' + plural(total, 'unidade subordinada', 'unidades subordinadas') + ' no total">' + total + '</span>' : '') +
                (nivel ? '<span class="etiqueta" title="' + GJ.esc(nivelPorId.get(u.nivel_id).nome) + '">' + GJ.esc(nivel) + '</span>' : '') +
                botaoExpandir(u, fs.length) +
                '</div>';
            if (ab) {
                html += '<ul role="group">' + fs.map(noLista).join('') + '</ul>';
            }
            return html + '</li>';
        }

        function visaoLista(raizesBloco) {
            return '<ul class="arvore" role="tree">' + raizesBloco.map(noLista).join('') + '</ul>';
        }

        /* --------------------------------------------- cartão (2 visões) */
        function cartao(u, qtdFilhos) {
            const cor = corDe(u.instituicao_id);
            const nivel = nivelCurto(u);
            const sel = estado.selecionada === u.id;
            return '<div class="cartao-unidade' + (sel ? ' is-selecionada' : '') + classeContexto(u) + '" data-cartao="' + u.id + '" style="--cor-instituicao:' + cor + '">' +
                '<div class="cartao-unidade__faixa"></div>' +
                '<div class="cartao-unidade__corpo">' +
                    '<div class="cartao-unidade__topo">' +
                        botaoFoco(u) +
                        '<button type="button" class="cartao-unidade__nome" data-acao="abrir" data-id="' + u.id + '">' + realcar(u.nome, estado.termo) + '</button>' +
                        botaoExpandir(u, qtdFilhos) +
                    '</div>' +
                    '<div class="cartao-unidade__etiquetas">' +
                        (u.sigla ? '<span class="etiqueta etiqueta--mono">' + realcar(u.sigla, estado.termo) + '</span>' : '') +
                        (nivel ? '<span class="etiqueta" title="' + GJ.esc(nivelPorId.get(u.nivel_id).nome) + '">' + GJ.esc(nivel) + '</span>' : '') +
                        (u.ativo ? '' : '<span class="etiqueta etiqueta--alerta">inativa</span>') +
                        (qtdFilhos ? '<span class="contador">' + qtdFilhos + ' sub</span>' : '') +
                    '</div>' +
                    '<div class="cartao-unidade__gestor">' +
                        '<span class="cartao-unidade__avatar' + (u.gestor ? '' : ' is-vazio') + '" aria-hidden="true">' + GJ.esc(iniciais(u.gestor)) + '</span>' +
                        '<span class="cartao-unidade__gestor-texto"><small>Gestor</small><span>' + (u.gestor ? realcar(u.gestor, estado.termo) : 'não informado') + '</span></span>' +
                    '</div>' +
                '</div>' +
            '</div>';
        }

        /* ---------------------------------------------------- Hierarquia */
        function noOrganograma(u) {
            const fs = filhosVisiveis(u.id);
            const ab = fs.length > 0 && aberto(u.id);
            return '<li><div class="organograma__no">' + cartao(u, fs.length) +
                (fs.length && !ab ? '<button type="button" class="organograma__mais" data-acao="alternar" data-id="' + u.id + '">+' + plural(fs.length, 'subunidade', 'subunidades') + '</button>' : '') +
                '</div>' +
                (ab ? '<ul>' + fs.map(noOrganograma).join('') + '</ul>' : '') +
                '</li>';
        }

        function visaoHierarquia(raizesBloco) {
            return '<ul class="organograma">' + raizesBloco.map(noOrganograma).join('') + '</ul>';
        }

        /* --------------------------------------------------------- Nível */
        function visaoNivel(raizesTodas) {
            const emCena = [];
            const vistos = new Set();
            (function percorrer(lista) {
                lista.forEach(function (u) {
                    if (vistos.has(u.id)) return;
                    vistos.add(u.id);
                    emCena.push(u);
                    if (aberto(u.id)) percorrer(filhosVisiveis(u.id));
                });
            })(raizesTodas);

            const faixas = new Map();
            emCena.forEach(function (u) {
                const chave = u.nivel_id === null ? 'sem' : u.nivel_id;
                if (!faixas.has(chave)) faixas.set(chave, []);
                faixas.get(chave).push(u);
            });
            const ordem = Array.from(faixas.keys()).sort(function (a, b) {
                if (a === 'sem') return 1;
                if (b === 'sem') return -1;
                return (ordemNivel.get(a) - ordemNivel.get(b)) || nivelPorId.get(a).nome.localeCompare(nivelPorId.get(b).nome, 'pt-BR', { numeric: true });
            });

            let html = '<div class="niveis" data-niveis>';
            ordem.forEach(function (chave) {
                const itens = faixas.get(chave);
                const nome = chave === 'sem' ? 'Nível não informado' : nivelPorId.get(chave).nome;
                // Dentro da faixa, um grupo por unidade superior: é o grupo que
                // preserva a leitura de "quem responde a quem".
                const grupos = new Map();
                itens.forEach(function (u) {
                    const g = u.superior_id === null ? 'raiz:' + (u.instituicao_id === null ? 'sem' : u.instituicao_id) : u.superior_id;
                    if (!grupos.has(g)) grupos.set(g, []);
                    grupos.get(g).push(u);
                });
                html += '<section class="faixa-nivel">' +
                    '<div class="faixa-nivel__cabecalho">' +
                        '<span class="faixa-nivel__rotulo' + (chave === 'sem' ? ' is-neutro' : '') + '">' + GJ.esc(nome) + '</span>' +
                        '<span class="faixa-nivel__total">' + plural(itens.length, 'unidade', 'unidades') + '</span>' +
                        '<span class="faixa-nivel__linha" aria-hidden="true"></span>' +
                    '</div><div class="faixa-nivel__grupos">';
                grupos.forEach(function (lista, g) {
                    const raiz = String(g).indexOf('raiz:') === 0;
                    const cor = corDe(lista[0].instituicao_id);
                    const inst = instPorId.get(lista[0].instituicao_id);
                    const pai = raiz ? null : porId.get(g);
                    html += '<div class="grupo-nivel" style="--cor-instituicao:' + cor + '">' +
                        '<div class="grupo-nivel__titulo">' +
                            (raiz
                                ? '<span class="grupo-nivel__marca" aria-hidden="true"></span><span>' + GJ.esc(inst ? (inst.sigla || inst.nome) : 'Sem instituição') + ' — unidade raiz</span>'
                                : '<span aria-hidden="true">↳</span><span>' + GJ.esc(pai ? pai.nome : '—') + '</span>') +
                        '</div><div class="grupo-nivel__cartoes">' +
                        lista.map(function (u) { return cartao(u, filhosVisiveis(u.id).length); }).join('') +
                        '</div></div>';
                });
                html += '</div></section>';
            });
            return html + '</div>';
        }

        /** Conectores do cartão até o superior, medidos depois que os cartões existem. */
        function desenharConectores() {
            const area = conteudo.querySelector('[data-niveis]');
            if (!area) return;
            const antigo = area.querySelector('svg.conectores');
            if (antigo) antigo.remove();
            if (!estado.conexoes) return;

            const caixa = area.getBoundingClientRect();
            const posicoes = new Map();
            area.querySelectorAll('[data-cartao]').forEach(function (el) {
                const r = el.getBoundingClientRect();
                posicoes.set(Number(el.dataset.cartao), {
                    x: r.left - caixa.left + r.width / 2,
                    topo: r.top - caixa.top,
                    base: r.top - caixa.top + r.height
                });
            });

            const ns = 'http://www.w3.org/2000/svg';
            const svg = document.createElementNS(ns, 'svg');
            svg.setAttribute('class', 'conectores');
            svg.setAttribute('aria-hidden', 'true');
            svg.setAttribute('width', area.scrollWidth);
            svg.setAttribute('height', area.scrollHeight);
            posicoes.forEach(function (filho, id) {
                const u = porId.get(id);
                if (!u || u.superior_id === null) return;
                const pai = posicoes.get(u.superior_id);
                // Só liga faixas diferentes, de cima para baixo.
                if (!pai || pai.base > filho.topo) return;
                const meio = (pai.base + filho.topo) / 2;
                const p = document.createElementNS(ns, 'path');
                p.setAttribute('d', 'M ' + pai.x + ' ' + pai.base + ' C ' + pai.x + ' ' + meio + ', ' + filho.x + ' ' + meio + ', ' + filho.x + ' ' + filho.topo);
                p.dataset.de = u.superior_id;
                p.dataset.para = id;
                svg.appendChild(p);
            });
            area.insertBefore(svg, area.firstChild);
        }

        conteudo.addEventListener('mouseover', function (ev) {
            const c = ev.target.closest('[data-cartao]');
            const svg = conteudo.querySelector('svg.conectores');
            if (!svg) return;
            const id = c ? c.dataset.cartao : null;
            svg.querySelectorAll('path').forEach(function (p) {
                p.classList.toggle('is-aceso', id !== null && (p.dataset.de === id || p.dataset.para === id));
            });
        });

        /* -------------------------------------------------------- render */
        function render() {
            recalcularVisiveis();
            const lista = blocos();
            const termo = estado.termo.trim();

            if (lista.length === 0) {
                conteudo.innerHTML = '<div class="estado-vazio"><span class="estado-vazio__icone" aria-hidden="true">🏢</span>' +
                    '<b>' + (dados.unidades.length ? 'Nenhuma unidade encontrada' : 'Nenhuma unidade cadastrada') + '</b>' +
                    '<p>' + (dados.unidades.length
                        ? (termo ? 'Não há unidades, siglas ou gestores correspondentes a “' + GJ.esc(termo) + '” com os filtros atuais.' : 'Nenhuma unidade atende aos filtros atuais.')
                        : 'Cadastre a primeira unidade raiz para começar a montar o organograma.') + '</p>' +
                    '<a class="btn btn--primario btn--sm" href="' + GJ.esc(dados.urls.nova) + '">＋ Nova unidade</a></div>';
            } else if (estado.visao === 'nivel') {
                conteudo.innerHTML = visaoNivel(lista.reduce(function (acc, b) { return acc.concat(b.raizes); }, []));
            } else {
                conteudo.innerHTML = '<div class="blocos-instituicao">' + lista.map(function (b) {
                    const total = b.raizes.reduce(function (s, r) { return s + 1 + descendentes(r.id).length; }, 0);
                    const cabecalho = estado.focada !== null ? '' :
                        '<div class="bloco-instituicao__cabecalho" style="--cor-instituicao:' + corDe(b.instId) + '">' +
                            '<span class="bloco-instituicao__marca" aria-hidden="true"></span>' +
                            '<h3 class="bloco-instituicao__nome">' + GJ.esc(b.inst ? b.inst.nome : 'Sem instituição') + '</h3>' +
                            (b.inst && b.inst.sigla ? '<span class="etiqueta">' + GJ.esc(b.inst.sigla) + '</span>' : '') +
                            '<span class="faixa-nivel__linha" aria-hidden="true"></span>' +
                            '<span class="etiqueta">' + plural(total, 'unidade', 'unidades') + '</span>' +
                        '</div>';
                    return '<section class="bloco-instituicao">' + cabecalho +
                        (estado.visao === 'lista' ? visaoLista(b.raizes) : '<div class="organograma-zoom" style="zoom:' + estado.zoom + '">' + visaoHierarquia(b.raizes) + '</div>') +
                        '</section>';
                }).join('') + '</div>';
            }

            palco.classList.toggle('palco__area--arrastavel', estado.visao !== 'lista');
            renderBarra();
            renderTrilha();
            renderEstatisticas();
            if (estado.visao === 'nivel') requestAnimationFrame(desenharConectores);
        }

        function renderBarra() {
            barra.querySelectorAll('[data-visao]').forEach(function (b) {
                const ativo = b.dataset.visao === estado.visao;
                b.setAttribute('aria-selected', String(ativo));
                b.tabIndex = ativo ? 0 : -1;
            });
            barra.querySelectorAll('[data-so-visao]').forEach(function (el) {
                el.hidden = el.dataset.soVisao !== estado.visao;
            });
            barra.querySelector('[data-zoom-valor]').textContent = Math.round(estado.zoom * 100) + '%';
            const nova = barra.querySelector('[data-nova-unidade]');
            nova.href = dados.urls.nova + (estado.instituicao !== null ? '?instituicao_id=' + estado.instituicao : '');
            const rotulo = { lista: 'Lista', hierarquia: 'Hierarquia', nivel: 'Nível' }[estado.visao];
            document.getElementById('palcoUnidades').setAttribute('aria-label', 'Unidades administrativas — visualização ' + rotulo);
        }

        function renderTrilha() {
            const u = estado.focada !== null ? porId.get(estado.focada) : null;
            trilha.hidden = !u;
            if (!u) { trilha.innerHTML = ''; return; }
            const inst = instPorId.get(u.instituicao_id);
            trilha.innerHTML = '<span class="trilha-foco__rotulo">Foco</span><ol class="trilha-foco__lista">' +
                '<li><button type="button" class="trilha-foco__item" data-acao="sair-foco">' + GJ.esc(inst ? (inst.sigla || inst.nome) : 'Sem instituição') + '</button></li>' +
                ancestrais(u.id).map(function (a) {
                    return '<li><span aria-hidden="true">/</span><button type="button" class="trilha-foco__item" data-acao="focar" data-id="' + a.id + '">' + GJ.esc(a.nome) + '</button></li>';
                }).join('') +
                '<li><span aria-hidden="true">/</span><b aria-current="location">' + GJ.esc(u.nome) + '</b></li></ol>' +
                '<button type="button" class="btn btn--ghost btn--sm trilha-foco__sair" data-acao="sair-foco">👁 Ver tudo</button>';
        }

        function renderEstatisticas() {
            const doFiltro = dados.unidades.filter(function (u) {
                if (estado.instituicao !== null && u.instituicao_id !== estado.instituicao) return false;
                if (estado.status === 'ativos') return u.ativo;
                if (estado.status === 'inativos') return !u.ativo;
                return true;
            });
            const contar = function (campo) {
                return new Set(doFiltro.map(function (u) { return u[campo]; }).filter(function (v) { return v !== null && v !== ''; })).size;
            };
            const definir = function (nome, valor) {
                const el = document.querySelector('[data-estatistica="' + nome + '"]');
                if (el) el.textContent = valor;
            };
            definir('instituicoes', estado.instituicao !== null ? 1 : instituicoes.length);
            definir('unidades', doFiltro.length);
            definir('niveis', contar('nivel_id'));
            definir('gestores', contar('gestor'));
            const resultados = document.querySelector('[data-estatistica-resultados]');
            const ativa = buscando();
            resultados.hidden = !ativa;
            if (ativa) {
                // O conjunto visível inclui ancestrais como contexto; a contagem
                // reflete só o que de fato casou com a busca.
                definir('resultados', doFiltro.filter(function (u) { return casam.has(u.id); }).length);
            }
        }

        /* -------------------------------------------------------- gaveta */
        let gatilhoGaveta = null;

        function abrirGaveta(id, gatilho) {
            const u = porId.get(id);
            if (!u) return;
            if (gatilho) gatilhoGaveta = gatilho;
            estado.selecionada = id;
            const cor = corDe(u.instituicao_id);
            const inst = instPorId.get(u.instituicao_id);
            const sup = u.superior_id !== null ? porId.get(u.superior_id) : null;
            const subs = filhos(u.id);
            const total = descendentes(u.id).length;
            const nivel = nivelPorId.get(u.nivel_id);

            painel.innerHTML =
                '<header class="gaveta__cabecalho" style="background-image:linear-gradient(135deg,' + cor + ' 0%,' + tom(cor, -46) + ' 100%)">' +
                    '<button type="button" class="gaveta__fechar" data-gaveta-fechar aria-label="Fechar painel">×</button>' +
                    '<p class="gaveta__sobrelinha">Unidade Administrativa</p>' +
                    '<h2 class="gaveta__titulo" id="gavetaTitulo">' + GJ.esc(u.nome) + '</h2>' +
                    '<div class="gaveta__fichas">' +
                        (u.sigla ? '<span class="gaveta__ficha mono">' + GJ.esc(u.sigla) + '</span>' : '') +
                        (nivel ? '<span class="gaveta__ficha">' + GJ.esc(nivel.nome) + '</span>' : '') +
                        (u.ativo ? '' : '<span class="gaveta__ficha">inativa</span>') +
                    '</div>' +
                '</header>' +
                '<div class="gaveta__corpo">' +
                    bloco('Instituição', inst
                        ? '<p class="gaveta__texto">' + GJ.esc(inst.nome) + (inst.sigla ? ' <span class="etiqueta">' + GJ.esc(inst.sigla) + '</span>' : '') + '</p>'
                        : '<p class="gaveta__vazio">Sem instituição — edite a unidade para completar o cadastro.</p>') +
                    bloco('Unidade superior', sup
                        ? '<button type="button" class="gaveta__link" data-acao="abrir" data-id="' + sup.id + '"><span aria-hidden="true">↑</span><span>' + GJ.esc(sup.nome) + '</span></button>'
                        : '<p class="gaveta__vazio">Topo da estrutura da instituição</p>') +
                    bloco('Gestor', u.gestor
                        ? '<p class="gaveta__pessoa"><span class="gaveta__avatar" style="background:' + cor + '" aria-hidden="true">' + GJ.esc(iniciais(u.gestor)) + '</span><span>' + GJ.esc(u.gestor) +
                          (u.gestor_matricula ? '<small>Matrícula ' + GJ.esc(u.gestor_matricula) + '</small>' : '') + '</span></p>'
                        : '<p class="gaveta__vazio">Não informado</p>') +
                    bloco('Pessoas', '<div class="gaveta__numeros">' +
                        '<span><b>' + u.servidores + '</b> no lotacionograma</span>' +
                        '<a href="' + GJ.esc(dados.urls.usuarios + u.id) + '"><b>' + u.usuarios + '</b> ' + (u.usuarios === 1 ? 'usuário lotado' : 'usuários lotados') + '</a>' +
                    '</div>') +
                    bloco('Competências', u.competencias
                        ? '<p class="gaveta__competencias">' + GJ.esc(u.competencias) + '</p>'
                        : '<p class="gaveta__vazio">Sem descrição cadastrada</p>') +
                    bloco('Subunidades diretas' + (subs.length ? ' (' + subs.length + (total > subs.length ? ' · ' + total + ' no total' : '') + ')' : ''), subs.length
                        ? '<ul class="gaveta__lista">' + subs.map(function (s) {
                            const abaixo = descendentes(s.id).length;
                            return '<li><button type="button" class="gaveta__link" data-acao="abrir" data-id="' + s.id + '"><span aria-hidden="true">↳</span><span>' + GJ.esc(s.nome) + '</span>' +
                                (s.ativo ? '' : '<span class="etiqueta etiqueta--alerta">inativa</span>') +
                                (abaixo ? '<small>+' + abaixo + '</small>' : '') + '</button></li>';
                        }).join('') + '</ul>'
                        : '<p class="gaveta__vazio">Nenhuma — esta é uma unidade terminal</p>') +
                '</div>' +
                '<footer class="gaveta__rodape">' +
                    '<button type="button" class="btn btn--ghost btn--sm" data-acao="focar-gaveta" data-id="' + u.id + '">⌖ Focar</button>' +
                    '<a class="btn btn--ghost btn--sm" href="' + GJ.esc(dados.urls.ficha + u.id) + '">Ficha completa</a>' +
                    '<a class="btn btn--ghost btn--sm" href="' + GJ.esc(dados.urls.nova + '?superior=' + u.id) + '">＋ Subunidade</a>' +
                    '<a class="btn btn--ghost btn--sm" href="' + GJ.esc(dados.urls.editar + u.id) + '">✎ Editar</a>' +
                    '<form method="post" action="' + GJ.esc(dados.urls.status) + '" data-status-unidade>' +
                        '<input type="hidden" name="_csrf" value="' + GJ.esc(GJ.csrf) + '">' +
                        '<input type="hidden" name="id" value="' + u.id + '">' +
                        '<input type="hidden" name="ativo" value="' + (u.ativo ? '0' : '1') + '">' +
                        '<input type="hidden" name="retorno" value="unidades-administrativas.php">' +
                        '<button type="submit" class="btn btn--ghost btn--sm ' + (u.ativo ? 'texto-perigo' : 'texto-sucesso') + '">' + (u.ativo ? 'Inativar' : 'Ativar') + '</button>' +
                    '</form>' +
                '</footer>';

            painel.querySelector('[data-status-unidade]').addEventListener('submit', function (ev) {
                const msg = u.ativo
                    ? 'Inativar a unidade “' + u.nome + '”? Usuários e solicitações que já a usam continuam vinculados, mas ela deixa de aparecer em novos cadastros.'
                    : 'Reativar a unidade “' + u.nome + '”?';
                if (!window.confirm(msg)) ev.preventDefault();
            });

            const estavaFechada = gaveta.hidden;
            gaveta.hidden = false;
            if (estavaFechada) requestAnimationFrame(function () { gaveta.classList.add('is-aberta'); });
            painel.scrollTop = 0;
            painel.querySelector('.gaveta__fechar').focus();
            render();
        }

        function bloco(rotulo, corpo) {
            return '<section class="gaveta__bloco"><h3 class="gaveta__rotulo">' + GJ.esc(rotulo) + '</h3>' + corpo + '</section>';
        }

        function fecharGaveta() {
            if (gaveta.hidden) return;
            gaveta.classList.remove('is-aberta');
            estado.selecionada = null;
            setTimeout(function () { gaveta.hidden = true; }, 180);
            render();
            if (gatilhoGaveta && document.body.contains(gatilhoGaveta)) gatilhoGaveta.focus();
            gatilhoGaveta = null;
        }

        /* --------------------------------------------------------- ações */
        function focar(id) {
            estado.focada = estado.focada === id ? null : id;
            estado.expandidos.add(id);
            estado.recolhidos.delete(id);
            const u = porId.get(id);
            if (u && estado.instituicao !== null && u.instituicao_id !== estado.instituicao) {
                estado.instituicao = u.instituicao_id;
                selInst.value = u.instituicao_id === null ? '' : String(u.instituicao_id);
            }
            render();
        }

        /** Depois de re-renderizar, devolve o foco ao botão equivalente. */
        function manterFoco(acao, id) {
            const alvo = conteudo.querySelector('[data-acao="' + acao + '"][data-id="' + id + '"]');
            if (alvo) alvo.focus();
        }

        function aoClicar(ev) {
            const botao = ev.target.closest('[data-acao]');
            if (!botao) return;
            const id = botao.dataset.id ? Number(botao.dataset.id) : null;
            switch (botao.dataset.acao) {
                case 'abrir': abrirGaveta(id, botao.closest('.gaveta') ? null : botao); break;
                case 'alternar':
                    if (aberto(id)) {
                        estado.expandidos.delete(id);
                        estado.recolhidos.add(id);
                    } else {
                        estado.expandidos.add(id);
                        estado.recolhidos.delete(id);
                    }
                    render();
                    manterFoco('alternar', id);
                    break;
                case 'focar': focar(id); manterFoco('focar', id); break;
                case 'focar-gaveta': fecharGaveta(); focar(id); break;
                case 'sair-foco': estado.focada = null; render(); break;
            }
        }

        conteudo.addEventListener('click', aoClicar);
        trilha.addEventListener('click', aoClicar);
        painel.addEventListener('click', aoClicar);
        gaveta.addEventListener('click', function (ev) {
            if (ev.target.closest('[data-gaveta-fechar]')) fecharGaveta();
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && !gaveta.hidden) { ev.preventDefault(); fecharGaveta(); }
        });

        // Segmentado: clique e setas (padrão de tablist).
        const abas = Array.from(barra.querySelectorAll('[data-visao]'));
        function escolherVisao(v) {
            estado.visao = v;
            estado.zoom = 1;
            try { localStorage.setItem(CHAVE_VISAO, v); } catch (e) { /* armazenamento bloqueado */ }
            palco.scrollTo(0, 0);
            render();
        }
        abas.forEach(function (b, i) {
            b.addEventListener('click', function () { escolherVisao(b.dataset.visao); });
            b.addEventListener('keydown', function (ev) {
                const passo = ev.key === 'ArrowRight' ? 1 : ev.key === 'ArrowLeft' ? -1 : 0;
                if (!passo) return;
                ev.preventDefault();
                const prox = abas[(i + passo + abas.length) % abas.length];
                escolherVisao(prox.dataset.visao);
                prox.focus();
            });
        });

        campoBusca.addEventListener('input', GJ.debounce(function () {
            estado.termo = campoBusca.value;
            estado.recolhidos = new Set();
            render();
        }, 200));
        selInst.addEventListener('change', function () {
            estado.instituicao = selInst.value ? Number(selInst.value) : null;
            estado.focada = null;
            render();
        });
        selNivel.addEventListener('change', function () {
            estado.nivel = selNivel.value ? Number(selNivel.value) : null;
            estado.recolhidos = new Set();
            render();
        });
        selStatus.addEventListener('change', function () {
            estado.status = selStatus.value;
            render();
        });
        barra.querySelector('[data-expandir-tudo]').addEventListener('click', function () {
            dados.unidades.forEach(function (u) { if (filhos(u.id).length) estado.expandidos.add(u.id); });
            estado.recolhidos = new Set();
            render();
        });
        barra.querySelector('[data-recolher-tudo]').addEventListener('click', function () {
            estado.expandidos = new Set();
            estado.recolhidos = new Set(dados.unidades.map(function (u) { return u.id; }));
            render();
        });
        barra.querySelector('[data-conexoes]').addEventListener('change', function (ev) {
            estado.conexoes = ev.target.checked;
            render();
        });
        barra.querySelectorAll('[data-zoom]').forEach(function (b) {
            b.addEventListener('click', function () {
                const z = b.dataset.zoom;
                estado.zoom = z === '1' ? 1 : Math.min(1.6, Math.max(0.45, +(estado.zoom + (z === '+' ? 0.1 : -0.1)).toFixed(2)));
                render();
            });
        });

        window.addEventListener('resize', GJ.debounce(function () {
            if (estado.visao === 'nivel') desenharConectores();
        }, 120));

        /* --------------------------------------- arrastar para navegar */
        (function arrastar() {
            const LIMIAR = 4;
            let pressionado = false, arrastando = false, x0 = 0, y0 = 0, esq0 = 0, topo0 = 0;
            const interativo = function (t) {
                return t instanceof Element && !!t.closest('button,a,input,textarea,select,label');
            };
            palco.addEventListener('mousedown', function (e) {
                if (e.button !== 0 || estado.visao === 'lista' || interativo(e.target)) return;
                pressionado = true;
                arrastando = false;
                x0 = e.clientX; y0 = e.clientY;
                esq0 = palco.scrollLeft; topo0 = palco.scrollTop;
            });
            window.addEventListener('mousemove', function (e) {
                if (!pressionado) return;
                const dx = e.clientX - x0, dy = e.clientY - y0;
                // Só vira arrasto depois que o ponteiro anda de fato: não rouba cliques.
                if (!arrastando) {
                    if (Math.abs(dx) < LIMIAR && Math.abs(dy) < LIMIAR) return;
                    arrastando = true;
                    palco.classList.add('is-arrastando');
                }
                e.preventDefault();
                palco.scrollLeft = esq0 - dx;
                palco.scrollTop = topo0 - dy;
            });
            window.addEventListener('mouseup', function () {
                pressionado = false;
                if (arrastando) {
                    arrastando = false;
                    palco.classList.remove('is-arrastando');
                }
            });
        })();

        render();
    });
})();
