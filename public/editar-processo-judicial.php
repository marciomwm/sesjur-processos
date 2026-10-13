<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\AssuntoProcessualRepository;
use App\Repositories\DocumentoJudicialRepository;
use App\Repositories\MovimentacaoJudicialRepository;
use App\Repositories\ParteProcessualRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Repositories\VinculoRepository;
use App\Services\ProcessoJudicialService;

$id = entrada_int('id');
if ($id === null) {
    flash('erro', 'Processo judicial não informado.');
    redirecionar('processos-judiciais.php');
}

$repo = new ProcessoJudicialRepository();
$registro = $repo->buscarPorId($id);
if ($registro === null) {
    flash('erro', 'Processo judicial não encontrado.');
    redirecionar('processos-judiciais.php');
}

$servico   = new ProcessoJudicialService();
$repoParte = new ParteProcessualRepository();
$repoAss   = new AssuntoProcessualRepository();
$repoVin   = new VinculoRepository();

$erros = [];

$dados = $registro;
$dados['valor_causa'] = $registro['valor_causa'] !== null
    ? number_format((float)$registro['valor_causa'], 2, ',', '.')
    : '';
foreach (['segredo_justica', 'justica_gratuita', 'tutela_liminar', 'prioridade'] as $sinalizador) {
    $dados[$sinalizador] = $registro[$sinalizador] === null ? '' : (string)(int)$registro[$sinalizador];
}
$dados['assuntos_secundarios'] = implode("\n", $repoAss->secundarios($id));
$dados['movimentacoes_json'] = '';
$dados['documentos_json'] = '';

// O codigo CNJ do assunto principal mora em assuntos_processuais, nao na
// tabela do processo: sem recarrega-lo aqui, salvar a edicao o apagaria.
$dados['assunto_principal_codigo'] = $repoAss->codigoPrincipal($id) ?? '';

$partes = $repoParte->listarPorProcesso($id);
$movimentacoes = (new MovimentacaoJudicialRepository())->listarPorProcesso($id);
$movimentacoesSalvas = true;
$documentos = (new DocumentoJudicialRepository())->listarPorProcesso($id);
$documentosSalvos = true;
$documentosPorMovimentacao = agrupar_por_movimentacao($documentos);

$administrativosSelecionados = array_map(static fn(array $v): array => [
    'id'      => (int)$v['id'],
    'rotulo'  => $v['protocolo'],
    'detalhe' => mb_substr((string)($v['objeto'] ?? ''), 0, 90),
], $repoVin->administrativosDoJudicial($id));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();

    $dados = ProcessoJudicialService::entradaDoFormulario($_POST, $registro);

    try {
        $servico->atualizar($id, $dados);
        flash('sucesso', 'Processo judicial atualizado com sucesso.');
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

    // Cronologia e documentos submetidos (nova extracao) ou, na ausencia
    // deles, os ja gravados.
    try {
        $novas = ProcessoJudicialService::movimentacoesDaEntrada($dados);
        if ($novas !== null) {
            $movimentacoes = $novas;
            $movimentacoesSalvas = false;
        }
    } catch (ValidationException) {
        // O erro ja e reportado ao salvar; aqui basta manter a lista gravada.
    }
    try {
        $novos = ProcessoJudicialService::documentosDaEntrada($dados);
        if ($novos !== null) {
            $documentos = $novos;
            $documentosSalvos = false;
        }
    } catch (ValidationException) {
        // Idem.
    }

    $repoAdm = new ProcessoAdministrativoRepository();
    $administrativosSelecionados = [];
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

$titulo      = 'Editar Processo Judicial';
$menuAtivo   = 'pj-lista';
$breadcrumbs = [
    'Processos Judiciais'   => 'processos-judiciais.php',
    $registro['numero_cnj'] => 'processo-judicial.php?id=' . $id,
    'Editar'                => null,
];
$scripts = ['extracao-ui.js', 'vinculos.js', 'partes.js', 'form-processo-judicial.js'];
$modo    = 'editar';

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

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
