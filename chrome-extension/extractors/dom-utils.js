/**
 * Motor genérico de leitura do DOM.
 *
 * Nada aqui conhece SIGADOC ou PJe: recebe a descrição de um campo
 * (config/selectors.js) e devolve o valor encontrado ou `null`.
 * Nunca inventa valores — a ausência é sempre `null` + warning.
 *
 * Páginas dinâmicas (seção 29): usa MutationObserver com timeout e polling
 * limitado. Nunca há laço infinito.
 */
(function (raiz) {
'use strict';

/**
 * Texto de um elemento, normalizado.
 *
 * `innerText` é preferido por respeitar a formatação visível (quebras de linha,
 * espaços colapsados), mas devolve STRING VAZIA quando o elemento não gera
 * caixas de texto — o que acontece sob `visibility: hidden`, e não apenas sob
 * `display: none` (onde a especificação manda cair para `textContent`).
 *
 * O PJe depende disso: o bloco "Mais detalhes", com classe, assunto, datas,
 * valor da causa e as partes, fica sob `visibility: hidden` até o usuário abrir
 * o menu do topo. Sem este fallback, todos esses campos eram lidos como vazios
 * e reportados como "não encontrados no DOM".
 */
function texto(el) {
    if (!el) return null;
    let bruto;
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
        bruto = el.value;
    } else if (el.tagName === 'SELECT') {
        bruto = el.selectedOptions && el.selectedOptions[0] ? el.selectedOptions[0].textContent : '';
    } else {
        bruto = el.innerText;
        if (bruto == null || String(bruto).trim() === '') {
            bruto = el.textContent;
        }
    }
    return limpar(bruto);
}

/** Colapsa espaços, remove NBSP e devolve null quando vazio. */
function limpar(valor) {
    if (valor == null) return null;
    const s = String(valor).replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
    return s === '' ? null : s;
}

/** Remove acentos e normaliza a caixa, para comparação de rótulos. */
function chave(valor) {
    return String(valor || '')
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase().replace(/[:：]\s*$/, '').trim();
}

/** Elemento é visível (não oculto por display/visibility/hidden). */
function visivel(el) {
    if (!el || !el.ownerDocument) return false;
    const janela = el.ownerDocument.defaultView;
    if (!janela || !janela.getComputedStyle) return true; // ambiente de teste
    const estilo = janela.getComputedStyle(el);
    return estilo.display !== 'none' && estilo.visibility !== 'hidden';
}

/** Primeiro elemento que casa com a lista de seletores CSS, em ordem. */
function porSeletores(raizEl, listaCss) {
    if (!Array.isArray(listaCss)) return null;
    for (const css of listaCss) {
        if (!css) continue;
        let el = null;
        try {
            el = raizEl.querySelector(css);
        } catch (e) {
            continue; // seletor inválido não derruba a extração
        }
        if (el) return el;
    }
    return null;
}

/** Todos os elementos que casam com a lista de seletores CSS. */
function todosPorSeletores(raizEl, listaCss) {
    if (!Array.isArray(listaCss)) return [];
    for (const css of listaCss) {
        if (!css) continue;
        try {
            const lista = raizEl.querySelectorAll(css);
            if (lista.length) return Array.from(lista);
        } catch (e) { /* ignora seletor inválido */ }
    }
    return [];
}

/**
 * Busca o valor associado a um rótulo visível, cobrindo as marcações usuais:
 *   label[for] → controle · label > controle · dt → dd
 *   th → td (mesma linha) · th → td (mesma coluna) · "Rótulo: valor"
 *   elemento rotulador → irmão seguinte
 */
function porRotulo(raizEl, rotulos) {
    if (!Array.isArray(rotulos) || rotulos.length === 0) return null;
    const alvos = rotulos.map(chave);
    const doc = raizEl.ownerDocument || raizEl;

    // 1. <label for="id">
    for (const label of raizEl.querySelectorAll('label[for]')) {
        if (!alvos.includes(chave(label.textContent))) continue;
        const controle = doc.getElementById(label.getAttribute('for'));
        const v = texto(controle);
        if (v) return v;
    }

    // 2. <label> envolvendo o controle
    for (const label of raizEl.querySelectorAll('label')) {
        if (!alvos.includes(chave(label.textContent))) continue;
        const controle = label.querySelector('input, textarea, select, output, span[data-valor]');
        const v = texto(controle);
        if (v) return v;
    }

    // 3. aria-label / aria-labelledby
    for (const el of raizEl.querySelectorAll('[aria-label]')) {
        if (alvos.includes(chave(el.getAttribute('aria-label')))) {
            const v = texto(el);
            if (v) return v;
        }
    }

    // 4. <dt> → <dd>
    for (const dt of raizEl.querySelectorAll('dt')) {
        if (!alvos.includes(chave(dt.textContent))) continue;
        let irmao = dt.nextElementSibling;
        while (irmao && irmao.tagName !== 'DD') irmao = irmao.nextElementSibling;
        const v = texto(irmao);
        if (v) return v;
    }

    // 5. <th> → <td> na mesma linha
    for (const th of raizEl.querySelectorAll('th')) {
        if (!alvos.includes(chave(th.textContent))) continue;
        const td = th.parentElement ? th.parentElement.querySelector('td') : null;
        const v = texto(td);
        if (v) return v;
    }

    // 6. <th> como cabeçalho de coluna → primeira <td> correspondente
    for (const tabela of raizEl.querySelectorAll('table')) {
        const cabecalhos = Array.from(tabela.querySelectorAll('thead th, tr:first-child th'));
        const indice = cabecalhos.findIndex(function (th) { return alvos.includes(chave(th.textContent)); });
        if (indice < 0) continue;
        const linha = tabela.querySelector('tbody tr') || tabela.querySelectorAll('tr')[1];
        if (!linha) continue;
        const v = texto(linha.children[indice]);
        if (v) return v;
    }

    // 7. Tabela vertical: rótulo em uma <tr>, valor na <tr> seguinte da MESMA tabela.
    //    Padrão observado no documento renderizado do SIGA-DOC, onde as tabelas
    //    não possuem id nem classe.
    for (const celula of raizEl.querySelectorAll('td, th')) {
        if (!alvos.includes(chave(celula.textContent))) continue;

        const linha = celula.closest('tr');
        if (!linha) continue;

        // Só se aplica quando o rótulo ocupa a linha inteira (uma célula, ou
        // uma célula com colspan cobrindo a linha).
        const rotuloOcupaLinha = linha.children.length === 1;
        if (!rotuloOcupaLinha) continue;

        const tabela = linha.closest('table');
        const proxima = linha.nextElementSibling;
        if (!proxima || proxima.tagName !== 'TR') continue;
        if (tabela && proxima.closest('table') !== tabela) continue;

        const indice = Array.prototype.indexOf.call(linha.children, celula);
        const alvo = proxima.children[indice] || proxima.children[0];
        const v = texto(alvo);
        if (v && chave(v) !== chave(celula.textContent)) return v;
    }

    // 8. Elemento rotulador → irmão/descendente seguinte
    const rotuladores = raizEl.querySelectorAll('span, strong, b, div, td, p, h1, h2, h3, h4, h5, h6, legend');
    for (const el of rotuladores) {
        const proprio = chave(el.textContent);
        if (!alvos.includes(proprio)) continue;
        if (!visivel(el)) continue;

        let irmao = el.nextElementSibling;
        let saltos = 0;
        while (irmao && saltos < 3) {
            const v = texto(irmao);
            if (v && chave(v) !== proprio) return v;
            irmao = irmao.nextElementSibling;
            saltos++;
        }
        // Valor dentro do próprio pai, após o rótulo.
        const pai = el.parentElement;
        if (pai) {
            const completo = texto(pai);
            if (completo) {
                const restante = limpar(completo.slice(texto(el) ? texto(el).length : 0).replace(/^[:\-–]\s*/, ''));
                if (restante) return restante;
            }
        }
    }

    // 9. Padrão textual "Rótulo: valor" em um nó de texto único
    const caminhador = doc.createTreeWalker(raizEl, 4 /* NodeFilter.SHOW_TEXT */);
    let no;
    while ((no = caminhador.nextNode())) {
        const conteudo = limpar(no.nodeValue);
        if (!conteudo || conteudo.length > 400) continue;
        for (let i = 0; i < rotulos.length; i++) {
            const prefixo = chave(rotulos[i]);
            const normalizado = chave(conteudo);
            if (normalizado.startsWith(prefixo)) {
                const resto = conteudo.slice(rotulos[i].length).replace(/^[\s:：\-–]+/, '');
                const v = limpar(resto);
                if (v) return v;
            }
        }
    }

    return null;
}

/**
 * Extrai do texto apenas o trecho que casa com o padrão do campo.
 * Devolve o texto original quando não há padrão definido, e null quando há
 * padrão mas nada casa — evitando aceitar um valor contaminado por ruído.
 */
function aplicarPadrao(valor, padrao) {
    if (!valor) return null;
    if (!padrao) return valor;
    const m = String(valor).match(padrao);
    return m ? limpar(m[0]) : null;
}

/** Primeira ocorrência de um padrão (regex) no texto da página. */
function porPadrao(raizEl, padrao) {
    if (!padrao) return null;
    // Mesmo cuidado de texto(): innerText vem vazio sob visibility:hidden.
    let conteudo = raizEl.innerText;
    if (conteudo == null || String(conteudo).trim() === '') {
        conteudo = raizEl.textContent;
    }
    if (!conteudo) return null;
    const m = String(conteudo).replace(/ /g, ' ').match(padrao);
    return m ? limpar(m[0]) : null;
}

/**
 * Resolve um campo descrito em config/selectors.js.
 * Ordem: seletores CSS → rótulos → padrão textual.
 * @returns {{valor: (string|null), estrategia: string}}
 */
function resolverCampo(raizEl, descricao) {
    if (!descricao) return { valor: null, estrategia: 'indefinida' };

    const porCss = aplicarPadrao(texto(porSeletores(raizEl, descricao.css)), descricao.padrao);
    if (porCss) return { valor: porCss, estrategia: 'css' };

    const porLabel = aplicarPadrao(porRotulo(raizEl, descricao.labels), descricao.padrao);
    if (porLabel) return { valor: porLabel, estrategia: 'rotulo' };

    const porRegex = porPadrao(raizEl, descricao.padrao);
    if (porRegex) return { valor: porRegex, estrategia: 'padrao' };

    return { valor: null, estrategia: 'nao-encontrado' };
}

/**
 * Espera um elemento aparecer. MutationObserver + timeout; sem laço infinito.
 * @returns {Promise<Element|null>}
 */
function esperarElemento(raizEl, listaCss, timeoutMs) {
    const limite = timeoutMs || 8000;
    return new Promise(function (resolver) {
        const imediato = porSeletores(raizEl, listaCss);
        if (imediato) { resolver(imediato); return; }

        let finalizado = false;
        const terminar = function (el) {
            if (finalizado) return;
            finalizado = true;
            clearTimeout(cronometro);
            observador.disconnect();
            resolver(el);
        };

        const observador = new MutationObserver(function () {
            const el = porSeletores(raizEl, listaCss);
            if (el) terminar(el);
        });
        observador.observe(raizEl, { childList: true, subtree: true, characterData: true });

        const cronometro = setTimeout(function () { terminar(null); }, limite);
    });
}

/**
 * Espera a página parar de mudar (útil em SPA/AJAX): resolve após `quietMs`
 * sem mutações, ou ao atingir `timeoutMs`.
 */
function esperarEstabilizar(raizEl, quietMs, timeoutMs) {
    const quieto = quietMs || 600;
    const limite = timeoutMs || 6000;
    return new Promise(function (resolver) {
        let cronometroQuieto = null;
        let finalizado = false;

        const terminar = function () {
            if (finalizado) return;
            finalizado = true;
            clearTimeout(cronometroQuieto);
            clearTimeout(cronometroLimite);
            observador.disconnect();
            resolver(true);
        };

        const reiniciar = function () {
            clearTimeout(cronometroQuieto);
            cronometroQuieto = setTimeout(terminar, quieto);
        };

        const observador = new MutationObserver(reiniciar);
        observador.observe(raizEl, { childList: true, subtree: true, characterData: true });

        const cronometroLimite = setTimeout(terminar, limite);
        reiniciar();
    });
}

const API = {
    texto, limpar, chave, visivel,
    porSeletores, todosPorSeletores, porRotulo, porPadrao, aplicarPadrao,
    resolverCampo, esperarElemento, esperarEstabilizar
};

raiz.GJ = raiz.GJ || {};
raiz.GJ.dom = API;
if (typeof module !== 'undefined' && module.exports) { module.exports = API; }

})(typeof globalThis !== 'undefined' ? globalThis : self);
