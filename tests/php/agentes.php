<?php
declare(strict_types=1);

/**
 * Agentes Publicos e a relacao Agente (pessoa) -> Usuario (credencial).
 *
 * @var \Tests\Runner $t
 */

use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AgentePublicoRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\UsuarioRepository;
use App\Services\AgentePublicoService;
use App\Services\UsuarioService;
use App\Validators\CpfValidator;

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

$apoio = static function (): array {
    $db = Database::conexao();
    return [
        'cargo'   => (string)$db->query("SELECT id FROM cargos WHERE nome = 'Analista'")->fetchColumn(),
        'lotacao' => (string)$db->query("SELECT id FROM lotacoes WHERE nome = 'Unidade Jurídica'")->fetchColumn(),
    ];
};

$t->grupo('Agentes Públicos — regras');

$t->teste('CPF: dígitos verificadores e formatação', function ($t) {
    $t->afirmar(CpfValidator::valido('529.982.247-25'), 'CPF válido com máscara');
    $t->afirmar(CpfValidator::valido('52998224725'), 'CPF válido sem máscara');
    $t->afirmar(!CpfValidator::valido('529.982.247-26'), 'dígito errado');
    $t->afirmar(!CpfValidator::valido('111.111.111-11'), 'sequência repetida');
    $t->afirmar(!CpfValidator::valido('5299822472'), '10 dígitos');
    $t->igual(CpfValidator::formatar('52998224725'), '529.982.247-25', 'formatação');
    $t->igual(telefone_br('65999998888'), '(65) 99999-8888', 'celular');
    $t->igual(telefone_br('6536135300'), '(65) 3613-5300', 'fixo');
});

$t->grupo('Agentes Públicos — cadastro');

$t->teste('validação: obrigatórios, CPF, e-mails, telefone, datas e unicidade', function ($t) use ($exigirBanco, $apoio) {
    $exigirBanco($t);
    ['cargo' => $cargo, 'lotacao' => $lotacao] = $apoio();
    $servico = new AgentePublicoService();

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar([
        'nome' => 'X', 'cpf' => '123.456.789-00', 'email_institucional' => 'sem-arroba', 'telefone' => '1234',
        'data_ingresso' => '2020-05-10', 'data_desligamento' => '2019-01-01',
    ]), 'dados inválidos');
    foreach (['nome', 'cpf', 'email_institucional', 'telefone', 'tipo_vinculo', 'lotacao_id', 'data_desligamento'] as $campo) {
        $t->afirmar(isset($e->erros()[$campo]), 'erro em ' . $campo);
    }

    $dados = ['nome' => ' Maria  da Silva ', 'cpf' => '529.982.247-25', 'email_institucional' => 'Maria.Silva@SES.mt.gov.br',
              'telefone' => '(65) 99999-8888', 'matricula' => '0012345', 'tipo_vinculo' => 'EFETIVO', 'cargo_id' => $cargo,
              'lotacao_id' => $lotacao, 'data_nascimento' => '1980-02-01', 'data_ingresso' => '2010-03-01', 'situacao' => 'ATIVO'];
    $id = $servico->criar($dados);
    $a = (new AgentePublicoRepository())->buscarPorId($id);
    $t->igual([$a['nome'], $a['cpf'], $a['email_institucional'], $a['telefone'], $a['matricula']],
        ['Maria da Silva', '52998224725', 'maria.silva@ses.mt.gov.br', '65999998888', '0012345'], 'normalização');
    $t->igual($a['instituicao_id'], null, 'órgão vem da unidade (sem instituição na unidade antiga)');
    $t->igual($a['usuario_id'], null, 'agente sem acesso ao sistema');

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['nome' => 'Outra Pessoa'] + $dados), 'repetidos');
    $t->igual($e->erros()['cpf'], AgentePublicoService::MSG_CPF_DUPLICADO, 'CPF único');
    $t->igual($e->erros()['matricula'], AgentePublicoService::MSG_MATRICULA_DUPLICADA, 'matrícula única');
    $t->igual($e->erros()['email_institucional'], AgentePublicoService::MSG_EMAIL_DUPLICADO, 'e-mail único');

    // Terceirizado sem matricula, CPF nem cargo: nem todo agente tem acesso.
    $terceirizado = $servico->criar(['nome' => 'João Terceirizado', 'tipo_vinculo' => 'TERCEIRIZADO', 'lotacao_id' => $lotacao]);
    $t->afirmar($terceirizado > 0, 'cadastro mínimo');
    $t->igual(count((new AuditoriaRepository())->listarPorEntidade('agentes_publicos', $terceirizado)), 1, 'auditoria do cadastro');
});

