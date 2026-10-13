<?php
declare(strict_types=1);

/**
 * Testes de perfis de acesso, login e designacao de processos.
 * Simula o usuario autenticado gravando $_SESSION['auth'] (como faz o login).
 *
 * @var \Tests\Runner $t
 */

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\CredencialRepository;
use App\Repositories\DesignacaoRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\UsuarioRepository;
use App\Services\AuthService;
use App\Services\DesignacaoService;
use App\Services\UsuarioService;
use App\Validators\SenhaValidator;
use App\Validators\UsuarioValidator;

$t->grupo('Acesso — validação');

$t->teste('perfil: três opções válidas', function ($t) {
    $t->igual(UsuarioValidator::perfil('GESTOR'), ['GESTOR', null], 'gestor');
    $t->igual(UsuarioValidator::perfil(' administrador ')[0], 'ADMINISTRADOR', 'normaliza caixa e espaços');
    $t->igual(UsuarioValidator::perfil('USUARIO')[1], null, 'usuário');
    $t->afirmar(UsuarioValidator::perfil('')[1] !== null, 'vazio rejeitado');
    $t->afirmar(UsuarioValidator::perfil('ROOT')[1] !== null, 'desconhecido rejeitado');
    $t->afirmar(UsuarioValidator::perfil(['GESTOR'])[1] !== null, 'array rejeitado');
    $t->igual(array_keys(Perfil::ROTULOS), ['ADMINISTRADOR', 'GESTOR', 'USUARIO'], 'lista de perfis');
    $t->afirmar(Perfil::podeDesignar('GESTOR') && Perfil::podeDesignar('ADMINISTRADOR'), 'admin e gestor designam');
    $t->afirmar(!Perfil::podeDesignar('USUARIO') && !Perfil::podeDesignar(null), 'usuário não designa');
});

$t->teste('senha: tamanho, letras e números, confirmação', function ($t) {
    $t->igual(SenhaValidator::validar('segura123', 'segura123'), null, 'senha válida');
    $t->afirmar(SenhaValidator::validar('', '') !== null, 'vazia');
    $t->afirmar(SenhaValidator::validar('abc123', 'abc123') !== null, 'curta');
    $t->afirmar(SenhaValidator::validar('somenteletras', 'somenteletras') !== null, 'sem números');
    $t->afirmar(SenhaValidator::validar('12345678', '12345678') !== null, 'sem letras');
    $t->afirmar(SenhaValidator::validar('segura123', 'segura124') !== null, 'confirmação diferente');
    $longa = str_repeat('a1', 37);
    $t->afirmar(SenhaValidator::validar($longa, $longa) !== null, 'acima de 72 bytes');
});

$t->teste('retorno após login só aceita páginas internas', function ($t) {
    $t->igual(Auth::retornoSeguro('processo-administrativo.php?id=3'), 'processo-administrativo.php?id=3', 'interna');
    foreach (['https://evil.example/x.php', '//evil.example/x.php', '../app/config.php', 'javascript:alert(1)', null] as $ruim) {
        $t->igual(Auth::retornoSeguro($ruim), 'index.php', 'rejeita ' . var_export($ruim, true));
    }
});

$t->grupo('Acesso — perfis e permissões');

$bancoPronto = $GLOBALS['gj_banco_teste_pronto'] ?? false;
$exigirBanco = static function ($t) use ($bancoPronto): void {
    if (!$bancoPronto) {
        $t->ignorar('banco de testes indisponível');
    }
};

$logarComo = static function (?int $id): void {
    if ($id === null) {
        unset($_SESSION['auth']);
    } else {
        $_SESSION['auth'] = ['usuario_id' => $id, 'ultimo_acesso' => time(), 'exigir_troca' => false];
    }
    Auth::esquecer();
};

