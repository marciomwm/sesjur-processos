<?php
declare(strict_types=1);

/**
 * Testes de integração com o banco de dados.
 * Executam sobre um banco de testes isolado (gestao_juridica_teste),
 * recriado a cada execução. O banco de produção não é tocado.
 *
 * @var \Tests\Runner $t
 */

use App\Core\Database;
use App\Core\ValidationException;
use App\Repositories\AssuntoProcessualRepository;
use App\Repositories\DocumentoJudicialRepository;
use App\Repositories\EventoAdministrativoRepository;
use App\Repositories\MovimentacaoJudicialRepository;
use App\Repositories\ParteProcessualRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Repositories\VinculoRepository;
use App\Services\ProcessoAdministrativoService;
use App\Services\ProcessoJudicialService;
use App\Services\VinculoService;

$bancoPronto = $GLOBALS['gj_banco_teste_pronto'] ?? false;

$exigirBanco = static function ($t) use ($bancoPronto): void {
    if (!$bancoPronto) {
        $t->ignorar('banco de testes indisponível');
    }
};

$t->grupo('CRUD — Processo Administrativo');

$t->teste('cria, lê, atualiza e exclui', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $servico = new ProcessoAdministrativoService();
    $repo    = new ProcessoAdministrativoRepository();

    $id = $servico->criar([
        'protocolo'     => 'seplag-pro-2026/16696',
        'data_abertura' => '17/09/2026',
        'objeto'        => 'Solicitação de informações para defesa do Estado autos nº 1008695-17.2026.8.11.0006 - FGTS - SES',
    ]);
    $t->afirmar($id > 0, 'id retornado');

    $registro = $repo->buscarPorId($id);
    $t->igual($registro['protocolo'], 'SEPLAG-PRO-2026/16696', 'protocolo normalizado na gravação');
    $t->igual($registro['data_abertura'], '2026-09-17', 'data armazenada em ISO');
    $t->contem($registro['objeto'], 'FGTS', 'objeto persistido');
    $t->igual($registro['origem'], 'MANUAL', 'origem padrão');

    $servico->atualizar($id, [
        'protocolo'     => 'SEPLAG-PRO-2026/16696',
        'data_abertura' => '18/09/2026',
        'objeto'        => 'Objeto atualizado',
    ]);
    $registro = $repo->buscarPorId($id);
    $t->igual($registro['data_abertura'], '2026-09-18', 'data atualizada');
    $t->igual($registro['objeto'], 'Objeto atualizado', 'objeto atualizado');

    $servico->excluir($id);
    $t->igual($repo->buscarPorId($id), null, 'registro removido');
});

$t->teste('grava situação, responsável e movimentações do SIGADOC', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $servico = new ProcessoAdministrativoService();
    $repo    = new ProcessoAdministrativoRepository();
    $repoEv  = new EventoAdministrativoRepository();

    $eventos = [
        ['data' => '2026-09-18', 'tipo' => 'RECEBIMENTO', 'tipoDescricao' => 'Recebimento',
         'lotacao' => 'SESUNIJUR', 'responsavel' => 'SERVIDOR 01', 'atendente' => 'SERVIDOR 01'],
        ['data' => '2026-08-28', 'tipo' => 'JUNTADA', 'tipoDescricao' => 'Juntada',
         'documento' => 'SES-CIN-2026/161332-A', 'responsavel' => 'SERVIDOR 02'],
        ['data' => '2026-05-07', 'tipo' => 'SOBRESTAMENTO', 'tipoDescricao' => 'Sobrestar'],
    ];

    $id = $servico->criar([
        'protocolo'             => 'SES-PRO-2026/23509',
        'origem'                => 'SIGADOC',
        'situacao'              => 'AGUARDANDO_ANDAMENTO',
        'situacao_descricao'    => 'Aguardando Andamento',
        'responsavel_nome'      => 'SERVIDOR 01',
        'responsavel_matricula' => 'SES000001',
        'responsavel_lotacao'   => 'UNIJUR',
        'eventos_json'          => json_encode($eventos),
    ]);

    $registro = $repo->buscarPorId($id);
    $t->igual($registro['situacao'], 'AGUARDANDO_ANDAMENTO', 'situação gravada');
    $t->igual($registro['responsavel_nome'], 'SERVIDOR 01', 'responsável gravado');
    $t->igual($registro['responsavel_lotacao'], 'UNIJUR', 'lotação gravada');

    $gravados = $repoEv->listarPorProcesso($id);
    $t->igual(count($gravados), 3, 'três movimentações');
    $t->igual($gravados[0]['tipo'], 'RECEBIMENTO', 'ordem da fonte preservada');
    $t->igual($gravados[1]['documento'], 'SES-CIN-2026/161332-A', 'documento juntado');
    $t->igual($gravados[2]['data_evento'], '2026-05-07', 'data do evento');

    // Edição sem nova extração mantém as movimentações.
    $servico->atualizar($id, [
        'protocolo' => 'SES-PRO-2026/23509', 'situacao' => 'SOBRESTADO', 'eventos_json' => '',
    ]);
    $t->igual($repo->buscarPorId($id)['situacao'], 'SOBRESTADO', 'situação editada manualmente');
    $t->igual($repoEv->contarPorProcesso($id), 3, 'movimentações mantidas sem nova extração');

    // Nova extração substitui a lista inteira.
    $servico->atualizar($id, [
        'protocolo' => 'SES-PRO-2026/23509', 'eventos_json' => json_encode([$eventos[0]]),
    ]);
    $t->igual($repoEv->contarPorProcesso($id), 1, 'movimentações substituídas');
    $t->igual($repo->buscarPorId($id)['situacao'], null, 'situação limpa quando não enviada');

    $t->lanca(ValidationException::class, fn() => $servico->atualizar($id, [
        'protocolo' => 'SES-PRO-2026/23509', 'situacao' => 'INVENTADA',
    ]), 'situação fora da lista é rejeitada');

    $servico->excluir($id);
    $t->igual($repoEv->contarPorProcesso($id), 0, 'movimentações removidas em cascata');
});

