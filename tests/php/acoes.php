<?php
declare(strict_types=1);

/**
 * Prazo e atividade do processo administrativo, Ações (tarefas) e a
 * pesquisa geral da barra superior.
 *
 * @var \Tests\Runner $t
 */

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\Prazo;
use App\Core\ValidationException;
use App\Repositories\AcaoRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Services\AcaoService;
use App\Services\PesquisaService;
use App\Services\ProcessoAdministrativoService;
use App\Services\ProcessoJudicialService;
use App\Services\UsuarioService;

$t->grupo('Prazo — regras');

$t->teste('prazo padrão: abertura + 30 dias (ou hoje + 30 sem abertura)', function ($t) {
    $t->igual(Prazo::padrao('2026-09-17'), '2026-10-17', 'abertura + 30');
    $t->igual(Prazo::padrao('2026-01-31'), '2026-03-02', 'atravessa fevereiro');
    $t->igual(Prazo::padrao(null, '2026-09-28'), '2026-10-28', 'sem abertura: hoje + 30');
});

$t->teste('hora opcional: formatos aceitos e rejeitados', function ($t) {
    $t->igual(Prazo::hora('14:30'), '14:30:00', 'HH:MM');
    $t->igual(Prazo::hora('9h'), '09:00:00', '9h');
    $t->igual(Prazo::hora('08:05:00'), '08:05:00', 'com segundos');
    $t->igual(Prazo::hora(''), null, 'vazia');
    $t->igual(Prazo::hora(null), null, 'nula');
    $t->igual(Prazo::hora('25:00'), false, 'hora inexistente');
    $t->igual(Prazo::hora('amanhã'), false, 'texto');
});

$t->teste('prazo vigente: o mais próximo entre o do processo e o das ações abertas', function ($t) {
    $proprio = Prazo::vigente('2026-10-17', null, []);
    $t->igual($proprio, ['prazo' => '2026-10-17', 'prazo_hora' => null, 'acao_id' => null], 'sem ações: prazo próprio');

    $acoes = [
        ['id' => 1, 'prazo' => '2026-10-20', 'prazo_hora' => null],
        ['id' => 2, 'prazo' => '2026-10-05', 'prazo_hora' => '15:00:00'],
        ['id' => 3, 'prazo' => '2026-10-05', 'prazo_hora' => '09:00:00'],
        ['id' => 4, 'prazo' => null, 'prazo_hora' => null],
    ];
    $t->igual(Prazo::vigente('2026-10-17', null, $acoes), ['prazo' => '2026-10-05', 'prazo_hora' => '09:00:00', 'acao_id' => 3], 'ação mais próxima, desempate pela hora');
    $t->igual(Prazo::vigente('2026-10-17', null, [$acoes[0]])['acao_id'], null, 'ação posterior não prevalece');
    $t->igual(Prazo::vigente('2026-10-05', null, [['id' => 9, 'prazo' => '2026-10-05', 'prazo_hora' => '18:00:00']])['acao_id'], 9, 'mesmo dia com hora vence antes do fim do dia');
    $t->igual(Prazo::vigente(null, null, [$acoes[0]])['prazo'], '2026-10-20', 'processo sem prazo próprio');
});

$t->teste('situação do prazo para exibição', function ($t) {
    $agora = new DateTimeImmutable('2026-09-28 10:00:00');
    $t->igual(Prazo::situacao('2026-09-27', null, $agora), 'vencido', 'ontem');
    $t->igual(Prazo::situacao('2026-09-28', '09:00:00', $agora), 'vencido', 'hoje, hora passada');
    $t->igual(Prazo::situacao('2026-09-28', null, $agora), 'hoje', 'hoje até o fim do dia');
    $t->igual(Prazo::situacao('2026-09-30', null, $agora), 'proximo', 'em 2 dias');
    $t->igual(Prazo::situacao('2026-10-20', null, $agora), 'no_prazo', 'distante');
    $t->igual(Prazo::situacao(null, null, $agora), null, 'sem prazo');
    $t->igual(Prazo::formatar('2026-10-05', '09:00:00'), '05/10/2026 09:00', 'formatação com hora');
});

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
        'nome'       => "Pessoa Ações {$seq}",
        'email'      => "acoes{$seq}@ses.mt.gov.br",
        'matricula'  => 'ACO-' . $seq,
        'cargo_id'   => (string)$db->query("SELECT id FROM cargos WHERE nome = 'Analista'")->fetchColumn(),
        'lotacao_id' => (string)$db->query("SELECT id FROM lotacoes WHERE nome = 'Unidade Jurídica'")->fetchColumn(),
        'perfil'     => $perfil,
        'data_nascimento' => '',
    ]);
};

$t->grupo('Processo Administrativo — prazo e atividade');

