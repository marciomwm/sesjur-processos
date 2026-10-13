<?php
declare(strict_types=1);

/**
 * Testes do modulo de usuarios: validacao (sem banco) e integracao
 * (banco de testes isolado).
 *
 * @var \Tests\Runner $t
 */

use App\Core\Database;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\UsuarioRepository;
use App\Services\TabelaApoioService;
use App\Services\UsuarioService;
use App\Validators\UsuarioValidator;

$t->grupo('Usuários — validação');

$t->teste('nome: normaliza espaços e aplica limites', function ($t) {
    $t->igual(UsuarioValidator::nome("  Marcio   Jean\tda Silva ")[0], 'Marcio Jean da Silva', 'espaços colapsados');
    $t->igual(UsuarioValidator::nome('Marcio Jean da Silva')[1], null, 'nome válido');
    $t->afirmar(UsuarioValidator::nome('   ')[1] !== null, 'vazio rejeitado');
    $t->afirmar(UsuarioValidator::nome('Al')[1] !== null, 'menos de 3 caracteres rejeitado');
    $t->afirmar(UsuarioValidator::nome(str_repeat('a', 151))[1] !== null, 'mais de 150 caracteres rejeitado');
    $t->igual(UsuarioValidator::nome(str_repeat('á', 150))[1], null, '150 caracteres multibyte aceitos');
    $t->afirmar(UsuarioValidator::nome('12345')[1] !== null, 'nome sem letras rejeitado');
    $t->afirmar(UsuarioValidator::nome(['x'])[1] !== null, 'array rejeitado');
});

$t->teste('e-mail: minúsculas e formato', function ($t) {
    $t->igual(UsuarioValidator::email(' Usuario@SES.MT.gov.br ')[0], 'usuario@ses.mt.gov.br', 'minúsculas e sem espaços');
    $t->igual(UsuarioValidator::email('usuario@ses.mt.gov.br')[1], null, 'e-mail válido');
    foreach (['', 'usuario', 'usuario@', '@ses.mt.gov.br', 'a b@ses.mt.gov.br@x'] as $invalido) {
        $t->afirmar(UsuarioValidator::email($invalido)[1] !== null, "rejeita '{$invalido}'");
    }
});

$t->teste('matrícula: texto, maiúsculas, zeros à esquerda preservados', function ($t) {
    $t->igual(UsuarioValidator::matricula('  0012345 ')[0], '0012345', 'trim e zeros à esquerda');
    $t->igual(UsuarioValidator::matricula('ab-123/4')[0], 'AB-123/4', 'maiúsculas');
    $t->igual(UsuarioValidator::matricula('AB-123/4')[1], null, 'separadores aceitos');
    $t->afirmar(UsuarioValidator::matricula('')[1] !== null, 'vazia rejeitada');
    $t->afirmar(UsuarioValidator::matricula(str_repeat('1', 31))[1] !== null, 'mais de 30 rejeitada');
    $t->afirmar(UsuarioValidator::matricula("12'; DROP TABLE usuarios; --")[1] !== null, 'caracteres especiais rejeitados');
    $t->afirmar(UsuarioValidator::matricula('-123')[1] !== null, 'separador no início rejeitado');
});

$t->teste('data de nascimento: opcional, válida, não futura', function ($t) {
    $hoje = new DateTimeImmutable('2026-09-25');
    $t->igual(UsuarioValidator::dataNascimento('', false, $hoje), [null, null], 'vazia é aceita quando opcional');
    $t->afirmar(UsuarioValidator::dataNascimento('', true, $hoje)[1] !== null, 'vazia rejeitada quando obrigatória');
    $t->igual(UsuarioValidator::dataNascimento('20/05/1993', false, $hoje), ['1993-05-20', null], 'formato brasileiro');
    $t->igual(UsuarioValidator::dataNascimento('1993-05-20', false, $hoje), ['1993-05-20', null], 'formato ISO');
    $t->afirmar(UsuarioValidator::dataNascimento('31/02/1993', false, $hoje)[1] !== null, 'data inexistente rejeitada');
    $t->afirmar(UsuarioValidator::dataNascimento('26/09/2026', false, $hoje)[1] !== null, 'data futura rejeitada');
    $t->igual(UsuarioValidator::dataNascimento('25/09/2026', false, $hoje)[1], null, 'hoje é aceito');
    $t->afirmar(UsuarioValidator::dataNascimento('01/01/1850', false, $hoje)[1] !== null, 'anterior a 1900 rejeitada');
    $t->afirmar(UsuarioValidator::dataNascimento('abc', false, $hoje)[1] !== null, 'texto rejeitado');
});