$t->teste('rejeita protocolo duplicado', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $servico = new ProcessoAdministrativoService();

    $id = $servico->criar(['protocolo' => 'SEPLAG-PRO-2026/10001']);
    $e = $t->lanca(ValidationException::class,
        fn() => $servico->criar(['protocolo' => 'seplag-pro-2026/10001']),
        'duplicidade deve ser bloqueada');
    $t->contem($e->erros()['protocolo'] ?? '', 'Já existe', 'mensagem de duplicidade');

    (new ProcessoAdministrativoService())->excluir($id);
});

$t->teste('rejeita protocolo e data inválidos', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $servico = new ProcessoAdministrativoService();

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['protocolo' => '123']), 'protocolo inválido');
    $t->afirmar(isset($e->erros()['protocolo']), 'erro no campo protocolo');

    $e = $t->lanca(ValidationException::class,
        fn() => $servico->criar(['protocolo' => 'SEPLAG-PRO-2026/10002', 'data_abertura' => '31/02/2026']),
        'data inválida');
    $t->afirmar(isset($e->erros()['data_abertura']), 'erro no campo data_abertura');
});

$t->grupo('CRUD — Processo Judicial, partes e assuntos');

$t->teste('cria processo com partes e assuntos em uma transação', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $servico   = new ProcessoJudicialService();
    $repo      = new ProcessoJudicialRepository();
    $repoParte = new ParteProcessualRepository();
    $repoAss   = new AssuntoProcessualRepository();

    $id = $servico->criar([
        'numero_cnj'           => '10086951720268110006',
        'tribunal'             => 'Tribunal de Justiça do Estado de Mato Grosso',
        'classe_judicial'      => 'Procedimento Comum Cível',
        'assunto_principal'    => 'FGTS',
        'assuntos_secundarios' => "Servidor Público Civil\nAdicional de Insalubridade",
        'data_distribuicao'    => '15/09/2026',
        'valor_causa'          => 'R$ 45.320,75',
        'situacao'             => 'Em andamento',
        'segredo_justica'      => '0',
        'partes'               => [
            ['nome' => 'FULANO DE TAL', 'documento' => '000.000.000-00', 'polo' => 'Polo Ativo', 'tipo' => 'Autor'],
            ['nome' => 'ESTADO DE MATO GROSSO', 'polo' => 'Polo Passivo', 'tipo' => 'Réu'],
            ['nome' => '', 'polo' => 'OUTRO'],
        ],
    ]);

    $registro = $repo->buscarPorId($id);
    $t->igual($registro['numero_cnj'], '1008695-17.2026.8.11.0006', 'CNJ normalizado');
    $t->igual($registro['data_distribuicao'], '2026-09-15', 'data em ISO');
    $t->igual((float)$registro['valor_causa'], 45320.75, 'valor da causa');
    $t->igual((int)$registro['segredo_justica'], 0, 'segredo de justiça');

    $partes = $repoParte->listarPorProcesso($id);
    $t->igual(count($partes), 2, 'linha vazia descartada');
    $t->igual($partes[0]['polo'], 'ATIVO', 'ordenação por polo');
    $t->igual($partes[1]['polo'], 'PASSIVO', 'segunda parte no polo passivo');

    $assuntos = $repoAss->listarPorProcesso($id);
    $t->igual(count($assuntos), 3, 'assunto principal + 2 secundários');
    $t->igual((int)$assuntos[0]['principal'], 1, 'primeiro é o principal');
    $t->igual($repoAss->secundarios($id), ['Servidor Público Civil', 'Adicional de Insalubridade'], 'secundários');

    $GLOBALS['gj_teste_judicial_id'] = $id;
});

