<?php
declare(strict_types=1);

/**
 * Intimações Pessoais: situação temporal, regras de negócio (RN01-RN10),
 * permissões, exclusão lógica, integração com processos e agentes e páginas.
 *
 * @var \Tests\Runner $t
 */

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AgentePublicoRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\IntimacaoPessoalRepository;
use App\Services\AgentePublicoService;
use App\Services\IntimacaoPessoalService as Intimacoes;
use App\Services\ProcessoAdministrativoService;
use App\Services\ProcessoExtrajudicialService;
use App\Services\ProcessoJudicialService;
use App\Services\UsuarioService;

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
        'nome'       => "Pessoa Intimações {$seq}",
        'email'      => "intimacoes{$seq}@ses.mt.gov.br",
        'matricula'  => 'INT-' . $seq,
        'cargo_id'   => (string)$db->query("SELECT id FROM cargos WHERE nome = 'Analista'")->fetchColumn(),
        'lotacao_id' => (string)$db->query("SELECT id FROM lotacoes WHERE nome = 'Unidade Jurídica'")->fetchColumn(),
        'perfil'     => $perfil,
        'data_nascimento' => '',
    ]);
};

/** Agente público sem conta no sistema. */
$novoAgente = static function (string $nome) use ($entrarComo): int {
    static $seq = 0;
    $seq++;
    $lotacao = (string)Database::conexao()->query("SELECT id FROM lotacoes WHERE nome = 'Gabinete'")->fetchColumn();
    $sessao = $_SESSION['auth'] ?? null;
    $entrarComo(null);
    $id = (new AgentePublicoService())->criar(['nome' => $nome, 'matricula' => 'AGI-' . $seq, 'tipo_vinculo' => 'EFETIVO', 'lotacao_id' => $lotacao]);
    $entrarComo($sessao['usuario_id'] ?? null);
    return $id;
};

$novoJudicial = static function (): int {
    static $seq = 0;
    $seq++;
    $n = str_pad((string)(6100000 + $seq), 7, '0', STR_PAD_LEFT);
    $resto = 0;
    foreach (str_split($n . '2026' . '8' . '11' . '0041' . '00') as $d) {
        $resto = ($resto * 10 + (int)$d) % 97;
    }
    $dv = str_pad((string)(98 - $resto), 2, '0', STR_PAD_LEFT);
    return (new ProcessoJudicialService())->criar(['numero_cnj' => "{$n}-{$dv}.2026.8.11.0041"]);
};

$dias = static fn(int $n): string => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));

$t->grupo('Intimações — situação temporal');

$t->teste('status informado e situação calculada são independentes', function ($t) {
    $r = static fn(string $status, string $prazo, ?string $cumprimento = null): array =>
        ['status' => $status, 'data_prazo' => $prazo, 'data_cumprimento' => $cumprimento];
    $hoje = '2026-10-15';
    $t->igual(Intimacoes::situacao($r('PENDENTE', '2026-10-30'), $hoje), 'NO_PRAZO', 'dentro do prazo');
    $t->igual(Intimacoes::situacao($r('EM_ANDAMENTO', '2026-10-18'), $hoje), 'PROXIMA', 'até 3 dias: próxima');
    $t->igual(Intimacoes::situacao($r('PENDENTE', '2026-10-19'), $hoje), 'NO_PRAZO', '4 dias: ainda no prazo');
    $t->igual(Intimacoes::situacao($r('PENDENTE', '2026-10-15'), $hoje), 'PROXIMA', 'vence hoje: próxima, não vencida');
    $t->igual(Intimacoes::situacao($r('PENDENTE', '2026-10-14'), $hoje), 'VENCIDA', 'RN05: prazo passado sem cumprimento');
    $t->igual(Intimacoes::situacao($r('CUMPRIDA', '2026-10-14', '2026-10-14'), $hoje), 'CUMPRIDA_NO_PRAZO', 'cumprida no último dia');
    $t->igual(Intimacoes::situacao($r('CUMPRIDA', '2026-10-14', '2026-10-15'), $hoje), 'CUMPRIDA_FORA_PRAZO', 'RN07: cumprida depois do prazo');
    $t->igual(Intimacoes::situacao($r('CANCELADA', '2026-01-01'), $hoje), null, 'cancelada não vence');
});

