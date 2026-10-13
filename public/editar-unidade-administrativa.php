<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\InstituicaoRepository;
use App\Repositories\LotacaoRepository;
use App\Repositories\NivelOrganizacionalRepository;
use App\Services\UnidadeAdministrativaService;

$id = entrada_int('id');
$repo = new LotacaoRepository();
$unidade = $id !== null ? $repo->buscarPorId($id) : null;
if ($unidade === null) {
    flash('erro', 'Unidade administrativa não encontrada.');
    redirecionar('unidades-administrativas.php');
}

$voltar = 'unidade-administrativa.php?id=' . $id;
$erros = [];
$dados = $unidade;
$servidores = $repo->servidores($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = entrada_unidade_administrativa();
    $servidores = $dados['servidores'];
    try {
        (new UnidadeAdministrativaService())->atualizar($id, $dados);
        flash('sucesso', 'Unidade administrativa atualizada.');
        redirecionar($voltar);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'unidade.editar');
        $erros['_geral'] = "Não foi possível salvar a unidade (código {$codigo}).";
    }
}

// Itens ja gravados continuam na lista mesmo que tenham sido inativados.
$instituicoes = (new InstituicaoRepository())->opcoes($unidade['instituicao_id'] !== null ? (int)$unidade['instituicao_id'] : null);
$niveis = (new NivelOrganizacionalRepository())->opcoes($unidade['nivel_id'] !== null ? (int)$unidade['nivel_id'] : null);
$superiores = UnidadeAdministrativaService::opcoesSuperior(
    $repo->estrutura(),
    $id,
    $unidade['unidade_superior_id'] !== null ? (int)$unidade['unidade_superior_id'] : null
);

$titulo       = 'Editar Unidade Administrativa';
$menuAtivo    = 'unidades';
$breadcrumbs  = ['Sistema' => null, 'Unidades Administrativas' => 'unidades-administrativas.php', $unidade['nome'] => $voltar, 'Editar' => null];
$scripts      = ['unidades.js'];
$rotuloSalvar = 'Salvar alterações';

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<?php if ($unidade['instituicao_id'] === null && $erros === []): ?>
    <div class="alert alert--aviso"><span class="alert__icone">⚠</span><span>Unidade cadastrada antes das Unidades Administrativas: selecione a instituição para salvar.</span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-unidade-administrativa.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
