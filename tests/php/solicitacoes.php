<?php
declare(strict_types=1);

/**
 * Solicitações dos processos administrativos (pedidos a outras unidades).
 *
 * @var \Tests\Runner $t
 */

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\LotacaoRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\SolicitacaoRepository;
use App\Services\PesquisaService;
use App\Services\ProcessoAdministrativoService;
use App\Services\SolicitacaoService;
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
        'nome'       => "Pessoa Solicitações {$seq}",
        'email'      => "solicitacoes{$seq}@ses.mt.gov.br",
        'matricula'  => 'SOL-' . $seq,
        'cargo_id'   => (string)$db->query("SELECT id FROM cargos WHERE nome = 'Analista'")->fetchColumn(),
        'lotacao_id' => (string)$db->query("SELECT id FROM lotacoes WHERE nome = 'Unidade Jurídica'")->fetchColumn(),
        'perfil'     => $perfil,
        'data_nascimento' => '',
    ]);
};

$lotacao = static function (string $nome): int {
    return (int)Database::conexao()->query('SELECT id FROM lotacoes WHERE nome = ' . Database::conexao()->quote($nome))->fetchColumn();
};

$t->grupo('Solicitações — regras');

$t->teste('status, badge e vencimento só para aguardando com prazo expirado', function ($t) {
    $t->igual(array_keys(SolicitacaoService::STATUS), ['AGUARDANDO', 'ATENDIDO', 'ENCERRADO'], 'status');
    $t->igual(SolicitacaoService::classeBadge('ATENDIDO'), 'badge--verde', 'badge atendido');
    $hoje = '2026-09-29';
    $t->afirmar(SolicitacaoService::vencida(['status' => 'AGUARDANDO', 'prazo' => '2026-09-28'], $hoje), 'ontem: vencida');
    $t->afirmar(!SolicitacaoService::vencida(['status' => 'AGUARDANDO', 'prazo' => '2026-09-29'], $hoje), 'hoje ainda não venceu');
    $t->afirmar(!SolicitacaoService::vencida(['status' => 'ATENDIDO', 'prazo' => '2026-09-01'], $hoje), 'atendida não vence');
    $t->afirmar(!SolicitacaoService::vencida(['status' => 'AGUARDANDO', 'prazo' => null], $hoje), 'sem prazo não vence');
});

$t->grupo('Solicitações — cadastro');

$t->teste('validação dos campos e criação pelo protocolo', function ($t) use ($exigirBanco, $lotacao) {
    $exigirBanco($t);
    (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/72101']);
    $servico = new SolicitacaoService();
    $gabinete = $lotacao('Gabinete');
    $base = [
        'protocolo' => 'SES-PRO-2026/72101', 'data_solicitacao' => '2026-09-29', 'expediente' => 'SES-OFI-2026/100',
        'destinatario_lotacao_id' => (string)$gabinete, 'assunto' => 'Pedido de informações',
    ];

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['data_solicitacao' => '', 'expediente' => '', 'destinatario_lotacao_id' => '', 'assunto' => ''] + $base), 'campos obrigatórios');
    $t->igual(array_keys($e->erros()), ['data_solicitacao', 'expediente', 'destinatario_lotacao_id', 'assunto'], 'erros de cada campo');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['data_solicitacao' => '31/02/2026'] + $base), 'data inexistente');
    $t->afirmar(isset($e->erros()['data_solicitacao']), 'erro na data');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['prazo' => '2026-09-28'] + $base), 'prazo anterior à data');
    $t->afirmar(isset($e->erros()['prazo']), 'erro no prazo');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['destinatario_lotacao_id' => '9999'] + $base), 'destinatário inexistente');
    $t->afirmar(isset($e->erros()['destinatario_lotacao_id']), 'erro no destinatário');
    $t->lanca(ValidationException::class, fn() => $servico->criar(['status' => 'RESPONDIDA'] + $base), 'status inválido');
    $t->lanca(ValidationException::class, fn() => $servico->criar(['expediente' => str_repeat('A', 61)] + $base), 'expediente longo demais');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['protocolo' => 'SES-PRO-2026/99998'] + $base), 'protocolo não cadastrado');
    $t->afirmar(isset($e->erros()['processo']), 'erro no processo');
    $t->lanca(ValidationException::class, fn() => $servico->criar(['protocolo' => '', 'processo_id' => 999999] + $base), 'processo inexistente');

    $id = $servico->criar(['protocolo' => 'ses-pro-2026/72101', 'expediente' => ' ses-ofi-2026 / 100 ', 'data_solicitacao' => '29/09/2026', 'prazo' => '2026-10-09', 'assunto' => '  Pedido   de informações '] + $base);
    $s = (new SolicitacaoRepository())->buscarPorId($id);
    $t->igual($s['protocolo'], 'SES-PRO-2026/72101', 'processo pelo protocolo');
    $t->igual([$s['data_solicitacao'], $s['prazo']], ['2026-09-29', '2026-10-09'], 'datas normalizadas');
    $t->igual($s['expediente'], 'SES-OFI-2026/100', 'expediente normalizado');
    $t->igual($s['assunto'], 'Pedido de informações', 'assunto normalizado');
    $t->igual($s['status'], 'AGUARDANDO', 'status padrão');
    $t->igual($s['destinatario_nome'], 'Gabinete', 'destinatário pela lotação');
});