$t->teste('prazo padrão, prazo informado e validações', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $servico = new ProcessoAdministrativoService();
    $repo = new ProcessoAdministrativoRepository();

    $id = $servico->criar(['protocolo' => 'SES-PRO-2026/70001', 'data_abertura' => '2026-09-17']);
    $pa = $repo->buscarPorId($id);
    $t->igual($pa['prazo'], '2026-10-17', 'padrão: abertura + 30 dias');
    $t->igual($pa['prazo_hora'], null, 'sem hora');
    $t->igual($pa['prazo_vigente'], '2026-10-17', 'vigente = próprio sem ações');
    $t->igual((int)$pa['ativo'], 0, 'sem ações: inativo');

    $id2 = $servico->criar(['protocolo' => 'SES-PRO-2026/70002', 'data_abertura' => '17/09/2026', 'prazo' => '01/10/2026', 'prazo_hora' => '16:00']);
    $pa2 = $repo->buscarPorId($id2);
    $t->igual([$pa2['prazo'], $pa2['prazo_hora']], ['2026-10-01', '16:00:00'], 'prazo e hora informados');
    $t->igual([$pa2['prazo_vigente'], $pa2['prazo_vigente_hora']], ['2026-10-01', '16:00:00'], 'vigente com hora');

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['protocolo' => 'SES-PRO-2026/70003', 'prazo_hora' => '99:00']), 'hora inválida');
    $t->afirmar(isset($e->erros()['prazo_hora']), 'erro na hora');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['protocolo' => 'SES-PRO-2026/70003', 'data_abertura' => '2026-09-17', 'prazo' => '2026-09-01']), 'prazo antes da abertura');
    $t->afirmar(isset($e->erros()['prazo']), 'erro no prazo');

    $id3 = $servico->criar(['protocolo' => 'SES-PRO-2026/70003']);
    $t->igual($repo->buscarPorId($id3)['prazo'], Prazo::padrao(null), 'sem abertura: hoje + 30');
});

$t->grupo('Ações — prazo vigente e atividade do processo');

$t->teste('ação com prazo menor prevalece até ser finalizada; atividade acompanha as ações', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $repo = new ProcessoAdministrativoRepository();
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/71001', 'data_abertura' => '2026-09-17']);
    $acoes = new AcaoService();
    $estado = static function () use ($repo, $pid): array {
        $p = $repo->buscarPorId($pid);
        return [$p['prazo_vigente'], $p['prazo_vigente_hora'], (int)$p['ativo']];
    };

    $longe = $acoes->criar(['processo_id' => $pid, 'descricao' => 'Arquivar cópia', 'prazo' => '2026-11-30']);
    $t->igual($estado(), ['2026-10-17', null, 1], 'ação com prazo posterior não muda o prazo; processo ativo');

    $perto = $acoes->criar(['processo_id' => $pid, 'descricao' => '  Pedir   informações ', 'prazo' => '05/10/2026', 'prazo_hora' => '14:00']);
    $t->igual($estado(), ['2026-10-05', '14:00:00', 1], 'ação com prazo menor prevalece');
    $t->igual((new AcaoRepository())->buscarPorId($perto)['descricao'], 'Pedir informações', 'descrição normalizada');

    $maisPerto = $acoes->criar(['processo_id' => $pid, 'descricao' => 'Responder ofício', 'prazo' => '2026-10-05', 'prazo_hora' => '09:30']);
    $t->igual($estado(), ['2026-10-05', '09:30:00', 1], 'entre várias, a mais próxima do vencimento');

    $acoes->alterarStatus($maisPerto, 'CONCLUIDA');
    $t->igual($estado(), ['2026-10-05', '14:00:00', 1], 'finalizada deixa de contar');
    $t->afirmar((new AcaoRepository())->buscarPorId($maisPerto)['finalizada_em'] !== null, 'data de finalização registrada');

    $acoes->alterarStatus($perto, 'CANCELADA');
    $acoes->alterarStatus($longe, 'CONCLUIDA');
    $t->igual($estado(), ['2026-10-17', null, 0], 'todas finalizadas: prazo próprio e inativo');

    $acoes->alterarStatus($perto, 'PENDENTE');
    $t->igual($estado(), ['2026-10-05', '14:00:00', 1], 'reabrir volta a valer');
    $t->igual((new AcaoRepository())->buscarPorId($perto)['finalizada_em'], null, 'reabrir limpa a finalização');

    foreach ([$perto, $maisPerto, $longe] as $a) {
        $acoes->excluir($a);
    }
    $t->igual($estado(), ['2026-10-17', null, 0], 'sem ações: inativo');

    // Alterar o prazo do próprio processo recalcula o vigente.
    $acoes->criar(['processo_id' => $pid, 'descricao' => 'Sem prazo']);
    $t->igual($estado(), ['2026-10-17', null, 1], 'ação sem prazo: ativo, prazo próprio');
    (new ProcessoAdministrativoService())->atualizar($pid, ['protocolo' => 'SES-PRO-2026/71001', 'data_abertura' => '2026-09-17', 'prazo' => '2026-10-10']);
    $t->igual($estado(), ['2026-10-10', null, 1], 'novo prazo do processo refletido');
});