$t->teste('grava os campos detalhados e a cronologia do PJe', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $servico = new ProcessoJudicialService();
    $repo    = new ProcessoJudicialRepository();
    $repoMov = new MovimentacaoJudicialRepository();
    $repoAss = new AssuntoProcessualRepository();

    $id = $servico->criar([
        'numero_cnj'               => '1001857-48.2023.8.11.0108',
        'orgao_julgador'           => '2ª VARA DE EXEMPLO',
        'cargo_judicial'           => 'Juiz Titular',
        'competencia'              => 'Direito à saúde pública - apenas contra Municípios',
        'classe_judicial'          => 'CUMPRIMENTO DE SENTENÇA',
        'classe_codigo'            => '156',
        'assunto_principal'        => 'Fornecimento de medicamentos',
        'assunto_principal_codigo' => '12484',
        'data_autuacao'            => '14 dez 2023',
        'data_distribuicao'        => '01 set 2026',
        'tutela_liminar'           => '1',
        'prioridade'               => '0',
        // justica_gratuita omitida de proposito: deve permanecer NULL.
        'historico_completo'       => '0',
        'movimentacoes_json'       => json_encode([
            ['data' => '2026-09-18', 'hora' => '15:14', 'tipo' => 'MOVIMENTO',
             'descricao' => 'Expedida/certificada a intimação eletrônica'],
            ['data' => '2026-09-18', 'hora' => '15:00', 'tipo' => 'DOCUMENTO',
             'documentoId' => '249562011', 'documentoTipo' => 'Sentença'],
        ]),
    ]);

    $registro = $repo->buscarPorId($id);
    $t->igual($registro['cargo_judicial'], 'Juiz Titular', 'cargo judicial gravado');
    $t->igual($registro['classe_codigo'], '156', 'código da classe gravado');
    $t->igual($registro['data_autuacao'], '2023-12-14', 'autuação por extenso convertida');
    $t->igual($registro['data_distribuicao'], '2026-09-01', 'distribuição por extenso convertida');
    $t->igual((int)$registro['tutela_liminar'], 1, 'tutela/liminar = sim');
    $t->igual((int)$registro['prioridade'], 0, 'prioridade = não');
    $t->igual($registro['justica_gratuita'], null, 'sinalizador não informado permanece NULL');

    $assuntos = $repoAss->listarPorProcesso($id);
    $t->igual($assuntos[0]['codigo'], '12484', 'código CNJ do assunto principal gravado');

    $movs = $repoMov->listarPorProcesso($id);
    $t->igual(count($movs), 2, 'duas movimentações gravadas');
    $t->igual($movs[0]['descricao'], 'Expedida/certificada a intimação eletrônica', 'ordem preservada (mais recente primeiro)');
    $t->igual($movs[0]['hora'], '15:14:00', 'hora gravada como TIME');
    $t->igual($movs[1]['documento_id'], '249562011', 'documento juntado gravado');

    // Salvar sem nova extração não pode apagar a cronologia já importada.
    $servico->atualizar($id, [
        'numero_cnj'         => '1001857-48.2023.8.11.0108',
        'situacao'           => 'Sentenciado',
        'movimentacoes_json' => '',
    ]);
    $t->igual(count($repoMov->listarPorProcesso($id)), 2, 'cronologia preservada em edição manual');

    // Uma nova extração substitui a lista inteira.
    $servico->atualizar($id, [
        'numero_cnj'         => '1001857-48.2023.8.11.0108',
        'movimentacoes_json' => json_encode([
            ['data' => '2026-09-20', 'hora' => '09:00', 'tipo' => 'MOVIMENTO', 'descricao' => 'Arquivado definitivamente'],
        ]),
    ]);
    $movs = $repoMov->listarPorProcesso($id);
    $t->igual(count($movs), 1, 'nova extração substitui a cronologia');
    $t->igual($movs[0]['descricao'], 'Arquivado definitivamente', 'conteúdo substituído');

    // Exclusão do processo leva a cronologia junto (CASCADE).
    $servico->excluir($id);
    $t->igual($repoMov->contarPorProcesso($id), 0, 'cronologia removida com o processo');
});

