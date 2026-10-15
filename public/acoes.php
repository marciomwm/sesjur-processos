<?php
declare(strict_types=1);

/**
 * Gerenciador de tarefas: as Tarefas (Acoes) de todos os processos
 * administrativos, com filtros de prazo, autoria e processo, em tres visoes:
 * quadro por status, lista e calendario mensal pelo prazo.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Prazo;
use App\Repositories\AcaoRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Services\AcaoService;

const VISOES_TAREFAS = ['quadro' => '▦ Quadro', 'lista' => '☰ Lista', 'calendario' => '📅 Calendário'];

// A visao escolhida vale para a sessao, como na lista de processos judiciais.
$visao = entrada('visao', $_GET);
if (array_key_exists($visao, VISOES_TAREFAS)) {
    $_SESSION['acoes_visao'] = $visao;
} else {
    $visao = $_SESSION['acoes_visao'] ?? 'quadro';
}

// Mes do calendario (AAAA-MM); a grade comeca no domingo e termina no sabado.
$mes = entrada('mes', $_GET);
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
    $mes = date('Y-m');
}
$inicioMes = new DateTimeImmutable($mes . '-01');
$inicioGrade = $inicioMes->modify('-' . $inicioMes->format('w') . ' days');
$fimMes = $inicioMes->modify('last day of this month');
$fimGrade = $fimMes->modify('+' . (6 - (int)$fimMes->format('w')) . ' days');

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
    'prazo_de'    => $visao === 'calendario' ? $inicioGrade->format('Y-m-d') : null,
    'prazo_ate'   => $visao === 'calendario' ? $fimGrade->format('Y-m-d') : null,
]);

$colunas = array_fill_keys(array_keys(AcaoService::STATUS), []);
$porDia = [];
foreach ($acoes as $acao) {
    $colunas[$acao['status']][] = $acao;
    if ($acao['prazo'] !== null) {
        $porDia[substr($acao['prazo'], 0, 10)][] = $acao;
    }
}
// No calendario, as do mesmo dia seguem a hora do prazo (sem hora = fim do dia).
foreach ($porDia as &$doDia) {
    usort($doDia, static fn($a, $b) => strcmp((string)($a['prazo_hora'] ?? '24'), (string)($b['prazo_hora'] ?? '24')));
}
unset($doDia);
$totalVencidas = $repo->contarVencidas();
$totalAbertas  = $repo->contarAbertas();

$query = array_filter($filtros, static fn($v) => $v !== '');
$linkVisao = static function (string $alvo, ?string $mesAlvo = null) use ($query, $mes): string {
    $params = $query + ['visao' => $alvo];
    if ($alvo === 'calendario') {
        $params['mes'] = $mesAlvo ?? $mes;
    }
    return url('acoes.php?' . http_build_query($params));
};
$retorno = 'acoes.php?' . http_build_query($query + ['visao' => $visao] + ($visao === 'calendario' ? ['mes' => $mes] : []));
$mostrarProcesso = true;

const MESES_TAREFAS = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho',
                       'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

$titulo      = 'Tarefas';
$menuAtivo   = 'acoes';
$breadcrumbs = ['Processos' => null, 'Tarefas' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<section class="indicadores indicadores--compactos">
    <a class="indicador" href="<?= e(url('acoes.php?status=abertas')) ?>">
        <span class="indicador__rotulo">Tarefas em aberto</span>
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
            <h2 class="card__titulo">Gerenciador de tarefas</h2>
            <p class="card__subtitulo">
                <?php if ($processo !== null): ?>
                    Tarefas do processo <a class="mono" href="<?= e(url('processo-administrativo.php?id=' . $processo['id'])) ?>"><?= e($processo['protocolo']) ?></a>
                <?php else: ?>
                    Tarefas dos processos administrativos · alteradas por administradores, gestores ou por quem as criou
                <?php endif; ?>
            </p>
        </div>
        <a class="btn btn--primario btn--sm"
           href="<?= e(url('nova-acao.php' . ($processo !== null ? '?processo_id=' . $processo['id'] : '') )) ?>">＋ Nova tarefa</a>
    </div>
    <div class="card__corpo">
        <form method="get" class="filtros">
            <?php if ($processo !== null): ?>
                <input type="hidden" name="processo_id" value="<?= (int)$processo['id'] ?>">
            <?php endif; ?>
            <?php if ($visao === 'calendario'): ?>
                <input type="hidden" name="mes" value="<?= e($mes) ?>">
            <?php endif; ?>
            <div class="campo">
                <label class="campo__rotulo" for="busca">Descrição ou protocolo</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>" placeholder="Termo da tarefa ou SES-PRO-2026/…">
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

    <div class="barra-visao">
        <span class="texto-secundario">
            <?= count($acoes) ?> tarefa(s)<?= $visao === 'calendario' ? ' com prazo no período' : '' ?>
        </span>
        <div class="alternador" role="group" aria-label="Forma de visualização">
            <?php foreach (VISOES_TAREFAS as $codigoVisao => $rotuloVisao): ?>
                <a class="alternador__item <?= $visao === $codigoVisao ? 'is-ativo' : '' ?>" href="<?= e($linkVisao($codigoVisao)) ?>"
                   <?= $visao === $codigoVisao ? 'aria-current="true"' : '' ?>><?= e($rotuloVisao) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if (count($acoes) >= AcaoRepository::LIMITE_QUADRO): ?>
    <div class="alert alert--aviso">
        <span class="alert__icone">⚠</span>
        <span>Exibindo as <?= AcaoRepository::LIMITE_QUADRO ?> primeiras tarefas pelo prazo. Use os filtros para refinar.</span>
    </div>
<?php endif; ?>

<?php if ($visao === 'lista'): ?>

<section class="card">
    <?php if ($acoes === []): ?>
        <div class="card__corpo">
            <p class="texto-secundario mb-0">Nenhuma tarefa encontrada com esses filtros.</p>
        </div>
    <?php else: ?>
        <?php require APP_ROOT . '/Views/partials/acoes-tabela.php'; ?>
    <?php endif; ?>
</section>

<?php elseif ($visao === 'calendario'): ?>

<?php
$hoje = date('Y-m-d');
$mesAnterior = $inicioMes->modify('-1 month')->format('Y-m');
$mesSeguinte = $inicioMes->modify('+1 month')->format('Y-m');
?>
<section class="card calendario">
    <div class="card__cabecalho">
        <h2 class="card__titulo calendario__titulo">
            <?= e(ucfirst(MESES_TAREFAS[(int)$inicioMes->format('n')])) ?> de <?= e($inicioMes->format('Y')) ?>
        </h2>
        <nav class="acoes-linha" aria-label="Navegação entre meses">
            <a class="btn btn--ghost btn--sm" href="<?= e($linkVisao('calendario', $mesAnterior)) ?>" aria-label="Mês anterior">‹ Anterior</a>
            <a class="btn btn--ghost btn--sm" href="<?= e($linkVisao('calendario', date('Y-m'))) ?>">Hoje</a>
            <a class="btn btn--ghost btn--sm" href="<?= e($linkVisao('calendario', $mesSeguinte)) ?>" aria-label="Mês seguinte">Seguinte ›</a>
        </nav>
    </div>
    <div class="card__corpo">
        <div class="calendario__grade" role="grid">
            <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $diaSemana): ?>
                <div class="calendario__semana" role="columnheader"><?= $diaSemana ?></div>
            <?php endforeach; ?>
            <?php for ($dia = $inicioGrade; $dia <= $fimGrade; $dia = $dia->modify('+1 day')): ?>
                <?php
                $data = $dia->format('Y-m-d');
                $doDia = $porDia[$data] ?? [];
                $classes = ['calendario__dia'];
                if ($dia->format('Y-m') !== $mes) { $classes[] = 'is-fora'; }
                if ($data === $hoje) { $classes[] = 'is-hoje'; }
                if ($doDia !== []) { $classes[] = 'tem-tarefas'; }
                ?>
                <div class="<?= e(implode(' ', $classes)) ?>" role="gridcell" aria-label="<?= e(data_br($data)) ?>, <?= count($doDia) ?> tarefa(s)">
                    <span class="calendario__numero">
                        <span class="calendario__data-extensa"><?= e(['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'][(int)$dia->format('w')]) ?>, </span><?= e($dia->format('j')) ?>
                    </span>
                    <?php foreach ($doDia as $acao): ?>
                        <?php
                        $finalizada = AcaoService::finalizada($acao['status']);
                        $situacaoPrazo = $finalizada ? 'finalizada' : (Prazo::situacao($acao['prazo'], $acao['prazo_hora']) ?? 'no_prazo');
                        $dica = $acao['protocolo'] . ' · ' . (AcaoService::STATUS[$acao['status']] ?? $acao['status'])
                              . ' · prazo ' . Prazo::formatar($acao['prazo'], $acao['prazo_hora']) . "\n" . $acao['descricao'];
                        ?>
                        <a class="calendario__tarefa calendario__tarefa--<?= e($situacaoPrazo) ?>"
                           href="<?= e(url('processo-administrativo.php?id=' . $acao['processo_administrativo_id'])) ?>#acao-<?= (int)$acao['id'] ?>"
                           title="<?= e($dica) ?>">
                            <?php if ($acao['prazo_hora'] !== null && $acao['prazo_hora'] !== ''): ?>
                                <span class="calendario__hora"><?= e(substr($acao['prazo_hora'], 0, 5)) ?></span>
                            <?php endif; ?>
                            <?= e(mb_strimwidth($acao['descricao'], 0, 70, '…')) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endfor; ?>
        </div>
        <p class="calendario__legenda texto-secundario">
            <span class="calendario__marca calendario__tarefa--vencido"></span> Vencida
            <span class="calendario__marca calendario__tarefa--proximo"></span> Vence em breve
            <span class="calendario__marca calendario__tarefa--no_prazo"></span> No prazo
            <span class="calendario__marca calendario__tarefa--finalizada"></span> Concluída ou cancelada
            · Tarefas sem prazo não aparecem no calendário.
        </p>
    </div>
</section>

<?php else: ?>

<div class="quadro-acoes">
    <?php foreach (AcaoService::STATUS as $codigo => $rotulo): ?>
        <section class="quadro-acoes__coluna quadro-acoes__coluna--<?= e(strtolower($codigo)) ?>" aria-label="<?= e($rotulo) ?>">
            <header class="quadro-acoes__cabecalho">
                <h2><?= e($rotulo) ?></h2>
                <span class="quadro-acoes__contador"><?= count($colunas[$codigo]) ?></span>
            </header>
            <div class="quadro-acoes__lista">
                <?php if ($colunas[$codigo] === []): ?>
                    <p class="quadro-acoes__vazio">Nenhuma tarefa.</p>
                <?php else: foreach ($colunas[$codigo] as $acao): ?>
                    <?php require APP_ROOT . '/Views/partials/acao-item.php'; ?>
                <?php endforeach; endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
