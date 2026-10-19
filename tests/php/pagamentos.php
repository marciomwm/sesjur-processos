<?php
declare(strict_types=1);

/**
 * Testes de Entregadores (CPF/CNPJ, inclusive o CNPJ alfanumerico) e dos
 * Dados do Pagamento do processo com procedimento Pagamento Indenizatorio.
 *
 * @var \Tests\Runner $t
 */

use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EntregadorRepository;
use App\Repositories\PagamentoIndenizatorioRepository;
use App\Services\EntregadorService;
use App\Services\PagamentoIndenizatorioService;
use App\Services\ProcedimentoService;
use App\Services\ProcessoAdministrativoService;
use App\Services\UsuarioService;
use App\Validators\CnpjValidator;

$bancoPronto = $GLOBALS['gj_banco_teste_pronto'] ?? false;
$exigirBanco = static function ($t) use ($bancoPronto): void {
    if (!$bancoPronto) {
        $t->ignorar('banco de testes indisponível');
    }
};

$entrarComo = static function (?int $id): void {
    if ($id === null) {
        unset($_SESSION['auth']);
    } else {
        $_SESSION['auth'] = ['usuario_id' => $id, 'ultimo_acesso' => time(), 'exigir_troca' => false];
    }
    Auth::esquecer();
};

$novoUsuario = static function (string $perfil) use ($entrarComo): int {
    static $seq = 0;
    $seq++;
    $entrarComo(null);
    $db = Database::conexao();
    return (new UsuarioService())->criar([
        'nome'            => "Pessoa Pagamento {$seq}",
        'email'           => "pagamento{$seq}@ses.mt.gov.br",
        'matricula'       => 'PG-' . $seq,
        'cargo_id'        => (string)$db->query("SELECT id FROM cargos WHERE nome = 'Analista'")->fetchColumn(),
        'lotacao_id'      => (string)$db->query("SELECT id FROM lotacoes WHERE nome = 'Unidade Jurídica'")->fetchColumn(),
        'perfil'          => $perfil,
        'data_nascimento' => '',
    ]);
};

/** Unidade administrativa (da SES-MT ou sem instituicao). */
$novaUnidade = static function (string $nome, bool $daSes = true, bool $ativa = true): int {
    $db = Database::conexao();
    $db->prepare(
        "INSERT INTO lotacoes (nome, instituicao_id, ativo)
         VALUES (:nome, IF(:ses = 1, (SELECT id FROM instituicoes WHERE sigla = 'SES-MT'), NULL), :ativo)"
    )->execute([':nome' => $nome, ':ses' => $daSes ? 1 : 0, ':ativo' => $ativa ? 1 : 0]);
    return (int)$db->lastInsertId();
};

$renderizar = static function (string $pagina, array $get): string {
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    try {
        require PROJECT_ROOT . '/public/' . $pagina;
    } finally {
        $html = (string)ob_get_clean();
    }
    return $html;
};

$t->grupo('Entregadores — validação do CNPJ');

$t->teste('CNPJ numérico e alfanumérico: dígitos verificadores e máscara', function ($t) {
    $t->afirmar(CnpjValidator::valido('11.222.333/0001-81'), 'numérico válido');
    $t->afirmar(!CnpjValidator::valido('11.222.333/0001-82'), 'dígito adulterado');
    $t->afirmar(CnpjValidator::valido('12.ABC.345/01DE-35'), 'alfanumérico (exemplo da Receita Federal)');
    $t->afirmar(CnpjValidator::valido('12abc34501de35'), 'minúsculas são aceitas');
    $t->afirmar(!CnpjValidator::valido('12.ABC.345/01DE-36'), 'alfanumérico com dígito adulterado');
    $t->afirmar(!CnpjValidator::valido('12.ABC.345/01DE-3A'), 'dígito verificador com letra');
    $t->afirmar(!CnpjValidator::valido('00.000.000/0000-00'), 'sequência repetida');
    $t->afirmar(!CnpjValidator::valido('1122233300018'), '13 caracteres');
    $t->igual(CnpjValidator::formatar('11222333000181'), '11.222.333/0001-81', 'máscara');
    $t->igual(CnpjValidator::formatar('12ABC34501DE35'), '12.ABC.345/01DE-35', 'máscara do alfanumérico');
});

$t->grupo('Entregadores — cadastro');