$t->teste('dias restantes: diferença corrida até o prazo, só enquanto aberta (RN04)', function ($t) {
    $hoje = '2026-10-15';
    $t->igual(Intimacoes::diasRestantes(['status' => 'PENDENTE', 'data_prazo' => '2026-10-25'], $hoje), 10, 'faltam 10');
    $t->igual(Intimacoes::diasRestantes(['status' => 'PENDENTE', 'data_prazo' => '2026-10-13'], $hoje), -2, 'vencida há 2');
    $t->igual(Intimacoes::diasRestantes(['status' => 'PENDENTE', 'data_prazo' => '2026-11-01'], '2026-10-31'), 1, 'virada de mês');
    $t->igual(Intimacoes::diasRestantes(['status' => 'CUMPRIDA', 'data_prazo' => '2026-10-25'], $hoje), null, 'cumprida sem contagem');
    $t->igual(Intimacoes::textoDiasRestantes(0), 'Vence hoje', 'hoje');
    $t->igual(Intimacoes::textoDiasRestantes(-3), 'Vencida há 3 dias', 'vencida');
    $t->igual(Intimacoes::textoDiasRestantes(5), 'Faltam 5 dias', 'faltam');
});

$t->teste('retorno depois de gravar: só páginas internas', function ($t) {
    $t->igual(destino_intimacao('processo-judicial.php?id=7', '#intimacao-3'), 'processo-judicial.php?id=7#intimacao-3', 'ficha');
    $t->igual(destino_intimacao('https://evil.example/x.php'), 'intimacoes-pessoais.php', 'URL externa recusada');
    $t->igual(destino_intimacao('//evil.example'), 'intimacoes-pessoais.php', 'protocolo relativo recusado');
    $t->igual(destino_intimacao(''), 'intimacoes-pessoais.php', 'vazio');
});

$t->grupo('Intimações — cadastro e regras');

$t->teste('valida processo, agentes e datas; reúne os erros de uma vez', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoAgente, $novoJudicial, $dias) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::GESTOR));
    $servico = new Intimacoes();
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['status' => '']), 'tudo vazio');
    $t->igual(array_keys($e->erros()), ['processo_id', 'agente_intimado_id', 'agente_recebedor_id', 'data_recebimento', 'data_prazo', 'status'], 'erros reunidos');

    $agente = $novoAgente('Validação Agente');
    $base = ['processo_tipo' => 'JUDICIAL', 'processo_id' => '999999', 'agente_intimado_id' => (string)$agente,
             'agente_recebedor_id' => '999999', 'data_recebimento' => $dias(1), 'data_prazo' => $dias(0), 'status' => 'PENDENTE'];
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($base), 'processo e recebedor inexistentes, datas incoerentes');
    $t->contem($e->erros()['processo_id'] ?? '', 'não está cadastrado', 'RN01: processo inexistente recusado');
    $t->afirmar(isset($e->erros()['agente_recebedor_id']), 'RN02: agente inexistente recusado');
    $t->contem($e->erros()['data_recebimento'] ?? '', 'futura', 'recebimento futuro');
    $t->contem($e->erros()['data_prazo'] ?? '', 'anterior ao recebimento', 'prazo antes do recebimento');

    $pid = $novoJudicial();
    $base = ['processo_id' => (string)$pid, 'agente_recebedor_id' => (string)$agente, 'data_recebimento' => '2026-09-10',
             'data_prazo' => '2026-09-20', 'status' => 'CUMPRIDA', 'data_cumprimento' => ''] + $base;
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($base), 'cumprida sem data');
    $t->igual(array_keys($e->erros()), ['data_cumprimento'], 'RN06: cumprida exige a data');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['data_cumprimento' => '2026-09-01'] + $base), 'cumprimento antes do recebimento');
    $t->contem($e->erros()['data_cumprimento'] ?? '', 'anterior ao recebimento', 'mensagem');
    $t->lanca(ValidationException::class, fn() => $servico->criar(['processo_tipo' => 'OUTRO'] + $base), 'tipo de processo fora da lista');
    $entrarComo(null);
});