$t->teste('ids e status', function ($t) {
    $t->igual(UsuarioValidator::id('3'), 3, 'id numérico');
    $t->igual(UsuarioValidator::id('0'), null, 'zero rejeitado');
    $t->igual(UsuarioValidator::id('3 OR 1=1'), null, 'injeção rejeitada');
    $t->igual(UsuarioValidator::id('-1'), null, 'negativo rejeitado');
    $t->igual(UsuarioValidator::ativo('1'), true, 'ativo');
    $t->igual(UsuarioValidator::ativo('0'), false, 'inativo');
    $t->igual(UsuarioValidator::ativo('sim'), null, 'valor desconhecido');
});

$t->teste('iniciais do nome', function ($t) {
    $t->igual(iniciais('Marcio Jean da Silva'), 'MS', 'primeiro e último nome');
    $t->igual(iniciais('Ana'), 'A', 'nome único');
    $t->igual(iniciais('Li Wu'), 'LW', 'nomes curtos');
});

$t->grupo('Usuários — integração');

$bancoPronto = $GLOBALS['gj_banco_teste_pronto'] ?? false;
$exigirBanco = static function ($t) use ($bancoPronto): void {
    if (!$bancoPronto) {
        $t->ignorar('banco de testes indisponível');
    }
};

/** Id de um cargo/lotacao semeado pelo database.sql. */
$idApoio = static function (string $tabela, string $nome): int {
    $stmt = Database::conexao()->prepare("SELECT id FROM {$tabela} WHERE nome = :n");
    $stmt->execute([':n' => $nome]);
    return (int)$stmt->fetchColumn();
};

$novoUsuario = static function (array $sobrescrever = []) use ($idApoio): array {
    static $seq = 0;
    $seq++;
    return $sobrescrever + [
        'nome'            => "Servidor de Teste {$seq}",
        'email'           => "servidor{$seq}@ses.mt.gov.br",
        'matricula'       => (string)(900000 + $seq),
        'cargo_id'        => (string)$idApoio('cargos', 'Assessor Jurídico'),
        'lotacao_id'      => (string)$idApoio('lotacoes', 'Unidade Jurídica'),
        'data_nascimento' => '',
    ];
};

$t->teste('cadastra normalizando e registra auditoria', function ($t) use ($exigirBanco, $novoUsuario) {
    $exigirBanco($t);
    $id = (new UsuarioService())->criar($novoUsuario([
        'nome'            => '  Marcio   Jean da Silva ',
        'email'           => 'Marcio.Silva@SES.MT.GOV.BR',
        'matricula'       => ' 0123456 ',
        'data_nascimento' => '20/05/1993',
    ]));

    $u = (new UsuarioRepository())->buscarPorId($id);
    $t->igual($u['nome'], 'Marcio Jean da Silva', 'nome normalizado');
    $t->igual($u['email'], 'marcio.silva@ses.mt.gov.br', 'e-mail em minúsculas');
    $t->igual($u['matricula'], '0123456', 'zero à esquerda preservado');
    $t->igual($u['data_nascimento'], '1993-05-20', 'data em ISO');
    $t->igual((int)$u['ativo'], 1, 'novo usuário ativo por padrão');
    $t->igual($u['cargo'], 'Assessor Jurídico', 'nome do cargo via JOIN');

    $hist = (new AuditoriaRepository())->listarPorEntidade('usuarios', $id);
    $t->igual(count($hist), 1, 'um evento');
    $t->igual($hist[0]['acao'], 'USUARIO_CRIADO', 'evento de cadastro');
    $t->contem($hist[0]['dados_novos'], 'marcio.silva@ses.mt.gov.br', 'snapshot gravado');
});

