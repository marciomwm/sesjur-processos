<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\AcaoRepository;
use App\Services\AcaoService;

$id = entrada_int('id');
$acao = $id !== null ? (new AcaoRepository())->buscarPorId($id) : null;
if ($acao === null) {
    flash('erro', 'Ação não encontrada.');
    redirecionar('acoes.php');
}
if (!AcaoService::podeAlterar($acao)) {
    throw new AcessoNegadoException(AcaoService::MSG_SEM_PERMISSAO);
}

$retorno = Auth::retornoSeguro(
    entrada('retorno', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET)
        ?: 'processo-administrativo.php?id=' . $acao['processo_administrativo_id']
);

$erros = [];
$dados = $acao;
$processo = ['id' => $acao['processo_administrativo_id'], 'protocolo' => $acao['protocolo'], 'objeto' => $acao['processo_objeto']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = [
        'descricao'  => entrada('descricao'),
        'prazo'      => entrada('prazo'),
        'prazo_hora' => entrada('prazo_hora'),
        'status'     => entrada('status'),
        'observacao' => entrada('observacao'),
    ];
    try {
        (new AcaoService())->atualizar($id, $dados);
        flash('sucesso', 'Ação atualizada.');
        redirecionar($retorno . '#acao-' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'acao.editar');
        $erros['_geral'] = "Não foi possível salvar a ação (código {$codigo}).";
    }
}

$titulo      = 'Editar Ação';
$menuAtivo   = 'acoes';
$breadcrumbs = ['Ações' => 'acoes.php', $acao['protocolo'] => 'processo-administrativo.php?id=' . $acao['processo_administrativo_id'], 'Editar ação' => null];
$rotuloSalvar = 'Salvar alterações';

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-acao.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