$t->teste('CPF ou CNPJ definem o tipo de pessoa; documento único e gravado sem máscara', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::USUARIO));
    $servico = new EntregadorService();
    $repo = new EntregadorRepository();

    $pf = $servico->criar(['documento' => '529.982.247-25', 'nome' => '  Maria   da Silva ']);
    $pj = $servico->criar(['documento' => '11.222.333/0001-81', 'nome' => 'Clínica Exemplo Ltda']);
    $alfa = $servico->criar(['documento' => '12.abc.345/01de-35', 'nome' => 'Fornecedora Alfa S.A.']);

    $t->igual($repo->buscarPorId($pf)['tipo_pessoa'], 'FISICA', 'CPF = pessoa física');
    $t->igual($repo->buscarPorId($pf)['documento'], '52998224725', 'CPF sem máscara');
    $t->igual($repo->buscarPorId($pf)['nome'], 'Maria da Silva', 'espaços normalizados');
    $t->igual($repo->buscarPorId($pj)['tipo_pessoa'], 'JURIDICA', 'CNPJ = pessoa jurídica');
    $t->igual($repo->buscarPorId($alfa)['documento'], '12ABC34501DE35', 'CNPJ alfanumérico em maiúsculas');
    $t->igual(EntregadorService::formatarDocumento('52998224725'), '529.982.247-25', 'exibição do CPF');

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['documento' => '52998224725', 'nome' => 'Outra']), 'CPF repetido sem máscara');
    $t->igual($e->erros()['documento'], EntregadorService::MSG_DOCUMENTO_DUPLICADO, 'mensagem de duplicidade');
    $t->lanca(ValidationException::class, fn() => $servico->criar(['documento' => '12ABC34501DE35', 'nome' => 'Outra']), 'CNPJ repetido');

    foreach (['', '123.456.789-00', '11.222.333/0001-82', '1234567', 'ABCDEFGHIJK'] as $invalido) {
        $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['documento' => $invalido, 'nome' => 'Teste']), 'rejeita ' . $invalido);
        $t->afirmar(isset($e->erros()['documento']), 'erro no documento: ' . $invalido);
    }
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['documento' => '39053344705', 'nome' => ' ']), 'nome obrigatório');
    $t->afirmar(isset($e->erros()['nome']), 'erro no nome');
});

$t->teste('edição valida o documento, recalcula o tipo e fica auditada', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::USUARIO));
    $servico = new EntregadorService();
    $repo = new EntregadorRepository();
    $id = $servico->criar(['documento' => '390.533.447-05', 'nome' => 'João Prestador']);

    $t->lanca(ValidationException::class, fn() => $servico->atualizar($id, ['documento' => '11.222.333/0001-81', 'nome' => 'João']), 'documento de outro entregador');
    $servico->atualizar($id, ['documento' => '11.444.777/0001-61', 'nome' => 'João Prestador ME']);
    $t->igual($repo->buscarPorId($id)['tipo_pessoa'], 'JURIDICA', 'tipo recalculado');
    $servico->atualizar($id, ['documento' => '11444777000161', 'nome' => 'João Prestador ME']);

    $acoes = array_column((new AuditoriaRepository())->listarPorEntidade('entregadores', $id), 'acao');
    $t->igual($acoes, ['ENTREGADOR_EDITADO', 'ENTREGADOR_CRIADO'], 'sem alteração, sem novo evento');
});

$t->grupo('Dados do Pagamento — Pagamento Indenizatório');

$t->teste('procedimento reconhecido pelo nome, sem caixa, acentos ou espaços extras', function ($t) {
    $t->afirmar(PagamentoIndenizatorioService::ehProcedimento('Pagamento Indenizatório'), 'nome exato');
    $t->afirmar(PagamentoIndenizatorioService::ehProcedimento('  PAGAMENTO   INDENIZATORIO '), 'caixa, acentos e espaços');
    $t->afirmar(!PagamentoIndenizatorioService::ehProcedimento('Pagamento Indenizatório Parcial'), 'outro procedimento');
    $t->afirmar(!PagamentoIndenizatorioService::ehProcedimento(null), 'sem procedimento');
    $t->igual(PagamentoIndenizatorioService::competencia('2026-01-15'), '2026-01', 'competência da data ISO');
    $t->igual(PagamentoIndenizatorioService::competencia('15/01/2026'), '2026-01', 'competência da data brasileira');
    $t->igual(PagamentoIndenizatorioService::competencia('31/02/2026'), null, 'data impossível');
});

