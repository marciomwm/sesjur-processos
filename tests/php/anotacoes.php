<?php
declare(strict_types=1);

/**
 * Anotações dos processos administrativos (notas internas da equipe).
 *
 * @var \Tests\Runner $t
 */

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AnotacaoRepository;
use App\Repositories\AuditoriaRepository;
use App\Services\AnotacaoService;
use App\Services\PesquisaService;
use App\Services\ProcessoAdministrativoService;
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
        'nome'       => "Pessoa Anotações {$seq}",
        'email'      => "anotacoes{$seq}@ses.mt.gov.br",
        'matricula'  => 'ANO-' . $seq,
        'cargo_id'   => (string)$db->query("SELECT id FROM cargos WHERE nome = 'Analista'")->fetchColumn(),
        'lotacao_id' => (string)$db->query("SELECT id FROM lotacoes WHERE nome = 'Unidade Jurídica'")->fetchColumn(),
        'perfil'     => $perfil,
        'data_nascimento' => '',
    ]);
};

$t->grupo('Anotações — regras');

$t->teste('texto: preserva quebras de linha, limpa espaços e linhas em branco extras', function ($t) {
    $t->igual(AnotacaoService::normalizarTexto("  Primeira   linha  \r\n\r\n\r\n\tSegunda\x07 linha\n"), "Primeira linha\n\nSegunda linha", 'normalização');
    $t->igual(AnotacaoService::normalizarTexto(" \n \n "), null, 'só espaços');
    $t->igual(AnotacaoService::normalizarTexto(null), null, 'nulo');
    $t->igual(AnotacaoService::normalizarTexto(['x']), null, 'não texto');
});

$t->grupo('Anotações — cadastro');

$t->teste('validação e criação pelo id ou pelo protocolo', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/72201']);
    $servico = new AnotacaoService();

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['processo_id' => $pid, 'texto' => "  \n "]), 'texto vazio');
    $t->afirmar(isset($e->erros()['texto']), 'erro no texto');
    $t->lanca(ValidationException::class, fn() => $servico->criar(['processo_id' => $pid, 'texto' => str_repeat('a', AnotacaoService::TEXTO_MAX + 1)]), 'texto longo demais');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['protocolo' => 'SES-PRO-2026/99997', 'texto' => 'X']), 'protocolo não cadastrado');
    $t->afirmar(isset($e->erros()['processo']), 'erro no processo');

    $id = $servico->criar(['protocolo' => 'ses-pro-2026/72201', 'texto' => "Ligar para a farmácia\nRetorno em 2 dias"]);
    $n = (new AnotacaoRepository())->buscarPorId($id);
    $t->igual((int)$n['processo_administrativo_id'], $pid, 'processo pelo protocolo');
    $t->igual($n['texto'], "Ligar para a farmácia\nRetorno em 2 dias", 'texto com quebra de linha');
});

$t->teste('permissão: administrador, gestor ou quem criou; auditoria no processo', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo) {
    $exigirBanco($t);
    $autor  = $novoUsuario(Perfil::USUARIO);
    $colega = $novoUsuario(Perfil::USUARIO);
    $gestor = $novoUsuario(Perfil::GESTOR);
    $admin  = $novoUsuario(Perfil::ADMINISTRADOR);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/72202']);
    $servico = new AnotacaoService();
    $repo = new AnotacaoRepository();

    $entrarComo($autor);
    $id = $servico->criar(['processo_id' => $pid, 'texto' => 'Minha nota']);
    $t->igual((int)$repo->buscarPorId($id)['criado_por'], $autor, 'autoria registrada');
    $servico->atualizar($id, ['texto' => 'Minha nota revisada']);
    $servico->atualizar($id, ['texto' => ' Minha nota revisada ']); // mesmo texto: não audita

    $entrarComo($colega);
    $e = $t->lanca(AcessoNegadoException::class, fn() => $servico->atualizar($id, ['texto' => 'Invasão']), 'outro usuário não altera');
    $t->igual($e->getMessage(), AnotacaoService::MSG_SEM_PERMISSAO, 'mensagem de permissão');
    $t->lanca(AcessoNegadoException::class, fn() => $servico->excluir($id), 'outro usuário não exclui');
    $t->afirmar(!AnotacaoService::podeAlterar($repo->buscarPorId($id)), 'botões ocultos para o colega');
    $idColega = $servico->criar(['processo_id' => $pid, 'texto' => 'Nota do colega']);

    $entrarComo($gestor);
    $servico->atualizar($id, ['texto' => 'Revisada pelo gestor']);
    $entrarComo($admin);
    $servico->excluir($idColega);

    $entrarComo($autor);
    $servico->excluir($id);
    $t->igual($repo->buscarPorId($id), null, 'autor exclui a própria anotação');

    $registros = (new AuditoriaRepository())->listarPorEntidade('processos_administrativos', $pid);
    $contagem = array_count_values(array_column($registros, 'acao'));
    ksort($contagem);
    $t->igual($contagem, ['ANOTACAO_CRIADA' => 2, 'ANOTACAO_EDITADA' => 2, 'ANOTACAO_EXCLUIDA' => 2], 'anotações auditadas no processo');
    $entrarComo(null);
});

$t->teste('listagem: filtros, paginação e pesquisa geral', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo) {
    $exigirBanco($t);
    $autor = $novoUsuario(Perfil::USUARIO);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/72203']);
    $servico = new AnotacaoService();
    $a = $servico->criar(['processo_id' => $pid, 'texto' => 'NOTALISTA primeira']);
    $entrarComo($autor);
    $b = $servico->criar(['processo_id' => $pid, 'texto' => 'NOTALISTA segunda']);
    $entrarComo(null);

    $repo = new AnotacaoRepository();
    $ids = static fn($p): array => array_map('intval', array_column($p->itens, 'id'));
    $t->igual($ids($repo->listar(['busca' => 'NOTALISTA'], 1, 50)), [$b, $a], 'mais recentes primeiro');
    $t->igual($ids($repo->listar(['busca' => 'NOTALISTA', 'direcao' => 'ASC'], 1, 50)), [$a, $b], 'mais antigas primeiro');
    $t->igual($ids($repo->listar(['busca' => 'NOTALISTA', 'criado_por' => $autor], 1, 50)), [$b], 'por autor');
    $t->igual($repo->listar(['busca' => 'SES-PRO-2026/72203'], 1, 50)->total, 2, 'busca pelo protocolo');
    $pagina = $repo->listar(['processo_id' => $pid], 2, 1);
    $t->igual([$pagina->total, $pagina->totalPaginas, $ids($pagina)], [2, 2, [$a]], 'paginação');
    $t->igual(array_map('intval', array_column($repo->listarPorProcesso($pid), 'id')), [$b, $a], 'ficha: mais recente primeiro');

    $grupos = (new PesquisaService())->pesquisar('notalista segunda');
    $t->contem($grupos['anotacoes']['itens'][0]['url'] ?? null, '#anotacao-' . $b, 'pesquisa geral leva à ficha');
});

$t->teste('excluir o processo exclui as anotações (cascata)', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/72204']);
    $id = (new AnotacaoService())->criar(['processo_id' => $pid, 'texto' => 'Some com o processo']);
    (new ProcessoAdministrativoService())->excluir($pid);
    $t->igual((new AnotacaoRepository())->buscarPorId($id), null, 'anotação removida com o processo');
});