$t->teste('rejeita e-mail duplicado (sem diferenciar maiúsculas)', function ($t) use ($exigirBanco, $novoUsuario) {
    $exigirBanco($t);
    $servico = new UsuarioService();
    $servico->criar($novoUsuario(['email' => 'duplicado@ses.mt.gov.br']));
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($novoUsuario(['email' => 'DUPLICADO@ses.mt.gov.br'])), 'duplicidade de e-mail');
    $t->igual($e->erros()['email'] ?? null, UsuarioService::MSG_EMAIL_DUPLICADO, 'mensagem de e-mail duplicado');
});

$t->teste('rejeita matrícula duplicada', function ($t) use ($exigirBanco, $novoUsuario) {
    $exigirBanco($t);
    $servico = new UsuarioService();
    $servico->criar($novoUsuario(['matricula' => 'abc-77']));
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($novoUsuario(['matricula' => ' ABC-77'])), 'duplicidade de matrícula');
    $t->igual($e->erros()['matricula'] ?? null, UsuarioService::MSG_MATRICULA_DUPLICADA, 'mensagem de matrícula duplicada');
});

$t->teste('restrição UNIQUE do banco é convertida em mensagem amigável', function ($t) use ($exigirBanco, $novoUsuario, $idApoio) {
    $exigirBanco($t);
    // Simula a corrida: a checagem previa passa, mas o banco ja tem o e-mail.
    $existente = $novoUsuario(['email' => 'corrida@ses.mt.gov.br']);
    (new UsuarioService())->criar($existente);

    $repoCego = new class extends UsuarioRepository {
        public function emailEmUso(string $email, ?int $ignorarId = null): bool { return false; }
    };
    $servico = new UsuarioService($repoCego);
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($novoUsuario(['email' => 'corrida@ses.mt.gov.br'])), 'erro 1062 traduzido');
    $t->igual($e->erros()['email'] ?? null, UsuarioService::MSG_EMAIL_DUPLICADO, 'mesma mensagem da validação');
});

$t->teste('valida cargo e lotação existentes e ativos', function ($t) use ($exigirBanco, $novoUsuario, $idApoio) {
    $exigirBanco($t);
    $servico = new UsuarioService();
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($novoUsuario(['cargo_id' => '999', 'lotacao_id' => ''])), 'ids inválidos');
    $t->afirmar(isset($e->erros()['cargo_id'], $e->erros()['lotacao_id']), 'erros em cargo e lotação');

    // Cargo inativado: nao pode ser escolhido em novo cadastro,
    // mas usuario que ja o possui continua editavel.
    $idEstagiario = $idApoio('cargos', 'Estagiário');
    $id = $servico->criar($novoUsuario(['cargo_id' => (string)$idEstagiario]));
    Database::conexao()->exec("UPDATE cargos SET ativo = 0 WHERE id = {$idEstagiario}");
    try {
        $t->lanca(ValidationException::class, fn() => $servico->criar($novoUsuario(['cargo_id' => (string)$idEstagiario])), 'cargo inativo em novo cadastro');
        $u = (new UsuarioRepository())->buscarPorId($id);
        $servico->atualizar($id, [
            'nome' => 'Nome Alterado', 'email' => $u['email'], 'matricula' => $u['matricula'],
            'cargo_id' => (string)$idEstagiario, 'lotacao_id' => (string)$u['lotacao_id'], 'data_nascimento' => '',
        ]);
        $t->igual((new UsuarioRepository())->buscarPorId($id)['nome'], 'Nome Alterado', 'edição mantém cargo inativo já gravado');
    } finally {
        Database::conexao()->exec("UPDATE cargos SET ativo = 1 WHERE id = {$idEstagiario}");
    }
});

