<?php
declare(strict_types=1);

/**
 * Gerador de Expedientes: catalogo de propriedades e valores entregues para
 * um Processo Administrativo e seus processos vinculados.
 *
 * @var \Tests\Runner $t
 */

use App\Core\Database;
use App\Services\ExpedienteService;
use App\Services\ProcessoAdministrativoService;
use App\Services\ProcessoExtrajudicialService;
use App\Services\ProcessoJudicialService;

$bancoPronto = $GLOBALS['gj_banco_teste_pronto'] ?? false;
$exigirBanco = static function ($t) use ($bancoPronto): void {
    if (!$bancoPronto) {
        $t->ignorar('banco de testes indisponível');
    }
};

// ---------------------------------------------------------------------
$t->grupo('Gerador de Expedientes — catálogo');

$t->teste('tokens e chaves únicos, no formato que o motor aceita, em grupos conhecidos', function ($t) {
    $catalogo = ExpedienteService::catalogo();
    $tokens = array_column($catalogo, 'token');
    $chaves = array_column($catalogo, 'chave');

    $t->igual(count(array_unique($tokens)), count($tokens), 'tokens sem repetição');
    $t->igual(count(array_unique($chaves)), count($chaves), 'chaves sem repetição');
    foreach ($catalogo as $p) {
        $t->afirmar((bool)preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $p['token']), 'token válido: ' . $p['token']);
        $t->afirmar(isset(ExpedienteService::GRUPOS[$p['grupo']]), 'grupo conhecido: ' . $p['token']);
        $t->afirmar(in_array($p['tipo'], ['texto', 'textarea', 'data'], true), 'tipo conhecido: ' . $p['token']);
        $t->afirmar($p['label'] !== '' && $p['ajuda'] !== '', 'rótulo e ajuda: ' . $p['token']);
    }
    // Reservados pelo motor (automáticas) e pelo gerador (protocolo do expediente).
    $t->afirmar(!in_array('DATA_HOJE', $tokens, true) && !in_array('ANO', $tokens, true), 'automáticas ficam com o motor');
    $t->igual($chaves[array_search('PROCESSO_SIGADOC', $tokens, true)], 'processo_administrativo', 'chave do protocolo');
    foreach (array_keys(ExpedienteService::GRUPOS) as $grupo) {
        $t->afirmar(in_array($grupo, array_column($catalogo, 'grupo'), true), 'grupo com propriedades: ' . $grupo);
    }
});

$t->teste('enumeração como se escreve num expediente', function ($t) {
    $t->igual(ExpedienteService::enumerar([]), '', 'vazia');
    $t->igual(ExpedienteService::enumerar(['A']), 'A', 'um item');
    $t->igual(ExpedienteService::enumerar(['A', 'B']), 'A e B', 'dois itens');
    $t->igual(ExpedienteService::enumerar(['A', ' B ', '', null, 'C', 'A']), 'A, B e C', 'três itens, sem vazios nem repetidos');
});

// ---------------------------------------------------------------------
$t->grupo('Gerador de Expedientes — dados do processo');

$t->teste('processo inexistente não tem pacote', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $t->igual((new ExpedienteService())->pacote(999999), null, 'null');
});

$t->teste('processo sem vínculos: atributos próprios e ausentes como string vazia', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $id = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/73001']);
    $pacote = (new ExpedienteService())->pacote($id);

    $t->igual($pacote['processo'], ['id' => $id, 'protocolo' => 'SES-PRO-2026/73001', 'resumo' => ''], 'identificação');
    $t->igual($pacote['judiciais'], [], 'sem judiciais');
    $t->igual($pacote['extrajudiciais'], [], 'sem extrajudiciais');
    $adm = $pacote['administrativo'];
    $t->igual($adm['processo_administrativo'], 'SES-PRO-2026/73001', 'protocolo');
    foreach (['pa_objeto', 'pa_procedimento', 'pa_situacao', 'pa_responsavel', 'pa_designados', 'pa_prioridades',
              'pa_produtos_saude', 'pj_todos', 'pe_todos'] as $chave) {
        $t->igual($adm[$chave], '', 'ausente fica vazio, sem travessão: ' . $chave);
    }
});

