<?php
declare(strict_types=1);

/**
 * Testes do contrato de extração (revalidação no servidor).
 * @var \Tests\Runner $t
 */

use App\Services\ExtracaoService;
use App\Validators\Normalizador;

$t->grupo('Contrato de dados do SIGADOC');

$t->teste('aceita payload válido e normaliza os campos', function ($t) {
    $payload = [
        'success'     => true,
        'source'      => 'SIGADOC',
        'extractedAt' => '2026-09-20T18:00:00-04:00',
        'data'        => [
            'protocolo'    => 'seplag-pro-2026/16696',
            'dataAbertura' => '17/09/2026',
            'objeto'       => "Solicitação  de   informações\npara defesa do Estado",
        ],
        'warnings'    => ['aviso de teste'],
    ];

    $r = ExtracaoService::conferirRetornoSigadoc($payload, 'SEPLAG-PRO-2026/16696');

    $t->afirmar($r['success'], 'payload aceito');
    $t->igual($r['data']['protocolo'], 'SEPLAG-PRO-2026/16696', 'protocolo normalizado');
    $t->igual($r['data']['dataAbertura'], '2026-09-17', 'data convertida para ISO');
    $t->igual($r['data']['objeto'], 'Solicitação de informações para defesa do Estado', 'objeto com espaços colapsados');
    $t->igual($r['warnings'], ['aviso de teste'], 'warnings preservados');
});

$t->teste('recusa protocolo divergente', function ($t) {
    $payload = [
        'success' => true,
        'data'    => ['protocolo' => 'SEPLAG-PRO-2026/99999', 'dataAbertura' => '01/01/2026', 'objeto' => 'Outro'],
    ];
    $r = ExtracaoService::conferirRetornoSigadoc($payload, 'SEPLAG-PRO-2026/16696');

    $t->afirmar(!$r['success'], 'recusado');
    $t->igual($r['error']['code'], 'IDENTIFIER_MISMATCH', 'código do erro');
    $t->igual($r['error']['details']['encontrado'], 'SEPLAG-PRO-2026/99999', 'divergência reportada');
    $t->afirmar(!isset($r['data']), 'nenhum dado é devolvido');
});

$t->teste('não fabrica campos ausentes', function ($t) {
    $payload = ['success' => true, 'data' => ['protocolo' => 'SEPLAG-PRO-2026/16696']];
    $r = ExtracaoService::conferirRetornoSigadoc($payload, 'SEPLAG-PRO-2026/16696');

    $t->afirmar($r['success'], 'aceito');
    $t->igual($r['data']['dataAbertura'], null, 'data ausente permanece null');
    $t->igual($r['data']['objeto'], null, 'objeto ausente permanece null');
});

$t->teste('normaliza situação, responsável e movimentações', function ($t) {
    $payload = [
        'success' => true,
        'extractorVersion' => '1.1.0',
        'sourceUrl' => 'javascript:alert(1)',
        'data' => [
            'protocolo'   => 'SES-PRO-2026/23509',
            'situacao'    => ['codigo' => 'SOBRESTADO', 'descricao' => 'Sobrestado'],
            'responsavel' => ['nome' => '  SERVIDOR   01 ', 'matricula' => 'SES000001', 'lotacao' => 'UNIJUR', 'lotacaoNome' => null],
            'eventos'     => [
                ['data' => '2026-09-18', 'tipo' => 'RECEBIMENTO', 'tipoDescricao' => 'Recebimento', 'atendenteLotacao' => 'SESUNIJUR'],
                ['data' => '31/02/2026', 'tipo' => 'HACK', 'tipoDescricao' => 'Evento X', 'descricao' => 'texto'],
                ['tipo' => '', 'descricao' => '  '],
                'lixo',
            ],
            'historicoCompleto' => true,
        ],
    ];
    $r = ExtracaoService::conferirRetornoSigadoc($payload, 'SES-PRO-2026/23509');

    $t->afirmar($r['success'], 'aceito');
    $t->igual($r['data']['situacao'], 'SOBRESTADO', 'código da situação');
    $t->igual($r['data']['situacaoDescricao'], 'Sobrestado', 'rótulo oficial');
    $t->igual($r['data']['responsavel']['nome'], 'SERVIDOR 01', 'nome normalizado');
    $t->igual(count($r['data']['eventos']), 2, 'itens vazios e inválidos descartados');
    $t->igual($r['data']['eventos'][0]['atendenteLotacao'], 'SESUNIJUR', 'chaves camelCase preservadas');
    $t->igual($r['data']['eventos'][1]['tipo'], 'OUTRO', 'tipo desconhecido vira OUTRO');
    $t->igual($r['data']['eventos'][1]['data'], null, 'data impossível vira null');
    $t->afirmar($r['data']['historicoCompleto'], 'histórico completo sinalizado');
    $t->igual($r['sourceUrl'], null, 'URL de origem fora da allowlist é descartada');
    $t->igual($r['extractorVersion'], '1.1.0', 'versão do extrator');
});

