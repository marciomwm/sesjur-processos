/**
 * Painel de Bloqueios Judiciais e Guias de Depósito.
 *
 * Os dados chegam uma vez (#dadosBloqueios, valores em centavos inteiros);
 * tudo abaixo dos filtros — indicadores, série histórica, ranking e tabelas —
 * é recalculado aqui para o mesmo período e processo, então os números
 * sempre conferem entre si. Gráficos em SVG próprio (sem biblioteca: ADR 0004).
 *
 * Séries: Bloqueado, Desbloqueado, Guias emitidas e Guias pagas são fluxos
 * (soma no período); Saldo retido é estoque (posição ao fim de cada período).
 * "Acumulado" transforma os fluxos em soma desde o primeiro registro.
 */
(function () {
    'use strict';

    const GJ = window.GJ;
    const NS = 'http://www.w3.org/2000/svg';
    const CHAVE_PREFERENCIAS = 'sesjur.painelBloqueios';

    // Ordem fixa da paleta categórica validada (azul, laranja, aqua, amarelo, magenta).
    const SERIES = [
        { id: 'bloqueado', nome: 'Bloqueado', cor: '#2a78d6' },
        { id: 'desbloqueado', nome: 'Desbloqueado', cor: '#eb6834' },
        { id: 'saldo', nome: 'Saldo retido', cor: '#1baf7a', estoque: true },
        { id: 'emitidas', nome: 'Guias emitidas', cor: '#eda100' },
        { id: 'pagas', nome: 'Guias pagas', cor: '#e87ba4' }
    ];
    const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    const MESES_LONGOS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    const moeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const compacto = new Intl.NumberFormat('pt-BR', { notation: 'compact', maximumFractionDigits: 1 });
    const reais = function (c) { return moeda.format(c / 100); };
    const reaisCurto = function (c) { return c === 0 ? 'R$ 0' : 'R$ ' + compacto.format(c / 100); };

    function svg(nome, atributos, pai) {
        const el = document.createElementNS(NS, nome);
        Object.keys(atributos || {}).forEach(function (k) { el.setAttribute(k, atributos[k]); });
        if (pai) pai.appendChild(el);
        return el;
    }

    function texto(el, valor) { el.textContent = valor; return el; }

    // ---------- Datas e períodos (strings YYYY-MM-DD, sem fuso) ----------

    function mesesAntes(iso, n) {
        let a = Number(iso.slice(0, 4)), m = Number(iso.slice(5, 7)) - n;
        while (m < 1) { m += 12; a -= 1; }
        return a + '-' + String(m).padStart(2, '0') + '-01';
    }

    function chave(iso, agrupamento) {
        const a = iso.slice(0, 4), m = Number(iso.slice(5, 7));
        if (agrupamento === 'ano') return a;
        if (agrupamento === 'trimestre') return a + '-T' + Math.ceil(m / 3);
        return iso.slice(0, 7);
    }

    function rotulos(k, agrupamento) {
        const aa = k.slice(2, 4);
        if (agrupamento === 'ano') return { curto: k, longo: k };
        if (agrupamento === 'trimestre') {
            const t = k.slice(-1);
            return { curto: t + 'º tri/' + aa, longo: t + 'º trimestre de ' + k.slice(0, 4) };
        }
        const m = Number(k.slice(5, 7)) - 1;
        return { curto: MESES[m] + '/' + aa, longo: MESES_LONGOS[m] + ' de ' + k.slice(0, 4) };
    }

    /** Períodos contíguos de `de` a `ate`, no agrupamento escolhido. */
    function gerarPeriodos(de, ate, agrupamento) {
        const lista = [];
        let a = Number(de.slice(0, 4)), m = Number(de.slice(5, 7));
        const fim = chave(ate, agrupamento);
        for (let guarda = 0; guarda < 2000; guarda++) {
            const iso = a + '-' + String(m).padStart(2, '0') + '-01';
            const k = chave(iso, agrupamento);
            if (!lista.length || lista[lista.length - 1].chave !== k) {
                lista.push(Object.assign({ chave: k }, rotulos(k, agrupamento)));
            }
            if (k === fim) break;
            m += 1;
            if (m > 12) { m = 1; a += 1; }
        }
        return lista;
    }

    // ---------- Preferências do usuário (conveniência local) ----------

    function lerPreferencias() {
        try { return JSON.parse(localStorage.getItem(CHAVE_PREFERENCIAS) || '{}') || {}; } catch (e) { return {}; }
    }

    function gravarPreferencias(estado) {
        try {
            localStorage.setItem(CHAVE_PREFERENCIAS, JSON.stringify({
                periodo: estado.periodo, de: estado.de, ate: estado.ate, agrupamento: estado.agrupamento,
                forma: estado.forma, acumulado: estado.acumulado, series: estado.series,
                rankingMedida: estado.rankingMedida, rankingQuantidade: estado.rankingQuantidade
            }));
        } catch (e) { /* armazenamento indisponível: segue sem lembrar */ }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const fonte = document.getElementById('dadosBloqueios');
        if (!fonte) return;
        let dados;
        try { dados = JSON.parse(fonte.textContent); } catch (e) { return; }

        const filtros = document.querySelector('[data-painel-filtros]');
        const cartaoSerie = document.querySelector('[data-grafico-serie]');
        const cartaoRanking = document.querySelector('[data-grafico-ranking]');
        filtros.hidden = false;
        cartaoSerie.hidden = false;
        cartaoRanking.hidden = false;

        const lancamentos = dados.lancamentos.map(function (l) { return { p: l[0], d: l[1], t: l[2], v: l[3] }; });
        const guias = dados.guias.map(function (g) { return { p: g[0], d: g[1], s: g[2], v: g[3], pg: g[4], venc: g[5] }; });
        const nomeProcesso = new Map(dados.processos.map(function (p) { return [p.id, p.cnj]; }));
        const hoje = dados.hoje;

        const pref = lerPreferencias();
        const seriesValidas = SERIES.map(function (s) { return s.id; });
        const estado = {
            periodo: ['12m', '24m', '60m', 'tudo', 'personalizado'].indexOf(pref.periodo) >= 0 ? pref.periodo : '12m',
            de: pref.de || '', ate: pref.ate || '',
            processo: filtros.querySelector('[data-filtro-processo]').value,
            agrupamento: ['mes', 'trimestre', 'ano'].indexOf(pref.agrupamento) >= 0 ? pref.agrupamento : 'mes',
            forma: pref.forma === 'linhas' ? 'linhas' : 'colunas',
            acumulado: pref.acumulado === true,
            series: Array.isArray(pref.series) && pref.series.some(function (s) { return seriesValidas.indexOf(s) >= 0; })
                ? pref.series.filter(function (s) { return seriesValidas.indexOf(s) >= 0; })
                : ['bloqueado', 'desbloqueado', 'saldo'],
            rankingMedida: pref.rankingMedida || 'saldo',
            rankingQuantidade: String(pref.rankingQuantidade || '10'),
            foco: null
        };

        // ---------- Recorte (período + processo) ----------

        function primeiraData() {
            let menor = hoje;
            lancamentos.forEach(function (l) { if (l.d < menor) menor = l.d; });
            guias.forEach(function (g) { if (g.d < menor) menor = g.d; });
            return menor;
        }

        function intervalo() {
            if (estado.periodo === 'tudo') return { de: primeiraData().slice(0, 7) + '-01', ate: hoje };
            if (estado.periodo === 'personalizado') {
                const de = estado.de || mesesAntes(hoje, 11);
                const ate = estado.ate && estado.ate >= de ? estado.ate : hoje;
                return { de: de, ate: ate };
            }
            const meses = { '12m': 11, '24m': 23, '60m': 59 }[estado.periodo];
            return { de: mesesAntes(hoje, meses), ate: hoje };
        }

        function doProcesso(item) { return !estado.processo || String(item.p) === estado.processo; }

        /** Valores de todas as séries por período, mais os totais do recorte. */
        function calcular() {
            const iv = intervalo();
            const periodos = gerarPeriodos(iv.de, iv.ate, estado.agrupamento);
            const indice = new Map(periodos.map(function (p, i) { return [p.chave, i]; }));
            const n = periodos.length;
            const fluxo = {}, antes = {};
            ['bloqueado', 'desbloqueado', 'emitidas', 'pagas'].forEach(function (s) { fluxo[s] = new Array(n).fill(0); antes[s] = 0; });
            const deltaSaldo = new Array(n).fill(0);
            let saldoAntes = 0;
            const t = { bloqueado: 0, desbloqueado: 0, qBloqueio: 0, qDesbloqueio: 0, emitidas: 0, qEmitidas: 0, pagas: 0, qPagas: 0 };

            function somar(serie, iso, valor) {
                if (iso > iv.ate) return -1;
                if (iso < iv.de) { antes[serie] += valor; return -1; }
                const i = indice.get(chave(iso, estado.agrupamento));
                fluxo[serie][i] += valor;
                return i;
            }

            lancamentos.forEach(function (l) {
                if (!doProcesso(l) || l.d > iv.ate) return;
                const bloqueio = l.t === 'B';
                const i = somar(bloqueio ? 'bloqueado' : 'desbloqueado', l.d, l.v);
                const sinal = bloqueio ? l.v : -l.v;
                if (i < 0) { saldoAntes += sinal; return; }
                deltaSaldo[i] += sinal;
                if (bloqueio) { t.bloqueado += l.v; t.qBloqueio++; } else { t.desbloqueado += l.v; t.qDesbloqueio++; }
            });
            guias.forEach(function (g) {
                if (!doProcesso(g)) return;
                if (g.s !== 'CANCELADA' && somar('emitidas', g.d, g.v) >= 0) { t.emitidas += g.v; t.qEmitidas++; }
                if (g.s === 'PAGA' && g.pg && somar('pagas', g.pg, g.v) >= 0) { t.pagas += g.v; t.qPagas++; }
            });

            const valores = { saldo: [] };
            let saldo = saldoAntes;
            deltaSaldo.forEach(function (d) { saldo += d; valores.saldo.push(saldo); });
            Object.keys(fluxo).forEach(function (s) {
                if (!estado.acumulado) { valores[s] = fluxo[s]; return; }
                let soma = antes[s];
                valores[s] = fluxo[s].map(function (v) { soma += v; return soma; });
            });
            t.saldo = saldo;
            return { iv: iv, periodos: periodos, valores: valores, totais: t };
        }

        // ---------- Indicadores ----------

        function indicadores(r) {
            const t = r.totais;
            const periodo = 'de ' + GJ.dataBr(r.iv.de) + ' a ' + GJ.dataBr(r.iv.ate);
            const abertas = guias.filter(function (g) { return doProcesso(g) && g.s === 'EMITIDA'; });
            const vencidas = abertas.filter(function (g) { return g.venc && g.venc < hoje; });
            const valores = {
                bloqueado: [t.bloqueado, t.qBloqueio + ' bloqueio(s) no período'],
                desbloqueado: [t.desbloqueado, t.qDesbloqueio + ' desbloqueio(s) no período'],
                saldo: [t.saldo, 'Posição em ' + GJ.dataBr(r.iv.ate)],
                emitidas: [t.emitidas, t.qEmitidas + ' guia(s) no período, exceto canceladas'],
                pagas: [t.pagas, t.qPagas + ' guia(s) pagas no período'],
                abertas: [abertas.reduce(function (s, g) { return s + g.v; }, 0),
                    abertas.length + ' guia(s) hoje · ' + vencidas.length + ' vencida(s)']
            };
            Object.keys(valores).forEach(function (k) {
                document.querySelector('[data-kpi="' + k + '"]').textContent = reais(valores[k][0]);
                document.querySelector('[data-kpi-nota="' + k + '"]').textContent = valores[k][1];
            });
            const cartaoAbertas = document.querySelector('[data-kpi-cartao="abertas"]');
            cartaoAbertas.classList.toggle('indicador--alerta', vencidas.length > 0);
            cartaoAbertas.classList.toggle('indicador--designados', vencidas.length === 0);
            filtros.querySelector('[data-filtro-resumo]').textContent = 'Período ' + periodo +
                (estado.processo ? ' · processo ' + nomeProcesso.get(Number(estado.processo)) : '');
        }

        // ---------- Série histórica ----------

        const areaSerie = cartaoSerie.querySelector('[data-serie-area]');
        const dica = cartaoSerie.querySelector('[data-serie-dica]');
        const legenda = cartaoSerie.querySelector('[data-serie-legenda]');
        let ultimo = null;

        function visiveis() { return SERIES.filter(function (s) { return estado.series.indexOf(s.id) >= 0; }); }

        function nomeSerie(s) {
            return estado.acumulado && !s.estoque ? s.nome + ' (acumulado)' : s.nome;
        }

        function passoBonito(bruto) {
            const ordem = Math.pow(10, Math.floor(Math.log10(bruto)));
            const r = bruto / ordem;
            return (r <= 1 ? 1 : r <= 2 ? 2 : r <= 2.5 ? 2.5 : r <= 5 ? 5 : 10) * ordem;
        }

        function desenharLegenda() {
            legenda.innerHTML = '';
            SERIES.forEach(function (s) {
                const ligada = estado.series.indexOf(s.id) >= 0;
                const botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'grafico-legenda__item';
                botao.setAttribute('aria-pressed', String(ligada));
                botao.dataset.serie = s.id;
                const marca = document.createElement('span');
                marca.className = 'grafico-legenda__marca grafico-legenda__marca--' + (estado.forma === 'linhas' ? 'linha' : 'barra');
                marca.style.setProperty('--cor', s.cor);
                botao.append(marca, document.createTextNode(nomeSerie(s)));
                botao.title = (ligada ? 'Ocultar ' : 'Mostrar ') + s.nome;
                legenda.appendChild(botao);
            });
        }

        function caminhoBarra(x, y, w, h) {
            const r = Math.min(4, h, w / 2);
            const base = y + h;
            return 'M' + x + ',' + base + 'V' + (y + r) + 'Q' + x + ',' + y + ' ' + (x + r) + ',' + y +
                'H' + (x + w - r) + 'Q' + (x + w) + ',' + y + ' ' + (x + w) + ',' + (y + r) + 'V' + base + 'Z';
        }

        function desenharSerie(r) {
            ultimo = r;
            areaSerie.querySelectorAll('svg, .grafico__vazio').forEach(function (el) { el.remove(); });
            dica.hidden = true;
            const series = visiveis();
            const n = r.periodos.length;
            const maximo = Math.max.apply(null, series.map(function (s) { return Math.max.apply(null, r.valores[s.id]); }).concat([0]));

            const subtitulo = cartaoSerie.querySelector('[data-serie-subtitulo]');
            subtitulo.textContent = (estado.acumulado ? 'Valores acumulados' : 'Valores') + ' por ' +
                { mes: 'mês', trimestre: 'trimestre', ano: 'ano' }[estado.agrupamento] + ', de ' + GJ.dataBr(r.iv.de) + ' a ' + GJ.dataBr(r.iv.ate) +
                ' · saldo retido = posição ao fim de cada período';

            if (maximo <= 0) {
                const vazio = document.createElement('p');
                vazio.className = 'grafico__vazio';
                vazio.textContent = 'Nenhum valor no período e nas séries escolhidas.';
                areaSerie.appendChild(vazio);
                desenharTabela(r, series);
                return;
            }

            const largura = Math.max(320, areaSerie.clientWidth);
            const altura = largura < 560 ? 240 : 300;
            const passo = passoBonito(maximo / 4);
            const topo = Math.ceil(maximo / passo) * passo;
            const marcas = [];
            for (let v = 0; v <= topo + 1e-6; v += passo) marcas.push(v);
            const rotuloMaisLargo = Math.max.apply(null, marcas.map(function (v) { return reaisCurto(v).length; }));
            const comFinal = estado.forma === 'linhas' && largura >= 560;
            const m = { esq: rotuloMaisLargo * 6.4 + 12, dir: comFinal ? 78 : 12, topo: 12, base: 28 };
            const w = largura - m.esq - m.dir;
            const h = altura - m.topo - m.base;
            const faixa = w / n;
            const y = function (v) { return m.topo + h - (v / topo) * h; };
            const xCentro = function (i) { return m.esq + faixa * i + faixa / 2; };

            const raiz = svg('svg', {
                width: largura, height: altura, viewBox: '0 0 ' + largura + ' ' + altura, class: 'grafico__svg',
                role: 'img', tabindex: '0',
                'aria-label': 'Série histórica: ' + series.map(nomeSerie).join(', ') + '. Use as setas para percorrer os períodos; os dados também estão na tabela abaixo.'
            });

            const realce = svg('rect', { class: 'grafico__realce', x: 0, y: m.topo, width: faixa, height: h, visibility: 'hidden' }, raiz);

            marcas.forEach(function (v) {
                svg('line', { class: 'grafico__grade', x1: m.esq, x2: m.esq + w, y1: y(v), y2: y(v) }, raiz);
                texto(svg('text', { class: 'grafico__eixo', x: m.esq - 8, y: y(v) + 4, 'text-anchor': 'end' }, raiz), reaisCurto(v));
            });

            const salto = Math.max(1, Math.ceil(n * 58 / w));
            r.periodos.forEach(function (p, i) {
                if (i % salto !== 0) return;
                texto(svg('text', { class: 'grafico__eixo', x: xCentro(i), y: altura - 8, 'text-anchor': 'middle' }, raiz), p.curto);
            });

            if (estado.forma === 'colunas') {
                const grupo = faixa * 0.78;
                const espaco = 2;
                const larguraBarra = Math.max(1, Math.min(24, (grupo - espaco * (series.length - 1)) / series.length));
                const total = larguraBarra * series.length + espaco * (series.length - 1);
                series.forEach(function (s, j) {
                    const g = svg('g', { fill: s.cor }, raiz);
                    r.valores[s.id].forEach(function (v, i) {
                        if (v <= 0) return;
                        const x = xCentro(i) - total / 2 + j * (larguraBarra + espaco);
                        const alto = Math.max(1, (v / topo) * h);
                        svg('path', { d: caminhoBarra(x, m.topo + h - alto, larguraBarra, alto) }, g);
                    });
                });
            } else {
                const finais = [];
                series.forEach(function (s) {
                    const pontos = r.valores[s.id].map(function (v, i) { return [xCentro(i), y(v)]; });
                    svg('path', {
                        class: 'grafico__linha', stroke: s.cor,
                        d: pontos.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join('')
                    }, raiz);
                    const marcarTodos = n <= 36;
                    pontos.forEach(function (p, i) {
                        if (!marcarTodos && i !== n - 1) return;
                        svg('circle', { class: 'grafico__ponto', cx: p[0], cy: p[1], r: 4, fill: s.cor }, raiz);
                    });
                    finais.push({ y: pontos[n - 1][1], valor: r.valores[s.id][n - 1] });
                });
                // Rótulo de valor no fim de cada linha, só onde não colide com outro.
                if (comFinal) {
                    finais.sort(function (a, b) { return a.y - b.y; });
                    let anterior = -Infinity;
                    finais.forEach(function (f) {
                        if (f.y - anterior < 14) return;
                        anterior = f.y;
                        texto(svg('text', { class: 'grafico__rotulo', x: m.esq + w + 8, y: f.y + 4 }, raiz), reaisCurto(f.valor));
                    });
                }
            }

            svg('line', { class: 'grafico__base', x1: m.esq, x2: m.esq + w, y1: m.topo + h, y2: m.topo + h }, raiz);
            const mira = svg('line', { class: 'grafico__mira', y1: m.topo, y2: m.topo + h, visibility: 'hidden' }, raiz);
            const captura = svg('rect', { x: m.esq, y: 0, width: w, height: altura, fill: 'transparent' }, raiz);

            function focar(i) {
                if (i === null) {
                    estado.foco = null;
                    dica.hidden = true;
                    realce.setAttribute('visibility', 'hidden');
                    mira.setAttribute('visibility', 'hidden');
                    return;
                }
                i = Math.max(0, Math.min(n - 1, i));
                estado.foco = i;
                const cx = xCentro(i);
                if (estado.forma === 'colunas') {
                    realce.setAttribute('x', m.esq + faixa * i);
                    realce.setAttribute('visibility', 'visible');
                    mira.setAttribute('visibility', 'hidden');
                } else {
                    mira.setAttribute('x1', cx);
                    mira.setAttribute('x2', cx);
                    mira.setAttribute('visibility', 'visible');
                    realce.setAttribute('visibility', 'hidden');
                }
                dica.innerHTML = '';
                const titulo = document.createElement('div');
                titulo.className = 'grafico__dica-titulo';
                titulo.textContent = r.periodos[i].longo;
                dica.appendChild(titulo);
                series.forEach(function (s) {
                    const linha = document.createElement('div');
                    linha.className = 'grafico__dica-linha';
                    const chaveCor = document.createElement('span');
                    chaveCor.className = 'grafico__dica-chave';
                    chaveCor.style.background = s.cor;
                    const valor = document.createElement('strong');
                    valor.textContent = reais(r.valores[s.id][i]);
                    const nome = document.createElement('span');
                    nome.textContent = nomeSerie(s);
                    linha.append(chaveCor, valor, nome);
                    dica.appendChild(linha);
                });
                dica.hidden = false;
                const larguraDica = dica.offsetWidth;
                let esquerda = cx + 14;
                if (esquerda + larguraDica > largura - 4) esquerda = cx - 14 - larguraDica;
                dica.style.left = Math.max(4, esquerda) + 'px';
                dica.style.top = m.topo + 'px';
            }

            captura.addEventListener('pointermove', function (ev) {
                const caixa = raiz.getBoundingClientRect();
                focar(Math.floor((ev.clientX - caixa.left - m.esq) / faixa));
            });
            captura.addEventListener('pointerleave', function () { if (document.activeElement !== raiz) focar(null); });
            raiz.addEventListener('focus', function () { focar(estado.foco === null ? n - 1 : estado.foco); });
            raiz.addEventListener('blur', function () { focar(null); });
            raiz.addEventListener('keydown', function (ev) {
                const mapa = { ArrowLeft: -1, ArrowRight: 1, Home: -n, End: n };
                if (ev.key === 'Escape') { focar(null); return; }
                if (!(ev.key in mapa)) return;
                ev.preventDefault();
                focar((estado.foco === null ? n - 1 : estado.foco) + mapa[ev.key]);
            });

            areaSerie.insertBefore(raiz, dica);
            desenharTabela(r, series);
        }

        function desenharTabela(r, series) {
            const destino = cartaoSerie.querySelector('[data-serie-tabela]');
            const tabela = document.createElement('table');
            tabela.className = 'tabela tabela--compacta tabela--valores';
            const cab = tabela.createTHead().insertRow();
            texto(cab.appendChild(document.createElement('th')), 'Período');
            series.forEach(function (s) {
                const th = texto(document.createElement('th'), nomeSerie(s));
                th.className = 'texto-direita';
                cab.appendChild(th);
            });
            const corpo = tabela.createTBody();
            r.periodos.forEach(function (p, i) {
                const tr = corpo.insertRow();
                texto(tr.insertCell(), p.longo);
                series.forEach(function (s) {
                    const td = texto(tr.insertCell(), reais(r.valores[s.id][i]));
                    td.className = 'texto-direita valor nowrap';
                });
            });
            if (!estado.acumulado) {
                const rodape = tabela.createTFoot().insertRow();
                texto(rodape.insertCell(), 'Total do período');
                series.forEach(function (s) {
                    const valores = r.valores[s.id];
                    const td = texto(rodape.insertCell(), s.estoque ? reais(valores[valores.length - 1]) + ' (posição final)'
                        : reais(valores.reduce(function (a, b) { return a + b; }, 0)));
                    td.className = 'texto-direita valor nowrap';
                });
            }
            destino.innerHTML = '';
            destino.appendChild(tabela);
        }

        // ---------- Ranking por processo ----------

        const areaRanking = cartaoRanking.querySelector('[data-ranking-area]');
        const ROTULO_MEDIDA = {
            saldo: 'Saldo retido em ', bloqueado: 'Bloqueado no período', desbloqueado: 'Desbloqueado no período', emitidas: 'Guias emitidas no período'
        };
        const SERIE_MEDIDA = { saldo: 'saldo', bloqueado: 'bloqueado', desbloqueado: 'desbloqueado', emitidas: 'emitidas' };

        function desenharRanking(r) {
            const iv = r.iv;
            const somas = new Map();
            const somar = function (p, v) { somas.set(p, (somas.get(p) || 0) + v); };
            const medida = estado.rankingMedida;
            lancamentos.forEach(function (l) {
                if (!doProcesso(l) || l.d > iv.ate) return;
                if (medida === 'saldo') somar(l.p, l.t === 'B' ? l.v : -l.v);
                else if (l.d >= iv.de && ((medida === 'bloqueado' && l.t === 'B') || (medida === 'desbloqueado' && l.t === 'D'))) somar(l.p, l.v);
            });
            if (medida === 'emitidas') {
                guias.forEach(function (g) { if (doProcesso(g) && g.s !== 'CANCELADA' && g.d >= iv.de && g.d <= iv.ate) somar(g.p, g.v); });
            }
            const linhas = Array.from(somas.entries()).filter(function (e) { return e[1] > 0; })
                .sort(function (a, b) { return b[1] - a[1]; }).slice(0, Number(estado.rankingQuantidade));

            cartaoRanking.querySelector('[data-ranking-subtitulo]').textContent =
                (medida === 'saldo' ? ROTULO_MEDIDA.saldo + GJ.dataBr(iv.ate) : ROTULO_MEDIDA[medida] + ' (' + GJ.dataBr(iv.de) + ' a ' + GJ.dataBr(iv.ate) + ')') +
                ' · clique no processo para abrir a ficha';
            areaRanking.innerHTML = '';
            if (!linhas.length) {
                texto(areaRanking.appendChild(document.createElement('p')), 'Nenhum processo com valor nesta medida e período.').className = 'grafico__vazio';
                return;
            }

            const cor = SERIES.find(function (s) { return s.id === SERIE_MEDIDA[medida]; }).cor;
            const largura = Math.max(320, areaRanking.clientWidth);
            const rotuloW = Math.min(196, Math.round(largura * 0.42));
            const valorW = 96;
            const linhaH = 30, barraH = 16;
            const altura = linhas.length * linhaH + 8;
            const maximo = linhas[0][1];
            const w = largura - rotuloW - valorW;
            const raiz = svg('svg', {
                width: largura, height: altura, viewBox: '0 0 ' + largura + ' ' + altura, class: 'grafico__svg', role: 'list',
                'aria-label': 'Ranking por processo'
            });
            svg('line', { class: 'grafico__base', x1: rotuloW, x2: rotuloW, y1: 0, y2: altura - 4 }, raiz);
            linhas.forEach(function (e, i) {
                const topo = 4 + i * linhaH;
                const link = svg('a', {
                    href: GJ.url('processo-judicial.php?id=' + e[0]) + '#secao-bloqueios', class: 'grafico__item', role: 'listitem'
                }, raiz);
                texto(svg('title', {}, link), (nomeProcesso.get(e[0]) || '') + ': ' + reais(e[1]));
                svg('rect', { class: 'grafico__alvo', x: 0, y: topo, width: largura, height: linhaH - 2 }, link);
                texto(svg('text', { class: 'grafico__rotulo-processo', x: rotuloW - 8, y: topo + linhaH / 2 + 2, 'text-anchor': 'end' }, link),
                    nomeProcesso.get(e[0]) || ('#' + e[0]));
                const comprimento = Math.max(2, (e[1] / maximo) * w);
                const y0 = topo + (linhaH - barraH) / 2 - 1;
                // Ponta arredondada de 4px; lado da base reto.
                svg('path', {
                    fill: cor,
                    d: 'M' + rotuloW + ',' + y0 + 'H' + (rotuloW + comprimento - 4) + 'Q' + (rotuloW + comprimento) + ',' + y0 + ' ' + (rotuloW + comprimento) + ',' + (y0 + 4) +
                        'V' + (y0 + barraH - 4) + 'Q' + (rotuloW + comprimento) + ',' + (y0 + barraH) + ' ' + (rotuloW + comprimento - 4) + ',' + (y0 + barraH) + 'H' + rotuloW + 'Z'
                }, link);
                texto(svg('text', { class: 'grafico__rotulo', x: rotuloW + comprimento + 6, y: topo + linhaH / 2 + 2 }, link), reaisCurto(e[1]));
            });
            areaRanking.appendChild(raiz);
        }

        // ---------- Tabelas (mesmo recorte) ----------

        function filtrarTabela(seletor, contagem, singular, iv) {
            const caixa = document.querySelector(seletor);
            let vistos = 0, total = 0;
            caixa.querySelectorAll('tr[data-linha-filtravel]').forEach(function (tr) {
                total++;
                const ok = tr.dataset.data >= iv.de && tr.dataset.data <= iv.ate && (!estado.processo || tr.dataset.processo === estado.processo);
                tr.hidden = !ok;
                if (ok) vistos++;
            });
            const corpo = caixa.querySelector('tbody');
            let aviso = corpo.querySelector('[data-sem-linhas]');
            if (total > 0 && vistos === 0) {
                if (!aviso) {
                    aviso = corpo.insertRow();
                    aviso.dataset.semLinhas = '';
                    const td = aviso.insertCell();
                    td.colSpan = 20;
                    td.className = 'tabela__vazia';
                    td.textContent = 'Nenhum registro no período e processo escolhidos.';
                }
            } else if (aviso) {
                aviso.remove();
            }
            document.querySelector(contagem).textContent = vistos + ' de ' + total + ' ' + singular + ' no recorte';
        }

        // ---------- Controles ----------

        function sincronizarControles() {
            filtros.querySelectorAll('[data-periodo]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.periodo === estado.periodo)); });
            const datas = filtros.querySelector('[data-periodo-datas]');
            datas.hidden = estado.periodo !== 'personalizado';
            const iv = intervalo();
            filtros.querySelector('[data-filtro-de]').value = iv.de;
            filtros.querySelector('[data-filtro-ate]').value = iv.ate;
            filtros.querySelector('[data-filtro-ate]').max = hoje;
            cartaoSerie.querySelectorAll('[data-agrupamento]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.agrupamento === estado.agrupamento)); });
            cartaoSerie.querySelectorAll('[data-forma]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.forma === estado.forma)); });
            cartaoSerie.querySelector('[data-acumulado]').checked = estado.acumulado;
            cartaoRanking.querySelector('[data-ranking-medida]').value = estado.rankingMedida;
            cartaoRanking.querySelector('[data-ranking-quantidade]').value = estado.rankingQuantidade;
        }

        function atualizar() {
            estado.foco = null;
            sincronizarControles();
            const r = calcular();
            indicadores(r);
            desenharLegenda();
            desenharSerie(r);
            desenharRanking(r);
            filtrarTabela('[data-tabela-lancamentos]', '[data-contagem-lancamentos]', 'lançamento(s)', r.iv);
            filtrarTabela('[data-tabela-guias]', '[data-contagem-guias]', 'guia(s)', r.iv);
            gravarPreferencias(estado);
        }

        filtros.addEventListener('click', function (ev) {
            const b = ev.target.closest('[data-periodo]');
            if (!b) return;
            if (b.dataset.periodo === 'personalizado' && estado.periodo !== 'personalizado') {
                const iv = intervalo();
                estado.de = iv.de;
                estado.ate = iv.ate;
            }
            estado.periodo = b.dataset.periodo;
            atualizar();
        });
        filtros.addEventListener('change', function (ev) {
            if (ev.target.matches('[data-filtro-processo]')) {
                estado.processo = ev.target.value;
                const url = new URL(location.href);
                if (estado.processo) url.searchParams.set('processo', estado.processo); else url.searchParams.delete('processo');
                history.replaceState(null, '', url);
            } else if (ev.target.matches('[data-filtro-de]')) {
                estado.de = ev.target.value;
            } else if (ev.target.matches('[data-filtro-ate]')) {
                estado.ate = ev.target.value;
            }
            atualizar();
        });
        cartaoSerie.addEventListener('click', function (ev) {
            const ag = ev.target.closest('[data-agrupamento]');
            const forma = ev.target.closest('[data-forma]');
            const serie = ev.target.closest('[data-serie]');
            if (ag) estado.agrupamento = ag.dataset.agrupamento;
            else if (forma) estado.forma = forma.dataset.forma;
            else if (serie) {
                const id = serie.dataset.serie;
                const pos = estado.series.indexOf(id);
                if (pos >= 0 && estado.series.length > 1) estado.series.splice(pos, 1);
                else if (pos < 0) estado.series = SERIES.map(function (s) { return s.id; })
                    .filter(function (s) { return s === id || estado.series.indexOf(s) >= 0; });
                else return;
            } else return;
            atualizar();
            const mesmo = cartaoSerie.querySelector('[data-serie="' + (serie && serie.dataset.serie) + '"]');
            if (mesmo) mesmo.focus();
        });
        cartaoSerie.querySelector('[data-acumulado]').addEventListener('change', function (ev) {
            estado.acumulado = ev.target.checked;
            atualizar();
        });
        cartaoRanking.addEventListener('change', function (ev) {
            if (ev.target.matches('[data-ranking-medida]')) estado.rankingMedida = ev.target.value;
            if (ev.target.matches('[data-ranking-quantidade]')) estado.rankingQuantidade = ev.target.value;
            atualizar();
        });

        // Redesenha só a geometria quando a largura muda (menu recolhido, janela).
        let larguraAnterior = areaSerie.clientWidth;
        if ('ResizeObserver' in window) {
            new ResizeObserver(GJ.debounce(function () {
                if (Math.abs(areaSerie.clientWidth - larguraAnterior) < 8 || !ultimo) return;
                larguraAnterior = areaSerie.clientWidth;
                desenharSerie(ultimo);
                desenharRanking(ultimo);
            }, 120)).observe(areaSerie);
        }

        atualizar();
    });
})();