/** Cria um usuario no contexto de sistema (sem sessao) e devolve o id. */
$criarUsuario = static function (string $perfil = Perfil::USUARIO, array $extra = []) use ($logarComo): int {
    static $seq = 0;
    $seq++;
    $anterior = $_SESSION['auth'] ?? null;
    $logarComo(null);
    $db = Database::conexao();
    $id = (new UsuarioService())->criar($extra + [
        'nome'            => "Pessoa Acesso {$seq}",
        'email'           => "acesso{$seq}@ses.mt.gov.br",
        'matricula'       => 'AC-' . $seq,
        'cargo_id'        => (string)$db->query("SELECT id FROM cargos WHERE nome = 'Analista'")->fetchColumn(),
        'lotacao_id'      => (string)$db->query("SELECT id FROM lotacoes WHERE nome = 'Unidade Jurídica'")->fetchColumn(),
        'perfil'          => $perfil,
        'data_nascimento' => '',
    ]);
    if ($anterior !== null) {
        $logarComo((int)$anterior['usuario_id']);
    }
    return $id;
};

/** Dados atuais do usuario no formato do formulario. */
$formulario = static function (int $id): array {
    $u = (new UsuarioRepository())->buscarPorId($id);
    return [
        'nome' => $u['nome'], 'email' => $u['email'], 'matricula' => $u['matricula'],
        'cargo_id' => (string)$u['cargo_id'], 'lotacao_id' => (string)$u['lotacao_id'], 'data_nascimento' => '',
    ];
};

$t->teste('cadastro grava o perfil (padrão: Usuário)', function ($t) use ($exigirBanco, $criarUsuario, $formulario, $logarComo) {
    $exigirBanco($t);
    $repo = new UsuarioRepository();
    $gestor = $criarUsuario(Perfil::GESTOR);
    $t->igual($repo->buscarPorId($gestor)['perfil'], 'GESTOR', 'perfil informado');

    $logarComo(null);
    $semPerfil = $formulario($gestor);
    $semPerfil['email'] = 'sem.perfil@ses.mt.gov.br';
    $semPerfil['matricula'] = 'SP-1';
    $id = (new UsuarioService())->criar($semPerfil);
    $t->igual($repo->buscarPorId($id)['perfil'], 'USUARIO', 'sem perfil = Usuário');

    $e = $t->lanca(ValidationException::class, fn() => (new UsuarioService())->criar(['perfil' => 'SUPER'] + $semPerfil), 'perfil inválido');
    $t->afirmar(isset($e->erros()['perfil']), 'erro no campo perfil');
});

$t->teste('usuário edita o próprio cadastro, mas não o perfil nem o status', function ($t) use ($exigirBanco, $criarUsuario, $formulario, $logarComo) {
    $exigirBanco($t);
    $servico = new UsuarioService();
    $comum = $criarUsuario(Perfil::USUARIO);
    $outro = $criarUsuario(Perfil::USUARIO);

    $logarComo($comum);
    $servico->atualizar($comum, ['nome' => 'Nome Proprio Editado'] + $formulario($comum));
    $t->igual((new UsuarioRepository())->buscarPorId($comum)['nome'], 'Nome Proprio Editado', 'editou o próprio nome');

    $servico->atualizar($comum, ['perfil' => 'USUARIO'] + $formulario($comum));
    $e = $t->lanca(AcessoNegadoException::class, fn() => $servico->atualizar($comum, ['perfil' => 'ADMINISTRADOR'] + $formulario($comum)), 'autopromoção');
    $t->igual($e->getMessage(), UsuarioService::MSG_SO_ADMIN_PERFIL, 'mensagem de perfil');
    $t->lanca(AcessoNegadoException::class, fn() => $servico->atualizar($comum, ['ativo' => '0'] + $formulario($comum)), 'alterar o próprio status');
    $t->igual((new UsuarioRepository())->buscarPorId($comum)['perfil'], 'USUARIO', 'perfil inalterado');

    $t->lanca(AcessoNegadoException::class, fn() => $servico->atualizar($outro, $formulario($outro)), 'editar outro usuário');
    $t->lanca(AcessoNegadoException::class, fn() => $servico->criar(['email' => 'x9@ses.mt.gov.br', 'matricula' => 'X9'] + $formulario($outro)), 'cadastrar');
    $t->lanca(AcessoNegadoException::class, fn() => $servico->alterarStatus($outro, false), 'inativar');
    $logarComo(null);
});

