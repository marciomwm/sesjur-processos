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
use App\Validators\Normalizador;

$voltar = 'unidades-administrativas.php';
$erros = [];
// Atalho "subordinada": ?superior=ID ja traz a instituicao e a superior.
$superiorSugerido = entrada_int('superior');
$sugerida = $superiorSugerido !== null ? (new LotacaoRepository())->buscarPorId($superiorSugerido) : null;
$dados = $sugerida !== null
    ? ['instituicao_id' => $sugerida['instituicao_id'], 'unidade_superior_id' => $sugerida['id']]
    : ['instituicao_id' => entrada_int('instituicao_id')];
$servidores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = entrada_unidade_administrativa();
    $servidores = $dados['servidores'];
    try {
        $id = (new UnidadeAdministrativaService())->criar($dados);
        flash('sucesso', 'Unidade administrativa “' . Normalizador::texto($dados['nome']) . '” cadastrada.');
        redirecionar('unidade-administrativa.php?id=' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'unidade.criar');
        $erros['_geral'] = "Não foi possível salvar a unidade (código {$codigo}).";
    }
}

$instituicoes = (new InstituicaoRepository())->opcoes(isset($dados['instituicao_id']) ? (int)$dados['instituicao_id'] : null);
$niveis = (new NivelOrganizacionalRepository())->opcoes();
$superiores = UnidadeAdministrativaService::opcoesSuperior((new LotacaoRepository())->estrutura(), null, null);

$titulo       = 'Nova Unidade Administrativa';
$menuAtivo    = 'unidades';
$breadcrumbs  = ['Sistema' => null, 'Unidades Administrativas' => $voltar, 'Nova' => null];
$scripts      = ['unidades.js'];
$rotuloSalvar = 'Salvar unidade';

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-unidade-administrativa.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