$t->teste('edição: impede conflito com outro usuário e audita só o que mudou', function ($t) use ($exigirBanco, $novoUsuario) {
    $exigirBanco($t);
    $servico = new UsuarioService();
    $repo    = new UsuarioRepository();
    $a = $servico->criar($novoUsuario(['email' => 'a.edicao@ses.mt.gov.br', 'matricula' => 'ED-1']));
    $b = $servico->criar($novoUsuario(['email' => 'b.edicao@ses.mt.gov.br', 'matricula' => 'ED-2']));

    $dadosB = $repo->buscarPorId($b);
    $base = [
        'nome' => $dadosB['nome'], 'email' => $dadosB['email'], 'matricula' => $dadosB['matricula'],
        'cargo_id' => (string)$dadosB['cargo_id'], 'lotacao_id' => (string)$dadosB['lotacao_id'], 'data_nascimento' => '',
    ];

    $e = $t->lanca(ValidationException::class, fn() => $servico->atualizar($b, ['email' => 'A.EDICAO@ses.mt.gov.br'] + $base), 'e-mail de outro usuário');
    $t->igual($e->erros()['email'] ?? null, UsuarioService::MSG_EMAIL_DUPLICADO, 'conflito de e-mail');
    $e = $t->lanca(ValidationException::class, fn() => $servico->atualizar($b, ['matricula' => 'ed-1'] + $base), 'matrícula de outro usuário');
    $t->igual($e->erros()['matricula'] ?? null, UsuarioService::MSG_MATRICULA_DUPLICADA, 'conflito de matrícula');

    // Salvar os proprios dados sem mudanca nao conflita consigo mesmo nem gera evento.
    $servico->atualizar($b, $base);
    $t->igual(count((new AuditoriaRepository())->listarPorEntidade('usuarios', $b)), 1, 'sem alteração, sem evento');

    Database::conexao()->exec("UPDATE usuarios SET updated_at = '2020-01-01 00:00:00' WHERE id = {$b}");
    $servico->atualizar($b, ['nome' => 'Nome Novo da Silva', 'data_nascimento' => '01/02/1990'] + $base);
    $depois = $repo->buscarPorId($b);
    $t->igual($depois['nome'], 'Nome Novo da Silva', 'nome atualizado');
    $t->afirmar($depois['updated_at'] > '2020-01-01 00:00:00', 'updated_at atualizado');

    $ultimo = (new AuditoriaRepository())->listarPorEntidade('usuarios', $b)[0];
    $t->igual($ultimo['acao'], 'USUARIO_EDITADO', 'evento de edição');
    $t->igual(array_keys(json_decode($ultimo['dados_novos'], true)), ['nome', 'data_nascimento'], 'apenas campos alterados');
});

$t->teste('inativa e reativa sem excluir; status idempotente', function ($t) use ($exigirBanco, $novoUsuario) {
    $exigirBanco($t);
    $servico = new UsuarioService();
    $repo    = new UsuarioRepository();
    $id = $servico->criar($novoUsuario());

    $t->igual($servico->alterarStatus($id, false), true, 'inativou');
    $u = $repo->buscarPorId($id);
    $t->igual((int)$u['ativo'], 0, 'ativo = 0');
    $t->afirmar($u['inativado_em'] !== null, 'inativado_em registrado');
    $t->igual($servico->alterarStatus($id, false), false, 'segunda inativação não altera nada');

    $t->igual($servico->alterarStatus($id, true), true, 'reativou');
    $u = $repo->buscarPorId($id);
    $t->igual((int)$u['ativo'], 1, 'ativo = 1');
    $t->igual($u['inativado_em'], null, 'inativado_em limpo');

    $acoes = array_column((new AuditoriaRepository())->listarPorEntidade('usuarios', $id), 'acao');
    $t->igual($acoes, ['USUARIO_ATIVADO', 'USUARIO_INATIVADO', 'USUARIO_CRIADO'], 'eventos auditados');

    // Status alterado pela tela de edicao.
    $base = [
        'nome' => $u['nome'], 'email' => $u['email'], 'matricula' => $u['matricula'],
        'cargo_id' => (string)$u['cargo_id'], 'lotacao_id' => (string)$u['lotacao_id'], 'data_nascimento' => '',
    ];
    $servico->atualizar($id, $base + ['ativo' => '0']);
    $t->igual((int)$repo->buscarPorId($id)['ativo'], 0, 'inativado pela edição');
    $t->lanca(ValidationException::class, fn() => $servico->atualizar($id, $base + ['ativo' => 'x']), 'status inválido');

    $t->afirmar(!method_exists($repo, 'excluir') && !method_exists($servico, 'excluir'), 'não existe exclusão física');
    $t->lanca(ValidationException::class, fn() => $servico->alterarStatus(999999, false), 'usuário inexistente');
});

