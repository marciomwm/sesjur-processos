<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\InstituicaoExternaRepository;
use App\Repositories\OrgaoExternoRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\ProcessoExtrajudicialRepository;
use App\Repositories\VinculoExtrajudicialRepository;
use App\Services\ProcessoExtrajudicialService;

$id = entrada_int('id');
$registro = $id !== null ? (new ProcessoExtrajudicialRepository())->buscarPorId($id) : null;
if ($registro === null) {
    flash('erro', 'Processo extrajudicial não encontrado.');
    redirecionar('processos-extrajudiciais.php');
}

$erros = [];
$dados = $registro;
$dados['administrativos_ids'] = (new VinculoExtrajudicialRepository())->idsAdministrativos($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = entrada_processo_extrajudicial();
    try {
        (new ProcessoExtrajudicialService())->atualizar($id, $dados);
        flash('sucesso', 'Processo extrajudicial atualizado com sucesso.');
        redirecionar('processo-extrajudicial.php?id=' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'extrajudicial.atualizar');
        $erros['_geral'] = "Não foi possível salvar as alterações (código {$codigo}).";
    }
}

$repoPa = new ProcessoAdministrativoRepository();
$administrativosSelecionados = itens_vinculo_administrativo(array_filter(array_map(
    static fn(int $pa): ?array => $repoPa->buscarPorId($pa),
    $dados['administrativos_ids'] ?? []
)));
$instituicoes = (new InstituicaoExternaRepository())->opcoes((int)$registro['instituicao_externa_id']);
$orgaos = (new OrgaoExternoRepository())->opcoesComInstituicao($registro['orgao_externo_id'] !== null ? (int)$registro['orgao_externo_id'] : null);
$modo = 'editar';
$urlCancelar = url('processo-extrajudicial.php?id=' . $id);

$titulo      = 'Editar Processo Extrajudicial';
$menuAtivo   = 'pe-lista';
$breadcrumbs = [
    'Processos Extrajudiciais' => 'processos-extrajudiciais.php',
    $registro['protocolo']     => 'processo-extrajudicial.php?id=' . $id,
    'Editar'                   => null,
];
$scripts = ['vinculos.js', 'form-processo-extrajudicial.js'];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-processo-extrajudicial.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