$t->teste('situação pelo rótulo do SIGADOC', function ($t) {
    $t->igual(Normalizador::situacaoAdministrativa('Arquivo Corrente'), 'ARQUIVADO', 'arquivo corrente');
    $t->igual(Normalizador::situacaoAdministrativa('Aguardando Andamento'), 'AGUARDANDO_ANDAMENTO', 'aguardando andamento');
    $t->igual(Normalizador::situacaoAdministrativa('sobrestado'), 'SOBRESTADO', 'código em minúsculas');
    $t->igual(Normalizador::situacaoAdministrativa('Caixa de Entrada (Digital)'), 'EM_TRANSITO', 'em trânsito');
    $t->igual(Normalizador::situacaoAdministrativa(null), null, 'ausente');
});

$t->teste('propaga a falha reportada pela extensão', function ($t) {
    $payload = ['success' => false, 'source' => 'SIGADOC',
                'error' => ['code' => 'SESSION_REQUIRED', 'message' => 'Não autenticado.']];
    $r = ExtracaoService::conferirRetornoSigadoc($payload, 'SEPLAG-PRO-2026/16696');

    $t->afirmar(!$r['success'], 'falha propagada');
    $t->igual($r['error']['code'], 'SESSION_REQUIRED', 'código preservado');
});

$t->grupo('Contrato de dados do PJe');

$t->teste('aceita payload válido, normaliza escalares e coleções', function ($t) {
    $payload = [
        'success'     => true,
        'source'      => 'PJE',
        'extractedAt' => '2026-09-20T18:00:00-04:00',
        'data'        => [
            'numeroCNJ'           => '10086951720268110006',
            'tribunal'            => ' Tribunal de Justiça  do Estado de Mato Grosso ',
            'classeJudicial'      => 'Procedimento Comum Cível',
            'dataDistribuicao'    => '15/09/2026',
            'valorCausa'          => 'R$ 45.320,75',
            'situacao'            => 'Em andamento',
            'segredoJustica'      => 'Não',
            'assuntosSecundarios' => ['Servidor Público Civil', '  ', 'Adicional de Insalubridade'],
            'partes'              => [
                ['nome' => 'FULANO DE TAL', 'documento' => '000.000.000-00', 'polo' => 'Polo Ativo', 'tipo' => 'Autor'],
                ['nome' => '', 'polo' => 'Polo Passivo'],
                ['nome' => 'ESTADO DE MATO GROSSO', 'polo' => 'Polo Passivo', 'tipo' => 'Réu'],
            ],
        ],
    ];

    $r = ExtracaoService::conferirRetornoPje($payload, '1008695-17.2026.8.11.0006');

    $t->afirmar($r['success'], 'payload aceito');
    $t->igual($r['data']['numeroCNJ'], '1008695-17.2026.8.11.0006', 'CNJ normalizado');
    $t->igual($r['data']['tribunal'], 'Tribunal de Justiça do Estado de Mato Grosso', 'texto normalizado');
    $t->igual($r['data']['dataDistribuicao'], '2026-09-15', 'data em ISO');
    $t->igual($r['data']['valorCausa'], 45320.75, 'valor monetário convertido');
    $t->igual($r['data']['segredoJustica'], false, 'booleano convertido');
    $t->igual(count($r['data']['assuntosSecundarios']), 2, 'assunto em branco descartado');
    $t->igual(count($r['data']['partes']), 2, 'parte sem nome descartada');
    $t->igual($r['data']['partes'][0]['polo'], 'ATIVO', 'polo normalizado');
    $t->igual($r['data']['partes'][1]['polo'], 'PASSIVO', 'polo normalizado');
});

