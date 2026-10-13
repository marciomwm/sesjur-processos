<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\InstituicaoExternaRepository;
use App\Repositories\OrgaoExternoRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Services\ProcessoExtrajudicialService;

$repoPa = new ProcessoAdministrativoRepository();
$erros = [];
// Vindo da ficha do processo administrativo, o vinculo ja vem marcado.
$administrativoId = entrada_int('administrativo_id');
$dados = [
    'protocolo'       => mb_substr(entrada('protocolo', $_GET), 0, ProcessoExtrajudicialService::PROTOCOLO_MAX),
    'data_expediente' => date('Y-m-d'),
    'situacao'        => 'EM_ANDAMENTO',
    'administrativos_ids' => $administrativoId !== null ? [$administrativoId] : [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = entrada_processo_extrajudicial();
    try {
        $id = (new ProcessoExtrajudicialService())->criar($dados);
        flash('sucesso', 'Processo extrajudicial cadastrado com sucesso.');
        redirecionar('processo-extrajudicial.php?id=' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'extrajudicial.criar');
        $erros['_geral'] = "Não foi possível cadastrar o processo extrajudicial (código {$codigo}).";
    }
}

$administrativosSelecionados = itens_vinculo_administrativo(array_filter(array_map(
    static fn(int $id): ?array => $repoPa->buscarPorId($id),
    $dados['administrativos_ids'] ?? []
)));
$instituicoes = (new InstituicaoExternaRepository())->opcoes();
$orgaos = (new OrgaoExternoRepository())->opcoesComInstituicao();
$modo = 'novo';
$urlCancelar = url($administrativoId !== null ? 'processo-administrativo.php?id=' . $administrativoId . '#extrajudiciais' : 'processos-extrajudiciais.php');

$titulo      = 'Novo Processo Extrajudicial';
$menuAtivo   = 'pe-novo';
$breadcrumbs = ['Processos Extrajudiciais' => 'processos-extrajudiciais.php', 'Novo' => null];
$scripts     = ['vinculos.js', 'form-processo-extrajudicial.js'];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-processo-extrajudicial.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
