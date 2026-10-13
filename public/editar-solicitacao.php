<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\LotacaoRepository;
use App\Repositories\SolicitacaoRepository;
use App\Services\SolicitacaoService;

$id = entrada_int('id');
$solicitacao = $id !== null ? (new SolicitacaoRepository())->buscarPorId($id) : null;
if ($solicitacao === null) {
    flash('erro', 'Solicitação não encontrada.');
    redirecionar('solicitacoes.php');
}
if (!SolicitacaoService::podeAlterar($solicitacao)) {
    throw new AcessoNegadoException(SolicitacaoService::MSG_SEM_PERMISSAO);
}

$retorno = Auth::retornoSeguro(
    entrada('retorno', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET)
        ?: 'processo-administrativo.php?id=' . $solicitacao['processo_administrativo_id']
);

$erros = [];
$dados = $solicitacao;
$processo = ['id' => $solicitacao['processo_administrativo_id'], 'protocolo' => $solicitacao['protocolo'], 'objeto' => $solicitacao['processo_objeto']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = [
        'data_solicitacao'        => entrada('data_solicitacao'),
        'expediente'              => entrada('expediente'),
        'destinatario_lotacao_id' => entrada('destinatario_lotacao_id'),
        'assunto'                 => entrada('assunto'),
        'prazo'                   => entrada('prazo'),
        'status'                  => entrada('status'),
    ];
    try {
        (new SolicitacaoService())->atualizar($id, $dados);
        flash('sucesso', 'Solicitação atualizada.');
        redirecionar($retorno . '#solicitacao-' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'solicitacao.editar');
        $erros['_geral'] = "Não foi possível salvar a solicitação (código {$codigo}).";
    }
}

// A unidade já gravada continua na lista mesmo que tenha sido inativada.
$lotacoes = (new LotacaoRepository())->opcoes((int)$solicitacao['destinatario_lotacao_id']);

$titulo      = 'Editar Solicitação';
$menuAtivo   = 'solicitacoes';
$breadcrumbs = ['Solicitações' => 'solicitacoes.php', $solicitacao['protocolo'] => 'processo-administrativo.php?id=' . $solicitacao['processo_administrativo_id'], 'Editar solicitação' => null];
$rotuloSalvar = 'Salvar alterações';

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-solicitacao.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