$t->teste('normaliza os campos detalhados do PJe', function ($t) {
    $payload = [
        'success'          => true,
        'source'           => 'PJE',
        'extractorVersion' => '1.2.0',
        'data'             => [
            'numeroCNJ'              => '1008695-17.2026.8.11.0006',
            'cargoJudicial'          => 'Juiz Titular',
            'competencia'            => 'Direito à saúde pública - apenas contra Municípios',
            'classeJudicial'         => 'CUMPRIMENTO DE SENTENÇA',
            'classeCodigo'           => '156',
            'assuntoPrincipal'       => 'Fornecimento de medicamentos',
            'assuntoPrincipalCodigo' => '12484',
            'assuntosSecundarios'    => [['descricao' => 'Tratamento médico-hospitalar', 'codigo' => '12490']],
            // O PJe escreve as datas por extenso.
            'dataAutuacao'           => '14 dez 2023',
            'dataDistribuicao'       => '01 set 2026',
            'segredoJustica'         => 'NÃO',
            'justicaGratuita'        => 'NÃO',
            'tutelaLiminar'          => 'SIM',
            'prioridade'             => 'NÃO',
        ],
    ];

    $r = ExtracaoService::conferirRetornoPje($payload, '1008695-17.2026.8.11.0006');

    $t->afirmar($r['success'], 'payload aceito');
    $t->igual($r['data']['cargoJudicial'], 'Juiz Titular', 'cargo judicial');
    $t->igual($r['data']['classeCodigo'], '156', 'código da classe');
    $t->igual($r['data']['assuntoPrincipalCodigo'], '12484', 'código do assunto principal');
    $t->igual($r['data']['assuntosSecundarios'][0]['codigo'], '12490', 'código do assunto secundário');
    $t->igual($r['data']['dataAutuacao'], '2023-12-14', 'autuação por extenso convertida para ISO');
    $t->igual($r['data']['dataDistribuicao'], '2026-09-01', 'distribuição por extenso convertida para ISO');
    $t->igual($r['data']['tutelaLiminar'], true, 'SIM → true');
    $t->igual($r['data']['prioridade'], false, 'NÃO → false');
    $t->igual($r['data']['justicaGratuita'], false, 'acento não impede a conversão');
    $t->igual($r['extractorVersion'], '1.2.0', 'versão do extrator preservada');
});

$t->teste('sinalizador ausente permanece null, e não "não"', function ($t) {
    $payload = ['success' => true, 'data' => ['numeroCNJ' => '1008695-17.2026.8.11.0006']];
    $r = ExtracaoService::conferirRetornoPje($payload, '1008695-17.2026.8.11.0006');

    $t->afirmar($r['success'], 'aceito');
    $t->igual($r['data']['tutelaLiminar'], null, 'tutela não informada permanece null');
    $t->igual($r['data']['prioridade'], null, 'prioridade não informada permanece null');
    $t->igual($r['data']['movimentacoes'], [], 'cronologia ausente vira coleção vazia');
    $t->igual($r['data']['historicoCompleto'], false, 'histórico não é declarado completo sem prova');
});