$t->teste('gestor também não altera o próprio perfil', function ($t) use ($exigirBanco, $criarUsuario, $formulario, $logarComo) {
    $exigirBanco($t);
    $gestor = $criarUsuario(Perfil::GESTOR);
    $logarComo($gestor);
    $t->lanca(AcessoNegadoException::class, fn() => (new UsuarioService())->atualizar($gestor, ['perfil' => 'ADMINISTRADOR'] + $formulario($gestor)), 'gestor → admin');
    $t->afirmar(!Auth::ehAdministrador() && Auth::podeDesignar(), 'gestor designa, mas não administra');
    $logarComo(null);
});

$t->teste('administrador altera perfis; auditoria registra a mudança', function ($t) use ($exigirBanco, $criarUsuario, $formulario, $logarComo) {
    $exigirBanco($t);
    $admin = $criarUsuario(Perfil::ADMINISTRADOR);
    $alvo  = $criarUsuario(Perfil::USUARIO);

    $logarComo($admin);
    (new UsuarioService())->atualizar($alvo, ['perfil' => 'GESTOR'] + $formulario($alvo));
    $t->igual((new UsuarioRepository())->buscarPorId($alvo)['perfil'], 'GESTOR', 'promovido a gestor');

    $ultimo = (new AuditoriaRepository())->listarPorEntidade('usuarios', $alvo)[0];
    $t->igual($ultimo['acao'], 'USUARIO_EDITADO', 'edição auditada');
    $t->igual(json_decode($ultimo['dados_novos'], true), ['perfil' => 'GESTOR'], 'só o perfil mudou');
    $t->igual(json_decode($ultimo['dados_anteriores'], true), ['perfil' => 'USUARIO'], 'perfil anterior');
    $t->igual((int)$ultimo['usuario_id'], $admin, 'autor registrado');
    $logarComo(null);
});

$t->teste('sempre resta um administrador ativo; ninguém se inativa', function ($t) use ($exigirBanco, $criarUsuario, $formulario, $logarComo) {
    $exigirBanco($t);
    Database::conexao()->exec("UPDATE usuarios SET perfil = 'USUARIO' WHERE perfil = 'ADMINISTRADOR'");
    $servico = new UsuarioService();
    $unico = $criarUsuario(Perfil::ADMINISTRADOR);

    $logarComo($unico);
    $e = $t->lanca(ValidationException::class, fn() => $servico->atualizar($unico, ['perfil' => 'GESTOR'] + $formulario($unico)), 'rebaixar o último');
    $t->igual($e->erros()['perfil'] ?? null, UsuarioService::MSG_ULTIMO_ADMIN, 'mensagem do último administrador');
    $t->lanca(ValidationException::class, fn() => $servico->alterarStatus($unico, false), 'inativar a si mesmo');

    $segundo = $criarUsuario(Perfil::ADMINISTRADOR);
    $servico->atualizar($segundo, ['perfil' => 'USUARIO'] + $formulario($segundo));
    $t->igual((new UsuarioRepository())->buscarPorId($segundo)['perfil'], 'USUARIO', 'rebaixa outro havendo administrador');

    $terceiro = $criarUsuario(Perfil::ADMINISTRADOR);
    $servico->atualizar($unico, ['perfil' => 'GESTOR'] + $formulario($unico));
    $t->igual((new UsuarioRepository())->buscarPorId($unico)['perfil'], 'GESTOR', 'rebaixa a si mesmo havendo outro administrador');

    $logarComo($terceiro);
    $t->lanca(ValidationException::class, fn() => $servico->atualizar($terceiro, ['ativo' => '0'] + $formulario($terceiro)), 'inativar o último pela edição');
    $logarComo(null);
});

$t->grupo('Acesso — login e senha');