$t->teste('validação da ação e criação pelo protocolo', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/71002']);
    $acoes = new AcaoService();

    $e = $t->lanca(ValidationException::class, fn() => $acoes->criar(['protocolo' => 'SES-PRO-2026/71002', 'descricao' => '']), 'descrição vazia');
    $t->afirmar(isset($e->erros()['descricao']), 'erro na descrição');
    $e = $t->lanca(ValidationException::class, fn() => $acoes->criar(['protocolo' => 'SES-PRO-2026/71002', 'descricao' => 'X', 'prazo_hora' => '10:00']), 'hora sem data');
    $t->afirmar(isset($e->erros()['prazo_hora']), 'erro na hora');
    $t->lanca(ValidationException::class, fn() => $acoes->criar(['protocolo' => 'SES-PRO-2026/71002', 'descricao' => 'X', 'status' => 'FEITA']), 'status inválido');
    $e = $t->lanca(ValidationException::class, fn() => $acoes->criar(['protocolo' => 'SES-PRO-2026/99999', 'descricao' => 'X']), 'protocolo não cadastrado');
    $t->afirmar(isset($e->erros()['processo']), 'erro no processo');
    $t->lanca(ValidationException::class, fn() => $acoes->criar(['processo_id' => 999999, 'descricao' => 'X']), 'processo inexistente');

    $id = $acoes->criar(['protocolo' => 'ses-pro-2026/71002', 'descricao' => 'Pelo protocolo', 'status' => 'CONCLUIDA']);
    $acao = (new AcaoRepository())->buscarPorId($id);
    $t->igual($acao['protocolo'], 'SES-PRO-2026/71002', 'protocolo normalizado');
    $t->afirmar($acao['finalizada_em'] !== null, 'criada já concluída tem data de finalização');
});

$t->teste('permissão: administrador, gestor ou quem criou; auditoria no processo', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo) {
    $exigirBanco($t);
    $autor  = $novoUsuario(Perfil::USUARIO);
    $colega = $novoUsuario(Perfil::USUARIO);
    $gestor = $novoUsuario(Perfil::GESTOR);
    $admin  = $novoUsuario(Perfil::ADMINISTRADOR);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/71003']);
    $acoes = new AcaoService();

    $entrarComo($autor);
    $id = $acoes->criar(['processo_id' => $pid, 'descricao' => 'Minha tarefa']);
    $t->igual((int)(new AcaoRepository())->buscarPorId($id)['criado_por'], $autor, 'autoria registrada');
    $acoes->alterarStatus($id, 'EM_ANDAMENTO');

    $entrarComo($colega);
    $e = $t->lanca(AcessoNegadoException::class, fn() => $acoes->alterarStatus($id, 'CONCLUIDA'), 'outro usuário não altera');
    $t->igual($e->getMessage(), AcaoService::MSG_SEM_PERMISSAO, 'mensagem de permissão');
    $t->lanca(AcessoNegadoException::class, fn() => $acoes->excluir($id), 'outro usuário não exclui');
    $t->afirmar(!AcaoService::podeAlterar((new AcaoRepository())->buscarPorId($id)), 'botões ocultos para o colega');
    $idColega = $acoes->criar(['processo_id' => $pid, 'descricao' => 'Tarefa do colega']);

    $entrarComo($gestor);
    $acoes->atualizar($id, ['descricao' => 'Minha tarefa revisada', 'status' => 'EM_ANDAMENTO']);
    $entrarComo($admin);
    $acoes->alterarStatus($idColega, 'CANCELADA');

    $entrarComo($autor);
    $acoes->excluir($id);
    $t->igual((new AcaoRepository())->buscarPorId($id), null, 'autor exclui a própria ação');

    $auditadas = array_column((new AuditoriaRepository())->listarPorEntidade('processos_administrativos', $pid), 'acao');
    $contagem = array_count_values($auditadas);
    ksort($contagem);
    $t->igual($contagem, ['ACAO_CRIADA' => 2, 'ACAO_EDITADA' => 3, 'ACAO_EXCLUIDA' => 1], 'ações auditadas no processo');
    $entrarComo(null);
});