$t->teste('intimado ≠ recebedor, autoria própria, id próprio e multiplicidade (RN03)', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoAgente, $novoJudicial) {
    $exigirBanco($t);
    $usuario = $novoUsuario(Perfil::GESTOR);
    $entrarComo($usuario);
    $intimado = $novoAgente('Fulana Intimada');
    $recebedor = $novoAgente('Beltrano Recebedor');
    $pid = $novoJudicial();
    $servico = new Intimacoes();
    $repo = new IntimacaoPessoalRepository();
    $base = ['processo_tipo' => 'judicial', 'processo_id' => (string)$pid, 'agente_intimado_id' => (string)$intimado,
             'agente_recebedor_id' => (string)$recebedor, 'data_recebimento' => '10/09/2026', 'data_prazo' => '2026-09-25',
             'status' => 'PENDENTE', 'data_cumprimento' => '2026-09-12', 'documento_referencia' => '  SES-OFI-2026/123 ',
             'observacoes' => "Linha 1\n\nLinha 2"];

    $id1 = $servico->criar($base);
    $r = $repo->buscarPorId($id1);
    $t->igual([$r['processo_tipo'], (int)$r['processo_id'], (int)$r['processo_judicial_id'], $r['processo_extrajudicial_id']],
        ['JUDICIAL', $pid, $pid, null], 'vinculado pelo id interno do processo');
    $t->igual([(int)$r['agente_intimado_id'], (int)$r['agente_recebedor_id']], [$intimado, $recebedor], 'pessoas diferentes');
    $t->igual((int)$r['criado_por'], $usuario, 'quem registrou fica à parte do recebedor');
    $t->igual([$r['data_recebimento'], $r['data_cumprimento'], $r['documento_referencia']], ['2026-09-10', null, 'SES-OFI-2026/123'],
        'data normalizada; cumprimento descartado se não cumprida');

    $id2 = $servico->criar(['data_prazo' => '2026-10-05'] + $base);
    $id3 = $servico->criar(['agente_intimado_id' => (string)$recebedor, 'agente_recebedor_id' => (string)$recebedor] + $base);
    $t->afirmar($id1 !== $id2 && $id2 !== $id3, 'cada intimação com identificador próprio');
    $t->igual(count($repo->listarPorProcesso('JUDICIAL', $pid)), 3, 'várias intimações no mesmo processo');
    $t->igual(count($repo->listarPorAgente($intimado, 'intimado')), 2, 'mesmo agente em vários registros');
    $t->igual(count($repo->listarPorAgente($recebedor, 'recebedor')), 3, 'papéis consultados em separado');
    $t->igual(count($repo->listarPorAgente($recebedor, 'intimado')), 1, 'recebedor também intimado uma vez');
    $entrarComo(null);
});

$t->teste('possível duplicidade só grava com confirmação expressa (RN10)', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoAgente, $novoJudicial) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::GESTOR));
    $agente = $novoAgente('Duplicidade Agente');
    $pid = $novoJudicial();
    $servico = new Intimacoes();
    $base = ['processo_tipo' => 'JUDICIAL', 'processo_id' => (string)$pid, 'agente_intimado_id' => (string)$agente,
             'agente_recebedor_id' => (string)$agente, 'data_recebimento' => '2026-08-01', 'data_prazo' => '2026-08-11', 'status' => 'PENDENTE'];
    $primeira = $servico->criar($base);
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($base), 'mesma combinação');
    $t->contem($e->erros()['confirmar_duplicidade'] ?? '', "nº {$primeira}", 'aponta a intimação existente');
    $segunda = $servico->criar($base + ['confirmar_duplicidade' => '1']);
    $t->afirmar($segunda > $primeira, 'com confirmação, grava');
    $servico->criar(['data_prazo' => '2026-08-12'] + $base);
    $t->afirmar(true, 'prazo diferente não é duplicidade');

    // Excluída não conta como duplicidade.
    $servico->excluir($primeira, 'teste');
    $servico->excluir($segunda, 'teste');
    $servico->criar($base);
    $t->afirmar(true, 'sem ativas iguais, grava sem confirmação');
    $entrarComo(null);
});

