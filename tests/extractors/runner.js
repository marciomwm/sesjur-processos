/**
 * Runner dos testes de extração (executa no navegador).
 *
 * Cada fixture é carregada com fetch + DOMParser, e o extrator roda contra o
 * Document resultante — o mesmo código que roda dentro da extensão.
 */
(function () {
'use strict';

const GJ = globalThis.GJ;
const { CASOS, CASOS_UNITARIOS } = globalThis.GJ_CASOS;

const saida = document.getElementById('resultados');
const resumo = document.getElementById('resumo');
let totalOk = 0, totalFalha = 0;

function criarAsserts(linhas) {
    function registrar(ok, descricao, detalhe) {
        linhas.push({ ok, descricao, detalhe });
        if (ok) totalOk++; else totalFalha++;
    }
    return {
        ok(valor, descricao) {
            registrar(!!valor, descricao, valor ? '' : 'esperado verdadeiro, recebido ' + JSON.stringify(valor));
        },
        igual(recebido, esperado, descricao) {
            const ok = Object.is(recebido, esperado);
            registrar(ok, descricao, ok ? '' : 'esperado ' + JSON.stringify(esperado) + ', recebido ' + JSON.stringify(recebido));
        },
        contem(texto, trecho, descricao) {
            const ok = typeof texto === 'string' && texto.indexOf(trecho) >= 0;
            registrar(ok, descricao, ok ? '' : 'esperado conter "' + trecho + '", recebido ' + JSON.stringify(texto));
        }
    };
}

function renderizar(titulo, linhas, extra) {
    const bloco = document.createElement('section');
    bloco.className = 'caso ' + (linhas.every(l => l.ok) ? 'passou' : 'falhou');

    const h = document.createElement('h3');
    h.textContent = (linhas.every(l => l.ok) ? '✓ ' : '✕ ') + titulo;
    bloco.appendChild(h);

    const ul = document.createElement('ul');
    linhas.forEach(function (l) {
        const li = document.createElement('li');
        li.className = l.ok ? 'ok' : 'falha';
        li.textContent = (l.ok ? '✓ ' : '✕ ') + l.descricao + (l.detalhe ? ' — ' + l.detalhe : '');
        ul.appendChild(li);
    });
    bloco.appendChild(ul);

    if (extra) {
        const det = document.createElement('details');
        const sum = document.createElement('summary');
        sum.textContent = 'Contrato retornado';
        det.appendChild(sum);
        const pre = document.createElement('pre');
        pre.textContent = JSON.stringify(extra, null, 2);
        det.appendChild(pre);
        bloco.appendChild(det);
    }

    saida.appendChild(bloco);
}

async function carregarFixture(caminho) {
    const resposta = await fetch(caminho);
    if (!resposta.ok) throw new Error('Fixture não encontrada: ' + caminho + ' (HTTP ' + resposta.status + ')');
    const html = await resposta.text();
    return new DOMParser().parseFromString(html, 'text/html');
}

async function executar() {
    // 1. Testes unitários (normalizadores, configuração).
    for (const caso of CASOS_UNITARIOS) {
        const linhas = [];
        const t = criarAsserts(linhas);
        try {
            await caso.executar(t, GJ);
        } catch (e) {
            linhas.push({ ok: false, descricao: 'exceção', detalhe: e.message });
            totalFalha++;
        }
        renderizar(caso.nome, linhas);
    }

    // 2. Testes de extração contra as fixtures.
    for (const caso of CASOS) {
        const linhas = [];
        const t = criarAsserts(linhas);
        let resultado = null;
        try {
            const doc = await carregarFixture(caso.fixture);
            // Página "Ver Mais" — na extensão, carregada pelo collector.
            const completo = caso.fixtureCompleta ? await carregarFixture(caso.fixtureCompleta) : null;
            resultado = caso.sistema === 'SIGADOC'
                ? GJ.sigadocExtractor.extrair(doc, { protocoloEsperado: caso.esperado, url: caso.fixture, documentoCompleto: completo })
                : GJ.pjeExtractor.extrair(doc, { cnjEsperado: caso.esperado, url: caso.fixture });
            caso.verificar(resultado, t);
        } catch (e) {
            linhas.push({ ok: false, descricao: 'exceção durante o teste', detalhe: e.message });
            totalFalha++;
        }
        renderizar(caso.nome, linhas, resultado);
    }

    resumo.textContent = totalOk + ' asserções aprovadas · ' + totalFalha + ' falharam';
    resumo.className = totalFalha === 0 ? 'resumo ok' : 'resumo falha';
    document.title = (totalFalha === 0 ? '✓' : '✕') + ' Testes dos extratores';
    globalThis.__GJ_RESULTADO__ = { ok: totalOk, falha: totalFalha };
}

executar();
})();