$t->teste('listagem: nome, matrícula, órgão, unidade, cargo, vínculo, situação e acesso', function ($t) use ($exigirBanco, $apoio) {
    $exigirBanco($t);
    ['cargo' => $cargo, 'lotacao' => $lotacao] = $apoio();
    $servico = new AgentePublicoService();
    $a = $servico->criar(['nome' => 'Agfiltro Estagiária', 'matricula' => 'AGF-1', 'tipo_vinculo' => 'ESTAGIARIO', 'cargo_id' => $cargo, 'lotacao_id' => $lotacao]);
    $b = $servico->criar(['nome' => 'Agfiltro Cedido', 'matricula' => 'AGF-2', 'tipo_vinculo' => 'CEDIDO', 'lotacao_id' => $lotacao, 'situacao' => 'AFASTADO']);
    $repo = new AgentePublicoRepository();
    $ids = static fn($p): array => array_map('intval', array_column($p->itens, 'id'));

    $t->igual($ids($repo->listar(['busca' => 'Agfiltro'], 1, 50)), [$b, $a], 'por nome, em ordem alfabética');
    $t->igual($ids($repo->listar(['matricula' => 'agf-1'], 1, 50)), [$a], 'por matrícula');
    $t->igual($ids($repo->listar(['busca' => 'Agfiltro', 'tipo_vinculo' => 'CEDIDO'], 1, 50)), [$b], 'por vínculo');
    $t->igual($ids($repo->listar(['busca' => 'Agfiltro', 'situacao' => 'AFASTADO'], 1, 50)), [$b], 'por situação');
    $t->igual($ids($repo->listar(['busca' => 'Agfiltro', 'cargo_id' => (int)$cargo], 1, 50)), [$a], 'por cargo');
    $t->igual($ids($repo->listar(['busca' => 'Agfiltro', 'lotacao_id' => (int)$lotacao, 'acesso' => 'sem'], 1, 50)), [$b, $a], 'por unidade e sem acesso');
});

$t->grupo('Agentes Públicos — usuário do sistema');

$t->teste('criar usuário a partir do agente: aproveita os dados e guarda só o acesso', function ($t) use ($exigirBanco, $apoio) {
    $exigirBanco($t);
    ['cargo' => $cargo, 'lotacao' => $lotacao] = $apoio();
    $agentes = new AgentePublicoService();
    $usuarios = new UsuarioService();

    $semMatricula = $agentes->criar(['nome' => 'Sem Matrícula', 'tipo_vinculo' => 'COLABORADOR', 'lotacao_id' => $lotacao]);
    $e = $t->lanca(ValidationException::class, fn() => $usuarios->criarParaAgente($semMatricula, ['email' => 'sem@ses.mt.gov.br']), 'sem matrícula e cargo');
    $t->contem($e->erros()['agente_publico_id'], 'matrícula', 'pendência apontada');

    $desligado = $agentes->criar(['nome' => 'Pessoa Desligada', 'matricula' => 'DES-1', 'cargo_id' => $cargo, 'tipo_vinculo' => 'EFETIVO', 'lotacao_id' => $lotacao, 'situacao' => 'DESLIGADO']);
    $e = $t->lanca(ValidationException::class, fn() => $usuarios->criarParaAgente($desligado, ['email' => 'des@ses.mt.gov.br']), 'desligado');
    $t->contem($e->erros()['agente_publico_id'], 'desligado', 'mensagem');

    $agenteId = $agentes->criar(['nome' => 'Ana Agente', 'matricula' => 'AGT-100', 'cargo_id' => $cargo, 'tipo_vinculo' => 'COMISSIONADO',
                                 'lotacao_id' => $lotacao, 'email_institucional' => 'ana.agente@ses.mt.gov.br', 'data_nascimento' => '1990-04-04']);
    $uid = $usuarios->criar(['agente_publico_id' => (string)$agenteId, 'perfil' => Perfil::GESTOR]);
    $u = (new UsuarioRepository())->buscarPorId($uid);
    $t->igual([$u['nome'], $u['matricula'], $u['email'], $u['perfil'], (int)$u['agente_publico_id'], $u['data_nascimento']],
        ['Ana Agente', 'AGT-100', 'ana.agente@ses.mt.gov.br', Perfil::GESTOR, $agenteId, '1990-04-04'], 'dados do agente e e-mail institucional como login');
    $e = $t->lanca(ValidationException::class, fn() => $usuarios->criarParaAgente($agenteId, ['email' => 'outra@ses.mt.gov.br']), 'segunda conta');
    $t->igual($e->erros()['agente_publico_id'], UsuarioService::MSG_AGENTE_COM_USUARIO, 'um usuário por agente');
    $t->igual((int)(new AgentePublicoRepository())->buscarPorId($agenteId)['usuario_id'], $uid, 'agente mostra a conta');
});