$t->teste('processos extrajudiciais e administrativos também são aceitos', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoAgente) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::GESTOR));
    $agente = $novoAgente('Tipos Agente');
    $mp = (string)Database::conexao()->query("SELECT id FROM instituicoes_externas WHERE sigla = 'MP-MT'")->fetchColumn();
    $pe = (new ProcessoExtrajudicialService())->criar(['protocolo' => 'SIMP 000777-001/2026', 'data_expediente' => '2026-09-20',
        'instituicao_externa_id' => $mp, 'assunto' => 'Requisição de informações', 'situacao' => 'PENDENTE_RESPOSTA']);
    $pa = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/77001', 'data_abertura' => '2026-09-01']);
    $servico = new Intimacoes();
    $repo = new IntimacaoPessoalRepository();
    $base = ['agente_intimado_id' => (string)$agente, 'agente_recebedor_id' => (string)$agente,
             'data_recebimento' => '2026-09-21', 'data_prazo' => '2026-10-01', 'status' => 'EM_ANDAMENTO'];
    $ie = $servico->criar(['processo_tipo' => 'EXTRAJUDICIAL', 'processo_id' => (string)$pe] + $base);
    $ia = $servico->criar(['processo_tipo' => 'ADMINISTRATIVO', 'processo_id' => (string)$pa] + $base);
    $t->igual([$repo->buscarPorId($ie)['processo_numero'], $repo->buscarPorId($ie)['processo_instituicao_sigla']],
        ['SIMP 000777-001/2026', 'MP-MT'], 'extrajudicial pelo protocolo da instituição');
    $t->igual($repo->buscarPorId($ia)['processo_numero'], 'SES-PRO-2026/77001', 'administrativo pelo protocolo SIGADOC');

    $e = $t->lanca(ValidationException::class, fn() => (new ProcessoExtrajudicialService())->excluir($pe), 'extrajudicial com intimação');
    $t->contem($e->getMessage(), '1 intimação', 'exclusão do processo recusada');
    $servico->excluir($ia, 'teste');
    $e = $t->lanca(ValidationException::class, fn() => (new ProcessoAdministrativoService())->excluir($pa), 'administrativo com intimação excluída');
    $t->contem($e->getMessage(), 'inclusive as excluídas', 'histórico preservado');
    $entrarComo(null);
});

$t->grupo('Intimações — alteração, cumprimento e exclusão');

$t->teste('retificar o prazo exige justificativa e fica no histórico (RN08)', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoAgente, $novoJudicial) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::ADMINISTRADOR));
    $intimado = $novoAgente('Histórico Intimado');
    $outro = $novoAgente('Histórico Outro');
    $pid = $novoJudicial();
    $servico = new Intimacoes();
    $base = ['processo_tipo' => 'JUDICIAL', 'processo_id' => (string)$pid, 'agente_intimado_id' => (string)$intimado,
             'agente_recebedor_id' => (string)$intimado, 'data_recebimento' => '2026-09-01', 'data_prazo' => '2026-09-15', 'status' => 'PENDENTE'];
    $id = $servico->criar($base);

    $e = $t->lanca(ValidationException::class, fn() => $servico->atualizar($id, ['data_prazo' => '2026-09-20'] + $base), 'prazo sem justificativa');
    $t->igual(array_keys($e->erros()), ['justificativa'], 'pede a justificativa');
    $servico->atualizar($id, ['data_prazo' => '2026-09-20', 'agente_recebedor_id' => (string)$outro,
                              'justificativa' => 'Prazo digitado com erro.'] + $base);
    $servico->atualizar($id, ['agente_recebedor_id' => (string)$outro, 'data_prazo' => '2026-09-20', 'observacoes' => 'Sem mudar o prazo.'] + $base);
    $t->afirmar(true, 'sem mudar o prazo, a justificativa não é exigida');

    $historico = (new AuditoriaRepository())->listarPorEntidade(Intimacoes::ENTIDADE, $id);
    $acoes = array_column($historico, 'acao');
    $t->igual($acoes, ['INTIMACAO_EDITADA', 'INTIMACAO_EDITADA', 'INTIMACAO_CRIADA'], 'cadastro e cada alteração');
    $retificacao = json_decode((string)$historico[1]['dados_novos'], true);
    $t->igual([$retificacao['data_prazo'] ?? null, $retificacao['justificativa'] ?? null], ['2026-09-20', 'Prazo digitado com erro.'], 'novo prazo e justificativa');
    $t->contem((string)($retificacao['agente_recebedor'] ?? ''), 'Histórico Outro', 'agente recebedor pelo nome');
    $t->contem((string)$historico[1]['dados_anteriores'], '2026-09-15', 'prazo anterior guardado');
    $t->afirmar(!isset($retificacao['descricao']), 'só o que mudou');
    $entrarComo(null);
});

