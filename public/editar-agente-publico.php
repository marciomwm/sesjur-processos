<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\AgentePublicoRepository;
use App\Repositories\CargoRepository;
use App\Repositories\InstituicaoRepository;
use App\Repositories\LotacaoRepository;
use App\Services\AgentePublicoService;

$id = entrada_int('id');
$registro = $id !== null ? (new AgentePublicoRepository())->buscarPorId($id) : null;
if ($registro === null) {
    flash('erro', 'Agente público não encontrado.');
    redirecionar('agentes-publicos.php');
}

$erros = [];
$dados = $registro;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = entrada_agente_publico();
    try {
        (new AgentePublicoService())->atualizar($id, $dados);
        flash('sucesso', 'Agente público atualizado com sucesso.');
        redirecionar('agente-publico.php?id=' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'agente.atualizar');
        $erros['_geral'] = "Não foi possível salvar as alterações (código {$codigo}).";
    }
}

// Mantem nos seletores o cargo e a unidade gravados, mesmo que inativados depois.
$cargos       = (new CargoRepository())->opcoes($registro['cargo_id'] !== null ? (int)$registro['cargo_id'] : null);
$lotacoes     = (new LotacaoRepository())->opcoes((int)$registro['lotacao_id']);
$instituicoes = (new InstituicaoRepository())->todos();
$modo         = 'editar';
$temUsuario   = $registro['usuario_id'] !== null;
$urlCancelar  = url('agente-publico.php?id=' . $id);

$titulo      = 'Editar Agente Público';
$menuAtivo   = 'agentes';
$breadcrumbs = ['Sistema' => null, 'Agentes Públicos' => 'agentes-publicos.php', $registro['nome'] => 'agente-publico.php?id=' . $id, 'Editar' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-agente-publico.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