$t->teste('atributos do processo e dos judiciais e extrajudiciais vinculados', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $db = Database::conexao();
    $mp = (string)$db->query("SELECT id FROM instituicoes_externas WHERE sigla = 'MP-MT'")->fetchColumn();

    $judicial = new ProcessoJudicialService();
    $pj1 = $judicial->criar([
        'numero_cnj'           => '1002001-24.2026.8.11.0041',
        'tribunal'             => 'Tribunal de Justiça do Estado de Mato Grosso',
        'orgao_julgador'       => '1ª Vara Especializada da Fazenda Pública de Cuiabá',
        'classe_judicial'      => 'Procedimento Comum Cível',
        'assunto_principal'    => 'Fornecimento de Medicamentos',
        'assuntos_secundarios' => 'Tratamento Médico-Hospitalar',
        'data_distribuicao'    => '15/09/2026',
        'valor_causa'          => 'R$ 45.320,75',
        'situacao'             => 'Em andamento',
        'partes'               => [
            ['nome' => 'MARIA DE TAL', 'polo' => 'Polo Ativo', 'tipo' => 'Autor'],
            ['nome' => 'ESTADO DE MATO GROSSO', 'polo' => 'Polo Passivo', 'tipo' => 'Réu'],
            ['nome' => 'MUNICÍPIO DE CUIABÁ', 'polo' => 'Polo Passivo', 'tipo' => 'Réu'],
        ],
    ]);
    $pj2 = $judicial->criarMinimo('1002002-09.2026.8.11.0041');

    $pe = (new ProcessoExtrajudicialService())->criar([
        'protocolo' => 'SIMP 000900-001/2026', 'data_expediente' => '2026-09-20', 'instituicao_externa_id' => $mp,
        'orgao_novo' => '5ª Promotoria de Justiça de Cuiabá', 'assunto' => 'Fila de cirurgia eletiva',
        'descricao' => 'Requisição de informações.', 'situacao' => 'PENDENTE_RESPOSTA',
    ]);

    $pa = (new ProcessoAdministrativoService())->criar([
        'protocolo'          => 'SES-PRO-2026/73002',
        'data_abertura'      => '17/09/2026',
        'objeto'             => 'Cumprimento de decisão judicial',
        'procedimento'       => 'Fornecimento de Produto de Saúde',
        'situacao'           => 'AGUARDANDO_ANDAMENTO',
        'judiciais_ids'      => [$pj1, $pj2],
        'extrajudiciais_ids' => [$pe],
    ]);

    $pacote = (new ExpedienteService())->pacote($pa);

    $adm = $pacote['administrativo'];
    $t->igual($adm['pa_data_abertura'], '17/09/2026', 'data no padrão brasileiro');
    $t->igual($adm['pa_objeto'], 'Cumprimento de decisão judicial', 'objeto');
    $t->igual($adm['pa_procedimento'], 'Fornecimento de Produto de Saúde', 'procedimento');
    $t->igual($adm['pa_situacao'], 'Aguardando andamento', 'situação pelo rótulo, não pelo código');
    $t->igual($adm['pa_prazo'], '17/10/2026', 'prazo padrão (abertura + 30 dias)');
    $t->igual($adm['pj_todos'], '1002001-24.2026.8.11.0041 e 1002002-09.2026.8.11.0041', 'todos os judiciais');
    $t->igual($adm['pe_todos'], 'SIMP 000900-001/2026', 'todos os extrajudiciais');

    $t->igual(array_column($pacote['judiciais'], 'id'), [$pj1, $pj2], 'judiciais vinculados');
    $t->igual($pacote['judiciais'][0]['rotulo'], '1002001-24.2026.8.11.0041', 'rótulo é o número CNJ');
    $pj = $pacote['judiciais'][0]['dados'];
    $t->igual($pj['processo_judicial'], '1002001-24.2026.8.11.0041', 'número CNJ');
    $t->igual($pj['pj_orgao_julgador'], '1ª Vara Especializada da Fazenda Pública de Cuiabá', 'órgão julgador');
    $t->igual($pj['pj_assunto_principal'], 'Fornecimento de Medicamentos', 'assunto principal');
    $t->igual($pj['pj_assuntos'], 'Fornecimento de Medicamentos e Tratamento Médico-Hospitalar', 'assuntos, o principal primeiro');
    $t->igual($pj['pj_polo_ativo'], 'MARIA DE TAL', 'polo ativo');
    $t->igual($pj['pj_polo_passivo'], 'ESTADO DE MATO GROSSO e MUNICÍPIO DE CUIABÁ', 'polo passivo');
    $t->igual($pj['pj_data_distribuicao'], '15/09/2026', 'data da distribuição');
    $t->igual($pj['pj_valor_causa'], 'R$ 45.320,75', 'valor da causa');
    $minimo = $pacote['judiciais'][1]['dados'];
    $t->igual([$minimo['pj_tribunal'], $minimo['pj_valor_causa'], $minimo['pj_data_autuacao'], $minimo['pj_polo_ativo']],
        ['', '', '', ''], 'judicial só com o número: demais atributos vazios');

    $t->igual(count($pacote['extrajudiciais']), 1, 'um extrajudicial');
    $ex = $pacote['extrajudiciais'][0]['dados'];
    $t->igual($ex['processo_extrajudicial'], 'SIMP 000900-001/2026', 'protocolo do extrajudicial');
    $t->igual($ex['pe_instituicao_sigla'], 'MP-MT', 'sigla da instituição');
    $t->igual($ex['pe_orgao'], '5ª Promotoria de Justiça de Cuiabá', 'órgão externo');
    $t->igual($ex['pe_data_expediente'], '20/09/2026', 'data do expediente');
    $t->igual($ex['pe_situacao'], 'Pendente de resposta', 'situação pelo rótulo');

    // O gerador monta os valores somando as três fontes: juntas, cobrem o catálogo inteiro.
    $entregues = array_keys($adm + $pj + $ex);
    $catalogo = array_column(ExpedienteService::catalogo(), 'chave');
    sort($entregues);
    sort($catalogo);
    $t->igual($entregues, $catalogo, 'toda propriedade do catálogo tem valor entregue, e nada além dele');
    foreach ($adm + $pj + $ex as $chave => $valor) {
        $t->afirmar(is_string($valor), 'valor em texto: ' . $chave);
    }
});
