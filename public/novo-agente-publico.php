<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\CargoRepository;
use App\Repositories\InstituicaoRepository;
use App\Repositories\LotacaoRepository;
use App\Services\AgentePublicoService;

$erros = [];
$dados = ['situacao' => 'ATIVO'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = entrada_agente_publico();
    try {
        $id = (new AgentePublicoService())->criar($dados);
        flash('sucesso', 'Agente público cadastrado com sucesso.');
        redirecionar('agente-publico.php?id=' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'agente.criar');
        $erros['_geral'] = "Não foi possível cadastrar o agente público (código {$codigo}).";
    }
}

$cargos       = (new CargoRepository())->opcoes();
$lotacoes     = (new LotacaoRepository())->opcoes();
$instituicoes = (new InstituicaoRepository())->opcoes();
$modo         = 'novo';
$temUsuario   = false;
$urlCancelar  = url('agentes-publicos.php');

$titulo      = 'Cadastrar Agente Público';
$menuAtivo   = 'agentes';
$breadcrumbs = ['Sistema' => null, 'Agentes Públicos' => 'agentes-publicos.php', 'Cadastrar' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-agente-publico.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
