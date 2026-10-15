/**
 * Intimações Pessoais.
 *
 * - Campos de pesquisa ([data-pesquisa], partial campo-pesquisa.php):
 *   autocomplete de processos e de agentes públicos; o id (e o tipo do
 *   processo) do item escolhido vai para os campos ocultos. Digitar de novo
 *   desfaz a escolha — só vale o que foi escolhido na lista.
 * - "= Pessoa intimada" copia a pessoa intimada para "Recebido por"; "Recebi
 *   eu" preenche com o agente do usuário logado (só por escolha expressa).
 * - Status Cumprida mostra e exige a data do cumprimento.
 * - O prazo não pode ser anterior ao recebimento; na edição, mudar o prazo
 *   mostra a justificativa obrigatória.
 * - Modal de cumprimento: contexto (prazo) e aviso de cumprimento fora do prazo.
 */
(function () {
    'use strict';

    const GJ = window.GJ;

    function ligarPesquisa(campo) {
        const busca = campo.querySelector('[data-pesquisa-busca]');
        const lista = campo.querySelector('[data-pesquisa-resultados]');
        const valor = campo.querySelector('[data-pesquisa-valor]');
        const tipo = campo.querySelector('[data-pesquisa-tipo]');
        const escolhido = campo.querySelector('[data-pesquisa-escolhido]');
        const ajudaInicial = escolhido.textContent;
        const minimo = Number(campo.dataset.minimo || 3);
        let itens = [];
        let ativo = -1;
        let consulta = 0;

        function fechar() {
            lista.hidden = true;
            lista.innerHTML = '';
            busca.setAttribute('aria-expanded', 'false');
            busca.removeAttribute('aria-activedescendant');
            itens = [];
            ativo = -1;
        }

        function escolher(item) {
            valor.value = String(item.id);
            if (tipo) tipo.value = item.tipo || '';
            busca.value = item.rotulo;
            busca.classList.remove('is-invalido');
            escolhido.textContent = 'Escolhido: ' + item.rotulo + (item.detalhe ? ' · ' + item.detalhe : '');
            fechar();
            campo.dispatchEvent(new CustomEvent('pesquisa:escolhido', { detail: item, bubbles: true }));
        }

        function desfazer() {
            valor.value = '';
            if (tipo) tipo.value = '';
            escolhido.textContent = ajudaInicial.indexOf('Escolhido:') === 0 ? 'Nenhum item escolhido.' : ajudaInicial;
        }

        function marcar(i) {
            const opcoes = lista.querySelectorAll('[role="option"]');
            opcoes.forEach(function (op, j) { op.classList.toggle('is-ativo', j === i); op.setAttribute('aria-selected', String(j === i)); });
            ativo = i;
            if (opcoes[i]) {
                busca.setAttribute('aria-activedescendant', opcoes[i].id);
                opcoes[i].scrollIntoView({ block: 'nearest' });
            }
        }

        function mostrar(resultado, mensagem) {
            lista.innerHTML = '';
            itens = resultado;
            if (!itens.length) {
                const vazio = document.createElement('div');
                vazio.className = 'vinculos__resultado vinculos__resultado--vazio';
                vazio.textContent = mensagem || campo.dataset.vazio || 'Nenhum resultado.';
                lista.appendChild(vazio);
            }
            itens.forEach(function (item, i) {
                const op = document.createElement('div');
                op.className = 'vinculos__resultado';
                op.setAttribute('role', 'option');
                op.id = lista.id + '-' + i;
                const forte = document.createElement('strong');
                forte.textContent = item.rotulo;
                const detalhe = document.createElement('span');
                detalhe.textContent = item.detalhe || '';
                op.append(forte, detalhe);
                op.addEventListener('mousedown', function (ev) { ev.preventDefault(); escolher(item); });
                lista.appendChild(op);
            });
            lista.hidden = false;
            busca.setAttribute('aria-expanded', 'true');
        }

        const pesquisar = GJ.debounce(async function () {
            const termo = busca.value.trim();
            if (termo.length < minimo) { fechar(); return; }
            const minha = ++consulta;
            const carregando = document.createElement('div');
            carregando.className = 'vinculos__resultado vinculos__resultado--vazio';
            carregando.textContent = 'Pesquisando…';
            lista.replaceChildren(carregando);
            lista.hidden = false;
            try {
                const resposta = await GJ.fetchJson(campo.dataset.endpoint + '?termo=' + encodeURIComponent(termo));
                if (minha === consulta) mostrar(resposta.itens || []);
            } catch (e) {
                if (minha === consulta) mostrar([], 'Falha na pesquisa. Tente novamente.');
            }
        }, 250);

        busca.addEventListener('input', function () { desfazer(); pesquisar(); });
        busca.addEventListener('blur', function () { setTimeout(fechar, 150); });
        busca.addEventListener('keydown', function (ev) {
            if (lista.hidden || !itens.length) {
                if (ev.key === 'Enter' && campo.closest('form[data-form-intimacao]')) ev.preventDefault();
                return;
            }
            if (ev.key === 'ArrowDown') { ev.preventDefault(); marcar(Math.min(itens.length - 1, ativo + 1)); }
            else if (ev.key === 'ArrowUp') { ev.preventDefault(); marcar(Math.max(0, ativo - 1)); }
            else if (ev.key === 'Enter') { ev.preventDefault(); escolher(itens[ativo >= 0 ? ativo : 0]); }
            else if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); fechar(); }
        });

        campo.gjEscolher = escolher;
        campo.gjItem = function () {
            return valor.value ? { id: valor.value, tipo: tipo ? tipo.value : '', rotulo: busca.value,
                detalhe: escolhido.textContent.replace(/^Escolhido: [^·]*· ?/, '') } : null;
        };
    }

    function campoPesquisa(idBusca) {
        const busca = document.getElementById(idBusca);
        return busca ? busca.closest('[data-pesquisa]') : null;
    }

    function ligarFormulario(form) {
        // Atalhos de preenchimento do "Recebido por".
        form.querySelectorAll('[data-preencher-pesquisa]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                const alvo = campoPesquisa(botao.dataset.preencherPesquisa);
                try { if (alvo) alvo.gjEscolher(JSON.parse(botao.dataset.item)); } catch (e) { /* item inválido */ }
            });
        });
        form.querySelectorAll('[data-copiar-de]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                const origem = campoPesquisa(botao.dataset.copiarDe);
                const alvo = campoPesquisa(botao.dataset.copiarPara);
                const item = origem && origem.gjItem();
                if (item && alvo) alvo.gjEscolher(item);
                else if (origem) origem.querySelector('[data-pesquisa-busca]').focus();
            });
        });

        // Status Cumprida: data do cumprimento visível e obrigatória.
        const status = form.querySelector('[data-status]');
        const blocoCumprimento = form.querySelector('[data-bloco-cumprimento]');
        const cumprimento = form.elements.data_cumprimento;
        function atualizarStatus() {
            const cumprida = status.value === 'CUMPRIDA';
            blocoCumprimento.hidden = !cumprida;
            cumprimento.required = cumprida;
            if (cumprida && !cumprimento.value) cumprimento.value = cumprimento.max || '';
        }
        status.addEventListener('change', atualizarStatus);

        // Prazo >= recebimento; justificativa quando o prazo muda na edição.
        const recebimento = form.querySelector('[data-recebimento]');
        const prazo = form.querySelector('[data-prazo]');
        const blocoJustificativa = form.querySelector('[data-bloco-justificativa]');
        function atualizarDatas() {
            prazo.min = recebimento.value || '';
            cumprimento.min = recebimento.value || '';
            if (blocoJustificativa) {
                const mudou = !!prazo.dataset.original && prazo.value !== prazo.dataset.original;
                blocoJustificativa.hidden = !mudou && !blocoJustificativa.querySelector('.campo__erro');
                form.elements.justificativa.required = mudou;
            }
        }
        recebimento.addEventListener('change', atualizarDatas);
        prazo.addEventListener('change', atualizarDatas);
        prazo.addEventListener('input', atualizarDatas);
        atualizarDatas();

        // Sem escolha na lista, o campo de pesquisa não vale: avisa antes de enviar.
        form.addEventListener('submit', function (ev) {
            let primeiro = null;
            form.querySelectorAll('[data-pesquisa]').forEach(function (campo) {
                const busca = campo.querySelector('[data-pesquisa-busca]');
                const vazio = !campo.querySelector('[data-pesquisa-valor]').value;
                busca.classList.toggle('is-invalido', vazio);
                if (vazio && !primeiro) primeiro = busca;
            });
            if (primeiro) {
                ev.preventDefault();
                primeiro.focus();
                primeiro.closest('[data-pesquisa]').querySelector('[data-pesquisa-escolhido]').textContent =
                    'Pesquise e escolha um item da lista para continuar.';
            }
        });
    }

    function ligarModalCumprimento(form) {
        const contexto = form.closest('dialog').querySelector('[data-cumprimento-contexto]');
        const aviso = form.querySelector('[data-cumprimento-aviso]');
        const data = form.elements.data_cumprimento;
        const avisoPadrao = aviso.textContent;
        let prazo = '';

        function conferir() {
            const fora = prazo && data.value && data.value > prazo;
            aviso.textContent = fora ? 'Depois do prazo (' + GJ.dataBr(prazo) + '): será sinalizada como cumprida fora do prazo.' : avisoPadrao;
            aviso.classList.toggle('campo__ajuda--alerta', !!fora);
        }

        form.addEventListener('modal:aberto', function (ev) {
            const gatilho = ev.detail && ev.detail.gatilho;
            prazo = (gatilho && gatilho.dataset.prazo) || '';
            data.min = (gatilho && gatilho.dataset.recebimento) || '';
            contexto.textContent = gatilho && gatilho.dataset.intimado
                ? 'Intimação de ' + gatilho.dataset.intimado + ' · prazo ' + GJ.dataBr(prazo) + '. Passa para o status Cumprida.'
                : 'A intimação passa para o status Cumprida.';
            conferir();
        });
        data.addEventListener('input', conferir);
        data.addEventListener('change', conferir);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-pesquisa]').forEach(ligarPesquisa);
        document.querySelectorAll('form[data-form-intimacao]').forEach(ligarFormulario);
        document.querySelectorAll('form[data-form-cumprimento]').forEach(ligarModalCumprimento);
    });
})();
