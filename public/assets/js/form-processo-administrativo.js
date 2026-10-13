/**
 * Fluxo "Completar cadastro pelo SIGADOC".
 *
 *  Protocolo informado
 *      → autorização no servidor (formato + allowlist)
 *      → extensão localiza a aba/página do SIGADOC e extrai o DOM
 *      → servidor revalida o payload e confere o protocolo
 *      → preenchimento do formulário
 *      → revisão humana → Salvar
 */
(function () {
    'use strict';

    const GJ = window.GJ;

    /** "2026-09-17" + n dias → "2026-10-17" (sem fuso: conta em UTC). */
    function somarDias(iso, dias) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        if (!m) return '';
        const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3] + dias));
        return d.toISOString().slice(0, 10);
    }

    /**
     * Prazo padrão = abertura + 30 dias. Acompanha a data de abertura enquanto
     * o prazo estiver vazio ou ainda for o padrão calculado — um prazo
     * digitado pelo usuário nunca é sobrescrito.
     */
    function ligarPrazoPadrao(form) {
        const abertura = form.querySelector('[name="data_abertura"]');
        const prazo = form.querySelector('[name="prazo"]');
        if (!abertura || !prazo) return;
        const dias = parseInt(prazo.dataset.prazoPadraoDias, 10) || 30;
        let automatico = somarDias(abertura.value, dias);

        const acompanhar = function () {
            const novo = somarDias(abertura.value, dias);
            if (novo && (prazo.value === '' || prazo.value === automatico)) {
                prazo.value = novo;
            }
            automatico = novo;
        };
        abertura.addEventListener('input', acompanhar);
        abertura.addEventListener('change', acompanhar);
        if (prazo.value === '') acompanhar();
    }

    document.addEventListener('DOMContentLoaded', function () {
        const formPrazo = document.getElementById('formProcessoAdministrativo');
        if (formPrazo) ligarPrazoPadrao(formPrazo);
    });

    document.addEventListener('DOMContentLoaded', function () {
        const form   = document.getElementById('formProcessoAdministrativo');
        const botao  = document.getElementById('btnCompletarSigadoc');
        const status = document.getElementById('statusSigadoc');
        if (!form || !botao || !status) return;

        const campoProtocolo = form.querySelector('[name="protocolo"]');
        const campoData      = form.querySelector('[name="data_abertura"]');
        const campoObjeto    = form.querySelector('[name="objeto"]');
        const UI = GJ.extracaoUI;

        botao.addEventListener('click', async function () {
            const protocolo = (campoProtocolo.value || '').trim();

            if (!protocolo) {
                UI.erro(status, 'Informe o protocolo antes de consultar o SIGADOC.');
                campoProtocolo.focus();
                return;
            }

            UI.botaoOcupado(botao, true, 'Consultando SIGADOC…');
            UI.processando(status, 'Consultando SIGADOC…');

            try {
                // 1. Autorização no servidor (formato do protocolo + allowlist de domínios).
                const autorizacao = await GJ.fetchJson('api/extracao-preparar.php', {
                    method: 'POST',
                    body: JSON.stringify({ origem: 'SIGADOC', protocolo: protocolo })
                });

                if (!autorizacao.success) {
                    UI.erro(status, UI.mensagemDe(autorizacao));
                    return;
                }

                const pedido = autorizacao.request;

                // 2. Extração no navegador, pela extensão.
                const bruto = await GJ.extensao.extrairSigadoc(pedido.protocolo, pedido.allowedHosts);

                if (!bruto || !bruto.success) {
                    UI.erro(status, UI.mensagemDe(bruto), [
                        bruto && bruto.error && bruto.error.details ? JSON.stringify(bruto.error.details) : null
                    ]);
                    return;
                }

                // 3. Revalidação no servidor (inclusive a conferência do protocolo).
                const confirmado = await GJ.fetchJson('api/extracao-confirmar.php', {
                    method: 'POST',
                    body: JSON.stringify({ origem: 'SIGADOC', solicitado: pedido.protocolo, payload: bruto })
                });

                if (!confirmado.success) {
                    const det = (confirmado.error && confirmado.error.details) || {};
                    UI.erro(status, UI.mensagemDe(confirmado), det.encontrado
                        ? ['Informado: ' + det.solicitado, 'Encontrado na página: ' + det.encontrado]
                        : []);
                    return;
                }

                // 4. Preenchimento (sem salvar) e revisão humana.
                const d = confirmado.data;
                const preenchidos = [];
                if (UI.preencher(campoProtocolo, d.protocolo)) preenchidos.push('Protocolo');
                if (UI.preencher(campoData, d.dataAbertura)) preenchidos.push('Data de Abertura');
                if (UI.preencher(campoObjeto, d.objeto)) preenchidos.push('Objeto');
                preenchidos.push.apply(preenchidos, preencherSituacao(d));
                if (aplicarEventos(d.eventos || [], d.historicoCompleto)) preenchidos.push('Movimentações (' + d.eventos.length + ')');

                UI.registrarProveniencia(form, 'SIGADOC', confirmado.extractedAt, confirmado.sourceUrl);
                const versao = form.querySelector('[name="extrator_versao"]');
                if (versao && confirmado.extractorVersion) versao.value = confirmado.extractorVersion;

                const avisos = (confirmado.warnings || []).filter(function (w) {
                    // Os ausentes são listados abaixo com orientação ao usuário.
                    return w.indexOf('Campo não encontrado no DOM') !== 0;
                });
                ['dataAbertura', 'objeto', 'situacao', 'responsavel'].forEach(function (campo) {
                    if (d[campo] == null) {
                        avisos.push('Campo não encontrado no DOM: ' + campo + ' (preencha manualmente).');
                    }
                });

                if (avisos.length) {
                    UI.aviso(status, '✓ Dados obtidos do SIGADOC, com ressalvas. Revise antes de salvar.', avisos);
                } else {
                    UI.sucesso(status, '✓ Dados obtidos do SIGADOC. Revise e clique em Salvar Processo.',
                        ['Campos preenchidos: ' + preenchidos.join(', ')]);
                }

                // Sugere o vínculo com processos judiciais citados no objeto.
                sugerirVinculosDoObjeto(d.objeto);

            } catch (erro) {
                UI.erro(status, 'Falha na comunicação com a aplicação: ' + erro.message);
            } finally {
                UI.botaoOcupado(botao, false);
            }
        });

        /** Situação e servidor responsável. Devolve os rótulos preenchidos. */
        function preencherSituacao(d) {
            const feitos = [];
            const campo = function (nome) { return form.querySelector('[name="' + nome + '"]'); };

            if (d.situacao && UI.preencher(campo('situacao'), d.situacao)) {
                feitos.push('Situação');
                const oficial = campo('situacao_descricao');
                if (oficial) oficial.value = d.situacaoDescricao || '';
                const ajuda = form.querySelector('[data-situacao-oficial]');
                if (ajuda && d.situacaoDescricao) ajuda.textContent = 'No SIGADOC: ' + d.situacaoDescricao;
            }

            const r = d.responsavel || {};
            if (UI.preencher(campo('responsavel_nome'), r.nome)) feitos.push('Servidor responsável');
            UI.preencher(campo('responsavel_matricula'), r.matricula);
            UI.preencher(campo('responsavel_lotacao'), r.lotacao);
            UI.preencher(campo('responsavel_lotacao_nome'), r.lotacaoNome);
            return feitos;
        }

        const ROTULO_TIPO = {
            SOBRESTAMENTO: 'badge--ambar', ARQUIVAMENTO: 'badge--ambar',
            DESSOBRESTAMENTO: 'badge--verde', DESARQUIVAMENTO: 'badge--verde',
            JUNTADA: 'badge--azul', APENSACAO: 'badge--azul',
            DESENTRANHAMENTO: 'badge--vermelho', DESAPENSACAO: 'badge--vermelho'
        };

        function dataBr(iso) {
            const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
            return m ? m[3] + '/' + m[2] + '/' + m[1] : '—';
        }

        /**
         * Guarda as movimentações no campo oculto (gravadas só ao salvar) e as
         * exibe para revisão. Mesma marcação de partials/eventos-administrativos.php.
         */
        function aplicarEventos(eventos, completo) {
            const oculto = form.querySelector('[name="eventos_json"]');
            const alvo = form.querySelector('[data-eventos]');
            if (!oculto || !alvo || eventos.length === 0) return false;

            oculto.value = JSON.stringify(eventos);

            const esc = GJ.esc;
            const pessoa = function (nome, lotacao) {
                return (nome ? esc(nome) : '—') +
                    (lotacao ? '<br><span class="texto-secundario mono">' + esc(lotacao) + '</span>' : '');
            };
            const linhas = eventos.map(function (ev) {
                const desc = (ev.documento ? '<span class="mono">' + esc(ev.documento) + '</span>' + (ev.descricao ? ' — ' : '') : '') +
                    (ev.descricao ? esc(ev.descricao) : (ev.documento ? '' : '—'));
                return '<tr data-tipo="' + esc(ev.tipo) + '">' +
                    '<td class="nowrap">' + dataBr(ev.data) + '</td>' +
                    '<td><span class="badge ' + (ROTULO_TIPO[ev.tipo] || 'badge--neutro') + '">' + esc(ev.tipoDescricao || ev.tipo) + '</span></td>' +
                    '<td>' + pessoa(ev.responsavel, ev.lotacao) + '</td>' +
                    '<td>' + pessoa(ev.atendente, ev.atendenteLotacao) + '</td>' +
                    '<td>' + desc + '</td></tr>';
            }).join('');

            alvo.innerHTML =
                '<div class="tabela-wrap eventos-wrap"><table class="tabela tabela--compacta">' +
                '<thead><tr><th>Data</th><th>Evento</th><th>Cadastrante</th><th>Atendente</th><th>Descrição</th></tr></thead>' +
                '<tbody>' + linhas + '</tbody></table></div>';

            const resumo = form.querySelector('[data-eventos-resumo]');
            if (resumo) {
                resumo.textContent = eventos.length + ' evento(s) extraído(s) — ' +
                    (completo ? 'histórico completo' : 'apenas juntadas e anotações') +
                    '. Serão gravados ao salvar, substituindo os anteriores.';
            }
            const nota = form.querySelector('[data-eventos-nota]');
            if (nota) nota.hidden = true;
            return true;
        }

        /** Detecta números CNJ no objeto e oferece o vínculo imediato. */
        async function sugerirVinculosDoObjeto(objeto) {
            if (!objeto) return;
            const encontrados = objeto.match(/\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}/g);
            if (!encontrados) return;

            const widget = document.querySelector('[data-vinculos]');
            if (!widget || !widget.adicionarVinculo) return;

            for (const cnj of Array.from(new Set(encontrados))) {
                try {
                    const r = await GJ.fetchJson('api/pesquisar-processos-judiciais.php?termo=' + encodeURIComponent(cnj));
                    const item = (r.itens || []).find(function (i) { return i.rotulo === cnj; });
                    if (item) {
                        widget.adicionarVinculo(item);
                    } else {
                        const caixa = document.getElementById('sugestaoVinculo');
                        if (caixa) {
                            caixa.hidden = false;
                            caixa.innerHTML = 'O objeto cita o processo <strong class="mono">' + GJ.esc(cnj) + '</strong>, ' +
                                'que ainda não está cadastrado. ' +
                                '<a href="' + GJ.esc(GJ.url('novo-processo-judicial.php') + '?numero_cnj=' + encodeURIComponent(cnj)) +
                                '" target="_blank" rel="noopener">Cadastrar agora</a> e depois vincular.';
                        }
                    }
                } catch (e) { /* sugestão é opcional */ }
            }
        }
    });
})();
