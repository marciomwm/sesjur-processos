/**
 * Fluxo "Importar do PJe".
 *
 *  CNJ + URL (+ opções: cronologia completa, íntegra das peças decisórias)
 *      → validação (formato CNJ, dígito verificador, esquema e allowlist da URL)
 *      → extensão abre/localiza a página no navegador autenticado e lê o DOM
 *      → primeira leitura: o número CNJ. Divergiu? interrompe e não preenche nada.
 *      → etapas opcionais na página do PJe, com progresso exibido aqui
 *      → servidor revalida e normaliza
 *      → preenchimento → resumo por seção → revisão humana → Salvar
 */
(function () {
    'use strict';

    const GJ = window.GJ;

    /* Rótulos espelhados de App\Validators\Normalizador. */
    const ORIGENS = {
        SISTEMA: 'Sistema', INTERNO: 'Interno', POLO_ATIVO: 'Polo ativo',
        POLO_PASSIVO: 'Polo passivo', EXTERNO: 'Externo', OUTROS: 'Outros'
    };
    const CLASSIFICACOES = {
        EMBARGOS: 'Embargos de declaração', ACORDAO: 'Acórdão', SENTENCA: 'Sentença',
        DECISAO: 'Decisão', DESPACHO: 'Despacho'
    };
    const METODOS = {
        IFRAME_HTML: 'Lida do visualizador do PJe', FETCH_HTML: 'Lida da página do documento',
        PDF_SEM_TEXTO: 'PDF sem texto legível', NAO_LOCALIZADO: 'Não localizado na cronologia carregada',
        FALHA: 'O PJe não exibiu o documento a tempo'
    };

    /** Campos de texto preenchidos pela extração (chave do contrato → name). */
    const MAPA = {
        tribunal:            'tribunal',
        orgaoJulgador:       'orgao_julgador',
        cargoJudicial:       'cargo_judicial',
        competencia:         'competencia',
        jurisdicao:          'jurisdicao',
        classeJudicial:      'classe_judicial',
        classeAbreviada:     'classe_abreviada',
        assuntoPrincipal:    'assunto_principal',
        resumoPartes:        'resumo_partes',
        dataAutuacao:        'data_autuacao',
        dataDistribuicao:    'data_distribuicao',
        situacao:            'situacao',
        prioridadeDescricao: 'prioridade_descricao'
    };

    /* Códigos CNJ: só a extração os fornece; vão em campos ocultos. */
    const MAPA_OCULTO = {
        classeCodigo:           'classe_codigo',
        assuntoPrincipalCodigo: 'assunto_principal_codigo'
    };

    /* Sinalizadores tri-estado: null = não informado, e o select fica vazio. */
    const MAPA_TRI_ESTADO = {
        segredoJustica:  'segredo_justica',
        justicaGratuita: 'justica_gratuita',
        tutelaLiminar:   'tutela_liminar',
        prioridade:      'prioridade'
    };

    /** Preferências de importação lembradas neste navegador. */
    const CHAVE_OPCOES = 'gj.pje.opcoesImportacao';

    function lerOpcoes() {
        try { return JSON.parse(localStorage.getItem(CHAVE_OPCOES) || 'null'); } catch (e) { return null; }
    }
    function guardarOpcoes(opcoes) {
        try { localStorage.setItem(CHAVE_OPCOES, JSON.stringify(opcoes)); } catch (e) { /* sem armazenamento */ }
    }

    function dataBr(iso) {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
        return m ? m[3] + '/' + m[2] + '/' + m[1] : '—';
    }
    function dataHoraBr(valor) {
        const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(valor || '');
        return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : '';
    }

    /** Contador ao lado de cada item da navegação por seções. */
    GJ.atualizarContador = function (chave, n) {
        const el = document.querySelector('[data-contador="' + chave + '"]');
        if (el) el.textContent = n ? String(n) : '';
    };

    /** Filtro por chips: mostra só as linhas cujo atributo casa com o chip ativo. */
    function ligarFiltro(grupo, alvo, atributo) {
        if (!grupo || !alvo) return function () {};
        const aplicar = function () {
            const ativo = grupo.querySelector('.is-ativo');
            const valor = ativo ? ativo.getAttribute('data-filtro') : '';
            alvo.querySelectorAll('tbody tr').forEach(function (tr) {
                tr.hidden = !!valor && tr.getAttribute(atributo) !== valor;
            });
        };
        grupo.addEventListener('click', function (ev) {
            const chip = ev.target.closest('[data-filtro]');
            if (!chip) return;
            grupo.querySelectorAll('[data-filtro]').forEach(function (c) { c.classList.toggle('is-ativo', c === chip); });
            aplicar();
        });
        return aplicar;
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form   = document.getElementById('formProcessoJudicial');
        const botao  = document.getElementById('btnCompletarPje');
        const status = document.getElementById('statusPje');
        if (!form || !botao || !status) return;

        const UI = GJ.extracaoUI;
        const esc = GJ.esc;
        const campoCnj = form.querySelector('[name="numero_cnj"]');
        const campoUrl = form.querySelector('[name="url_origem"]');
        const optCronologia = document.getElementById('optCronologiaCompleta');
        const optIntegra = document.getElementById('optIntegra');
        const alvoCronologia = form.querySelector('[data-movimentacoes]');
        const alvoDocumentos = form.querySelector('[data-documentos]');

        const salvas = lerOpcoes();
        if (salvas) {
            optCronologia.checked = salvas.cronologiaCompleta !== false;
            optIntegra.checked = salvas.integra === true;
        }
        [optCronologia, optIntegra].forEach(function (opt) {
            opt.addEventListener('change', function () {
                guardarOpcoes({ cronologiaCompleta: optCronologia.checked, integra: optIntegra.checked });
            });
        });

        const filtrarCronologia = ligarFiltro(form.querySelector('[data-filtro-cronologia]'), alvoCronologia, 'data-tipo');
        const filtrarDocumentos = ligarFiltro(form.querySelector('[data-filtro-documentos]'), alvoDocumentos, 'data-decisoria');

        // Contador de vínculos acompanha o widget (que é de outro script).
        const listaVinculos = form.querySelector('#secao-vinculos');
        if (listaVinculos && window.MutationObserver) {
            new MutationObserver(function () {
                GJ.atualizarContador('vinculos', listaVinculos.querySelectorAll('input[name="administrativos_ids[]"]').length);
            }).observe(listaVinculos, { childList: true, subtree: true });
        }

        // Progresso das etapas longas, repassado pela extensão.
        let emAndamento = false;
        document.addEventListener('gj:progresso', function (ev) {
            if (emAndamento && ev.detail && ev.detail.texto) UI.processando(status, ev.detail.texto);
        });

        /** Cronologia: mesma marcação de partials/movimentacoes-judiciais.php. */
        function renderizarCronologia(movimentacoes, completo) {
            const oculto = form.querySelector('[name="movimentacoes_json"]');
            if (!oculto || !alvoCronologia || movimentacoes.length === 0) return false;

            oculto.value = JSON.stringify(movimentacoes);

            const linhas = movimentacoes.map(function (mov) {
                const docs = (mov.documentos && mov.documentos.length)
                    ? mov.documentos
                    : (mov.documentoId ? [{ id: mov.documentoId, tipo: mov.documentoTipo }] : []);
                const rotuloOrigem = mov.origem ? (ORIGENS[mov.origem] || mov.origem) : mov.natureza;
                const origem = rotuloOrigem
                    ? '<span class="origem origem--' + esc(String(mov.origem || 'outro').toLowerCase()) + '"' +
                      (mov.natureza ? ' title="' + esc(mov.natureza) + '"' : '') + '>' + esc(rotuloOrigem) + '</span>'
                    : '—';
                const documentos = docs.length
                    ? '<ul class="docs-evento">' + docs.map(function (d) {
                        return '<li><span class="mono">' + esc(d.id || '') + '</span>' +
                               (d.tipo ? ' <span class="texto-secundario">' + esc(d.tipo) + '</span>' : '') + '</li>';
                      }).join('') + '</ul>'
                    : '<span class="texto-secundario">—</span>';
                return '<tr data-tipo="' + esc(mov.tipo) + '">' +
                    '<td class="nowrap">' + dataBr(mov.data) + '</td>' +
                    '<td class="nowrap mono">' + (mov.hora ? esc(mov.hora.slice(0, 5)) : '—') + '</td>' +
                    '<td class="nowrap">' + origem + '</td>' +
                    '<td>' + (mov.descricao ? esc(mov.descricao) : '—') + '</td>' +
                    '<td>' + documentos + '</td></tr>';
            }).join('');

            alvoCronologia.innerHTML =
                '<div class="tabela-wrap eventos-wrap"><table class="tabela tabela--compacta tabela--cronologia">' +
                '<thead><tr><th>Data</th><th>Hora</th><th>Origem</th><th>Movimentação</th><th>Documentos</th></tr></thead>' +
                '<tbody>' + linhas + '</tbody></table></div>';
            filtrarCronologia();

            const resumo = form.querySelector('[data-movimentacoes-resumo]');
            if (resumo) {
                resumo.textContent = movimentacoes.length + ' evento(s) importado(s) — ' +
                    (completo ? 'cronologia completa' : 'apenas a parte carregada da linha do tempo') +
                    '. Serão gravados ao salvar, substituindo os anteriores.';
            }
            const nota = form.querySelector('[data-movimentacoes-nota]');
            if (nota) nota.hidden = true;
            GJ.atualizarContador('cronologia', movimentacoes.length);
            return true;
        }

        /** Documentos: mesma marcação de partials/documentos-judiciais.php. */
        function renderizarDocumentos(documentos) {
            const oculto = form.querySelector('[name="documentos_json"]');
            if (!oculto || !alvoDocumentos || documentos.length === 0) return false;

            oculto.value = JSON.stringify(documentos);

            const linhas = documentos.map(function (doc) {
                let integra = '<span class="texto-secundario">—</span>';
                if (doc.integra) {
                    integra = '<details class="integra"><summary>Prévia da íntegra</summary>' +
                              '<div class="integra__texto">' + esc(doc.integra) + '</div></details>';
                } else if (doc.metodoExtracao) {
                    const rotulo = METODOS[doc.metodoExtracao] || doc.metodoExtracao;
                    integra = '<span class="badge badge--ambar" title="' + esc(rotulo) + '">' + esc(rotulo) + '</span>';
                }
                const juntada = doc.juntadoPor
                    ? esc(doc.juntadoPor) + (doc.dataJuntada ? '<br><span class="texto-secundario">' + esc(dataHoraBr(doc.dataJuntada)) + '</span>' : '')
                    : '<span class="texto-secundario">—</span>';
                return '<tr data-decisoria="' + (doc.classificacao ? '1' : '0') + '">' +
                    '<td><span class="mono">' + esc(doc.id) + '</span>' +
                        (doc.tipo ? '<br><span class="texto-secundario">' + esc(doc.tipo) + '</span>' : '') + '</td>' +
                    '<td class="nowrap">' + dataBr(doc.data) +
                        (doc.hora ? ' <span class="mono texto-secundario">' + esc(doc.hora.slice(0, 5)) + '</span>' : '') + '</td>' +
                    '<td>' + (doc.classificacao
                        ? '<span class="badge badge--azul">' + esc(CLASSIFICACOES[doc.classificacao] || doc.classificacao) + '</span>'
                        : '<span class="texto-secundario">—</span>') + '</td>' +
                    '<td>' + juntada + '</td>' +
                    '<td>' + integra + '</td></tr>';
            }).join('');

            alvoDocumentos.innerHTML =
                '<div class="tabela-wrap eventos-wrap"><table class="tabela tabela--compacta tabela--documentos">' +
                '<thead><tr><th>Documento</th><th>Data</th><th>Peça</th><th>Juntada</th><th>Íntegra</th></tr></thead>' +
                '<tbody>' + linhas + '</tbody></table></div>';
            filtrarDocumentos();

            const decisorias = documentos.filter(function (d) { return d.classificacao; }).length;
            const integras = documentos.filter(function (d) { return d.integra; }).length;
            const resumo = form.querySelector('[data-documentos-resumo]');
            if (resumo) {
                resumo.textContent = documentos.length + ' documento(s) importado(s) · ' + decisorias +
                    ' peça(s) decisória(s) · ' + integras + ' íntegra(s). Serão gravados ao salvar.';
            }
            const nota = form.querySelector('[data-documentos-nota]');
            if (nota) nota.hidden = true;
            GJ.atualizarContador('documentos', documentos.length);
            return true;
        }

        /** Painel-resumo da importação: um bloco por seção, com link para ela. */
        function renderizarResumo(d, preenchidos, totalCampos, avisos) {
            const alvo = form.querySelector('[data-resumo-importacao]');
            if (!alvo) return;

            const partes = d.partes || [];
            const contaPolo = function (polos) {
                return partes.filter(function (p) { return polos.indexOf(p.polo) >= 0; }).length;
            };
            const docs = d.documentos || [];
            const decisorias = docs.filter(function (x) { return x.classificacao; }).length;
            const integras = docs.filter(function (x) { return x.integra; }).length;
            const movs = d.movimentacoes || [];

            const blocos = [
                { secao: 'secao-detalhes', rotulo: 'Detalhes', valor: preenchidos + '/' + totalCampos,
                  nota: 'campos preenchidos' },
                { secao: 'secao-partes', rotulo: 'Partes', valor: partes.length,
                  nota: contaPolo(['ATIVO']) + ' ativo · ' + contaPolo(['PASSIVO']) + ' passivo · ' +
                        contaPolo(['TERCEIRO', 'OUTRO']) + ' outros' },
                { secao: 'secao-cronologia', rotulo: 'Cronologia', valor: movs.length,
                  nota: movs.length ? (d.historicoCompleto ? 'completa' : 'parcial') : 'nada importado',
                  alerta: movs.length > 0 && !d.historicoCompleto },
                { secao: 'secao-documentos', rotulo: 'Documentos', valor: docs.length,
                  nota: decisorias + ' decisória(s)' + (d.integraSolicitada ? ' · ' + integras + ' íntegra(s)' : '') },
                { secao: 'statusPje', rotulo: 'Ressalvas', valor: avisos.length,
                  nota: avisos.length ? 'veja acima' : 'nenhuma', alerta: avisos.length > 0 }
            ];

            alvo.innerHTML = blocos.map(function (b) {
                return '<a class="resumo-importacao__item' + (b.alerta ? ' is-alerta' : '') + '" href="#' + b.secao + '">' +
                    '<span class="resumo-importacao__rotulo">' + esc(b.rotulo) + '</span>' +
                    '<strong class="resumo-importacao__valor">' + esc(String(b.valor)) + '</strong>' +
                    '<span class="resumo-importacao__nota">' + esc(b.nota) + '</span></a>';
            }).join('');
            alvo.hidden = false;
        }

        botao.addEventListener('click', async function () {
            const cnj = (campoCnj.value || '').trim();
            const url = (campoUrl.value || '').trim();

            if (!cnj) { UI.erro(status, 'Informe o número do processo judicial.'); campoCnj.focus(); return; }
            if (!url) { UI.erro(status, 'Informe o endereço do processo no PJe.'); campoUrl.focus(); return; }

            const opcoes = { cronologiaCompleta: optCronologia.checked, integra: optIntegra.checked };
            const longa = opcoes.cronologiaCompleta || opcoes.integra;

            UI.botaoOcupado(botao, true, 'Importando…');
            UI.processando(status, longa
                ? 'Consultando o PJe… com as opções marcadas, isto pode levar alguns minutos. Mantenha a aba do PJe aberta.'
                : 'Consultando o PJe…');
            emAndamento = true;

            try {
                const autorizacao = await GJ.fetchJson('api/extracao-preparar.php', {
                    method: 'POST',
                    body: JSON.stringify({ origem: 'PJE', numero_cnj: cnj, url: url })
                });

                if (!autorizacao.success) {
                    UI.erro(status, UI.mensagemDe(autorizacao));
                    return;
                }

                const pedido = autorizacao.request;
                const bruto = await GJ.extensao.extrairPje(pedido.numeroCNJ, pedido.url, pedido.allowedHosts, opcoes);
                emAndamento = false;

                if (!bruto || !bruto.success) {
                    const det = (bruto && bruto.error && bruto.error.details) || {};
                    UI.erro(status, UI.mensagemDe(bruto), det.encontrado
                        ? ['Informado: ' + det.solicitado, 'Encontrado na página: ' + det.encontrado]
                        : []);
                    return;
                }

                UI.processando(status, 'Conferindo os dados importados…');
                const confirmado = await GJ.fetchJson('api/extracao-confirmar.php', {
                    method: 'POST',
                    body: JSON.stringify({ origem: 'PJE', solicitado: pedido.numeroCNJ, payload: bruto })
                });

                if (!confirmado.success) {
                    const det = (confirmado.error && confirmado.error.details) || {};
                    UI.erro(status, UI.mensagemDe(confirmado), det.encontrado
                        ? ['Informado: ' + det.solicitado, 'Encontrado na página: ' + det.encontrado]
                        : []);
                    return;
                }

                const d = confirmado.data;
                const preenchidos = [];
                const ausentes = [];

                UI.preencher(campoCnj, d.numeroCNJ);

                Object.keys(MAPA).forEach(function (chave) {
                    const input = form.querySelector('[name="' + MAPA[chave] + '"]');
                    if (d[chave] != null && d[chave] !== '') {
                        if (UI.preencher(input, d[chave])) preenchidos.push(MAPA[chave]);
                    } else if (chave !== 'prioridadeDescricao') {
                        // O motivo da prioridade só existe às vezes: não é "ausência".
                        ausentes.push(MAPA[chave]);
                    }
                });

                if (d.valorCausa != null) {
                    UI.preencher(form.querySelector('[name="valor_causa"]'),
                        Number(d.valorCausa).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
                    preenchidos.push('valor_causa');
                } else {
                    ausentes.push('valor_causa');
                }

                Object.keys(MAPA_OCULTO).forEach(function (chave) {
                    const input = form.querySelector('[name="' + MAPA_OCULTO[chave] + '"]');
                    if (input && d[chave] != null) input.value = d[chave];
                });

                Object.keys(MAPA_TRI_ESTADO).forEach(function (chave) {
                    const campo = form.querySelector('[name="' + MAPA_TRI_ESTADO[chave] + '"]');
                    if (!campo) return;
                    if (d[chave] == null) {
                        // A fonte não informou: mantém "Não informado" em vez de
                        // afirmar "Não", que seria uma informação inventada.
                        ausentes.push(MAPA_TRI_ESTADO[chave]);
                        return;
                    }
                    campo.value = d[chave] ? '1' : '0';
                    campo.classList.add('is-preenchido-extracao');
                    preenchidos.push(MAPA_TRI_ESTADO[chave]);
                });

                if (Array.isArray(d.assuntosSecundarios) && d.assuntosSecundarios.length) {
                    const campoSec = form.querySelector('[name="assuntos_secundarios"]');
                    // O servidor devolve {descricao, codigo}; o textarea recebe
                    // a descrição com o código, no mesmo formato exibido pelo PJe.
                    const texto = d.assuntosSecundarios.map(function (a) {
                        const desc = typeof a === 'string' ? a : a.descricao;
                        const cod = typeof a === 'string' ? null : a.codigo;
                        return cod ? desc + ' (' + cod + ')' : desc;
                    }).join('\n');
                    if (UI.preencher(campoSec, texto)) preenchidos.push('assuntos_secundarios');
                }

                if (Array.isArray(d.partes) && d.partes.length && GJ.definirPartes) {
                    GJ.definirPartes(d.partes);
                }

                if (Array.isArray(d.movimentacoes) && d.movimentacoes.length) {
                    renderizarCronologia(d.movimentacoes, d.historicoCompleto);
                    const completo = form.querySelector('[name="historico_completo"]');
                    if (completo) completo.value = d.historicoCompleto ? '1' : '0';
                }

                if (Array.isArray(d.documentos) && d.documentos.length) {
                    renderizarDocumentos(d.documentos);
                }

                const versao = form.querySelector('[name="extrator_versao"]');
                if (versao && confirmado.extractorVersion) versao.value = confirmado.extractorVersion;

                UI.registrarProveniencia(form, 'PJE', confirmado.extractedAt, pedido.url);

                // "Campo não encontrado no DOM: x" do extrator é resumido pela
                // linha de campos ausentes abaixo.
                const avisos = (confirmado.warnings || []).filter(function (w) {
                    return w.indexOf('Campo não encontrado no DOM') !== 0;
                });
                if (ausentes.length) {
                    avisos.push('Campos não localizados na página (preencha manualmente se souber): ' + ausentes.join(', ') + '.');
                }
                if (!Array.isArray(d.partes) || d.partes.length === 0) {
                    avisos.push('Nenhuma parte foi localizada. Adicione as partes manualmente.');
                }
                if (!Array.isArray(d.movimentacoes) || d.movimentacoes.length === 0) {
                    avisos.push('Nenhuma movimentação foi localizada. A cronologia já gravada foi mantida.');
                }

                renderizarResumo(d, preenchidos.length, preenchidos.length + ausentes.length, avisos);

                if (avisos.length) {
                    UI.aviso(status, 'Dados importados do PJe, com ressalvas. Revise antes de salvar.', avisos);
                } else {
                    UI.sucesso(status, 'Dados importados do PJe. Revise cada seção e clique em Salvar.');
                }

            } catch (erro) {
                UI.erro(status, 'Falha na comunicação com a aplicação: ' + erro.message);
            } finally {
                emAndamento = false;
                UI.botaoOcupado(botao, false);
            }
        });
    });
})();
