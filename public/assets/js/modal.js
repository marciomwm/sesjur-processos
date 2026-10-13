/**
 * Modais de cadastro rápido (<dialog class="modal">).
 *
 * Gatilho: qualquer elemento com data-modal-abrir="idDoDialog". Quando é um
 * link, o href é a alternativa sem JavaScript (a página de cadastro comum).
 * data-modal-dados='{"campo": "valor"}' preenche o formulário (edição) e
 * data-modal-titulo troca o título do modal.
 *
 * O formulário do modal (form[data-modal-form]) é enviado por fetch com
 * "Accept: application/json". Sucesso: segue para `redirecionar` (a ficha,
 * já na âncora do registro criado). Erro de validação: marca cada campo
 * devolvido em error.details e mostra o resumo no topo do modal, sem
 * perder o que foi digitado.
 */
(function () {
    'use strict';

    const GJ = window.GJ;

    function limparErros(form) {
        form.querySelectorAll('.is-invalido').forEach(function (el) {
            el.classList.remove('is-invalido');
            el.removeAttribute('aria-invalid');
        });
        form.querySelectorAll('[data-erro-modal]').forEach(function (el) { el.remove(); });
        form.querySelectorAll('.campo__ajuda[data-oculta-por-erro]').forEach(function (el) {
            el.hidden = false;
            el.removeAttribute('data-oculta-por-erro');
        });
        const geral = form.querySelector('[data-modal-erro]');
        if (geral) { geral.hidden = true; geral.innerHTML = ''; }
    }

    function mostrarErros(form, detalhes, mensagem) {
        const avulsos = [];
        let primeiro = null;
        Object.keys(detalhes || {}).forEach(function (nome) {
            const campo = form.elements[nome];
            if (!campo || !campo.classList) { avulsos.push(detalhes[nome]); return; }
            campo.classList.add('is-invalido');
            campo.setAttribute('aria-invalid', 'true');
            const span = document.createElement('span');
            span.className = 'campo__erro';
            span.dataset.erroModal = '';
            span.textContent = detalhes[nome];
            const ajuda = campo.parentElement.querySelector('.campo__ajuda');
            if (ajuda) { ajuda.hidden = true; ajuda.dataset.ocultaPorErro = ''; }
            campo.insertAdjacentElement('afterend', span);
            primeiro = primeiro || campo;
        });
        const geral = form.querySelector('[data-modal-erro]');
        if (geral) {
            geral.innerHTML = '<span class="alert__icone">✕</span><span>' + GJ.esc(mensagem) +
                (avulsos.length ? ' ' + avulsos.map(GJ.esc).join(' ') : '') + '</span>';
            geral.hidden = false;
        }
        // O resumo tem tabindex="-1": recebe o foco quando o erro não é de um campo.
        const alvoFoco = primeiro || geral;
        if (alvoFoco) alvoFoco.focus();
    }

    function preencher(form, dados) {
        Object.keys(dados).forEach(function (nome) {
            const campo = form.elements[nome];
            if (!campo) return;
            campo.value = dados[nome] == null ? '' : String(dados[nome]);
        });
    }

    function abrir(dialogo, gatilho) {
        const form = dialogo.querySelector('form');
        const titulo = dialogo.querySelector('[data-modal-titulo]');
        if (titulo) {
            titulo.dataset.original = titulo.dataset.original || titulo.textContent;
            titulo.textContent = (gatilho && gatilho.dataset.modalTitulo) || titulo.dataset.original;
        }
        if (form) {
            form.reset();
            limparErros(form);
            // Campos ocultos não voltam ao padrão com reset(): o gatilho informa os valores.
            form.querySelectorAll('input[type="hidden"][data-padrao]').forEach(function (el) { el.value = el.dataset.padrao; });
            if (gatilho && gatilho.dataset.modalDados) {
                try { preencher(form, JSON.parse(gatilho.dataset.modalDados)); } catch (e) { /* dados opcionais */ }
            }
            form.dispatchEvent(new CustomEvent('modal:aberto', { detail: { gatilho: gatilho } }));
        }
        dialogo.showModal();
        const foco = dialogo.querySelector('[autofocus]') ||
            dialogo.querySelector('.modal__corpo input:not([type="hidden"]):not([disabled]), .modal__corpo select, .modal__corpo textarea');
        if (foco) foco.focus();
    }

    /** Vai para o destino; se só a âncora muda, recarrega para mostrar o registro novo. */
    function seguir(destino) {
        const url = new URL(destino || location.href, location.href);
        if (url.pathname === location.pathname && url.search === location.search) {
            history.replaceState(null, '', url.hash || location.hash);
            location.reload();
        } else {
            location.href = url.href;
        }
    }

    function ligarFormulario(form) {
        let enviando = false;
        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            if (enviando) return;
            enviando = true;
            limparErros(form);
            const botao = form.querySelector('[type="submit"]');
            const rotulo = botao ? botao.textContent : '';
            if (botao) { botao.disabled = true; botao.textContent = 'Salvando…'; }
            try {
                const resposta = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-Token': GJ.csrf }
                });
                let dados = {};
                try { dados = await resposta.json(); } catch (e) { /* resposta sem JSON */ }
                if (resposta.ok && dados.success) {
                    seguir(dados.redirecionar);
                    return;
                }
                const erro = dados.error || {};
                mostrarErros(form, erro.details || {}, erro.message || ('Não foi possível salvar (erro ' + resposta.status + ').'));
            } catch (e) {
                mostrarErros(form, {}, 'Falha de comunicação com o servidor. Verifique a conexão e tente novamente.');
            } finally {
                enviando = false;
                if (botao) { botao.disabled = false; botao.textContent = rotulo; }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-modal-form]').forEach(ligarFormulario);

        document.addEventListener('click', function (ev) {
            const gatilho = ev.target.closest('[data-modal-abrir]');
            if (gatilho) {
                const dialogo = document.getElementById(gatilho.dataset.modalAbrir);
                // Navegador sem <dialog>: segue o link da página de cadastro.
                if (!dialogo || typeof dialogo.showModal !== 'function') return;
                ev.preventDefault();
                abrir(dialogo, gatilho);
                return;
            }
            const fechar = ev.target.closest('[data-modal-fechar]');
            if (fechar) {
                const dialogo = fechar.closest('dialog');
                if (dialogo) dialogo.close();
            }
        });
    });
})();
