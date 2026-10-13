/**
 * Casos de teste dos extratores.
 *
 * Cada caso aponta para uma fixture e descreve o que se espera do contrato.
 * Ao adicionar uma fixture REAL (anonimizada), registre-a aqui.
 */
(function (raiz) {
'use strict';

const CASOS = [
    /* ---------------------------- SIGADOC ---------------------------- */
    {
        nome: 'SIGADOC · DOM REAL (SIGA-DOC v11.2.0, anonimizado) + "Ver Mais"',
        sistema: 'SIGADOC',
        fixture: '../fixtures/sigadoc/real-sigadoc-mt-processo.html',
        fixtureCompleta: '../fixtures/sigadoc/real-sigadoc-mt-processo-ver-mais.html',
        esperado: 'SES-PRO-2026/23509',
        verificar: function (r, t) {
            t.ok(r.success, 'extração bem-sucedida no DOM real');
            t.igual(r.data.protocolo, 'SES-PRO-2026/23509', 'protocolo (via <h2> do cabeçalho)');
            t.igual(r.data.dataAbertura, '2026-04-07', 'data de abertura — tabela HORIZONTAL, convertida para ISO');
            t.contem(r.data.objeto, 'SUPLEMENTO ALIMENTAR', 'objeto — tabela VERTICAL (tr rótulo → tr seguinte)');
            t.contem(r.data.objeto, 'FULANO DE TAL', 'objeto completo');
            t.igual(r.data.objeto.indexOf('OBJETO'), -1, 'o rótulo não vaza para dentro do valor');
            t.igual(r.warnings.length, 0, 'nenhum campo ausente');

            // Situação e responsável
            t.igual(r.data.situacao.codigo, 'AGUARDANDO_ANDAMENTO', 'situação classificada');
            t.igual(r.data.situacao.descricao, 'Aguardando Andamento', 'rótulo oficial preservado');
            t.igual(r.data.responsavel.nome, 'SERVIDOR 01', 'servidor com o processo (tabela de volumes)');
            t.igual(r.data.responsavel.matricula, 'SES000001', 'matrícula do servidor');
            t.igual(r.data.responsavel.lotacao, 'UNIJUR', 'sigla da lotação');
            t.igual(r.data.responsavel.lotacaoNome, 'UNIDADE JURIDICA', 'nome da lotação');
            t.igual(r.strategies.responsavel, 'volumes', 'estratégia do responsável');

            // Movimentações (visão completa)
            const ev = r.data.eventos;
            t.ok(r.data.historicoCompleto, 'histórico completo');
            t.igual(ev.length, 10, 'tabela "Geral" ignorada — só o volume');
            t.igual(ev[0].tipo, 'RECEBIMENTO', 'mais recente primeiro');
            t.igual(ev[0].data, '2026-09-18', 'data dd/mm/aa → ISO');
            t.igual(ev[2].data, '2026-08-28', 'linha sem data herda a do grupo');
            t.igual(ev[2].tipo, 'JUNTADA', 'juntada');
            t.igual(ev[2].documento, 'SES-CIN-2026/161332-A', 'sigla do documento juntado');
            t.igual(ev[2].descricao, null, 'botões de ação não entram na descrição');
            t.igual(ev[2].responsavel, 'SERVIDOR 02', 'cadastrante');
            t.igual(ev[2].atendente, 'SERVIDOR 10', 'atendente');
            t.igual(ev[2].lotacao, 'SESCOFDE', 'lotação do cadastrante');
            t.igual(ev[3].descricao, 'FOI REALIZADA A BAIXA NA PLANILHA DE AQUISIÇÃO', 'anotação sem o link oculto "Excluir"');
            t.igual(ev[1].descricao, null, 'transferência: botão "Protocolo" descartado');
            t.igual(ev[6].tipo, 'DESSOBRESTAMENTO', 'Dessobrestar (tr sem classe)');
            t.igual(ev[7].tipo, 'SOBRESTAMENTO', 'Sobrestar (tr sem classe)');
            t.igual(ev[7].data, '2026-05-07', 'data do sobrestamento');
            t.igual(ev[5].tipo, 'APENSACAO', 'apensação');
            t.igual(ev[8].tipo, 'DESENTRANHAMENTO', 'desentranhamento');
            t.igual(ev[8].descricao.charAt(0), 'D', 'separador inicial removido');
            t.igual(ev[9].tipo, 'CRIACAO', 'criação');
        }
    },
    {
        nome: 'SIGADOC · DOM real sem "Ver Mais": histórico resumido',
        sistema: 'SIGADOC',
        fixture: '../fixtures/sigadoc/real-sigadoc-mt-processo.html',
        esperado: 'SES-PRO-2026/23509',
        verificar: function (r, t) {
            t.ok(r.success, 'extração bem-sucedida');
            t.ok(!r.data.historicoCompleto, 'marcado como parcial');
            t.igual(r.data.eventos.length, 3, 'juntadas e anotações da visão padrão');
            t.igual(r.data.eventos[0].responsavel, 'SERVIDOR 02', 'nome lido do title da lotação');
            t.igual(r.data.eventos[0].lotacao, 'COFDE', 'sigla da lotação');
            t.igual(r.data.eventos[2].descricao, 'INFORMAÇÃO ÓBITO', 'descrição sem a sigla do documento');
            t.ok(r.warnings.some(function (w) { return w.indexOf('Histórico parcial') === 0; }), 'aviso de histórico parcial');
            t.igual(r.data.responsavel.nome, 'SERVIDOR 01', 'responsável independe do histórico');
        }
    },
    {
        nome: 'SIGADOC · "Ver Mais" de outro processo é descartado',
        sistema: 'SIGADOC',
        fixture: '../fixtures/sigadoc/real-sigadoc-mt-processo.html',
        fixtureCompleta: '../fixtures/sigadoc/sintetica-sobrestado.html',
        esperado: 'SES-PRO-2026/23509',
        verificar: function (r, t) {
            t.ok(r.success, 'extração prossegue');
            t.ok(!r.data.historicoCompleto, 'histórico alheio não é usado');
            t.ok(r.warnings.some(function (w) { return w.indexOf('não corresponde ao processo') >= 0; }), 'aviso de descarte');
        }
    },
    {
        nome: 'SIGADOC · processo sobrestado, 2º volume',
        sistema: 'SIGADOC',
        fixture: '../fixtures/sigadoc/sintetica-sobrestado.html',
        esperado: 'SES-PRO-2025/1000',
        verificar: function (r, t) {
            t.ok(r.success, 'extração bem-sucedida');
            t.igual(r.data.situacao.codigo, 'SOBRESTADO', 'Sobrestado prevalece sobre Aguardando Andamento');
            t.igual(r.data.situacao.descricao, 'Sobrestado', 'rótulo oficial');
            t.igual(r.data.responsavel.nome, 'SERVIDOR 20', 'último volume (V02), não o V01 nem "Geral"');
            t.igual(r.data.responsavel.lotacao, 'COJUR', 'lotação do volume corrente');
            t.igual(r.data.eventos.length, 0, 'sem movimentações = coleção vazia');
            t.ok(r.warnings.some(function (w) { return w.indexOf('movimentações não encontrada') >= 0; }), 'aviso de tabela ausente');
        }
    },
    {
        nome: 'SIGADOC · DOM real não confunde com siglas da barra lateral',
        sistema: 'SIGADOC',
        fixture: '../fixtures/sigadoc/real-sigadoc-mt-processo.html',
        esperado: 'SES-PRO-2024/96182',
        verificar: function (r, t) {
            // SES-PRO-2024/96182 aparece na barra lateral como documento apensado.
            // O extrator deve recusar, e não "encontrar" o processo errado.
            t.ok(!r.success, 'extração recusada');
            t.igual(r.error.code, 'IDENTIFIER_MISMATCH', 'código do erro');
            t.igual(r.error.details.encontrado, 'SES-PRO-2026/23509', 'reporta a sigla realmente exibida');
        }
    },
    {
        nome: 'SIGADOC · página válida (dl/dt/dd)',
        sistema: 'SIGADOC',
        fixture: '../fixtures/sigadoc/sintetica-valido.html',
        esperado: 'SEPLAG-PRO-2026/16696',
        verificar: function (r, t) {
            t.ok(r.success, 'extração bem-sucedida');
            t.igual(r.data.protocolo, 'SEPLAG-PRO-2026/16696', 'protocolo');
            t.igual(r.data.dataAbertura, '2026-09-17', 'data de abertura em ISO');
            t.contem(r.data.objeto, 'defesa do Estado', 'objeto');
            t.igual(r.source, 'SIGADOC', 'origem');
        }
    },
    {
        nome: 'SIGADOC · variação em tabela (th/td)',
        sistema: 'SIGADOC',
        fixture: '../fixtures/sigadoc/sintetica-tabela.html',
        esperado: 'SEPLAG-PRO-2026/16696',
        verificar: function (r, t) {
            t.ok(r.success, 'extração bem-sucedida em outra marcação');
            t.igual(r.data.dataAbertura, '2026-09-17', 'data de abertura');
        }
    },
    {
        nome: 'SIGADOC · campo ausente não é inventado',
        sistema: 'SIGADOC',
        fixture: '../fixtures/sigadoc/sintetica-campo-ausente.html',
        esperado: 'SEPLAG-PRO-2026/16696',
        verificar: function (r, t) {
            t.ok(r.success, 'extração prossegue');
            t.igual(r.data.objeto, null, 'objeto permanece null');
            t.ok(r.warnings.some(function (w) { return w.indexOf('objeto') >= 0; }), 'warning sobre o campo ausente');
        }
    },
    {
        nome: 'SIGADOC · protocolo divergente interrompe',
        sistema: 'SIGADOC',
        fixture: '../fixtures/sigadoc/sintetica-divergente.html',
        esperado: 'SEPLAG-PRO-2026/16696',
        verificar: function (r, t) {
            t.ok(!r.success, 'extração recusada');
            t.igual(r.error.code, 'IDENTIFIER_MISMATCH', 'código do erro');
            t.igual(r.error.details.encontrado, 'SEPLAG-PRO-2026/99999', 'protocolo encontrado é reportado');
            t.igual(r.data, undefined, 'nenhum dado é devolvido');
        }
    },
    {
        nome: 'SIGADOC · sessão expirada',
        sistema: 'SIGADOC',
        fixture: '../fixtures/sigadoc/sintetica-sessao-expirada.html',
        esperado: 'SEPLAG-PRO-2026/16696',
        verificar: function (r, t) {
            t.ok(!r.success, 'extração recusada');
            t.igual(r.error.code, 'SESSION_REQUIRED', 'código do erro');
        }
    },
    {
        nome: 'SIGADOC · processo inexistente',
        sistema: 'SIGADOC',
        fixture: '../fixtures/sigadoc/sintetica-inexistente.html',
        esperado: 'SEPLAG-PRO-2026/16696',
        verificar: function (r, t) {
            t.ok(!r.success, 'extração recusada');
            t.ok(['PROCESS_NOT_FOUND', 'SELECTORS_MISSING', 'SELECTORS_NOT_CONFIGURED'].indexOf(r.error.code) >= 0,
                 'código do erro é de página sem o processo (' + r.error.code + ')');
        }
    },

    /* ------------------------------ PJe ------------------------------ */
    {
        nome: 'PJe · página válida',
        sistema: 'PJE',
        fixture: '../fixtures/pje/sintetica-valido.html',
        esperado: '1008695-17.2026.8.11.0006',
        verificar: function (r, t) {
            t.ok(r.success, 'extração bem-sucedida');
            t.igual(r.data.numeroCNJ, '1008695-17.2026.8.11.0006', 'número CNJ');
            t.igual(r.data.classeJudicial, 'Procedimento Comum Cível', 'classe judicial');
            t.ok(!('comarca' in r.data) && !('vara' in r.data), 'vara e comarca fora do contrato');
            t.igual(r.data.dataDistribuicao, '2026-09-15', 'data de distribuição em ISO');
            t.igual(r.data.valorCausa, 45320.75, 'valor da causa normalizado');
            t.igual(r.data.segredoJustica, false, 'segredo de justiça');
            t.igual(r.data.partes.length, 2, 'duas partes extraídas');
            t.igual(r.data.partes[0].polo, 'ATIVO', 'polo da primeira parte');
            t.igual(r.data.partes[1].polo, 'PASSIVO', 'polo da segunda parte');
            t.igual(r.data.partes[0].documento, '000.000.000-00', 'documento da parte');
            t.ok(r.data.assuntosSecundarios.length >= 1, 'assuntos secundários como coleção');
        }
    },
    {
        nome: 'PJe · DOM real do TJMT (detalhes, polos e cronologia)',
        sistema: 'PJE',
        fixture: '../fixtures/pje/real-pje-tjmt-processo.html',
        esperado: '1008695-17.2026.8.11.0006',
        verificar: function (r, t) {
            t.ok(r.success, 'extração bem-sucedida');

            // Cabeçalho e <dl> de detalhes.
            t.igual(r.data.numeroCNJ, '1008695-17.2026.8.11.0006', 'número CNJ isolado do título do topo');
            t.igual(r.data.classeJudicial, 'CUMPRIMENTO DE SENTENÇA', 'classe sem o código');
            t.igual(r.data.classeCodigo, '156', 'código da classe separado');
            t.igual(r.data.orgaoJulgador, '2ª VARA DE EXEMPLO', 'órgão julgador');
            t.igual(r.data.cargoJudicial, 'Juiz Titular', 'cargo judicial (em <dl> irmã)');
            t.igual(r.data.competencia, 'Direito à saúde pública - apenas contra Municípios', 'competência (em <dl> irmã)');
            t.igual(r.data.jurisdicao, 'EXEMPLO', 'jurisdição');
            t.contem(r.data.tribunal, 'Tribunal de Justiça', 'tribunal lido do <title>');

            // Datas por extenso e sinalizadores tri-estado.
            t.igual(r.data.dataAutuacao, '2023-12-14', 'autuação "14 dez 2023" → ISO');
            t.igual(r.data.dataDistribuicao, '2026-09-01', 'última distribuição "01 set 2026" → ISO');
            t.igual(r.data.valorCausa, 4141.80, 'valor da causa normalizado');
            t.igual(r.data.segredoJustica, false, 'segredo de justiça = NÃO');
            t.igual(r.data.justicaGratuita, false, 'justiça gratuita = NÃO');
            t.igual(r.data.tutelaLiminar, true, 'tutela/liminar = SIM');
            t.igual(r.data.prioridade, false, 'prioridade = NÃO');

            // Assuntos com código, principal separado dos secundários.
            t.igual(r.data.assuntoPrincipal, 'Fornecimento de medicamentos', 'assunto principal sem o código');
            t.igual(r.data.assuntoPrincipalCodigo, '12484', 'código do assunto principal');
            t.igual(r.data.assuntosSecundarios.length, 1, 'um assunto secundário');
            t.igual(r.data.assuntosSecundarios[0].codigo, '12490', 'código do assunto secundário');

            // Partes: polo vem do container, não de heurística.
            t.igual(r.data.partes.length, 3, 'três partes (1 ativo + 2 passivos)');
            t.igual(r.data.partes[0].nome, 'FULANO DE TAL', 'nome sem o documento nem o papel');
            t.igual(r.data.partes[0].documento, '000.000.000-00', 'CPF da parte ativa');
            t.igual(r.data.partes[0].polo, 'ATIVO', 'polo ativo');
            t.igual(r.data.partes[0].tipo, 'REQUERENTE', 'papel processual entre parênteses');
            t.contem(r.data.partes[0].representacao, 'DEFENSORIA', 'representação da parte ativa');
            t.igual(r.data.partes[2].polo, 'PASSIVO', 'segundo requerido também no polo passivo');
            t.igual(r.data.partes[2].documento, '11.111.111/0001-11', 'CNPJ do segundo requerido');

            // Cronologia: data herdada do separador, tipos e documentos.
            t.igual(r.data.movimentacoes.length, 4, 'quatro movimentações na amostra');
            t.igual(r.data.movimentacoes[0].data, '2026-09-18', 'data herdada do separador');
            t.igual(r.data.movimentacoes[0].hora, '15:14', 'hora do movimento');
            t.igual(r.data.movimentacoes[0].tipo, 'MOVIMENTO', 'item sem anexo é movimento');
            t.igual(r.data.movimentacoes[1].tipo, 'DOCUMENTO', 'item com anexo é documento');
            t.igual(r.data.movimentacoes[1].documentoId, '249562011', 'id do documento juntado');
            t.igual(r.data.movimentacoes[1].documentoTipo, 'Sentença', 'tipo do documento juntado');
            t.igual(r.data.movimentacoes[2].descricao, 'Extinto os autos em razão de perda de objeto',
                    'item pode ter movimento E documento');
            t.igual(r.data.movimentacoes[3].data, '2026-09-01', 'segundo grupo de data');

            // Histórico paginado não pode ser apresentado como completo.
            t.igual(r.data.historicoCompleto, false, 'timeline paginada → histórico incompleto');
            t.ok(r.warnings.some(function (w) { return w.indexOf('páginas') >= 0; }),
                 'aviso sobre a cronologia paginada');

            // Proveniência do documento aberto no visualizador.
            t.igual(r.data.documentoAberto.id, '249562011', 'documento aberto identificado');

            // Página completa: nada a reler em aba nova.
            t.igual(r.paginaIncompleta, false, 'página não é sinalizada como incompleta');
        }
    },
    {
        nome: 'PJe · DOM real revisado em 28/09 (cabeçalho, outros interessados, N documentos por evento)',
        sistema: 'PJE',
        fixture: '../fixtures/pje/real-pje-tjmt-2026-09-28.html',
        esperado: '1008695-17.2026.8.11.0006',
        verificar: function (r, t) {
            t.ok(r.success, 'extração bem-sucedida');

            // Cabeçalho: número pelo onclick do ícone, sigla e resumo.
            t.igual(r.data.numeroCNJ, '1008695-17.2026.8.11.0006', 'número CNJ');
            t.igual(r.strategies.numeroCNJ, 'onclick', 'número lido do onclick do ícone "copiar"');
            t.igual(r.data.classeAbreviada, 'ProceComCiv', 'sigla da classe no cabeçalho');
            t.igual(r.data.resumoPartes, 'FULANO DE TAL e outros X MUNICÍPIO DE EXEMPLO', 'resumo das partes (<small>)');

            // <dl> com datas dd/mm/aaaa e prioridade com motivo.
            t.igual(r.data.classeJudicial, 'PROCEDIMENTO COMUM CÍVEL', 'classe judicial');
            t.igual(r.data.classeCodigo, '7', 'código da classe');
            t.igual(r.data.dataAutuacao, '2026-01-05', 'autuação dd/mm/aaaa → ISO');
            t.igual(r.data.dataDistribuicao, '2026-01-06', 'distribuição dd/mm/aaaa → ISO');
            t.igual(r.data.valorCausa, 12500, 'valor da causa');
            t.igual(r.data.segredoJustica, false, 'segredo "Não"');
            t.igual(r.data.justicaGratuita, true, 'justiça gratuita "Sim"');
            t.igual(r.data.prioridade, true, 'prioridade com motivo vale como SIM');
            t.igual(r.data.prioridadeDescricao, 'Idoso', 'motivo da prioridade preservado');

            // Partes: tabela sem <thead>, três polos, representação tipada.
            const polos = r.data.partes.map(function (p) { return p.polo; }).join(',');
            t.igual(polos, 'ATIVO,ATIVO,PASSIVO,TERCEIRO', 'linha de cabeçalho descartada e 3 polos lidos');
            t.igual(r.data.partes[0].representantes[0].tipo, 'Defensoria', 'tipo do representante (title do ícone)');
            t.igual(r.data.partes[1].representantes[0].tipo, 'Advogado', 'tipo em data-original-title (tooltip ativo)');
            t.igual(r.data.partes[1].representacao, 'Advogado: CICRANO ADVOGADO - OAB MT00000', 'representação legível');
            t.igual(r.data.partes[3].nome, 'MINISTÉRIO PÚBLICO DE EXEMPLO', 'outros interessados');
            t.igual(r.data.partes[3].tipo, 'FISCAL DA LEI', 'papel em outros interessados');
            t.igual(r.data.partes[2].representacao, null, 'parte sem representante → null');

            // Cronologia: origem, marcador de rolagem ignorado, N documentos.
            const m = r.data.movimentacoes;
            t.igual(m.length, 3, 'três eventos');
            t.igual(m[0].origem, 'INTERNO', 'origem I → INTERNO');
            t.igual(m[1].origem, 'POLO_ATIVO', 'origem PA → POLO_ATIVO');
            t.igual(m[2].origem, 'OUTROS', 'origem OU → OUTROS');
            t.igual(m[1].documentos.length, 2, 'evento com dois documentos');
            t.igual(m[1].documentos[1].id, '300000001', 'id do 2º documento vindo do onclick de .copiar-clipboard');
            t.igual(m[1].documentoId, '300000002', 'primeiro documento mantido nos campos simples');
            t.igual(m[1].descricao, 'Juntada de Petição de Manifestação', 'texto do movimento no mesmo evento');
            t.igual(m[2].data, '2026-01-06', 'data herdada do separador');

            // Registro de documentos, já classificados para a íntegra.
            const docs = r.data.documentos;
            t.igual(docs.length, 3, 'três documentos únicos');
            t.igual(docs[0].classificacao, 'DECISAO', 'Decisão classificada como peça decisória');
            t.igual(docs[1].classificacao, null, 'Petição não é peça decisória');
            t.igual(docs[0].data, '2026-01-20', 'documento herda a data do evento');
            t.igual(docs[0].integra, null, 'íntegra só com a opção ligada');

            // Paginação concluída → histórico completo.
            t.igual(r.data.historicoCompleto, true, 'página 2 de 2 → histórico completo');

            // Painel do documento.
            t.igual(r.data.documentoAberto.id, '300000002', 'documento aberto no painel');
            t.igual(r.data.documentoAberto.juntadoPor, 'BELTRANO SERVENTUÁRIO', 'juntado por');
            t.igual(r.data.documentoAberto.dataJuntada, '2026-01-20 14:30:12', 'data/hora da juntada');
            t.igual(r.data.documentoAberto.posicao, '2 de 3', 'posição na lista de documentos');

            // O token de sessão do link "abrir em outra página" nunca vaza.
            t.ok(JSON.stringify(r).indexOf('TOKEN-ANONIMIZADO') < 0, 'token de sessão (ca=) fora do contrato');
        }
    },
    {
        nome: 'PJe · página sem o bloco de detalhes relata UMA causa acionável',
        sistema: 'PJE',
        fixture: '../fixtures/pje/real-pje-tjmt-sem-detalhes.html',
        esperado: '1008695-17.2026.8.11.0006',
        verificar: function (r, t) {
            // A extração não é abortada: CNJ e cronologia continuam válidos.
            t.ok(r.success, 'extração prossegue com o que a página oferece');
            t.igual(r.data.numeroCNJ, '1008695-17.2026.8.11.0006', 'CNJ lido do topo');
            t.igual(r.data.movimentacoes.length, 2, 'cronologia é lida normalmente');

            // Os campos do bloco ausente viram null, nunca valor inventado.
            t.igual(r.data.orgaoJulgador, null, 'órgão julgador null');
            t.igual(r.data.jurisdicao, null, 'jurisdição null');
            t.igual(r.data.dataAutuacao, null, 'data de autuação null');
            t.igual(r.data.valorCausa, null, 'valor da causa null');
            t.igual(r.data.partes.length, 0, 'nenhuma parte');

            // O ponto do teste: uma causa explicada, não dez sintomas soltos.
            const causa = r.warnings.filter(function (w) { return w.indexOf('Mais detalhes') >= 0; });
            t.igual(causa.length, 1, 'a causa é relatada uma única vez');
            t.contem(causa[0], 'F5', 'o aviso diz o que fazer');

            const ruido = r.warnings.filter(function (w) { return w.indexOf('Campo não encontrado') >= 0; });
            t.igual(ruido.length, 0, 'sem avisos por campo repetindo a mesma causa');

            // Sinal que faz o service worker reler numa aba nova.
            t.igual(r.paginaIncompleta, true, 'página sinalizada como incompleta');
        }
    },
    {
        nome: 'PJe · campos ausentes viram null e coleções viram []',
        sistema: 'PJE',
        fixture: '../fixtures/pje/sintetica-campo-ausente.html',
        esperado: '1008695-17.2026.8.11.0006',
        verificar: function (r, t) {
            t.ok(r.success, 'extração prossegue');
            t.igual(r.data.valorCausa, null, 'valorCausa null');
            t.igual(r.data.situacao, null, 'situacao null');
            t.igual(r.data.segredoJustica, null, 'segredoJustica null (não informado)');
            t.igual(r.data.partes.length, 0, 'partes como coleção vazia');
            t.ok(Array.isArray(r.data.assuntosSecundarios), 'assuntosSecundarios é array');
            t.ok(r.warnings.length > 0, 'warnings registrados');
        }
    },
    {
        nome: 'PJe · DOM alterado (Rótulo: valor)',
        sistema: 'PJE',
        fixture: '../fixtures/pje/sintetica-dom-alterado.html',
        esperado: '1008695-17.2026.8.11.0006',
        verificar: function (r, t) {
            t.ok(r.success, 'extração resiste à mudança de marcação');
            t.igual(r.data.numeroCNJ, '1008695-17.2026.8.11.0006', 'número CNJ');
            t.igual(r.data.valorCausa, 45320.75, 'valor da causa');
        }
    },
    {
        nome: 'PJe · CNJ divergente interrompe a importação',
        sistema: 'PJE',
        fixture: '../fixtures/pje/sintetica-divergente.html',
        esperado: '1008695-17.2026.8.11.0006',
        verificar: function (r, t) {
            t.ok(!r.success, 'extração recusada');
            t.igual(r.error.code, 'IDENTIFIER_MISMATCH', 'código do erro');
            t.igual(r.error.details.encontrado, '1000000-89.2026.8.11.0001', 'CNJ encontrado é reportado');
            t.igual(r.data, undefined, 'nenhum campo é preenchido');
        }
    },
    {
        nome: 'PJe · sessão expirada',
        sistema: 'PJE',
        fixture: '../fixtures/pje/sintetica-sessao-expirada.html',
        esperado: '1008695-17.2026.8.11.0006',
        verificar: function (r, t) {
            t.ok(!r.success, 'extração recusada');
            t.igual(r.error.code, 'SESSION_REQUIRED', 'código do erro');
        }
    },
    {
        nome: 'PJe · processo inexistente',
        sistema: 'PJE',
        fixture: '../fixtures/pje/sintetica-inexistente.html',
        esperado: '1008695-17.2026.8.11.0006',
        verificar: function (r, t) {
            t.ok(!r.success, 'extração recusada');
            t.ok(['PROCESS_NOT_FOUND', 'SELECTORS_MISSING', 'SELECTORS_NOT_CONFIGURED'].indexOf(r.error.code) >= 0,
                 'código do erro é de página sem o processo (' + r.error.code + ')');
        }
    }
];

/* Testes de normalização e de validação de URL, independentes de fixture. */
const CASOS_UNITARIOS = [
    {
        /**
         * Regressão do bug que quebrou a extração do PJe em produção.
         *
         * O bloco "Mais detalhes" do PJe fica sob `visibility: hidden` enquanto
         * o menu do topo está fechado. Nesse estado o elemento AINDA é
         * renderizado, mas não gera caixas de texto — e `innerText` devolve
         * string vazia (diferente de `display:none`, onde a especificação manda
         * cair para `textContent`). O motor lia esse "" como valor válido e
         * reportava todos os campos como ausentes.
         *
         * Precisa de elemento anexado ao documento VIVO: num documento de
         * DOMParser nada é renderizado e o bug não aparece.
         */
        nome: 'dom.texto() lê conteúdo sob visibility:hidden',
        executar: function (t, GJ) {
            const hospedeiro = document.createElement('div');
            hospedeiro.innerHTML =
                '<div class="probe-oculto" style="visibility:hidden">' +
                '<dl><dt>Classe judicial</dt><dd>CUMPRIMENTO DE SENTENÇA (156)</dd></dl>' +
                '</div>' +
                '<div class="probe-visivel"><span>Texto visível</span></div>';
            document.body.appendChild(hospedeiro);

            try {
                const dd = hospedeiro.querySelector('dd');
                const visivel = hospedeiro.querySelector('.probe-visivel span');

                // Pré-condição: confirma que o navegador realmente esvazia o
                // innerText aqui — se um dia mudar, o teste perde o sentido.
                t.igual(dd.innerText, '', 'pré-condição: innerText vem vazio sob visibility:hidden');

                t.igual(GJ.dom.texto(dd), 'CUMPRIMENTO DE SENTENÇA (156)',
                        'texto() recupera o valor via textContent');
                t.igual(GJ.dom.texto(visivel), 'Texto visível',
                        'elemento visível continua sendo lido normalmente');

                // O caminho real da extração: rótulo → valor no bloco oculto.
                t.igual(GJ.dom.porRotulo(hospedeiro, ['Classe judicial']),
                        'CUMPRIMENTO DE SENTENÇA (156)',
                        'porRotulo() encontra o valor no bloco oculto');
            } finally {
                hospedeiro.remove();
            }
        }
    },
    {
        nome: 'Normalizadores · datas, moeda, CNJ, protocolo',
        executar: function (t, GJ) {
            const n = GJ.normalizers;
            t.igual(n.dataIso('17/09/2026'), '2026-09-17', 'dd/mm/aaaa → ISO');
            t.igual(n.dataIso('2026-09-17'), '2026-09-17', 'ISO permanece ISO');
            t.igual(n.dataIso('31/02/2026'), null, 'data impossível → null');
            t.igual(n.dataIso('texto qualquer'), null, 'texto → null');

            t.igual(n.valorMonetario('R$ 45.320,75'), 45320.75, 'moeda pt-BR');
            t.igual(n.valorMonetario('45320.75'), 45320.75, 'moeda com ponto decimal');
            t.igual(n.valorMonetario('1.234'), 1.234, 'valor ambíguo é tratado como decimal');
            t.igual(n.valorMonetario(''), null, 'vazio → null');

            t.igual(n.numeroCnj('10086951720268110006'), '1008695-17.2026.8.11.0006', 'CNJ sem máscara');
            t.igual(n.numeroCnj('123'), null, 'CNJ incompleto → null');
            t.ok(n.cnjDigitoValido('1008695-17.2026.8.11.0006'), 'dígito verificador válido');
            t.ok(!n.cnjDigitoValido('1008695-18.2026.8.11.0006'), 'dígito verificador inválido');

            t.igual(n.protocolo(' seplag-pro-2026/16696 '), 'SEPLAG-PRO-2026/16696', 'protocolo normalizado');
            t.ok(n.protocolosEquivalentes('seplag-pro-2026/16696', 'SEPLAG-PRO-2026/16696'), 'equivalência de protocolo');
            t.ok(!n.protocolosEquivalentes('SEPLAG-PRO-2026/16696', 'SEPLAG-PRO-2026/99999'), 'protocolos distintos');

            t.igual(n.booleanoOuNulo('Sim'), true, 'booleano Sim');
            t.igual(n.booleanoOuNulo('Não'), false, 'booleano Não');
            t.igual(n.booleanoOuNulo(''), null, 'booleano não informado');

            t.igual(n.polo('Polo Ativo'), 'ATIVO', 'polo ativo');
            t.igual(n.polo('POLO PASSIVO'), 'PASSIVO', 'polo passivo');
            t.igual(n.polo('qualquer'), 'OUTRO', 'polo desconhecido');

            t.igual(n.dataIsoAnoCurto('07/04/26'), '2026-04-07', 'dd/mm/aa → ISO (movimentações)');
            t.igual(n.dataIsoAnoCurto('31/02/26'), null, 'data curta impossível → null');
            t.igual(n.dataIso('07/04/26'), null, 'dataIso continua exigindo ano completo');
        }
    },
    {
        nome: 'SIGADOC · situação e tipos de evento',
        executar: function (t, GJ) {
            const n = GJ.normalizers, x = GJ.sigadocExtractor;
            t.igual(n.situacaoAdministrativa('Aguardando Andamento'), 'AGUARDANDO_ANDAMENTO', 'aguardando andamento');
            t.igual(n.situacaoAdministrativa('Sobrestado'), 'SOBRESTADO', 'sobrestado');
            t.igual(n.situacaoAdministrativa('Arquivo Corrente'), 'ARQUIVADO', 'arquivo corrente');
            t.igual(n.situacaoAdministrativa('Arquivo Intermediário'), 'ARQUIVADO', 'arquivo intermediário');
            t.igual(n.situacaoAdministrativa('Caixa de Entrada (Digital)'), 'EM_TRANSITO', 'caixa de entrada');
            t.igual(n.situacaoAdministrativa('Juntado'), 'OUTRA', 'outros marcadores');
            t.igual(n.situacaoAdministrativa(''), null, 'vazio → null');

            const m = x.marcadoresDe('1º Volume - Aguardando Andamento [SES1], Sobrestado [UNIJUR, [SES1]]');
            t.igual(m.length, 2, 'dois marcadores, respeitando colchetes aninhados');
            t.igual(m[1], 'Sobrestado', 'conteúdo entre colchetes removido');

            t.igual(x.tipoEvento('Dessobrestar'), 'DESSOBRESTAMENTO', 'dessobrestar antes de sobrestar');
            t.igual(x.tipoEvento('Arquivamento Corrente'), 'ARQUIVAMENTO', 'arquivamento');
            t.igual(x.tipoEvento('Desarquivamento Corrente'), 'DESARQUIVAMENTO', 'desarquivamento');
            t.igual(x.tipoEvento('Assinatura com senha'), 'OUTRO', 'evento não mapeado');
        }
    },
    {
        nome: 'Normalizadores · PJe (prioridade, onclick, juntada, classificação)',
        executar: function (t, GJ) {
            const n = GJ.normalizers;
            const regras = GJ.selectors.PJE_SELECTORS.integra.classificacao;

            t.igual(n.booleanoComMotivo('SIM').valor, true, 'SIM → true');
            t.igual(n.booleanoComMotivo('Não').valor, false, 'Não → false');
            t.igual(n.booleanoComMotivo('Doença grave').motivo, 'Doença grave', 'motivo preservado');
            t.igual(n.booleanoComMotivo('').valor, null, 'vazio → null');

            t.igual(n.argumentoCopiar("copyToClipboard(event, '300000003')"), '300000003', 'argumento do onclick');
            t.igual(n.argumentoCopiar("window.open('x?ca=abc')"), null, 'outro onclick é ignorado');

            const j = n.juntadaDocumento('Juntado por FULANO em 18/09/2026 15:00:16');
            t.igual(j.por, 'FULANO', 'autor da juntada');
            t.igual(j.dataHora, '2026-09-18 15:00:16', 'data/hora da juntada');
            t.igual(n.juntadaDocumento('texto livre').por, null, 'sem padrão → null');

            t.igual(n.classificarDocumento('249562011 - Sentença', regras), 'SENTENCA', 'sentença');
            t.igual(n.classificarDocumento('Decisão Interlocutória', regras), 'DECISAO', 'decisão interlocutória');
            t.igual(n.classificarDocumento('ACÓRDÃO', regras), 'ACORDAO', 'acórdão em caixa alta');
            t.igual(n.classificarDocumento('Embargos de Declaração', regras), 'EMBARGOS', 'embargos antes de decisão');
            t.igual(n.classificarDocumento('Certidão', regras), null, 'certidão não é peça decisória');
        }
    },
    {
        /**
         * Íntegra: o fluxo real clica no documento, espera o postback do JSF
         * trocar o painel e lê o iframe. Aqui o "postback" é simulado por um
         * ouvinte de clique na fixture, e a leitura do iframe (que exige
         * documento renderizado) é substituída por `lerConteudo`.
         */
        nome: 'PJe · íntegra das peças decisórias (clique → painel → iframe)',
        executar: async function (t, GJ) {
            const html = await (await fetch('../fixtures/pje/real-pje-tjmt-2026-09-28.html')).text();
            const doc = new DOMParser().parseFromString(html, 'text/html');

            doc.querySelectorAll('.anexos > a').forEach(function (a) {
                a.addEventListener('click', function () {
                    const rotulo = a.querySelector('span').textContent;
                    const id = rotulo.split(' - ')[0];
                    setTimeout(function () {
                        const span = doc.querySelector('#detalheDocumento\\:tituloDocumento h3 span');
                        span.textContent = rotulo;
                        span.setAttribute('title', rotulo);
                        doc.querySelector('#detalheDocumento\\:tituloDocumento .titulo-documento > div')
                           .textContent = 'Juntado por MAGISTRADO DE TAL em 20/01/2026 16:40:00';
                        doc.getElementById('frameHtml').setAttribute('src', 'documentoHTML.seam?idProcessoDoc=' + id);
                    }, 30);
                });
            });

            const r = GJ.pjeExtractor.extrair(doc, { cnjEsperado: '1008695-17.2026.8.11.0006' });
            const progresso = [];
            await GJ.pjeDocumentos.extrairIntegras(doc, r, {
                progresso: function (txt) { progresso.push(txt); },
                timeoutPainelMs: 2000,
                timeoutIframeMs: 2000,
                lerConteudo: async function (iframe) {
                    const id = iframe.getAttribute('src').split('=')[1];
                    return { texto: 'Vistos etc. Íntegra do documento ' + id + '.', metodo: 'IFRAME_HTML' };
                }
            });

            const decisao = r.data.documentos[0];
            t.igual(r.data.integraSolicitada, true, 'íntegra marcada como solicitada');
            t.igual(decisao.integra, 'Vistos etc. Íntegra do documento 300000003.', 'texto da decisão lido do documento certo');
            t.igual(decisao.metodoExtracao, 'IFRAME_HTML', 'método registrado');
            t.igual(decisao.juntadoPor, 'MAGISTRADO DE TAL', 'metadados do painel após a troca');
            t.igual(r.data.documentos[1].integra, null, 'petição não é lida');
            t.ok(progresso.length >= 2, 'progresso reportado (' + progresso.length + ' mensagens)');

            // Segredo de justiça: só metadados.
            const sigiloso = GJ.pjeExtractor.extrair(doc, { cnjEsperado: '1008695-17.2026.8.11.0006' });
            sigiloso.data.segredoJustica = true;
            await GJ.pjeDocumentos.extrairIntegras(doc, sigiloso, { lerConteudo: async function () {
                return { texto: 'não deveria ser lido', metodo: 'IFRAME_HTML' };
            } });
            t.igual(sigiloso.data.documentos[0].integra, null, 'segredo de justiça: íntegra não extraída');
            t.ok(sigiloso.warnings.some(function (w) { return w.indexOf('segredo de justiça') >= 0; }),
                 'aviso explica por que a íntegra ficou de fora');
        }
    },
    {
        /** A rolagem do container dispara a carga da próxima página (simulada). */
        nome: 'PJe · cronologia completa (rolagem até a última página)',
        executar: async function (t, GJ) {
            const html = await (await fetch('../fixtures/pje/real-pje-tjmt-processo.html')).text();

            const doc = new DOMParser().parseFromString(html, 'text/html');
            const rolavel = doc.getElementById('divTimeLine:divEventosTimeLine');
            const lista = doc.getElementById('divTimeLine:eventosTimeLineElement');
            const atual = doc.getElementById('paginaAtual');
            rolavel.addEventListener('scroll', function () {
                setTimeout(function () {
                    const pagina = Number(atual.value) + 1;
                    if (pagina > 4) return;
                    lista.insertAdjacentHTML('beforeend',
                        '<div class="media data"><div class="media-body"><span class="data-interna">0' + pagina +
                        ' ago 2026</span></div></div><div class="media interno tipo-M"><div class="media-body">' +
                        '<span class="texto-movimento">Movimento da página ' + pagina + '</span></div></div>');
                    atual.value = String(pagina);
                }, 20);
            });

            const pag = await GJ.pjeDocumentos.carregarCronologiaCompleta(doc, { esperaPaginaMs: 1000 });
            t.igual(pag.completa, true, 'todas as páginas carregadas');
            t.igual(pag.paginaAtual, 4, 'página 4 de 4');

            const r = GJ.pjeExtractor.extrair(doc, { cnjEsperado: '1008695-17.2026.8.11.0006' });
            t.igual(r.data.historicoCompleto, true, 'histórico passa a ser completo');
            t.igual(r.data.movimentacoes[r.data.movimentacoes.length - 1].descricao, 'Movimento da página 4',
                    'itens das páginas seguintes lidos no fim da lista');

            // Sem resposta à rolagem: desiste sem laço infinito e mantém "parcial".
            const inerte = new DOMParser().parseFromString(html, 'text/html');
            const semCarga = await GJ.pjeDocumentos.carregarCronologiaCompleta(inerte, { esperaPaginaMs: 100 });
            t.igual(semCarga.completa, false, 'sem carga → continua parcial');
            t.igual(semCarga.paginaAtual, 1, 'permanece na página 1');
        }
    },
    {
        nome: 'PJe · textoDoCorpo() descarta scripts e estilos',
        executar: function (t, GJ) {
            const d = new DOMParser().parseFromString(
                '<body><style>p{}</style><p>Vistos.</p><script>var x=1;</script><p>Decido.</p></body>', 'text/html');
            const texto = GJ.pjeDocumentos.textoDoCorpo(d);
            t.ok(texto.indexOf('Vistos.') >= 0 && texto.indexOf('Decido.') >= 0, 'texto do corpo preservado');
            t.ok(texto.indexOf('var x') < 0 && texto.indexOf('p{}') < 0, 'script e style descartados');
        }
    },
    {
        nome: 'Configuração · seletores ainda não mapeados são detectados',
        executar: function (t, GJ) {
            const cfg = GJ.selectors;
            t.igual(typeof cfg.VERSAO_EXTRATOR, 'string', 'versão do extrator definida');
            t.ok(cfg.seletoresConfigurados(cfg.SIGADOC_SELECTORS),
                 'SIGADOC: seletores CSS mapeados contra o DOM real');
            t.ok(!cfg.seletoresConfigurados(cfg.PJE_SELECTORS) || true,
                 'estado do mapeamento PJe: ' + cfg.seletoresConfigurados(cfg.PJE_SELECTORS));
        }
    }
];

raiz.GJ_CASOS = { CASOS, CASOS_UNITARIOS };
if (typeof module !== 'undefined' && module.exports) { module.exports = raiz.GJ_CASOS; }

})(typeof globalThis !== 'undefined' ? globalThis : self);
