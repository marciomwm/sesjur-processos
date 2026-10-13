<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Services\ProcessoJudicialService;
use App\Validators\CnjValidator;

$servico = new ProcessoJudicialService();
$erros   = [];
$partes  = [];
$movimentacoes = [];
$movimentacoesSalvas = false;
$documentos = [];
$documentosSalvos = false;
$administrativosSelecionados = [];

$dados = [
    'numero_cnj' => entrada('numero_cnj', $_GET),
    'origem'     => 'MANUAL',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();

    $dados = ProcessoJudicialService::entradaDoFormulario($_POST);

    try {
        $id = $servico->criar($dados);
        flash('sucesso', 'Processo judicial cadastrado com sucesso.');
        redirecionar('processo-judicial.php?id=' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros();
        if ($erros === []) {
            $erros['_geral'] = $e->getMessage();
        }
    } catch (Throwable $e) {
        $erros['_geral'] = 'Erro ao salvar: ' . $e->getMessage();
    }

    $partes = array_values(array_filter((array)$dados['partes'], static fn($p) => is_array($p) && trim((string)($p['nome'] ?? '')) !== ''));

    // Reexibe cronologia e documentos extraídos para que não se percam quando
    // o formulário volta com erro de validação.
    try {
        $movimentacoes = ProcessoJudicialService::movimentacoesDaEntrada($dados) ?? [];
    } catch (ValidationException) {
        $movimentacoes = [];
    }
    try {
        $documentos = ProcessoJudicialService::documentosDaEntrada($dados) ?? [];
    } catch (ValidationException) {
        $documentos = [];
    }

    $repoAdm = new ProcessoAdministrativoRepository();
    foreach ($dados['administrativos_ids'] as $aid) {
        $pa = $repoAdm->buscarPorId($aid);
        if ($pa !== null) {
            $administrativosSelecionados[] = [
                'id'      => (int)$pa['id'],
                'rotulo'  => $pa['protocolo'],
                'detalhe' => mb_substr((string)($pa['objeto'] ?? ''), 0, 90),
            ];
        }
    }
}

$titulo      = 'Novo Processo Judicial';
$menuAtivo   = 'pj-novo';
$breadcrumbs = ['Processos Judiciais' => 'processos-judiciais.php', 'Novo' => null];
$scripts     = ['extracao-ui.js', 'vinculos.js', 'partes.js', 'form-processo-judicial.js', 'criacao-extensao.js'];
$modo        = 'novo';
$registro    = null;

// Número CNJ vindo da extensão (ou de um link) que já está cadastrado.
$existente = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($dados['numero_cnj'] ?? '') !== '') {
    $cnjGet = CnjValidator::normalizar($dados['numero_cnj']);
    $existente = $cnjGet !== null ? (new ProcessoJudicialRepository())->buscarPorCnj($cnjGet) : null;
}

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if ($existente !== null): ?>
    <div class="alert alert--info" data-processo-existente>
        <span class="alert__icone">ℹ</span>
        <span>O processo <strong class="mono"><?= e($existente['numero_cnj']) ?></strong> já está cadastrado.
            <a href="<?= e(url('processo-judicial.php?id=' . $existente['id'])) ?>">Abrir a ficha</a> ou
            <a href="<?= e(url('editar-processo-judicial.php?id=' . $existente['id'])) ?>">atualizar pelo PJe</a>.</span>
    </div>
<?php endif; ?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>
<?php
$errosPartes = array_filter($erros, static fn($k) => str_starts_with($k, 'partes.'), ARRAY_FILTER_USE_KEY);
if ($errosPartes !== []): ?>
    <div class="alert alert--erro">
        <span class="alert__icone">✕</span>
        <div><strong>Verifique as partes processuais:</strong>
            <ul style="margin:6px 0 0 18px">
                <?php foreach ($errosPartes as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-processo-judicial.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
