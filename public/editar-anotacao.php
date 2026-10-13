<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\AnotacaoRepository;
use App\Services\AnotacaoService;

$id = entrada_int('id');
$anotacao = $id !== null ? (new AnotacaoRepository())->buscarPorId($id) : null;
if ($anotacao === null) {
    flash('erro', 'Anotação não encontrada.');
    redirecionar('anotacoes.php');
}
if (!AnotacaoService::podeAlterar($anotacao)) {
    throw new AcessoNegadoException(AnotacaoService::MSG_SEM_PERMISSAO);
}

$retorno = Auth::retornoSeguro(
    entrada('retorno', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET)
        ?: 'processo-administrativo.php?id=' . $anotacao['processo_administrativo_id']
);

$erros = [];
$dados = $anotacao;
$processo = ['id' => $anotacao['processo_administrativo_id'], 'protocolo' => $anotacao['protocolo'], 'objeto' => $anotacao['processo_objeto']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = ['texto' => is_string($_POST['texto'] ?? null) ? $_POST['texto'] : ''];
    try {
        (new AnotacaoService())->atualizar($id, $dados);
        flash('sucesso', 'Anotação atualizada.');
        redirecionar($retorno . '#anotacao-' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'anotacao.editar');
        $erros['_geral'] = "Não foi possível salvar a anotação (código {$codigo}).";
    }
}

$titulo      = 'Editar Anotação';
$menuAtivo   = 'anotacoes';
$breadcrumbs = ['Anotações' => 'anotacoes.php', $anotacao['protocolo'] => 'processo-administrativo.php?id=' . $anotacao['processo_administrativo_id'], 'Editar anotação' => null];
$rotuloSalvar = 'Salvar alterações';

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-anotacao.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