$t->teste('normaliza a cronologia e descarta itens vazios', function ($t) {
    $payload = ['success' => true, 'data' => [
        'numeroCNJ'     => '1008695-17.2026.8.11.0006',
        'movimentacoes' => [
            ['data' => '2026-09-18', 'hora' => '15:14', 'tipo' => 'MOVIMENTO',
             'descricao' => 'Expedida/certificada a intimação eletrônica', 'natureza' => 'Movimento processual lançado'],
            ['data' => '2026-09-18', 'hora' => '15:00', 'tipo' => 'DOCUMENTO',
             'descricao' => null, 'documentoId' => '249562011', 'documentoTipo' => 'Sentença'],
            // Item sem descrição e sem documento é ruído de layout.
            ['data' => '2026-09-01', 'hora' => '12:43'],
            // Sem tipo declarado, a presença do documento é que classifica.
            ['data' => '2026-09-01', 'documentoId' => '247328318', 'documentoTipo' => 'Certidão'],
        ],
        'historicoCompleto' => true,
    ]];

    $r = ExtracaoService::conferirRetornoPje($payload, '1008695-17.2026.8.11.0006');
    $movs = $r['data']['movimentacoes'];

    $t->igual(count($movs), 3, 'item sem descrição e sem documento descartado');
    $t->igual($movs[0]['hora'], '15:14:00', 'hora normalizada para TIME');
    $t->igual($movs[0]['tipo'], 'MOVIMENTO', 'movimento sem documento');
    $t->igual($movs[1]['documentoId'], '249562011', 'id do documento');
    $t->igual($movs[1]['documentoTipo'], 'Sentença', 'espécie do documento');
    $t->igual($movs[2]['tipo'], 'DOCUMENTO', 'tipo deduzido da presença do documento');
    $t->igual($r['data']['historicoCompleto'], true, 'histórico completo preservado');
});

$t->teste('aceita cabeçalho, prioridade com motivo, origem e N documentos por evento', function ($t) {
    $payload = ['success' => true, 'data' => [
        'numeroCNJ'           => '1008695-17.2026.8.11.0006',
        'classeAbreviada'     => 'ProceComCiv',
        'resumoPartes'        => '  FULANO DE TAL e outros   X MUNICÍPIO DE EXEMPLO ',
        'prioridade'          => null,
        'prioridadeDescricao' => 'Idoso',
        'movimentacoes'       => [
            ['data' => '2026-01-20', 'hora' => '14:30', 'tipo' => 'DOCUMENTO', 'origem' => 'POLO_ATIVO',
             'descricao' => 'Juntada de Petição', 'documentoId' => '300000002', 'documentoTipo' => 'Petição',
             'documentos' => [['id' => '300000002', 'tipo' => 'Petição'], ['id' => '300000001', 'tipo' => 'Anexo'],
                              ['id' => 'x<script>', 'tipo' => 'lixo']]],
            ['data' => '2026-01-06', 'tipo' => 'MOVIMENTO', 'origem' => 'INVENTADA', 'descricao' => 'Ciência'],
        ],
        'documentos'          => [
            ['id' => '300000003', 'tipo' => 'Decisão', 'classificacao' => 'DECISAO', 'data' => '2026-01-20',
             'juntadoPor' => 'MAGISTRADO', 'dataJuntada' => '2026-01-20 16:40:00',
             'integra' => "Vistos.\r\n\r\n\r\n\r\nDecido.\u{0007}", 'metodoExtracao' => 'IFRAME_HTML', 'ordemMovimentacao' => 0],
            ['id' => '300000002', 'tipo' => 'Sentença', 'classificacao' => 'INVENTADA'],
            ['id' => 'sem-numero', 'tipo' => 'Certidão'],
            ['id' => '300000003', 'tipo' => 'duplicado'],
        ],
        'integraSolicitada'   => true,
    ]];

    $r = ExtracaoService::conferirRetornoPje($payload, '1008695-17.2026.8.11.0006');
    $d = $r['data'];

    $t->afirmar($r['success'], 'aceito');
    $t->igual($d['classeAbreviada'], 'ProceComCiv', 'sigla da classe');
    $t->igual($d['resumoPartes'], 'FULANO DE TAL e outros X MUNICÍPIO DE EXEMPLO', 'resumo das partes normalizado');
    $t->igual($d['prioridade'], true, 'motivo da prioridade implica SIM');
    $t->igual($d['prioridadeDescricao'], 'Idoso', 'motivo preservado');

    $t->igual($d['movimentacoes'][0]['origem'], 'POLO_ATIVO', 'origem conhecida preservada');
    $t->igual($d['movimentacoes'][1]['origem'], null, 'origem desconhecida descartada');
    $t->igual(count($d['movimentacoes'][0]['documentos']), 2, 'documento com número inválido descartado do evento');

    $t->igual(count($d['documentos']), 2, 'documento sem número e duplicado descartados');
    $t->igual($d['documentos'][0]['integra'], "Vistos.\n\nDecido.", 'íntegra mantém parágrafos, sem controles');
    $t->igual($d['documentos'][0]['dataJuntada'], '2026-01-20 16:40:00', 'data da juntada');
    $t->igual($d['documentos'][0]['ordemMovimentacao'], 0, 'ligação com o evento');
    $t->igual($d['documentos'][1]['classificacao'], 'SENTENCA', 'classificação inválida é refeita pelo tipo');
    $t->igual($d['integraSolicitada'], true, 'íntegra solicitada');
});

