/**
 * Cadastro aberto pela extensão a partir de uma página do SIGADOC ou do PJe
 * (novo-processo-*.php?extensao=1).
 *
 * Recebe da extensão o que ela identificou na página — número do processo,
 * endereço (PJe) e o texto selecionado — e dispara a importação normal do
 * formulário: autorização no servidor, extração, revalidação e preenchimento.
 * Como em qualquer importação, nada é salvo sem a revisão do usuário.
 */
(function () {
    'use strict';

    const GJ = window.GJ;

    document.addEventListener('DOMContentLoaded', async function () {
        const params = new URLSearchParams(window.location.search);
        if (params.get('extensao') !== '1') return;

        // Um F5 não deve repetir a importação: tira o marcador da barra.
        params.delete('extensao');
        const query = params.toString();
        window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : ''));

        const formPa = document.getElementById('formProcessoAdministrativo');
        const formPj = document.getElementById('formProcessoJudicial');
        const form = formPa || formPj;
        if (!form) return;

        const UI = GJ.extracaoUI;
        const sistema = formPa ? 'SIGADOC' : 'PJe';
        const status = document.getElementById(formPa ? 'statusSigadoc' : 'statusPje');
        const botao = document.getElementById(formPa ? 'btnCompletarSigadoc' : 'btnCompletarPje');

        UI.processando(status, 'Recebendo os dados da página do ' + sistema + '…');
        const resposta = await GJ.extensao.obterPendente();
        const dados = resposta && resposta.success ? resposta.data : null;

        if (!resposta || !resposta.success) {
            UI.erro(status, UI.mensagemDe(resposta, 'A extensão não respondeu.'));
            return;
        }
        if (!dados) {
            UI.aviso(status, 'Os dados enviados pela extensão não estão mais disponíveis.',
                ['Confira o número do processo e use o botão de importação abaixo.']);
            return;
        }

        if (dados.selecao) mostrarSelecao(dados.selecao);

        if (!dados.identificador) {
            UI.erro(status, dados.erro || 'Não foi possível identificar o processo na página.',
                ['Informe o número do processo e use o botão de importação abaixo.']);
            return;
        }

        const existente = document.querySelector('[data-processo-existente]');
        if (existente) {
            UI.limpar(status);
            existente.scrollIntoView({ block: 'center' });
            return;
        }

        if (formPj && dados.url) {
            const campoUrl = form.querySelector('[name="url_origem"]');
            if (campoUrl && !campoUrl.value) campoUrl.value = dados.url;
        }

        if (formPj && !form.querySelector('[name="url_origem"]').value) {
            UI.aviso(status, 'Processo ' + dados.identificador + ' identificado pelo texto selecionado.',
                ['Cole o endereço da página do processo no PJe e clique em “Importar do PJe”.']);
            return;
        }

        UI.limpar(status);
        botao.click();

        /** Texto selecionado na página, oferecido para o objeto (PA) ou as observações (PJ). */
        function mostrarSelecao(texto) {
            const alvo = form.querySelector(formPa ? '[name="objeto"]' : '[name="observacoes"]');
            if (!alvo) return;
            const destino = formPa ? 'Objeto' : 'Observações internas';

            const caixa = document.createElement('div');
            caixa.className = 'selecao-extensao';
            caixa.innerHTML =
                '<strong>Texto selecionado na página do ' + GJ.esc(sistema) + '</strong>' +
                '<blockquote>' + GJ.esc(texto) + '</blockquote>' +
                '<button type="button" class="btn btn--secundario btn--sm" data-usar>Usar em “' + GJ.esc(destino) + '”</button> ' +
                '<button type="button" class="btn btn--ghost btn--sm" data-descartar>Descartar</button>';
            status.insertAdjacentElement('afterend', caixa);

            caixa.querySelector('[data-usar]').addEventListener('click', function () {
                alvo.value = alvo.value.trim() ? alvo.value.trim() + '\n\n' + texto : texto;
                alvo.classList.add('is-preenchido-extracao');
                alvo.dispatchEvent(new Event('input', { bubbles: true }));
                caixa.remove();
                alvo.focus();
            });
            caixa.querySelector('[data-descartar]').addEventListener('click', function () { caixa.remove(); });
        }
    });
})();