$t->teste('login: senha correta, incorreta, sem senha, e-mail desconhecido', function ($t) use ($exigirBanco, $criarUsuario, $logarComo) {
    $exigirBanco($t);
    $logarComo(null);
    $id = $criarUsuario(Perfil::USUARIO, ['email' => 'login.teste@ses.mt.gov.br', 'matricula' => 'LG-1']);
    $auth = new AuthService();

    $e = $t->lanca(ValidationException::class, fn() => $auth->autenticar('login.teste@ses.mt.gov.br', 'qualquer123'), 'sem senha definida');
    $t->igual($e->getMessage(), AuthService::MSG_FALHA, 'mensagem genérica');

    $auth->definirSenhaProvisoria($id, 'Provisoria1', 'Provisoria1');
    $r = $auth->autenticar(' LOGIN.Teste@ses.mt.gov.br ', 'Provisoria1');
    $t->igual($r, ['id' => $id, 'exigir_troca' => true], 'autenticou e exige troca');

    $e = $t->lanca(ValidationException::class, fn() => $auth->autenticar('login.teste@ses.mt.gov.br', 'errada123'), 'senha incorreta');
    $t->igual($e->getMessage(), AuthService::MSG_FALHA, 'mesma mensagem');
    $e = $t->lanca(ValidationException::class, fn() => $auth->autenticar('ninguem@ses.mt.gov.br', 'Provisoria1'), 'e-mail desconhecido');
    $t->igual($e->getMessage(), AuthService::MSG_FALHA, 'mesma mensagem');

    $credencial = (new CredencialRepository())->buscar($id);
    $t->afirmar(password_verify('Provisoria1', $credencial['senha_hash']) && !str_contains($credencial['senha_hash'], 'Provisoria1'), 'senha guardada como hash');

    $acoes = array_column((new AuditoriaRepository())->listarPorEntidade('usuarios', $id), 'acao');
    $t->afirmar(in_array('LOGIN_REALIZADO', $acoes, true) && in_array('LOGIN_FALHOU', $acoes, true), 'login auditado');
    $t->afirmar(in_array('SENHA_DEFINIDA', $acoes, true), 'definição de senha auditada');
});

$t->teste('login: bloqueio após tentativas e usuário inativo', function ($t) use ($exigirBanco, $criarUsuario, $logarComo) {
    $exigirBanco($t);
    $logarComo(null);
    $id = $criarUsuario(Perfil::USUARIO, ['email' => 'bloqueio@ses.mt.gov.br', 'matricula' => 'BL-1']);
    $auth = new AuthService();
    $auth->definirSenhaProvisoria($id, 'Correta123', 'Correta123');

    for ($i = 0; $i < 5; $i++) {
        try { $auth->autenticar('bloqueio@ses.mt.gov.br', 'errada123'); } catch (ValidationException) {}
    }
    $t->igual((int)(new CredencialRepository())->situacao($id)['bloqueado'], 1, 'bloqueado');
    $t->lanca(ValidationException::class, fn() => $auth->autenticar('bloqueio@ses.mt.gov.br', 'Correta123'), 'senha correta recusada durante o bloqueio');

    $auth->definirSenhaProvisoria($id, 'Correta123', 'Correta123');
    $t->igual($auth->autenticar('bloqueio@ses.mt.gov.br', 'Correta123')['id'], $id, 'redefinir a senha desbloqueia');

    (new UsuarioService())->alterarStatus($id, false);
    $t->lanca(ValidationException::class, fn() => $auth->autenticar('bloqueio@ses.mt.gov.br', 'Correta123'), 'inativo não entra');
});