$t->teste('documentos do PJe: acumulam, ligam-se ao evento e nunca perdem a íntegra', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $servico = new ProcessoJudicialService();
    $repo    = new ProcessoJudicialRepository();
    $repoMov = new MovimentacaoJudicialRepository();
    $repoDoc = new DocumentoJudicialRepository();

    $movs = json_encode([
        ['data' => '2026-01-20', 'hora' => '16:40', 'tipo' => 'DOCUMENTO', 'origem' => 'INTERNO',
         'documentoId' => '300000003', 'documentoTipo' => 'Decisão',
         'documentos' => [['id' => '300000003', 'tipo' => 'Decisão']]],
        ['data' => '2026-01-20', 'hora' => '14:30', 'tipo' => 'DOCUMENTO', 'origem' => 'POLO_ATIVO',
         'descricao' => 'Juntada de Petição', 'documentoId' => '300000002', 'documentoTipo' => 'Petição',
         'documentos' => [['id' => '300000002', 'tipo' => 'Petição'], ['id' => '300000001', 'tipo' => 'Anexo']]],
    ]);

    $id = $servico->criar([
        'numero_cnj'           => '0004321-76.2026.8.11.0037',
        'classe_abreviada'     => 'ProceComCiv',
        'resumo_partes'        => 'FULANO DE TAL X MUNICÍPIO DE EXEMPLO',
        'prioridade'           => '',
        'prioridade_descricao' => 'Idoso',
        'movimentacoes_json'   => $movs,
        'documentos_json'      => json_encode([
            ['id' => '300000003', 'tipo' => 'Decisão', 'classificacao' => 'DECISAO', 'data' => '2026-01-20',
             'integra' => "Vistos.\n\nDefiro a tutela.", 'metodoExtracao' => 'IFRAME_HTML', 'ordemMovimentacao' => 0],
            ['id' => '300000002', 'tipo' => 'Petição', 'ordemMovimentacao' => 1],
            ['id' => '300000001', 'tipo' => 'Anexo', 'ordemMovimentacao' => 1],
        ]),
        'partes' => [
            ['nome' => 'FULANO DE TAL', 'polo' => 'ATIVO',
             'representacao' => str_repeat('Advogado: FULANO ADVOGADO - OAB MT00000; ', 10)],
        ],
    ]);

    $registro = $repo->buscarPorId($id);
    $t->igual($registro['classe_abreviada'], 'ProceComCiv', 'sigla da classe gravada');
    $t->igual($registro['resumo_partes'], 'FULANO DE TAL X MUNICÍPIO DE EXEMPLO', 'resumo das partes gravado');
    $t->igual((int)$registro['prioridade'], 1, 'motivo sem resposta explícita grava prioridade = sim');
    $t->igual($registro['prioridade_descricao'], 'Idoso', 'motivo gravado');

    $partes = (new ParteProcessualRepository())->listarPorProcesso($id);
    $t->afirmar(mb_strlen((string)$partes[0]['representacao']) > 255, 'representação longa cabe na coluna TEXT');

    $gravadas = $repoMov->listarPorProcesso($id);
    $t->igual($gravadas[1]['origem'], 'POLO_ATIVO', 'origem do evento gravada');

    $docs = $repoDoc->listarPorProcesso($id);
    $t->igual(count($docs), 3, 'três documentos');
    $t->igual($docs[0]['classificacao'], 'DECISAO', 'classificação gravada');
    $t->igual((int)$docs[0]['movimentacao_id'], (int)$gravadas[0]['id'], 'decisão ligada ao seu evento');
    $t->igual((int)$docs[2]['movimentacao_id'], (int)$gravadas[1]['id'], 'anexo ligado ao evento com dois documentos');
    $t->igual((int)$docs[0]['tamanho_integra'], mb_strlen("Vistos.\n\nDefiro a tutela."), 'listagem traz só o tamanho da íntegra');
    $t->afirmar(!array_key_exists('texto_integral', $docs[0]), 'listagem não carrega o texto');
    $t->igual($repoDoc->buscarPorId((int)$docs[0]['id'])['texto_integral'], "Vistos.\n\nDefiro a tutela.", 'íntegra com parágrafos');

    $agrupados = agrupar_por_movimentacao($docs);
    $t->igual(count($agrupados[(int)$gravadas[1]['id']]), 2, 'dois documentos agrupados no mesmo evento');

    // Nova importação SEM a íntegra e com a timeline parcial: nada se perde,
    // e uma tentativa sem texto (PDF) não apaga o que já foi lido.
    $servico->atualizar($id, [
        'numero_cnj'         => '0004321-76.2026.8.11.0037',
        'movimentacoes_json' => json_encode([
            ['data' => '2026-02-01', 'hora' => '10:00', 'tipo' => 'DOCUMENTO',
             'documentoId' => '300000004', 'documentoTipo' => 'Sentença',
             'documentos' => [['id' => '300000004', 'tipo' => 'Sentença']]],
        ]),
        'documentos_json'    => json_encode([
            ['id' => '300000004', 'tipo' => 'Sentença', 'ordemMovimentacao' => 0, 'metodoExtracao' => 'PDF_SEM_TEXTO'],
            ['id' => '300000003', 'tipo' => 'Decisão', 'metodoExtracao' => 'PDF_SEM_TEXTO'],
        ]),
    ]);

    $docs = $repoDoc->listarPorProcesso($id);
    $porNumero = array_column($docs, null, 'documento_id');
    $t->igual(count($docs), 4, 'documento novo acrescentado, antigos mantidos');
    $t->igual($porNumero['300000004']['classificacao'], 'SENTENCA', 'classificação deduzida do tipo');
    $t->igual($porNumero['300000004']['metodo_extracao'], 'PDF_SEM_TEXTO', 'tentativa sem texto registrada');
    $t->afirmar((int)$porNumero['300000003']['tamanho_integra'] > 0, 'íntegra anterior preservada');
    $t->igual($porNumero['300000003']['metodo_extracao'], 'IFRAME_HTML', 'método da íntegra válida mantido');
    $t->igual($porNumero['300000002']['movimentacao_id'], null, 'evento substituído → ligação desfeita, documento mantido');

    // Edição manual (sem importação) não toca nos documentos.
    $servico->atualizar($id, ['numero_cnj' => '0004321-76.2026.8.11.0037', 'prioridade' => '0', 'prioridade_descricao' => 'Idoso']);
    $t->igual($repoDoc->contarPorProcesso($id), 4, 'documentos preservados na edição manual');
    $t->igual($repo->buscarPorId($id)['prioridade_descricao'], null, 'prioridade "Não" descarta o motivo');

    $servico->excluir($id);
    $t->igual($repoDoc->contarPorProcesso($id), 0, 'documentos removidos com o processo');
});

