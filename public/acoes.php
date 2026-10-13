<?php
declare(strict_types=1);

/**
 * Gerenciador de tarefas: as Ações de todos os processos administrativos
 * em um quadro por status, com filtros de prazo, autoria e processo.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Repositories\AcaoRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Services\AcaoService;

$filtros = [
    'busca'       => entrada('busca', $_GET),
    'status'      => entrada('status', $_GET),
    'prazo'       => entrada('prazo', $_GET),
    'minhas'      => entrada('minhas', $_GET) === '1' ? '1' : '',
    'processo_id' => (string)(entrada_int('processo_id') ?? ''),
];

$processo = $filtros['processo_id'] !== ''
    ? (new ProcessoAdministrativoRepository())->buscarPorId((int)$filtros['processo_id'])
    : null;

$repo = new AcaoRepository();
$acoes = $repo->listar([
    'busca'       => $filtros['busca'],
    'status'      => $filtros['status'],
    'prazo'       => $filtros['prazo'],
    'processo_id' => $processo !== null ? (int)$processo['id'] : null,
    'criado_por'  => $filtros['minhas'] === '1' ? usuario_logado_id() : null,
]);

$colunas = array_fill_keys(array_keys(AcaoService::STATUS), []);
foreach ($acoes as $acao) {
    $colunas[$acao['status']][] = $acao;
}
$totalVencidas = $repo->contarVencidas();
$totalAbertas  = $repo->contarAbertas();

$query = array_filter($filtros, static fn($v) => $v !== '');
$retorno = 'acoes.php' . ($query !== [] ? '?' . http_build_query($query) : '');
$mostrarProcesso = true;

$titulo      = 'Ações';
$menuAtivo   = 'acoes';
$breadcrumbs = ['Processos' => null, 'Ações' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<section class="indicadores indicadores--compactos">
    <a class="indicador" href="<?= e(url('acoes.php?status=abertas')) ?>">
        <span class="indicador__rotulo">Ações em aberto</span>
        <span class="indicador__valor"><?= $totalAbertas ?></span>
        <span class="indicador__nota">Pendentes ou em andamento</span>
    </a>
    <a class="indicador indicador--alerta" href="<?= e(url('acoes.php?prazo=vencidas')) ?>">
        <span class="indicador__rotulo">Vencidas</span>
        <span class="indicador__valor"><?= $totalVencidas ?></span>
        <span class="indicador__nota">Em aberto com o prazo expirado</span>
    </a>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Gerenciador de ações</h2>
            <p class="card__subtitulo">
                <?php if ($processo !== null): ?>
                    Ações do processo <a class="mono" href="<?= e(url('processo-administrativo.php?id=' . $processo['id'])) ?>"><?= e($processo['protocolo']) ?></a>
                <?php else: ?>
                    Tarefas dos processos administrativos · alteradas por administradores, gestores ou por quem as criou
                <?php endif; ?>
            </p>
        </div>
        <a class="btn btn--primario btn--sm"
           href="<?= e(url('nova-acao.php' . ($processo !== null ? '?processo_id=' . $processo['id'] : '') )) ?>">＋ Nova ação</a>
    </div>
    <div class="card__corpo">
        <form method="get" class="filtros">
            <?php if ($processo !== null): ?>
                <input type="hidden" name="processo_id" value="<?= (int)$processo['id'] ?>">
            <?php endif; ?>
            <div class="campo">
                <label class="campo__rotulo" for="busca">Descrição ou protocolo</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>" placeholder="Termo da ação ou SES-PRO-2026/…">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="status">Status</label>
                <select class="select" id="status" name="status">
                    <option value="">Todos</option>
                    <option value="abertas" <?= $filtros['status'] === 'abertas' ? 'selected' : '' ?>>Em aberto</option>
                    <?php foreach (AcaoService::STATUS as $codigo => $rotulo): ?>
                        <option value="<?= e($codigo) ?>" <?= $filtros['status'] === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="prazo">Prazo</label>
                <select class="select" id="prazo" name="prazo">
                    <option value="">Qualquer</option>
                    <option value="vencidas" <?= $filtros['prazo'] === 'vencidas' ? 'selected' : '' ?>>Vencidas</option>
                    <option value="hoje" <?= $filtros['prazo'] === 'hoje' ? 'selected' : '' ?>>Vencem hoje</option>
                    <option value="semana" <?= $filtros['prazo'] === 'semana' ? 'selected' : '' ?>>Próximos 7 dias</option>
                    <option value="sem_prazo" <?= $filtros['prazo'] === 'sem_prazo' ? 'selected' : '' ?>>Sem prazo</option>
                </select>
            </div>
            <div class="campo">
                <span class="campo__rotulo">Autoria</span>
                <label class="checkbox-linha filtros__checkbox">
                    <input type="checkbox" name="minhas" value="1" <?= $filtros['minhas'] === '1' ? 'checked' : '' ?>> Criadas por mim
                </label>
            </div>
            <div class="filtros__acoes">
                <button type="submit" class="btn btn--primario">Filtrar</button>
                <a class="btn btn--ghost" href="<?= e(url('acoes.php' . ($processo !== null ? '?processo_id=' . $processo['id'] : ''))) ?>">Limpar</a>
            </div>
        </form>
    </div>
</section>

<?php if (count($acoes) >= AcaoRepository::LIMITE_QUADRO): ?>
    <div class="alert alert--aviso">
        <span class="alert__icone">⚠</span>
        <span>Exibindo as <?= AcaoRepository::LIMITE_QUADRO ?> primeiras ações pelo prazo. Use os filtros para refinar.</span>
    </div>
<?php endif; ?>

<div class="quadro-acoes">
    <?php foreach (AcaoService::STATUS as $codigo => $rotulo): ?>
        <section class="quadro-acoes__coluna quadro-acoes__coluna--<?= e(strtolower($codigo)) ?>" aria-label="<?= e($rotulo) ?>">
            <header class="quadro-acoes__cabecalho">
                <h2><?= e($rotulo) ?></h2>
                <span class="quadro-acoes__contador"><?= count($colunas[$codigo]) ?></span>
            </header>
            <div class="quadro-acoes__lista">
                <?php if ($colunas[$codigo] === []): ?>
                    <p class="quadro-acoes__vazio">Nenhuma ação.</p>
                <?php else: foreach ($colunas[$codigo] as $acao): ?>
                    <?php require APP_ROOT . '/Views/partials/acao-item.php'; ?>
                <?php endforeach; endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