$t->teste('prazo da solicitação não altera prazo vigente nem atividade do processo', function ($t) use ($exigirBanco, $lotacao) {
    $exigirBanco($t);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/72102', 'data_abertura' => '2026-09-17']);
    (new SolicitacaoService())->criar([
        'processo_id' => $pid, 'data_solicitacao' => '2026-09-18', 'expediente' => 'SES-OFI-2026/101',
        'destinatario_lotacao_id' => $lotacao('Gerência'), 'assunto' => 'Prazo curto', 'prazo' => '2026-09-20',
    ]);
    $pa = (new ProcessoAdministrativoRepository())->buscarPorId($pid);
    $t->igual([$pa['prazo_vigente'], (int)$pa['ativo']], ['2026-10-17', 0], 'prazo e atividade inalterados');
});

$t->teste('unidade inativa: não aceita em nova, mas a já gravada continua na edição', function ($t) use ($exigirBanco, $lotacao) {
    $exigirBanco($t);
    $lotacoes = new LotacaoRepository();
    $extinta = $lotacoes->inserir(['nome' => 'Unidade Extinta Teste', 'sigla' => 'EXTTESTE']);
    $outraExtinta = $lotacoes->inserir(['nome' => 'Outra Unidade Extinta', 'sigla' => 'EXTTESTE2']);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/72103']);
    $servico = new SolicitacaoService();
    $dados = ['processo_id' => $pid, 'data_solicitacao' => '2026-09-29', 'expediente' => 'SES-OFI-2026/102',
              'destinatario_lotacao_id' => $extinta, 'assunto' => 'Antes da inativação'];
    $id = $servico->criar($dados);

    $lotacoes->definirAtivo($extinta, false);
    $lotacoes->definirAtivo($outraExtinta, false);
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($dados), 'nova com unidade inativa');
    $t->contem($e->erros()['destinatario_lotacao_id'] ?? '', 'inativa', 'mensagem de inativa');

    $servico->atualizar($id, ['status' => 'ATENDIDO'] + $dados);
    $t->igual((new SolicitacaoRepository())->buscarPorId($id)['status'], 'ATENDIDO', 'edição mantém a unidade já gravada');
    $t->lanca(ValidationException::class, fn() => $servico->atualizar($id, ['destinatario_lotacao_id' => $outraExtinta] + $dados), 'trocar para outra inativa');
    $servico->atualizar($id, ['destinatario_lotacao_id' => $lotacao('Gabinete')] + $dados);
    $t->igual((new SolicitacaoRepository())->buscarPorId($id)['destinatario_nome'], 'Gabinete', 'troca para unidade ativa');
});

$t->teste('permissão: administrador, gestor ou quem criou; auditoria no processo', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $lotacao) {
    $exigirBanco($t);
    $autor  = $novoUsuario(Perfil::USUARIO);
    $colega = $novoUsuario(Perfil::USUARIO);
    $gestor = $novoUsuario(Perfil::GESTOR);
    $admin  = $novoUsuario(Perfil::ADMINISTRADOR);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/72104']);
    $servico = new SolicitacaoService();
    $repo = new SolicitacaoRepository();
    $dados = ['processo_id' => $pid, 'data_solicitacao' => '2026-09-29', 'expediente' => 'SES-OFI-2026/103',
              'destinatario_lotacao_id' => $lotacao('Coordenadoria'), 'assunto' => 'Minha solicitação'];

    $entrarComo($autor);
    $id = $servico->criar($dados);
    $t->igual((int)$repo->buscarPorId($id)['criado_por'], $autor, 'autoria registrada');
    $servico->atualizar($id, ['prazo' => '2026-10-15'] + $dados);

    $entrarComo($colega);
    $e = $t->lanca(AcessoNegadoException::class, fn() => $servico->atualizar($id, ['status' => 'ENCERRADO'] + $dados), 'outro usuário não altera');
    $t->igual($e->getMessage(), SolicitacaoService::MSG_SEM_PERMISSAO, 'mensagem de permissão');
    $t->lanca(AcessoNegadoException::class, fn() => $servico->excluir($id), 'outro usuário não exclui');
    $t->afirmar(!SolicitacaoService::podeAlterar($repo->buscarPorId($id)), 'botões ocultos para o colega');
    $idColega = $servico->criar(['assunto' => 'Do colega'] + $dados);

    $entrarComo($gestor);
    $servico->atualizar($id, ['status' => 'ATENDIDO', 'prazo' => '2026-10-15'] + $dados);
    $servico->atualizar($id, ['status' => 'ATENDIDO', 'prazo' => '2026-10-15'] + $dados); // sem mudança: não audita
    $entrarComo($admin);
    $servico->excluir($idColega);

    $entrarComo($autor);
    $servico->excluir($id);
    $t->igual($repo->buscarPorId($id), null, 'autor exclui a própria solicitação');

    $registros = (new AuditoriaRepository())->listarPorEntidade('processos_administrativos', $pid);
    $contagem = array_count_values(array_column($registros, 'acao'));
    ksort($contagem);
    $t->igual($contagem, ['SOLICITACAO_CRIADA' => 2, 'SOLICITACAO_EDITADA' => 2, 'SOLICITACAO_EXCLUIDA' => 2], 'solicitações auditadas no processo');
    $entrarComo(null);
});