$t->teste('registra e altera: competência automática, valor em reais e auditoria no processo', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novaUnidade) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::ADMINISTRADOR));
    $procedimentos = new ProcedimentoService();
    $pi = $procedimentos->criar(['nome' => 'Pagamento Indenizatório']);
    $outro = $procedimentos->criar(['nome' => 'Consulta Jurídica (pagamento)']);

    $entrarComo($novoUsuario(Perfil::USUARIO));
    $processos = new ProcessoAdministrativoService();
    $pa = $processos->criar(['protocolo' => 'SES-PRO-2026/91001', 'procedimento_id' => (string)$pi]);
    $semPi = $processos->criar(['protocolo' => 'SES-PRO-2026/91002', 'procedimento_id' => (string)$outro]);

    $unidade = $novaUnidade('Hospital Regional (teste)');
    $outraUnidade = $novaUnidade('Coordenadoria de Compras (teste)');
    $credor = (new EntregadorService())->criar(['documento' => '45.723.174/0001-10', 'nome' => 'Distribuidora Teste Ltda']);
    $servico = new PagamentoIndenizatorioService();
    $repo = new PagamentoIndenizatorioRepository();

    $entrada = [
        'demandante_lotacao_id' => (string)$unidade,
        'entregador_id'         => (string)$credor,
        'objeto'                => "Fornecimento de 200 frascos de soro fisiológico",
        'data_execucao'         => '2026-01-15',
        'valor'                 => '1.234,56',
    ];
    $e = $t->lanca(ValidationException::class, fn() => $servico->salvar($semPi, $entrada), 'outro procedimento não aceita');
    $t->contem($e->getMessage(), 'Pagamento Indenizatório', 'mensagem cita o procedimento');

    $t->afirmar($servico->salvar($pa, $entrada), 'criado');
    $gravado = $repo->doProcesso($pa);
    $t->igual($gravado['competencia'], '2026-01', 'competência = ano-mês da execução');
    $t->igual($gravado['valor'], '1234.56', 'valor exato em DECIMAL');
    $t->igual($gravado['demandante_nome'], 'Hospital Regional (teste)', 'demandante');
    $t->igual($gravado['credor_nome'], 'Distribuidora Teste Ltda', 'credor');

    $t->afirmar(!$servico->salvar($pa, ['data_execucao' => '03/02/2026', 'valor' => 'R$ 1.500', 'demandante_lotacao_id' => (string)$outraUnidade] + $entrada), 'alterado');
    $gravado = $repo->doProcesso($pa);
    $t->igual($gravado['competencia'], '2026-02', 'competência acompanha a nova data');
    $t->igual($gravado['valor'], '1500.00', 'novo valor');

    $eventos = (new AuditoriaRepository())->listarPorEntidade('processos_administrativos', $pa);
    $t->igual(array_column($eventos, 'acao'), ['PAGAMENTO_EDITADO', 'PAGAMENTO_REGISTRADO'], 'registro e alteração no histórico do processo');
    $depois = json_decode((string)$eventos[0]['dados_novos'], true);
    $campos = array_keys($depois);
    sort($campos); // a coluna JSON não preserva a ordem das chaves
    $t->igual($campos, ['competencia', 'data_execucao', 'demandante', 'valor'], 'só os campos alterados, com nomes legíveis');
    $t->igual($depois['demandante'], 'Coordenadoria de Compras (teste)', 'nome da unidade, não o id');

    $servico->salvar($pa, ['data_execucao' => '2026-02-03', 'valor' => '1500,00', 'demandante_lotacao_id' => (string)$outraUnidade] + $entrada);
    $t->igual(count((new AuditoriaRepository())->listarPorEntidade('processos_administrativos', $pa)), 2, 'sem alteração, sem novo evento');

    $t->lanca(\PDOException::class, fn() => Database::conexao()->exec(
        "UPDATE processo_administrativo_pagamentos SET competencia = '2025-12' WHERE processo_administrativo_id = {$pa}"
    ), 'CHECK impede competência divergente da data');
    $t->lanca(\PDOException::class, fn() => Database::conexao()->exec('DELETE FROM entregadores WHERE id = ' . $credor), 'credor em uso não é excluído');

    Database::conexao()->exec('UPDATE procedimentos SET ativo = 0');
});

$t->teste('validação: campos obrigatórios, unidade da SES-MT ativa, data passada e valor positivo', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novaUnidade) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::ADMINISTRADOR));
    $pi = (int)Database::conexao()->query("SELECT id FROM procedimentos WHERE nome = 'Pagamento Indenizatório'")->fetchColumn();
    (new ProcedimentoService())->alterarStatus($pi, true);
    $pa = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/91101', 'procedimento_id' => (string)$pi]);
    $servico = new PagamentoIndenizatorioService();

    $e = $t->lanca(ValidationException::class, fn() => $servico->salvar($pa, []), 'tudo vazio');
    $t->igual(array_keys($e->erros()), ['demandante_lotacao_id', 'entregador_id', 'objeto', 'data_execucao', 'valor'], 'cinco campos obrigatórios');

    $ses = $novaUnidade('Farmácia de Alto Custo (teste)');
    $credor = (new EntregadorService())->criar(['documento' => '86.142.871/0001-65', 'nome' => 'Prestadora Validação']);
    $base = ['demandante_lotacao_id' => (string)$ses, 'entregador_id' => (string)$credor, 'objeto' => 'Serviço', 'data_execucao' => '2026-03-10', 'valor' => '10,00'];

    $casos = [
        'demandante_lotacao_id' => [(string)$novaUnidade('Unidade Fora da SES (teste)', false), (string)$novaUnidade('Unidade Inativa SES (teste)', true, false), '999999', 'abc'],
        'entregador_id'         => ['999999', '0'],
        'data_execucao'         => ['31/02/2026', date('Y-m-d', strtotime('+1 day'))],
        'valor'                 => ['0,00', '-5', 'dez reais', '1.23.4'],
    ];
    foreach ($casos as $campo => $valores) {
        foreach ($valores as $valor) {
            $e = $t->lanca(ValidationException::class, fn() => $servico->salvar($pa, [$campo => $valor] + $base), "{$campo} = {$valor}");
            $t->igual(array_keys($e->erros()), [$campo], "erro só em {$campo} ({$valor})");
        }
    }
    $t->igual((new PagamentoIndenizatorioRepository())->doProcesso($pa), null, 'nada gravado');

    // Unidade gravada e inativada depois continua aceita no mesmo registro.
    $servico->salvar($pa, $base);
    Database::conexao()->exec('UPDATE lotacoes SET ativo = 0 WHERE id = ' . $ses);
    $servico->salvar($pa, ['objeto' => 'Serviço prestado'] + $base);
    $t->igual((new PagamentoIndenizatorioRepository())->doProcesso($pa)['objeto'], 'Serviço prestado', 'unidade inativada mantida');
    Database::conexao()->exec('UPDATE procedimentos SET ativo = 0');
});