$t->teste('listagem: busca, filtros e status', function ($t) use ($exigirBanco, $novoUsuario, $idApoio) {
    $exigirBanco($t);
    $servico = new UsuarioService();
    $repo    = new UsuarioRepository();
    $idGab   = $idApoio('lotacoes', 'Gabinete');

    $x = $servico->criar($novoUsuario(['nome' => 'Zuleica Filtro Teste', 'matricula' => 'FLT-1', 'lotacao_id' => (string)$idGab]));
    $y = $servico->criar($novoUsuario(['nome' => 'Zenon Filtro Teste', 'matricula' => 'FLT-2', 'lotacao_id' => (string)$idGab]));
    $servico->alterarStatus($y, false);

    $ids = static fn($p) => array_map('intval', array_column($p->itens, 'id'));

    $t->igual($ids($repo->listar(['busca' => 'Filtro Teste', 'status' => 'ativos'], 1, 50)), [$x], 'apenas ativos por padrão');
    $t->igual($ids($repo->listar(['busca' => 'Filtro Teste', 'status' => 'inativos'], 1, 50)), [$y], 'apenas inativos');
    $t->igual(count($repo->listar(['busca' => 'Filtro Teste', 'status' => 'todos'], 1, 50)->itens), 2, 'todos');
    $t->igual($ids($repo->listar(['busca' => 'flt-1', 'status' => 'todos'], 1, 50)), [$x], 'busca por matrícula');
    $t->igual($repo->listar(['lotacao_id' => $idGab, 'status' => 'todos'], 1, 50)->total, 2, 'filtro por lotação');
    $t->igual($repo->listar(['busca' => '%', 'status' => 'todos'], 1, 50)->total, 0, '% é literal, não curinga');
    $t->igual($repo->listar(['busca' => "' OR 1=1 --", 'status' => 'todos'], 1, 50)->total, 0, 'injeção tratada como texto');
});

$t->grupo('Cargos e lotações');

$t->teste('tipo desconhecido é rejeitado', function ($t) {
    $t->afirmar(!TabelaApoioService::tipoValido('usuarios'), 'tipo fora da lista');
    $t->lanca(InvalidArgumentException::class, fn() => new TabelaApoioService("cargos; DROP TABLE x"), 'construtor rejeita');
});

$t->teste('cadastra, valida e impede duplicidade (sem diferenciar acento/maiúscula)', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $cargos = new TabelaApoioService('cargos');
    $id = $cargos->criar(['nome' => '  Gerente   de Projetos ']);
    $t->igual($cargos->repositorio()->buscarPorId($id)['nome'], 'Gerente de Projetos', 'nome normalizado');

    $e = $t->lanca(ValidationException::class, fn() => $cargos->criar(['nome' => 'GERENTE DE PROJETOS']), 'duplicado');
    $t->igual($e->erros()['nome'] ?? null, 'Já existe um cargo cadastrado com este nome.', 'mensagem de cargo duplicado');
    $t->lanca(ValidationException::class, fn() => $cargos->criar(['nome' => 'Assessor Juridico']), 'duplicado sem acento');
    $t->lanca(ValidationException::class, fn() => $cargos->criar(['nome' => 'A']), 'curto demais');
    $t->lanca(ValidationException::class, fn() => $cargos->criar(['nome' => str_repeat('x', 101)]), 'longo demais');

    $acoes = array_column((new AuditoriaRepository())->listarPorEntidade('cargos', $id), 'acao');
    $t->igual($acoes, ['CARGO_CRIADO'], 'auditado');
});