$t->teste('troca da própria senha e permissões de definição', function ($t) use ($exigirBanco, $criarUsuario, $logarComo) {
    $exigirBanco($t);
    $logarComo(null);
    $id = $criarUsuario(Perfil::GESTOR, ['email' => 'troca@ses.mt.gov.br', 'matricula' => 'TR-1']);
    $outro = $criarUsuario(Perfil::USUARIO);
    $auth = new AuthService();
    $auth->definirSenhaProvisoria($id, 'Provisoria1', 'Provisoria1');

    $logarComo($id);
    $e = $t->lanca(ValidationException::class, fn() => $auth->alterarPropriaSenha($id, 'errada', 'NovaSenha9', 'NovaSenha9'), 'senha atual errada');
    $t->afirmar(isset($e->erros()['senha_atual']), 'erro na senha atual');
    $t->lanca(ValidationException::class, fn() => $auth->alterarPropriaSenha($id, 'Provisoria1', 'Provisoria1', 'Provisoria1'), 'nova igual à atual');
    $auth->alterarPropriaSenha($id, 'Provisoria1', 'NovaSenha9', 'NovaSenha9');
    $logarComo(null);
    $t->igual($auth->autenticar('troca@ses.mt.gov.br', 'NovaSenha9'), ['id' => $id, 'exigir_troca' => false], 'nova senha sem exigir troca');

    $logarComo($id);
    $t->lanca(AcessoNegadoException::class, fn() => $auth->definirSenhaProvisoria($outro, 'Qualquer12', 'Qualquer12'), 'gestor não define senha de outros');
    $logarComo(null);
});

$t->grupo('Designação de processos');

$novoPa = static function (string $protocolo): int {
    $stmt = Database::conexao()->prepare("INSERT INTO processos_administrativos (protocolo, origem) VALUES (:p, 'MANUAL')");
    $stmt->execute([':p' => $protocolo]);
    return (int)Database::conexao()->lastInsertId();
};

$t->teste('gestor designa e remove; usuário não altera a designação', function ($t) use ($exigirBanco, $criarUsuario, $logarComo, $novoPa) {
    $exigirBanco($t);
    $gestor  = $criarUsuario(Perfil::GESTOR);
    $comum   = $criarUsuario(Perfil::USUARIO);
    $colega  = $criarUsuario(Perfil::USUARIO);
    $pa      = $novoPa('DESIG-PRO-2026/1');
    $servico = new DesignacaoService();
    $repo    = new DesignacaoRepository();

    $logarComo($gestor);
    $id = $servico->designar('administrativo', $pa, (string)$comum, '  Acompanhar   prazos ');
    $lista = $repo->listarPorProcesso('administrativo', $pa);
    $t->igual(count($lista), 1, 'uma designação');
    $t->igual((int)$lista[0]['usuario_id'], $comum, 'usuário designado');
    $t->igual($lista[0]['observacao'], 'Acompanhar prazos', 'observação normalizada');
    $t->igual($lista[0]['designado_por_nome'], (new UsuarioRepository())->buscarPorId($gestor)['nome'], 'quem designou');

    $e = $t->lanca(ValidationException::class, fn() => $servico->designar('administrativo', $pa, (string)$comum), 'duplicada');
    $t->igual($e->erros()['usuario_id'] ?? null, DesignacaoService::MSG_DUPLICADA, 'mensagem de duplicidade');
    $t->lanca(ValidationException::class, fn() => $servico->designar('administrativo', $pa, 'abc'), 'usuário inválido');
    $t->lanca(ValidationException::class, fn() => $servico->designar('administrativo', 999999, (string)$colega), 'processo inexistente');
    $t->lanca(ValidationException::class, fn() => $servico->designar('administrativo', $pa, (string)$colega, str_repeat('x', 256)), 'observação longa');

    $logarComo($comum);
    $e = $t->lanca(AcessoNegadoException::class, fn() => $servico->designar('administrativo', $pa, (string)$colega), 'usuário designando');
    $t->igual($e->getMessage(), DesignacaoService::MSG_SEM_PERMISSAO, 'mensagem de permissão');
    $t->lanca(AcessoNegadoException::class, fn() => $servico->remover('administrativo', $pa, $id), 'usuário removendo a própria designação');
    $t->igual(count($repo->listarPorProcesso('administrativo', $pa)), 1, 'designação intacta');

    $logarComo($gestor);
    $t->igual($servico->remover('administrativo', $pa + 1, $id), false, 'designação de outro processo não é removida');
    $t->igual($servico->remover('administrativo', $pa, $id), true, 'removida');
    $t->igual($servico->remover('administrativo', $pa, $id), false, 'remoção idempotente');

    $acoes = array_column((new AuditoriaRepository())->listarPorEntidade('processos_administrativos', $pa), 'acao');
    $t->igual($acoes, ['DESIGNACAO_REMOVIDA', 'DESIGNACAO_CRIADA'], 'designação auditada no processo');
    $logarComo(null);
});