$t->teste('só administradores e gestores registram e alteram; cumprimento fora do prazo', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoAgente, $novoJudicial, $dias) {
    $exigirBanco($t);
    $autor = $novoUsuario(Perfil::ADMINISTRADOR);
    $outro = $novoUsuario(Perfil::USUARIO);
    $gestor = $novoUsuario(Perfil::GESTOR);
    $entrarComo($autor);
    $agente = $novoAgente('Permissões Agente');
    $pid = $novoJudicial();
    $servico = new Intimacoes();
    $repo = new IntimacaoPessoalRepository();
    $id = $servico->criar(['processo_tipo' => 'JUDICIAL', 'processo_id' => (string)$pid, 'agente_intimado_id' => (string)$agente,
        'agente_recebedor_id' => (string)$agente, 'data_recebimento' => $dias(-10), 'data_prazo' => $dias(-3), 'status' => 'PENDENTE']);
    $t->igual(Intimacoes::situacao($repo->buscarPorId($id)), 'VENCIDA', 'vencida');

    $entrarComo($outro);
    $t->afirmar(!Intimacoes::podeAcessar(), 'perfil Usuário sem acesso ao módulo');
    $t->lanca(AcessoNegadoException::class, fn() => $servico->criar(['status' => 'PENDENTE']), 'Usuário não registra');
    $t->lanca(AcessoNegadoException::class, fn() => $servico->atualizar($id, []), 'Usuário não altera');
    $t->lanca(AcessoNegadoException::class, fn() => $servico->registrarCumprimento($id, $dias(0)), 'Usuário não registra cumprimento');
    $t->lanca(AcessoNegadoException::class, fn() => $servico->excluir($id, 'x'), 'Usuário não exclui');
    $t->afirmar(!Intimacoes::podeAlterar($repo->buscarPorId($id)), 'botões ocultos para ele');

    $entrarComo($gestor);
    $e = $t->lanca(ValidationException::class, fn() => $servico->registrarCumprimento($id, $dias(1)), 'cumprimento futuro');
    $t->afirmar(isset($e->erros()['data_cumprimento']), 'erro no campo do modal');
    $servico->registrarCumprimento($id, $dias(-1));
    $r = $repo->buscarPorId($id);
    $t->igual([$r['status'], $r['data_cumprimento']], ['CUMPRIDA', $dias(-1)], 'gestor registra o cumprimento do registro do administrador');
    $t->igual(Intimacoes::situacao($r), 'CUMPRIDA_FORA_PRAZO', 'sinalizada como cumprida fora do prazo');
    $t->igual((int)$r['atualizado_por'], $gestor, 'última alteração com o autor');

    $entrarComo($autor);
    $servico->atualizar($id, ['processo_tipo' => 'JUDICIAL', 'processo_id' => (string)$pid, 'agente_intimado_id' => (string)$agente,
        'agente_recebedor_id' => (string)$agente, 'data_recebimento' => $dias(-10), 'data_prazo' => $dias(-3), 'status' => 'CANCELADA',
        'data_cumprimento' => $dias(-1)]);
    $r = $repo->buscarPorId($id);
    $t->igual([$r['status'], $r['data_cumprimento'], Intimacoes::situacao($r)], ['CANCELADA', null, null], 'cancelada: sem cumprimento nem situação');
    $t->lanca(ValidationException::class, fn() => $servico->registrarCumprimento($id, $dias(0)), 'cancelada não recebe cumprimento direto');
    $entrarComo(null);
});