$t->teste('gerenciador: filtros de status, prazo, autoria e processo', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/71004']);
    $acoes = new AcaoService();
    $ontem = date('Y-m-d', strtotime('-1 day'));
    $vencida  = $acoes->criar(['processo_id' => $pid, 'descricao' => 'FILTRO vencida', 'prazo' => $ontem]);
    $concluida = $acoes->criar(['processo_id' => $pid, 'descricao' => 'FILTRO concluída vencida', 'prazo' => $ontem, 'status' => 'CONCLUIDA']);
    $semana   = $acoes->criar(['processo_id' => $pid, 'descricao' => 'FILTRO semana', 'prazo' => date('Y-m-d', strtotime('+3 days'))]);
    $sem      = $acoes->criar(['processo_id' => $pid, 'descricao' => 'FILTRO sem prazo']);

    $repo = new AcaoRepository();
    $ids = static fn(array $l): array => array_map('intval', array_column($l, 'id'));
    $t->igual($ids($repo->listar(['busca' => 'FILTRO', 'prazo' => 'vencidas'])), [$vencida], 'vencidas: só as abertas');
    $t->igual($ids($repo->listar(['busca' => 'FILTRO', 'prazo' => 'semana'])), [$semana], 'próximos 7 dias');
    $t->igual($ids($repo->listar(['busca' => 'FILTRO', 'prazo' => 'sem_prazo'])), [$sem], 'sem prazo');
    $t->igual(count($repo->listar(['busca' => 'FILTRO', 'status' => 'abertas'])), 3, 'em aberto');
    $t->igual($ids($repo->listar(['busca' => 'FILTRO', 'status' => 'CONCLUIDA'])), [$concluida], 'por status');
    $t->igual(count($repo->listar(['processo_id' => $pid])), 4, 'por processo');
    $t->igual($ids($repo->listar(['processo_id' => $pid]))[3], $sem, 'sem prazo por último');

    $repoPa = new ProcessoAdministrativoRepository();
    $idsPa = static fn($p): array => array_map('intval', array_column($p->itens, 'id'));
    $t->afirmar(in_array($pid, $idsPa($repoPa->listar(['atividade' => 'ativos', 'prazo' => 'vencidos'], 1, 100)), true), 'processo com ação vencida aparece como ativo e vencido');
    $t->afirmar(!in_array($pid, $idsPa($repoPa->listar(['atividade' => 'inativos'], 1, 100)), true), 'não aparece entre os inativos');
    $linha = $repoPa->listar(['busca' => 'SES-PRO-2026/71004'], 1, 10)->itens[0];
    $t->igual((int)$linha['acoes_abertas'], 3, 'contagem de ações abertas na listagem');
});

$t->grupo('Pesquisa geral');

$t->teste('encontra processos, partes e ações; identificador exato abre a ficha', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $pa = (new ProcessoAdministrativoService())->criar([
        'protocolo' => 'SES-PRO-2026/72001', 'objeto' => 'Fornecimento de insulina ZETAPESQUISA',
    ]);
    $pj = (new ProcessoJudicialService())->criar([
        'numero_cnj' => '0800123-48.2026.8.11.0041',
        'classe_judicial' => 'Procedimento Comum Cível',
        'partes' => [['nome' => 'MARIA ZETAPESQUISA', 'polo' => 'ATIVO']],
    ]);
    (new AcaoService())->criar(['processo_id' => $pa, 'descricao' => 'Cobrar resposta ZETAPESQUISA']);

    $servico = new PesquisaService();
    $grupos = $servico->pesquisar('zetapesquisa');
    $t->igual(array_keys($grupos), ['administrativos', 'partes', 'acoes'], 'grupos com resultado');
    $t->igual($grupos['administrativos']['itens'][0]['rotulo'], 'SES-PRO-2026/72001', 'processo pelo objeto');
    $t->contem($grupos['partes']['itens'][0]['url'], 'processo-judicial.php?id=' . $pj, 'parte leva ao processo judicial');
    $t->contem($grupos['acoes']['itens'][0]['url'], '#acao-', 'ação leva à ficha do processo');

    $t->igual(array_keys($servico->pesquisar('08001234820268110041')), ['judiciais'], 'CNJ sem máscara');
    $t->igual($servico->pesquisar('pro-2026/7200')['administrativos']['total'], 1, 'trecho do protocolo');
    $t->igual($servico->pesquisar('z'), [], 'termo curto demais');
    $t->igual($servico->pesquisar('100%'), [], 'curinga do LIKE tratado como texto');

    $t->igual($servico->identificadorExato('ses-pro-2026/72001'), ['tipo' => 'administrativo', 'id' => $pa], 'protocolo exato');
    $t->igual($servico->identificadorExato('08001234820268110041'), ['tipo' => 'judicial', 'id' => $pj], 'CNJ exato');
    $t->igual($servico->identificadorExato('SES-PRO-2026/7200'), null, 'trecho não é exato');

    $t->afirmar(!isset($servico->pesquisar('Pessoa Ações')['usuarios']), 'usuários só para administradores');
    $t->afirmar(isset($servico->pesquisar('Pessoa Ações', 8, true)['usuarios']), 'usuários incluídos quando pedido');
});