$t->teste('instituição: sigla opcional, maiúscula e única', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $inst = new TabelaApoioService('instituicoes');
    $a = $inst->criar(['nome' => 'Instituição de Teste', 'sigla' => ' ses teste ']);
    $t->igual($inst->repositorio()->buscarPorId($a)['sigla'], 'SESTESTE', 'sigla normalizada');
    $b = $inst->criar(['nome' => 'Instituição Sem Sigla', 'sigla' => '']);
    $t->igual($inst->repositorio()->buscarPorId($b)['sigla'], null, 'sigla vazia vira NULL');
    $inst->criar(['nome' => 'Outra Sem Sigla']); // varios NULL nao violam UNIQUE

    $e = $t->lanca(ValidationException::class, fn() => $inst->criar(['nome' => 'Outra Instituição', 'sigla' => 'sesteste']), 'sigla duplicada');
    $t->igual($e->erros()['sigla'] ?? null, 'Já existe uma instituição cadastrada com esta sigla.', 'mensagem de sigla duplicada');
    $t->lanca(ValidationException::class, fn() => $inst->criar(['nome' => 'Mais Uma', 'sigla' => "X'--"]), 'sigla inválida');

    $inst->atualizar($a, ['nome' => 'Instituição de Teste Renomeada', 'sigla' => 'SESTESTE']);
    $ultimo = (new AuditoriaRepository())->listarPorEntidade('instituicoes', $a)[0];
    $t->igual($ultimo['acao'], 'INSTITUICAO_EDITADA', 'edição auditada');
    $t->igual(array_keys(json_decode($ultimo['dados_novos'], true)), ['nome'], 'só o nome mudou');
    $t->afirmar(!TabelaApoioService::tipoValido('lotacoes'), 'lotações saíram das tabelas de apoio simples');
});

$t->teste('inativar preserva usuários vinculados e some das opções', function ($t) use ($exigirBanco, $novoUsuario) {
    $exigirBanco($t);
    $cargos = new TabelaApoioService('cargos');
    $idCargo = $cargos->criar(['nome' => 'Cargo Temporário']);
    $idUsuario = (new UsuarioService())->criar($novoUsuario(['cargo_id' => (string)$idCargo]));

    $t->igual($cargos->repositorio()->usuariosAtivos($idCargo), 1, 'um usuário ativo');
    $t->igual($cargos->alterarStatus($idCargo, false), true, 'inativou');
    $t->igual($cargos->alterarStatus($idCargo, false), false, 'idempotente');

    $opcoes = array_map('intval', array_column($cargos->repositorio()->opcoes(), 'id'));
    $t->afirmar(!in_array($idCargo, $opcoes, true), 'fora das opções de novo cadastro');
    $opcoesEdicao = array_map('intval', array_column($cargos->repositorio()->opcoes($idCargo), 'id'));
    $t->afirmar(in_array($idCargo, $opcoesEdicao, true), 'presente na edição de quem já o possui');
    $t->igual((int)(new UsuarioRepository())->buscarPorId($idUsuario)['cargo_id'], $idCargo, 'usuário mantém o cargo');

    $linha = array_values(array_filter($cargos->repositorio()->listar(['busca' => 'Temporário', 'status' => 'inativos']), fn($l) => (int)$l['id'] === $idCargo))[0] ?? null;
    $t->afirmar($linha !== null && (int)$linha['usuarios_ativos'] === 1, 'listagem conta usuários');

    $acoes = array_column((new AuditoriaRepository())->listarPorEntidade('cargos', $idCargo), 'acao');
    $t->igual($acoes[0], 'CARGO_INATIVADO', 'inativação auditada');
    $t->afirmar(!method_exists($cargos->repositorio(), 'excluir'), 'sem exclusão física');
});

$t->teste('rótulos de auditoria', function ($t) {
    $t->igual(\App\Services\AuditoriaService::rotulo('LOTACAO_INATIVADA'), 'Inativação', 'feminino');
    $t->igual(\App\Services\AuditoriaService::rotulo('CARGO_ATIVADO'), 'Ativação', 'ativação não confunde com inativação');
    $t->igual(\App\Services\AuditoriaService::rotulo('USUARIO_EDITADO'), 'Edição', 'usuário');
});