$t->grupo('Dados do Pagamento — páginas');

$t->teste('guia aparece só no Pagamento Indenizatório (ou com dados já gravados); Entregadores no menu', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $renderizar) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::ADMINISTRADOR));
    $db = Database::conexao();
    $pi = (int)$db->query("SELECT id FROM procedimentos WHERE nome = 'Pagamento Indenizatório'")->fetchColumn();
    $outro = (int)$db->query("SELECT id FROM procedimentos WHERE nome = 'Consulta Jurídica (pagamento)'")->fetchColumn();
    $db->exec("UPDATE procedimentos SET ativo = 1 WHERE id IN ({$pi}, {$outro})");
    $processos = new ProcessoAdministrativoService();
    $comPi = $processos->criar(['protocolo' => 'SES-PRO-2026/91201', 'procedimento_id' => (string)$pi]);
    $semPi = $processos->criar(['protocolo' => 'SES-PRO-2026/91202', 'procedimento_id' => (string)$outro]);

    $html = $renderizar('processo-administrativo.php', ['id' => (string)$comPi]);
    $t->contem($html, 'data-aba="dados-pagamento"', 'guia presente');
    $t->contem($html, 'name="demandante_lotacao_id"', 'campo Demandante');
    $t->contem($html, 'name="entregador_id"', 'campo Credor');
    $t->contem($html, 'data-pagamento-competencia', 'Competência automática');
    $t->contem($html, 'entregadores.php?processo_id=' . $comPi, 'atalho para cadastrar entregador');

    $html = $renderizar('processo-administrativo.php', ['id' => (string)$semPi]);
    $t->afirmar(!str_contains($html, 'data-aba="dados-pagamento"'), 'sem a guia em outro procedimento');

    // Dados gravados e procedimento trocado depois: exibidos sem edição.
    $ses = (int)$db->query("SELECT id FROM lotacoes WHERE nome = 'Hospital Regional (teste)'")->fetchColumn();
    $credor = (int)$db->query("SELECT id FROM entregadores WHERE nome = 'Distribuidora Teste Ltda'")->fetchColumn();
    (new PagamentoIndenizatorioService())->salvar($comPi, [
        'demandante_lotacao_id' => (string)$ses, 'entregador_id' => (string)$credor,
        'objeto' => 'Objeto mantido', 'data_execucao' => '2026-04-01', 'valor' => '99,90',
    ]);
    $processos->atualizar($comPi, ['protocolo' => 'SES-PRO-2026/91201', 'procedimento_id' => (string)$outro]);
    $html = $renderizar('processo-administrativo.php', ['id' => (string)$comPi]);
    $t->contem($html, 'data-aba="dados-pagamento"', 'guia mantida com dados gravados');
    $t->contem($html, 'não podem ser alterados', 'aviso de somente leitura');
    $t->afirmar(!str_contains($html, 'salvar-dados-pagamento.php'), 'sem formulário de edição');
    $t->lanca(ValidationException::class, fn() => (new PagamentoIndenizatorioService())->salvar($comPi, ['objeto' => 'x']), 'serviço também recusa');

    $html = $renderizar('entregadores.php', []);
    $t->contem($html, 'Distribuidora Teste Ltda', 'listagem de entregadores');
    $t->contem($html, '45.723.174/0001-10', 'documento com máscara');
    $t->contem($html, 'data-mascara="cpf-cnpj"', 'campo CPF/CNPJ');
    $db->exec('UPDATE procedimentos SET ativo = 0');
});