$t->teste('classifica peças decisórias como a extensão', function ($t) {
    $t->igual(Normalizador::classificarDocumento('249562011 - Sentença'), 'SENTENCA', 'sentença');
    $t->igual(Normalizador::classificarDocumento('DECISÃO INTERLOCUTÓRIA'), 'DECISAO', 'decisão em caixa alta');
    $t->igual(Normalizador::classificarDocumento('Acórdão'), 'ACORDAO', 'acórdão');
    $t->igual(Normalizador::classificarDocumento('Embargos de Declaração'), 'EMBARGOS', 'embargos antes de decisão');
    $t->igual(Normalizador::classificarDocumento('Petição inicial'), null, 'petição não é peça decisória');
});

$t->teste('recusa CNJ divergente sem preencher nada', function ($t) {
    $payload = ['success' => true, 'data' => [
        'numeroCNJ' => '1000000-89.2026.8.11.0001',
        'tribunal'  => 'Outro tribunal',
    ]];
    $r = ExtracaoService::conferirRetornoPje($payload, '1008695-17.2026.8.11.0006');

    $t->afirmar(!$r['success'], 'recusado');
    $t->igual($r['error']['code'], 'IDENTIFIER_MISMATCH', 'código do erro');
    $t->afirmar(!isset($r['data']), 'nenhum campo é devolvido');
});

$t->teste('escalares ausentes viram null e coleções viram array vazio', function ($t) {
    $payload = ['success' => true, 'data' => ['numeroCNJ' => '1008695-17.2026.8.11.0006']];
    $r = ExtracaoService::conferirRetornoPje($payload, '1008695-17.2026.8.11.0006');

    $t->afirmar($r['success'], 'aceito');
    foreach (['tribunal', 'orgaoJulgador', 'jurisdicao', 'classeJudicial',
              'assuntoPrincipal', 'dataDistribuicao', 'valorCausa', 'situacao', 'segredoJustica'] as $campo) {
        $t->igual($r['data'][$campo], null, "escalar ausente {$campo} é null");
    }
    $t->afirmar(!array_key_exists('vara', $r['data']) && !array_key_exists('comarca', $r['data']), 'vara e comarca fora do contrato');
    $t->igual($r['data']['partes'], [], 'partes como coleção vazia');
    $t->igual($r['data']['assuntosSecundarios'], [], 'assuntos como coleção vazia');
});

$t->grupo('Autorização do pedido de extração');

$t->teste('rejeita protocolo mal formado', function ($t) {
    $r = ExtracaoService::autorizarSigadoc('12345');
    $t->afirmar(!$r['success'], 'rejeitado');
    $t->igual($r['error']['code'], 'INVALID_PROTOCOL', 'código do erro');
});

$t->teste('rejeita CNJ com dígito verificador inválido', function ($t) {
    $r = ExtracaoService::autorizarPje('1008695-18.2026.8.11.0006', 'https://pje.exemplo.jus.br/p/1');
    $t->afirmar(!$r['success'], 'rejeitado');
    $t->igual($r['error']['code'], 'INVALID_CNJ_DV', 'código do erro');
});

$t->teste('bloqueia a extração quando a allowlist está vazia', function ($t) {
    // A allowlist do ambiente de teste é vazia por padrão.
    $r = ExtracaoService::autorizarSigadoc('SEPLAG-PRO-2026/16696');
    if ($r['success']) {
        $t->ignorar('allowlist já configurada neste ambiente');
    }
    $t->igual($r['error']['code'], 'ALLOWLIST_EMPTY', 'código do erro');
});