$t->teste('entrada do formulário preserva origem e histórico na edição', function ($t) {
    $post = ['numero_cnj' => ' 1008695-17.2026.8.11.0006 ', 'classe_abreviada' => 'ProceComCiv',
             'partes' => [['nome' => 'X']], 'administrativos_ids' => ['3', 'x']];
    $d = ProcessoJudicialService::entradaDoFormulario($post, ['origem' => 'PJE', 'historico_completo' => 1]);

    $t->igual($d['numero_cnj'], '1008695-17.2026.8.11.0006', 'texto aparado');
    $t->igual($d['classe_abreviada'], 'ProceComCiv', 'campo novo incluído');
    $t->igual($d['origem'], 'PJE', 'origem do registro mantida sem nova extração');
    $t->igual($d['historico_completo'], '1', 'histórico completo mantido sem nova extração');
    $t->igual($d['documentos_json'], '', 'sem documentos enviados');
    $t->igual($d['administrativos_ids'], [3, 0], 'ids convertidos para inteiro');
});

$t->teste('códigos CNJ sobrevivem ao round-trip do formulário', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $servico = new ProcessoJudicialService();
    $repo    = new ProcessoJudicialRepository();
    $repoAss = new AssuntoProcessualRepository();

    // O usuário cola do PJe, no formato "Descrição (código)".
    $id = $servico->criar([
        'numero_cnj'           => '1000000-89.2026.8.11.0001',
        'classe_judicial'      => 'CUMPRIMENTO DE SENTENÇA (156)',
        'assunto_principal'    => 'Fornecimento de medicamentos (12484)',
        'assuntos_secundarios' => 'Tratamento médico-hospitalar (12490)',
    ]);

    $registro = $repo->buscarPorId($id);
    $t->igual($registro['classe_judicial'], 'CUMPRIMENTO DE SENTENÇA', 'classe guardada sem o código');
    $t->igual($registro['classe_codigo'], '156', 'código da classe extraído do texto');
    $t->igual($registro['assunto_principal'], 'Fornecimento de medicamentos', 'assunto sem o código');
    $t->igual($repoAss->codigoPrincipal($id), '12484', 'código do assunto principal em coluna própria');

    // O formulário de edição relê os secundários com o código entre parênteses.
    $secundarios = $repoAss->secundarios($id);
    $t->igual($secundarios, ['Tratamento médico-hospitalar (12490)'], 'secundário volta com o código');

    // Reenviar exatamente o que o formulário mostra não pode duplicar nem perder o código.
    $servico->atualizar($id, [
        'numero_cnj'               => '1000000-89.2026.8.11.0001',
        'classe_judicial'          => $registro['classe_judicial'],
        'classe_codigo'            => $registro['classe_codigo'],
        'assunto_principal'        => $registro['assunto_principal'],
        'assunto_principal_codigo' => $repoAss->codigoPrincipal($id),
        'assuntos_secundarios'     => implode("\n", $secundarios),
    ]);

    $t->igual($repoAss->codigoPrincipal($id), '12484', 'código do principal preservado ao salvar');
    $t->igual($repoAss->secundarios($id), ['Tratamento médico-hospitalar (12490)'], 'secundário preservado ao salvar');
    $t->igual($repo->buscarPorId($id)['assunto_principal'], 'Fornecimento de medicamentos',
              'descrição não acumula parênteses a cada salvamento');

    $servico->excluir($id);
});