$t->teste('listagem: filtros, paginação e ordenação por allowlist', function ($t) use ($exigirBanco, $lotacao) {
    $exigirBanco($t);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/72105']);
    $servico = new SolicitacaoService();
    $gabinete = $lotacao('Gabinete');
    $gerencia = $lotacao('Gerência');
    $ontem = date('Y-m-d', strtotime('-1 day'));
    $base = ['processo_id' => $pid, 'data_solicitacao' => date('Y-m-d', strtotime('-5 days')), 'destinatario_lotacao_id' => $gabinete];

    $vencida  = $servico->criar(['expediente' => 'LISTA-OFI-2026/1', 'assunto' => 'LISTA vencida', 'prazo' => $ontem] + $base);
    $atendida = $servico->criar(['expediente' => 'LISTA-OFI-2026/2', 'assunto' => 'LISTA atendida vencida', 'prazo' => $ontem, 'status' => 'ATENDIDO'] + $base);
    $futura   = $servico->criar(['expediente' => 'LISTA-OFI-2026/3', 'assunto' => 'LISTA futura', 'prazo' => date('Y-m-d', strtotime('+5 days')), 'destinatario_lotacao_id' => $gerencia] + $base);
    $semPrazo = $servico->criar(['expediente' => 'LISTA-OFI-2026/4', 'assunto' => 'LISTA sem prazo', 'status' => 'ENCERRADO'] + $base);

    $repo = new SolicitacaoRepository();
    $ids = static fn($p): array => array_map('intval', array_column($p->itens, 'id'));
    $t->igual($ids($repo->listar(['busca' => 'LISTA', 'vencidas' => true], 1, 50)), [$vencida], 'vencidas: só aguardando');
    $t->igual($ids($repo->listar(['busca' => 'LISTA', 'destinatario' => $gerencia], 1, 50)), [$futura], 'por destinatário');
    $t->igual($ids($repo->listar(['busca' => 'LISTA', 'status' => 'ENCERRADO'], 1, 50)), [$semPrazo], 'por status');
    $t->igual($repo->listar(['busca' => 'LISTA-OFI-2026/3'], 1, 50)->total, 1, 'busca pelo expediente');
    $t->igual($repo->listar(['processo_id' => $pid], 1, 50)->total, 4, 'por processo');

    $pagina = $repo->listar(['processo_id' => $pid, 'ordenar' => 'prazo', 'direcao' => 'ASC'], 2, 3);
    $t->igual([$pagina->total, $pagina->totalPaginas, count($pagina->itens)], [4, 2, 1], 'paginação');
    $t->igual($ids($pagina), [$semPrazo], 'sem prazo por último na ordenação por prazo');
    $t->igual(count($repo->listar(['processo_id' => $pid, 'ordenar' => 's.id; DROP TABLE x', 'direcao' => 'sideways'], 1, 50)->itens), 4, 'ordenação fora da allowlist ignorada');

    $porProcesso = array_map('intval', array_column($repo->listarPorProcesso($pid), 'id'));
    $t->igual(array_slice($porProcesso, 0, 2), [$vencida, $futura], 'ficha: aguardando primeiro, pelo prazo');
    $t->igual($repo->contarVencidas() >= 1, true, 'contagem de vencidas');
    $t->afirmar(in_array($atendida, $ids($repo->listar(['busca' => 'LISTA'], 1, 50)), true), 'sem filtros traz todas');

    $grupos = (new PesquisaService())->pesquisar('LISTA-OFI-2026/3');
    $t->contem($grupos['solicitacoes']['itens'][0]['url'] ?? null, '#solicitacao-' . $futura, 'pesquisa geral leva à ficha');
});

$t->teste('excluir o processo exclui as solicitações (cascata)', function ($t) use ($exigirBanco, $lotacao) {
    $exigirBanco($t);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/72106']);
    $id = (new SolicitacaoService())->criar([
        'processo_id' => $pid, 'data_solicitacao' => '2026-09-29', 'expediente' => 'SES-OFI-2026/104',
        'destinatario_lotacao_id' => $lotacao('Gabinete'), 'assunto' => 'Some com o processo',
    ]);
    (new ProcessoAdministrativoService())->excluir($pid);
    $t->igual((new SolicitacaoRepository())->buscarPorId($id), null, 'solicitação removida com o processo');
});