$t->teste('não designa usuário inativo; processo judicial não tem designação', function ($t) use ($exigirBanco, $criarUsuario, $logarComo, $novoPa) {
    $exigirBanco($t);
    $admin   = $criarUsuario(Perfil::ADMINISTRADOR);
    $inativo = $criarUsuario(Perfil::USUARIO);
    $ativo   = $criarUsuario(Perfil::USUARIO);
    $pa      = $novoPa('DESIG-PRO-2026/2');
    (new UsuarioService())->alterarStatus($inativo, false);

    $logarComo($admin);
    $servico = new DesignacaoService();
    $e = $t->lanca(ValidationException::class, fn() => $servico->designar('administrativo', $pa, (string)$inativo), 'inativo');
    $t->afirmar(isset($e->erros()['usuario_id']), 'erro no usuário');
    $servico->designar('administrativo', $pa, (string)$ativo);
    $servico->designar('administrativo', $pa, (string)$admin);
    $t->igual(count((new DesignacaoRepository())->listarPorProcesso('administrativo', $pa)), 2, 'vários designados no mesmo processo');
    $t->lanca(InvalidArgumentException::class, fn() => $servico->designar('judicial', $pa, (string)$ativo), 'processo judicial não tem designação');
    $logarComo(null);
});

$t->teste('filtro "designado a", painel do usuário e exclusão do processo', function ($t) use ($exigirBanco, $criarUsuario, $logarComo, $novoPa) {
    $exigirBanco($t);
    $gestor = $criarUsuario(Perfil::GESTOR);
    $comum  = $criarUsuario(Perfil::USUARIO);
    $pa1 = $novoPa('FILTRO-DESIG/1');
    $pa2 = $novoPa('FILTRO-DESIG/2');
    $pa3 = $novoPa('FILTRO-DESIG/3');

    $logarComo($gestor);
    $servico = new DesignacaoService();
    $servico->designar('administrativo', $pa1, (string)$comum);
    $servico->designar('administrativo', $pa3, (string)$comum);
    // Mesmo segundo de created_at: o desempate da listagem é pelo protocolo.
    Database::conexao()->prepare('UPDATE processo_administrativo_designacoes SET created_at = created_at + INTERVAL 1 MINUTE WHERE processo_administrativo_id = :p')
        ->execute([':p' => $pa3]);

    $ids = static fn($p) => array_map('intval', array_column($p->itens, 'id'));
    $repoPa = new ProcessoAdministrativoRepository();
    $t->igual($ids($repoPa->listar(['busca' => 'FILTRO-DESIG', 'designado' => $comum, 'ordenar' => 'protocolo', 'direcao' => 'ASC'], 1, 50)), [$pa1, $pa3], 'designados ao usuário');
    $t->igual($ids($repoPa->listar(['busca' => 'FILTRO-DESIG', 'designado' => 'nenhum'], 1, 50)), [$pa2], 'sem designação');
    $linha = $repoPa->listar(['busca' => 'FILTRO-DESIG/1'], 1, 50)->itens[0];
    $t->igual($linha['designados'], (new UsuarioRepository())->buscarPorId($comum)['nome'], 'nomes dos designados na listagem');

    $repo = new DesignacaoRepository();
    $t->igual($repo->contarDoUsuario($comum), 2, 'dois processos designados');
    $t->igual(array_map('intval', array_column($repo->doUsuario($comum), 'id')), [$pa3, $pa1], 'mais recentes primeiro');

    $repoPa->excluir($pa1);
    $t->igual($repo->contarDoUsuario($comum), 1, 'excluir o processo remove a designação');
    $logarComo(null);
});