$t->teste('cadastro direto de usuário sempre fica ligado a um agente', function ($t) use ($exigirBanco, $apoio, $entrarComo) {
    $exigirBanco($t);
    ['cargo' => $cargo, 'lotacao' => $lotacao] = $apoio();
    $entrarComo(null);
    $usuarios = new UsuarioService();
    $base = ['cargo_id' => $cargo, 'lotacao_id' => $lotacao, 'perfil' => Perfil::USUARIO, 'data_nascimento' => ''];

    $novo = $usuarios->criar(['nome' => 'Direto Novo', 'email' => 'direto.novo@ses.mt.gov.br', 'matricula' => 'DIR-1'] + $base);
    $agenteNovo = (new UsuarioRepository())->buscarPorId($novo)['agente_publico_id'];
    $t->afirmar($agenteNovo !== null, 'agente criado junto');
    $a = (new AgentePublicoRepository())->buscarPorId((int)$agenteNovo);
    $t->igual([$a['nome'], $a['matricula'], $a['email_institucional'], $a['tipo_vinculo']], ['Direto Novo', 'DIR-1', 'direto.novo@ses.mt.gov.br', 'OUTRO'], 'dados do agente criado');

    // Agente ja cadastrado com a mesma matricula e sem conta: e reaproveitado.
    $existente = (new AgentePublicoService())->criar(['nome' => 'Direto Existente', 'matricula' => 'DIR-2', 'tipo_vinculo' => 'EFETIVO', 'lotacao_id' => $lotacao]);
    $uid = $usuarios->criar(['nome' => 'Direto Existente', 'email' => 'direto.existente@ses.mt.gov.br', 'matricula' => 'dir-2'] + $base);
    $t->igual((int)(new UsuarioRepository())->buscarPorId($uid)['agente_publico_id'], $existente, 'ligado ao agente existente');
    $t->igual((new AgentePublicoRepository())->buscarPorId($existente)['cargo_id'], (int)$cargo, 'cargo levado ao agente');
});

$t->teste('dados pessoais e funcionais sincronizados nos dois sentidos; desligar inativa o acesso', function ($t) use ($exigirBanco, $apoio, $entrarComo) {
    $exigirBanco($t);
    ['cargo' => $cargo, 'lotacao' => $lotacao] = $apoio();
    $entrarComo(null);
    $agentes = new AgentePublicoService();
    $usuarios = new UsuarioService();
    $agenteId = $agentes->criar(['nome' => 'Sincronia Um', 'matricula' => 'SYN-1', 'cargo_id' => $cargo, 'tipo_vinculo' => 'EFETIVO', 'lotacao_id' => $lotacao]);
    $uid = $usuarios->criarParaAgente($agenteId, ['email' => 'sincronia@ses.mt.gov.br']);
    $repoA = new AgentePublicoRepository();
    $repoU = new UsuarioRepository();

    $dadosAgente = static fn(array $mudancas) => $mudancas + [
        'nome' => 'Sincronia Um', 'matricula' => 'SYN-1', 'cargo_id' => $cargo, 'tipo_vinculo' => 'EFETIVO',
        'lotacao_id' => $lotacao, 'situacao' => 'ATIVO',
    ];
    $agentes->atualizar($agenteId, $dadosAgente(['nome' => 'Sincronia Dois', 'data_nascimento' => '1985-01-01']));
    $u = $repoU->buscarPorId($uid);
    $t->igual([$u['nome'], $u['data_nascimento'], $u['email']], ['Sincronia Dois', '1985-01-01', 'sincronia@ses.mt.gov.br'], 'agente -> usuário (login mantido)');
    $acoesUsuario = array_column((new AuditoriaRepository())->listarPorEntidade('usuarios', $uid), 'acao');
    $t->afirmar(in_array('USUARIO_EDITADO', $acoesUsuario, true), 'alteração auditada na conta');

    $usuarios->atualizar($uid, ['nome' => 'Sincronia Três', 'email' => 'sincronia@ses.mt.gov.br', 'matricula' => 'SYN-1',
                                'cargo_id' => $cargo, 'lotacao_id' => $lotacao, 'data_nascimento' => '1985-01-01']);
    $t->igual($repoA->buscarPorId($agenteId)['nome'], 'Sincronia Três', 'usuário -> agente');

    $e = $t->lanca(ValidationException::class, fn() => $agentes->atualizar($agenteId, $dadosAgente(['nome' => 'Sincronia Três', 'matricula' => ''])), 'com conta, matrícula obrigatória');
    $t->afirmar(isset($e->erros()['matricula']), 'erro na matrícula');

    $agentes->atualizar($agenteId, $dadosAgente(['nome' => 'Sincronia Três', 'situacao' => 'DESLIGADO', 'data_desligamento' => '2026-09-30', 'data_nascimento' => '1985-01-01']));
    $t->igual((int)$repoU->buscarPorId($uid)['ativo'], 0, 'desligar o agente inativa o usuário');
});