$t->teste('exclusão lógica: motivo obrigatório, sai do acompanhamento e mantém o registro (RN09)', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoAgente, $novoJudicial) {
    $exigirBanco($t);
    $usuario = $novoUsuario(Perfil::GESTOR);
    $entrarComo($usuario);
    $agente = $novoAgente('Exclusão Agente');
    $pid = $novoJudicial();
    $servico = new Intimacoes();
    $repo = new IntimacaoPessoalRepository();
    $id = $servico->criar(['processo_tipo' => 'JUDICIAL', 'processo_id' => (string)$pid, 'agente_intimado_id' => (string)$agente,
        'agente_recebedor_id' => (string)$agente, 'data_recebimento' => '2026-09-01', 'data_prazo' => '2026-09-30', 'status' => 'PENDENTE']);
    $e = $t->lanca(ValidationException::class, fn() => $servico->excluir($id, '  '), 'sem motivo');
    $t->afirmar(isset($e->erros()['motivo']), 'erro no motivo');

    $servico->excluir($id, 'Registro em duplicidade.');
    $r = $repo->buscarPorId($id);
    $t->afirmar($r !== null && $r['excluida_em'] !== null, 'registro preservado e marcado');
    $t->igual([(int)$r['excluida_por'], $r['motivo_exclusao']], [$usuario, 'Registro em duplicidade.'], 'quem e por quê');
    $t->igual($repo->listarPorProcesso('JUDICIAL', $pid), [], 'fora da ficha do processo');
    $t->igual($repo->listar(['processo_tipo' => 'JUDICIAL', 'processo_id' => $pid], 1, 20)->total, 0, 'fora da listagem');
    $t->lanca(ValidationException::class, fn() => $servico->excluir($id, 'de novo'), 'não exclui duas vezes');
    $t->lanca(ValidationException::class, fn() => $servico->registrarCumprimento($id, '2026-09-10'), 'excluída não é alterada');
    $t->afirmar(!Intimacoes::podeAlterar($r), 'excluída é só consulta');
    $acoes = array_column((new AuditoriaRepository())->listarPorEntidade(Intimacoes::ENTIDADE, $id), 'acao');
    $t->igual($acoes, ['INTIMACAO_EXCLUIDA', 'INTIMACAO_CRIADA'], 'auditoria da exclusão');
    $e = $t->lanca(ValidationException::class, fn() => (new ProcessoJudicialService())->excluir($pid), 'processo com intimação excluída');
    $t->contem($e->getMessage(), 'intimação', 'processo não sai em cascata');
    $entrarComo(null);
});

$t->grupo('Intimações — painel e filtros');

$t->teste('indicadores e filtros combináveis refletem os registros', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoAgente, $novoJudicial, $dias) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::GESTOR));
    $repo = new IntimacaoPessoalRepository();
    $hoje = date('Y-m-d');
    $limite = Intimacoes::limiteAlerta($hoje);
    $antes = $repo->indicadores($hoje, $limite);

    $agente = $novoAgente('Painel Zacarias');
    $outro = $novoAgente('Painel Outro');
    $pid = $novoJudicial();
    $servico = new Intimacoes();
    $base = ['processo_tipo' => 'JUDICIAL', 'processo_id' => (string)$pid, 'agente_intimado_id' => (string)$agente,
             'agente_recebedor_id' => (string)$outro, 'data_recebimento' => $dias(-20)];
    $vencida = $servico->criar(['data_prazo' => $dias(-2), 'status' => 'PENDENTE'] + $base);
    $proxima = $servico->criar(['data_prazo' => $dias(2), 'status' => 'EM_ANDAMENTO'] + $base);
    $servico->criar(['data_prazo' => $dias(30), 'status' => 'PENDENTE', 'documento_referencia' => 'SES-OFI-2026/PAINEL'] + $base);
    $servico->criar(['data_prazo' => $dias(-10), 'status' => 'CUMPRIDA', 'data_cumprimento' => $dias(-5)] + $base);
    $servico->criar(['data_prazo' => $dias(-11), 'status' => 'CUMPRIDA', 'data_cumprimento' => $dias(-12)] + $base);
    $servico->criar(['data_prazo' => $dias(-9), 'status' => 'CANCELADA'] + $base);

    $depois = $repo->indicadores($hoje, $limite);
    $delta = array_map(static fn(string $k): int => $depois[$k] - $antes[$k], array_combine(array_keys($depois), array_keys($depois)));
    $t->igual($delta, ['total' => 6, 'pendentes' => 2, 'em_andamento' => 1, 'proximas' => 1, 'vencidas' => 1,
                       'cumpridas' => 2, 'fora_prazo' => 1, 'canceladas' => 1], 'indicadores calculados dos registros');

    $listar = static fn(array $f): array => array_map('intval', array_column(
        $repo->listar($f + ['processo_tipo' => 'JUDICIAL', 'processo_id' => $pid, 'hoje' => $hoje, 'limite_alerta' => $limite], 1, 50)->itens, 'id'));
    $t->igual(count($listar([])), 6, 'todas do processo');
    $t->igual($listar([])[0], $vencida, 'prioridade: vencida primeiro');
    $t->igual($listar([])[1], $proxima, 'depois a próxima do vencimento');
    $t->igual($listar(['situacao' => 'VENCIDA']), [$vencida], 'filtro vencidas');
    $t->igual($listar(['situacao' => 'PROXIMA']), [$proxima], 'filtro próximas');
    $t->igual(count($listar(['situacao' => 'CUMPRIDA_FORA_PRAZO'])), 1, 'cumpridas fora do prazo');
    $t->igual(count($listar(['status' => 'PENDENTE', 'situacao' => 'NO_PRAZO'])), 1, 'status e situação combinados');
    $t->igual(count($listar(['busca' => 'zacarias'])), 6, 'pesquisa pelo nome do intimado');
    $t->igual(count($listar(['busca' => 'OFI-2026/PAINEL'])), 1, 'pesquisa pelo documento');
    $t->igual(count($listar(['agente_id' => $outro])), 6, 'agente como recebedor');
    $t->igual(count($listar(['prazo_de' => $dias(0), 'prazo_ate' => $dias(5)])), 1, 'intervalo de vencimento');
    $t->igual(count($listar(['recebido_de' => $dias(-1)])), 0, 'período de recebimento');
    $t->igual(count($listar(['tipo' => 'EXTRAJUDICIAL'])), 0, 'tipo de processo');
    $pagina = $repo->listar(['processo_tipo' => 'JUDICIAL', 'processo_id' => $pid], 2, 4);
    $t->igual([$pagina->total, count($pagina->itens), $pagina->totalPaginas], [6, 2, 2], 'paginação');
    $entrarComo(null);
});