$t->teste('recusa cronologia corrompida sem gravar nada', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $servico = new ProcessoJudicialService();
    $repo    = new ProcessoJudicialRepository();

    try {
        $servico->criar([
            'numero_cnj'         => '1000000-89.2026.8.11.0001',
            'movimentacoes_json' => '{isso não é json}',
        ]);
        $t->afirmar(false, 'deveria ter lançado ValidationException');
    } catch (ValidationException $e) {
        $t->afirmar(isset($e->erros()['movimentacoes']), 'erro reportado no campo movimentacoes');
    }

    $t->igual($repo->buscarPorCnj('1000000-89.2026.8.11.0001'), null, 'nenhum processo foi criado');
});

$t->teste('rejeita autuação posterior à distribuição', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $servico = new ProcessoJudicialService();
    try {
        $servico->criar([
            'numero_cnj'        => '1000000-89.2026.8.11.0001',
            'data_autuacao'     => '2026-09-20',
            'data_distribuicao' => '2026-09-01',
        ]);
        $t->afirmar(false, 'deveria ter lançado ValidationException');
    } catch (ValidationException $e) {
        $t->afirmar(isset($e->erros()['data_autuacao']), 'erro reportado na data de autuação');
    }
});

$t->teste('rejeita CNJ com dígito verificador inválido e duplicado', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $servico = new ProcessoJudicialService();

    $e = $t->lanca(ValidationException::class,
        fn() => $servico->criar(['numero_cnj' => '1008695-18.2026.8.11.0006']),
        'dígito verificador inválido');
    $t->contem($e->erros()['numero_cnj'] ?? '', 'dígito verificador', 'mensagem do DV');

    $e = $t->lanca(ValidationException::class,
        fn() => $servico->criar(['numero_cnj' => '1008695-17.2026.8.11.0006']),
        'CNJ duplicado');
    $t->contem($e->erros()['numero_cnj'] ?? '', 'Já existe', 'mensagem de duplicidade');
});

$t->teste('atualização sincroniza partes (inclui, altera e remove)', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $id = $GLOBALS['gj_teste_judicial_id'] ?? null;
    if ($id === null) {
        $t->ignorar('processo judicial de apoio não foi criado');
    }

    $servico   = new ProcessoJudicialService();
    $repoParte = new ParteProcessualRepository();

    $partesAtuais = $repoParte->listarPorProcesso($id);
    $idPrimeira = (int)$partesAtuais[0]['id'];

    $servico->atualizar($id, [
        'numero_cnj' => '1008695-17.2026.8.11.0006',
        'partes'     => [
            ['id' => $idPrimeira, 'nome' => 'FULANO DE TAL (alterado)', 'polo' => 'ATIVO', 'tipo' => 'Autor'],
            ['nome' => 'TERCEIRO INTERESSADO', 'polo' => 'Terceiro'],
        ],
    ]);

    $partes = $repoParte->listarPorProcesso($id);
    $t->igual(count($partes), 2, 'parte ausente foi removida');
    $t->igual($partes[0]['nome'], 'FULANO DE TAL (alterado)', 'parte existente atualizada');
    $t->igual($partes[1]['polo'], 'TERCEIRO', 'nova parte inserida');
});

