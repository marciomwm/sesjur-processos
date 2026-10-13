<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\LotacaoRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Services\SolicitacaoService;

// Com processo_id (vindo da ficha), o processo fica fixo no formulário.
$processoId = entrada_int('processo_id', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);
$processo = $processoId !== null ? (new ProcessoAdministrativoRepository())->buscarPorId($processoId) : null;

$retornoPadrao = $processo !== null ? 'processo-administrativo.php?id=' . $processo['id'] : 'solicitacoes.php';
$retorno = Auth::retornoSeguro(entrada('retorno', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET) ?: $retornoPadrao);

$erros = [];
$dados = ['status' => 'AGUARDANDO', 'data_solicitacao' => date('Y-m-d'), 'protocolo' => entrada('protocolo', $_GET)];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = [
        'processo_id'             => $processo !== null ? (string)$processo['id'] : '',
        'protocolo'               => entrada('protocolo'),
        'data_solicitacao'        => entrada('data_solicitacao'),
        'expediente'              => entrada('expediente'),
        'destinatario_lotacao_id' => entrada('destinatario_lotacao_id'),
        'assunto'                 => entrada('assunto'),
        'prazo'                   => entrada('prazo'),
        'status'                  => entrada('status'),
    ];
    try {
        $id = (new SolicitacaoService())->criar($dados);
        flash('sucesso', 'Solicitação cadastrada.');
        redirecionar($retorno . '#solicitacao-' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'solicitacao.criar');
        $erros['_geral'] = "Não foi possível salvar a solicitação (código {$codigo}).";
    }
}

$lotacoes = (new LotacaoRepository())->opcoes();

$titulo      = 'Nova Solicitação';
$menuAtivo   = 'solicitacoes';
$breadcrumbs = ['Solicitações' => 'solicitacoes.php', 'Nova' => null];
$scripts     = ['acoes.js'];
$rotuloSalvar = 'Salvar solicitação';

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-solicitacao.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