$t->teste('pesquisa de agentes: nome ou matrícula, sem dados pessoais', function ($t) use ($exigirBanco, $novoAgente) {
    $exigirBanco($t);
    $id = $novoAgente('Pesquisável Quintino');
    $porNome = (new AgentePublicoRepository())->pesquisar('quintino');
    $t->igual(array_map('intval', array_column($porNome, 'id')), [$id], 'por nome');
    $matricula = (new AgentePublicoRepository())->buscarPorId($id)['matricula'];
    $t->afirmar(in_array($id, array_map('intval', array_column((new AgentePublicoRepository())->pesquisar(strtolower($matricula)), 'id')), true), 'por matrícula');
    $t->afirmar(!array_key_exists('cpf', $porNome[0]) && !array_key_exists('email_institucional', $porNome[0]), 'sem CPF nem e-mail');
    $item = Intimacoes::descreverAgente($porNome[0]);
    $t->contem($item['detalhe'], 'Mat. ' . $matricula, 'detalhe com matrícula');
});

$t->grupo('Intimações — páginas');

$t->teste('painel, ficha, formulário e integrações renderizam', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoAgente, $novoJudicial, $dias) {
    $exigirBanco($t);
    $admin = $novoUsuario(Perfil::ADMINISTRADOR);
    $entrarComo($admin);
    $agente = $novoAgente('Página Intimada');
    $recebedor = $novoAgente('Página Recebedora');
    $pid = $novoJudicial();
    $pa = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/77002']);
    $servico = new Intimacoes();
    $id = $servico->criar(['processo_tipo' => 'JUDICIAL', 'processo_id' => (string)$pid, 'agente_intimado_id' => (string)$agente,
        'agente_recebedor_id' => (string)$recebedor, 'data_recebimento' => $dias(-5), 'data_prazo' => $dias(-1), 'status' => 'PENDENTE',
        'observacoes' => '<script>alert(1)</script>']);
    $servico->criar(['processo_tipo' => 'ADMINISTRATIVO', 'processo_id' => (string)$pa, 'agente_intimado_id' => (string)$agente,
        'agente_recebedor_id' => (string)$agente, 'data_recebimento' => $dias(0), 'data_prazo' => $dias(10), 'status' => 'PENDENTE']);

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

    $painel = $renderizar('intimacoes-pessoais.php', ['situacao' => 'VENCIDA']);
    $t->contem($painel, 'Nova Intimação', 'botão de destaque');
    $t->contem($painel, 'Cumpridas fora do prazo', 'indicadores');
    $t->contem($painel, 'is-ativo', 'indicador do filtro ativo marcado');
    $t->contem($painel, 'id="intimacao-' . $id . '"', 'vencida listada');
    $t->contem($painel, 'class="is-vencida"', 'prioridade visual da vencida');
    $t->contem($painel, 'Vencida há 1 dia', 'dias restantes');
    $t->contem($painel, 'id="modalCumprimentoIntimacao"', 'modal de cumprimento');

    $ficha = $renderizar('intimacao.php', ['id' => (string)$id]);
    $t->contem($ficha, 'Página Recebedora', 'recebedor na ficha');
    $t->contem($ficha, '&lt;script&gt;', 'observação escapada (XSS)');
    $t->afirmar(!str_contains($ficha, '<script>alert(1)'), 'sem script injetado');
    $t->contem($ficha, 'Intimação registrada', 'histórico');
    $t->contem($ficha, 'agente-publico.php?id=' . $agente, 'administrador acessa a ficha do agente');

    $form = $renderizar('nova-intimacao.php', ['processo_tipo' => 'JUDICIAL', 'processo_id' => (string)$pid]);
    $t->contem($form, 'name="processo_id" value="' . $pid . '"', 'processo pré-selecionado');
    $t->contem($form, 'name="processo_tipo" value="JUDICIAL"', 'tipo pré-selecionado');
    $t->contem($form, 'name="agente_recebedor_id" value=""', 'recebedor não preenchido automaticamente');
    $t->contem($renderizar('editar-intimacao.php', ['id' => (string)$id]), 'data-original="' . $dias(-1) . '"', 'edição guarda o prazo original');

    $t->contem($renderizar('processo-judicial.php', ['id' => (string)$pid]), 'id="secao-intimacoes"', 'seção na ficha judicial');
    $fichaPa = $renderizar('processo-administrativo.php', ['id' => (string)$pa]);
    $t->contem($fichaPa, 'data-aba="intimacoes"', 'guia na ficha administrativa');
    $t->contem($fichaPa, 'processo_tipo=ADMINISTRATIVO', 'nova intimação com o processo escolhido');
    $fichaAgente = $renderizar('agente-publico.php', ['id' => (string)$agente]);
    $t->contem($fichaAgente, 'Como pessoa intimada', 'guia de intimações do agente');
    $t->contem($fichaAgente, 'Como responsável pelo recebimento', 'papéis separados');
    $t->contem($painel, 'intimacoes-pessoais.php"', 'menu com o painel para o administrador');

    $entrarComo($novoUsuario(Perfil::GESTOR));
    $t->contem($renderizar('intimacoes-pessoais.php', []), 'id="intimacao-' . $id . '"', 'gestor vê o painel');
    $t->contem($renderizar('editar-intimacao.php', ['id' => (string)$id]), 'Salvar alterações', 'gestor edita');

    $entrarComo($novoUsuario(Perfil::USUARIO));
    foreach (['intimacoes-pessoais.php' => [], 'intimacao.php' => ['id' => (string)$id], 'nova-intimacao.php' => [],
              'editar-intimacao.php' => ['id' => (string)$id]] as $pagina => $get) {
        $t->lanca(AcessoNegadoException::class, fn() => $renderizar($pagina, $get), "Usuário barrado em {$pagina}");
    }
    $fichaUsuario = $renderizar('processo-judicial.php', ['id' => (string)$pid]);
    $t->afirmar(!str_contains($fichaUsuario, 'secao-intimacoes'), 'ficha judicial sem a seção para o Usuário');
    $t->afirmar(!str_contains($fichaUsuario, 'intimacoes-pessoais.php'), 'menu sem o painel para o Usuário');
    $t->afirmar(!str_contains($renderizar('processo-administrativo.php', ['id' => (string)$pa]), 'data-aba="intimacoes"'), 'ficha administrativa sem a guia');
    $_GET = [];
    $entrarComo(null);
});