$t->teste('transação: falha em partes não grava o processo', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $servico = new ProcessoJudicialService();
    $repo    = new ProcessoJudicialRepository();
    $cnj     = '1000000-89.2026.8.11.0001';

    $t->lanca(ValidationException::class, function () use ($servico, $cnj) {
        $servico->criar([
            'numero_cnj' => $cnj,
            'partes'     => [['nome' => str_repeat('X', 300), 'polo' => 'ATIVO']],
        ]);
    }, 'parte inválida deve interromper a operação');

    $t->igual($repo->buscarPorCnj($cnj), null, 'nenhum registro parcial foi persistido');
});

$t->grupo('Relacionamento N:N e navegação bidirecional');

$t->teste('vincula, navega nos dois sentidos e desvincula sem excluir processos', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $servicoAdm = new ProcessoAdministrativoService();
    $servicoJud = new ProcessoJudicialService();
    $servicoVin = new VinculoService();
    $repoAdm    = new ProcessoAdministrativoRepository();
    $repoJud    = new ProcessoJudicialRepository();

    $idAdm = $servicoAdm->criar(['protocolo' => 'SEPLAG-PRO-2026/20001', 'data_abertura' => '17/09/2026']);
    $idJud = $servicoJud->criar(['numero_cnj' => '1000431-23.2026.8.11.0002']);

    $r = $servicoVin->vincular($idAdm, $idJud);
    $t->afirmar($r['criado'], 'vínculo criado');

    // Duplicidade bloqueada pelo UNIQUE.
    $r2 = $servicoVin->vincular($idAdm, $idJud);
    $t->afirmar(!$r2['criado'], 'vínculo duplicado não é criado novamente');

    // Navegação administrativo → judicial.
    $judiciais = $servicoVin->judiciaisDe($idAdm);
    $t->igual(count($judiciais), 1, 'um judicial vinculado');
    $t->igual($judiciais[0]['numero_cnj'], '1000431-23.2026.8.11.0002', 'CNJ acessível pela ficha administrativa');

    // Navegação judicial → administrativo.
    $administrativos = $servicoVin->administrativosDe($idJud);
    $t->igual(count($administrativos), 1, 'um administrativo vinculado');
    $t->igual($administrativos[0]['protocolo'], 'SEPLAG-PRO-2026/20001', 'protocolo acessível pela ficha judicial');

    // Remoção apenas do vínculo.
    $t->afirmar($servicoVin->desvincular($idAdm, $idJud), 'vínculo removido');
    $t->igual(count($servicoVin->judiciaisDe($idAdm)), 0, 'sem vínculos após a remoção');
    $t->afirmar($repoAdm->buscarPorId($idAdm) !== null, 'processo administrativo preservado');
    $t->afirmar($repoJud->buscarPorId($idJud) !== null, 'processo judicial preservado');

    // Restabelecimento do vínculo.
    $t->afirmar($servicoVin->vincular($idAdm, $idJud)['criado'], 'vínculo restabelecido');

    // Exclusão do administrativo não remove o judicial.
    $servicoAdm->excluir($idAdm);
    $t->afirmar($repoJud->buscarPorId($idJud) !== null, 'judicial sobrevive à exclusão do administrativo');
    $t->igual(count($servicoVin->administrativosDe($idJud)), 0, 'vínculo caiu junto com o administrativo');

    $servicoJud->excluir($idJud);
});

$t->teste('cardinalidade N:N nos dois sentidos', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $servicoAdm = new ProcessoAdministrativoService();
    $servicoJud = new ProcessoJudicialService();
    $repoVin    = new VinculoRepository();

    $adm1 = $servicoAdm->criar(['protocolo' => 'SEPLAG-PRO-2026/30001']);
    $adm2 = $servicoAdm->criar(['protocolo' => 'SEPLAG-PRO-2026/30002']);
    $jud1 = $servicoJud->criar(['numero_cnj' => '1000432-08.2026.8.11.0002']);
    $jud2 = $servicoJud->criar(['numero_cnj' => '1000433-90.2026.8.11.0002']);

    // Um administrativo com vários judiciais.
    $repoVin->sincronizarPorAdministrativo($adm1, [$jud1, $jud2]);
    $t->igual(count($repoVin->judiciaisDoAdministrativo($adm1)), 2, 'um administrativo → vários judiciais');

    // Um judicial com vários administrativos.
    $repoVin->sincronizarPorJudicial($jud1, [$adm1, $adm2]);
    $t->igual(count($repoVin->administrativosDoJudicial($jud1)), 2, 'um judicial → vários administrativos');

    // Sincronização remove o que não está na lista.
    $repoVin->sincronizarPorAdministrativo($adm1, [$jud2]);
    $judiciais = $repoVin->judiciaisDoAdministrativo($adm1);
    $t->igual(count($judiciais), 1, 'sincronização removeu o vínculo ausente da lista');
    $t->igual((int)$judiciais[0]['id'], $jud2, 'permaneceu o vínculo correto');

    // Zero vínculos é estado válido.
    $repoVin->sincronizarPorAdministrativo($adm2, []);
    $t->igual(count($repoVin->judiciaisDoAdministrativo($adm2)), 0, 'nenhum judicial é estado válido');

    foreach ([$adm1, $adm2] as $id) { $servicoAdm->excluir($id); }
    foreach ([$jud1, $jud2] as $id) { $servicoJud->excluir($id); }
});

