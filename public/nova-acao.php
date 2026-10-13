<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Services\AcaoService;

// Com processo_id (vindo da ficha), o processo fica fixo no formulário.
$processoId = entrada_int('processo_id', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);
$processo = $processoId !== null ? (new ProcessoAdministrativoRepository())->buscarPorId($processoId) : null;

$retornoPadrao = $processo !== null ? 'processo-administrativo.php?id=' . $processo['id'] : 'acoes.php';
$retorno = Auth::retornoSeguro(entrada('retorno', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET) ?: $retornoPadrao);

$erros = [];
$dados = ['status' => 'PENDENTE', 'protocolo' => entrada('protocolo', $_GET)];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = [
        'processo_id' => $processo !== null ? (string)$processo['id'] : '',
        'protocolo'   => entrada('protocolo'),
        'descricao'   => entrada('descricao'),
        'prazo'       => entrada('prazo'),
        'prazo_hora'  => entrada('prazo_hora'),
        'status'      => entrada('status'),
        'observacao'  => entrada('observacao'),
    ];
    try {
        $id = (new AcaoService())->criar($dados);
        flash('sucesso', 'Ação cadastrada.');
        redirecionar($retorno . '#acao-' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'acao.criar');
        $erros['_geral'] = "Não foi possível salvar a ação (código {$codigo}).";
    }
}

$titulo      = 'Nova Ação';
$menuAtivo   = 'acoes';
$breadcrumbs = ['Ações' => 'acoes.php', 'Nova' => null];
$scripts     = ['acoes.js'];
$rotuloSalvar = 'Salvar ação';

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-acao.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
