/**
 * Modais de lançamento de Bloqueio/Desbloqueio e de Guia de Depósito
 * (ficha do processo judicial e painel de bloqueios).
 *
 * - Painel: busca do processo judicial pelo número CNJ (api/pesquisar-
 *   processos-judiciais.php). Na edição o processo é fixo: o gatilho traz
 *   data-processo-rotulo e a busca some.
 * - Valor: ao sair do campo, reescreve no formato 1.234,56 (o servidor
 *   aceita também 1234,56 e 1234.56).
 * - Guia: a data do pagamento só aparece (e só vale) na situação Paga.
 */
(function () {
    'use strict';

    const GJ = window.GJ;

    /** Mesmas regras de App\Core\Dinheiro::centavos(); null se ilegível. */
    function centavos(texto) {
        const t = String(texto || '').replace(/R\$|\s| /g, '');
        let m = t.match(/^(\d{1,3}(?:\.\d{3})+)(?:,(\d{1,2}))?$/);
        let inteiro, fracao;
        if (m) {
            inteiro = m[1].replace(/\./g, ''); fracao = m[2] || '';
        } else if ((m = t.match(/^(\d+)(?:[,.](\d{1,2}))?$/))) {
            inteiro = m[1]; fracao = m[2] || '';
        } else {
            return null;
        }
        if (inteiro.replace(/^0+/, '').length > 13) return null;
        return Number(inteiro) * 100 + Number((fracao + '00').slice(0, 2));
    }

    function formatar(c) {
        const inteiro = Math.floor(c / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return inteiro + ',' + String(c % 100).padStart(2, '0');
    }

    function ligarValor(form) {
        form.querySelectorAll('[data-campo-valor]').forEach(function (campo) {
            campo.addEventListener('blur', function () {
                const c = centavos(campo.value);
                if (c !== null && c > 0) campo.value = formatar(c);
            });
        });
    }

    function ligarProcesso(form) {
        const campo = form.querySelector('[data-processo-busca-campo]');
        const contexto = form.closest('dialog').querySelector('[data-processo-contexto]');
        const rotuloContexto = contexto && contexto.querySelector('[data-processo-contexto-rotulo]');

        form.addEventListener('modal:aberto', function (ev) {
            const gatilho = ev.detail && ev.detail.gatilho;
            const fixo = gatilho && gatilho.dataset.processoRotulo;
            if (!campo) return; // ficha: o processo já é fixo no formulário
            campo.hidden = !!fixo;
            contexto.hidden = !fixo;
            if (fixo) rotuloContexto.textContent = fixo;
            limparEscolha();
        });

        if (!campo) return;
        const busca = campo.querySelector('[data-processo-busca]');
        const lista = campo.querySelector('[data-processo-resultados]');
        const escolhido = campo.querySelector('[data-processo-escolhido]');
        const oculto = form.elements.processo_judicial_id;
        let itens = [];
        let ativo = -1;
        let consulta = 0;

        function limparEscolha() {
            busca.value = '';
            escolhido.textContent = 'Nenhum processo escolhido.';
            fechar();
        }

        function fechar() {
            lista.hidden = true;
            lista.innerHTML = '';
            busca.setAttribute('aria-expanded', 'false');
            itens = [];
            ativo = -1;
        }

        function escolher(item) {
            oculto.value = String(item.id);
            busca.value = item.rotulo;
            escolhido.textContent = 'Escolhido: ' + item.rotulo + (item.detalhe ? ' · ' + item.detalhe : '');
            fechar();
        }

        function marcar(i) {
            const opcoes = lista.querySelectorAll('[role="option"]');
            opcoes.forEach(function (op, j) { op.classList.toggle('is-ativo', j === i); op.setAttribute('aria-selected', String(j === i)); });
            ativo = i;
            if (opcoes[i]) opcoes[i].scrollIntoView({ block: 'nearest' });
        }

        function mostrar(resultado) {
            lista.innerHTML = '';
            itens = resultado;
            if (!itens.length) {
                const vazio = document.createElement('div');
                vazio.className = 'vinculos__resultado vinculos__resultado--vazio';
                vazio.textContent = 'Nenhum processo judicial encontrado. Cadastre-o antes em Novo Processo Judicial.';
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
            if (termo.length < 3) { fechar(); return; }
            const minha = ++consulta;
            try {
                const resposta = await GJ.fetchJson('api/pesquisar-processos-judiciais.php?termo=' + encodeURIComponent(termo));
                if (minha === consulta) mostrar(resposta.itens || []);
            } catch (e) {
                if (minha === consulta) mostrar([]);
            }
        }, 250);

        busca.addEventListener('input', function () {
            oculto.value = '';
            escolhido.textContent = 'Nenhum processo escolhido.';
            pesquisar();
        });
        busca.addEventListener('blur', function () { setTimeout(fechar, 150); });
        busca.addEventListener('keydown', function (ev) {
            if (lista.hidden || !itens.length) return;
            if (ev.key === 'ArrowDown') { ev.preventDefault(); marcar(Math.min(itens.length - 1, ativo + 1)); }
            else if (ev.key === 'ArrowUp') { ev.preventDefault(); marcar(Math.max(0, ativo - 1)); }
            else if (ev.key === 'Enter' && ativo >= 0) { ev.preventDefault(); escolher(itens[ativo]); }
            else if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); fechar(); }
        });
    }

    function ligarGuia(form) {
        const status = form.querySelector('[data-guia-status]');
        const bloco = form.querySelector('[data-guia-pagamento]');
        const data = form.elements.data_pagamento;
        function atualizar() {
            const paga = status.value === 'PAGA';
            bloco.hidden = !paga;
            data.required = paga;
            if (paga && !data.value) data.value = data.max || '';
        }
        status.addEventListener('change', atualizar);
        form.addEventListener('modal:aberto', atualizar);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-form-bloqueio], form[data-form-guia]').forEach(function (form) {
            ligarValor(form);
            ligarProcesso(form);
            if (form.matches('[data-form-guia]')) ligarGuia(form);
        });
    });
})();