$t->grupo('Consultas: filtros, ordenação e paginação');

$t->teste('filtra, ordena e pagina os processos administrativos', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $servico = new ProcessoAdministrativoService();
    $repo    = new ProcessoAdministrativoRepository();
    $ids = [];

    for ($i = 1; $i <= 7; $i++) {
        $ids[] = $servico->criar([
            'protocolo'     => sprintf('TESTE-PRO-2026/%05d', 40000 + $i),
            'data_abertura' => sprintf('2026-09-%02d', $i),
            'objeto'        => $i % 2 === 0 ? 'Objeto par número ' . $i : 'Objeto ímpar número ' . $i,
        ]);
    }

    $p = $repo->listar(['busca' => 'TESTE-PRO-2026'], 1, 3);
    $t->igual($p->total, 7, 'total de registros filtrados');
    $t->igual(count($p->itens), 3, 'primeira página com 3 itens');
    $t->igual($p->totalPaginas, 3, 'três páginas');

    $p3 = $repo->listar(['busca' => 'TESTE-PRO-2026'], 3, 3);
    $t->igual(count($p3->itens), 1, 'última página com o resto');

    $p = $repo->listar(['busca' => 'Objeto par'], 1, 20);
    $t->igual($p->total, 3, 'filtro por objeto');

    $p = $repo->listar(['busca' => 'TESTE-PRO-2026', 'de' => '2026-09-03', 'ate' => '2026-09-05'], 1, 20);
    $t->igual($p->total, 3, 'filtro por período de abertura');

    $p = $repo->listar(['busca' => 'TESTE-PRO-2026', 'ordenar' => 'data_abertura', 'direcao' => 'ASC'], 1, 20);
    $t->igual($p->itens[0]['data_abertura'], '2026-09-01', 'ordenação ascendente');
    $p = $repo->listar(['busca' => 'TESTE-PRO-2026', 'ordenar' => 'data_abertura', 'direcao' => 'DESC'], 1, 20);
    $t->igual($p->itens[0]['data_abertura'], '2026-09-07', 'ordenação descendente');

    // Ordenação por campo não permitido cai no padrão, sem quebrar (anti SQL injection).
    $p = $repo->listar(['busca' => 'TESTE-PRO-2026', 'ordenar' => 'protocolo; DROP TABLE x'], 1, 20);
    $t->igual($p->total, 7, 'campo de ordenação inválido é ignorado com segurança');

    foreach ($ids as $id) { $servico->excluir($id); }
});

$t->teste('autocomplete de pesquisa retorna os processos esperados', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $servicoJud = new ProcessoJudicialService();
    $repoJud    = new ProcessoJudicialRepository();

    $id = $servicoJud->criar(['numero_cnj' => '1000434-75.2026.8.11.0002', 'tribunal' => 'TJMT']);
    $resultados = $repoJud->pesquisar('1000434-75.2026.8.11.0002', 10);

    $t->igual(count($resultados), 1, 'um resultado');
    $t->igual($resultados[0]['numero_cnj'], '1000434-75.2026.8.11.0002', 'CNJ encontrado');

    $servicoJud->excluir($id);
});

$t->grupo('Segurança de consultas');

$t->teste('entrada maliciosa é tratada como dado, não como SQL', function ($t) use ($exigirBanco) {
    $exigirBanco($t);

    $repo = new ProcessoAdministrativoRepository();
    $p = $repo->listar(['busca' => "'; DROP TABLE processos_administrativos; --"], 1, 10);
    $t->igual($p->total, 0, 'nenhum resultado, sem erro de SQL');

    // A tabela continua existindo.
    $t->afirmar($repo->contar() >= 0, 'tabela intacta após a tentativa de injeção');
});
