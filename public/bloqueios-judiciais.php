<?php
declare(strict_types=1);

/**
 * Painel de Bloqueios Judiciais e Guias de Deposito.
 *
 * Totais, serie historica configuravel e ranking por processo. Os
 * lancamentos e guias vao para a pagina uma unica vez (JSON, valores em
 * centavos); assets/js/painel-bloqueios.js recalcula indicadores, graficos e
 * tabelas para o periodo e o processo escolhidos, sem ida ao servidor. Sem
 * JavaScript, a pagina mostra os totais gerais e as tabelas completas.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Dinheiro;
use App\Repositories\BloqueioJudicialRepository;
use App\Repositories\GuiaDepositoRepository;
use App\Services\BloqueioJudicialService;
use App\Services\GuiaDepositoService;

$lancamentos = (new BloqueioJudicialRepository())->listarTodos();
$guias = (new GuiaDepositoRepository())->listarTodas();
$hoje = date('Y-m-d');

$totais = BloqueioJudicialService::saldos($lancamentos);
$somaGuias = static fn(array $lista): int => array_sum(array_map(static fn(array $g): int => Dinheiro::deDecimal($g['valor']), $lista));
$guiasEmitidas = array_filter($guias, static fn(array $g): bool => $g['status'] !== 'CANCELADA');
$guiasPagas = array_filter($guias, static fn(array $g): bool => $g['status'] === 'PAGA');
$guiasAbertas = array_filter($guias, static fn(array $g): bool => $g['status'] === 'EMITIDA');
$guiasVencidas = array_filter($guias, static fn(array $g): bool => GuiaDepositoService::situacao($g, $hoje) === 'VENCIDA');

// Processos com algum registro (filtro), em ordem de numero.
$processos = [];
foreach (array_merge($lancamentos, $guias) as $r) {
    $processos[(int)$r['processo_judicial_id']] = $r['numero_cnj'];
}
asort($processos);
$processoInicial = entrada_int('processo');
if ($processoInicial !== null && !isset($processos[$processoInicial])) {
    $processoInicial = null;
}

$dados = [
    'hoje'        => $hoje,
    'processos'   => array_map(static fn(int $id, string $cnj): array => ['id' => $id, 'cnj' => $cnj], array_keys($processos), $processos),
    'lancamentos' => array_map(static fn(array $l): array => [
        (int)$l['processo_judicial_id'], $l['data_lancamento'], $l['tipo'] === 'BLOQUEIO' ? 'B' : 'D', Dinheiro::deDecimal($l['valor']),
    ], $lancamentos),
    'guias'       => array_map(static fn(array $g): array => [
        (int)$g['processo_judicial_id'], $g['data_emissao'], $g['status'], Dinheiro::deDecimal($g['valor']), $g['data_pagamento'], $g['vencimento'],
    ], $guias),
];
$semDados = $lancamentos === [] && $guias === [];

$titulo      = 'Bloqueios e Depósitos';
$menuAtivo   = 'bloqueios';
$breadcrumbs = ['Processos Judiciais' => 'processos-judiciais.php', 'Bloqueios e Depósitos' => null];
$scripts     = ['modal.js', 'bloqueios.js', 'painel-bloqueios.js'];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<div class="cabecalho-pagina">
    <div class="cabecalho-pagina__texto">
        <p class="sobrelinha">Controle financeiro dos processos judiciais</p>
        <h2 class="cabecalho-pagina__titulo">Bloqueios judiciais e guias de depósito</h2>
        <p class="cabecalho-pagina__descricao">Valores bloqueados e desbloqueados nas contas do Estado e guias emitidas para depósito em juízo.
            Para lançar a partir de um processo, abra a ficha dele; aqui, escolha o processo no próprio formulário.</p>
    </div>
    <div class="acoes-linha">
        <button type="button" class="btn btn--secundario btn--sm" data-modal-abrir="modalBloqueio" data-modal-titulo="Registrar bloqueio"
                data-modal-dados='{"tipo":"BLOQUEIO"}'>▼ Bloqueio</button>
        <button type="button" class="btn btn--secundario btn--sm" data-modal-abrir="modalBloqueio" data-modal-titulo="Registrar desbloqueio"
                data-modal-dados='{"tipo":"DESBLOQUEIO"}'>▲ Desbloqueio</button>
        <button type="button" class="btn btn--primario btn--sm" data-modal-abrir="modalGuia">＋ Guia de depósito</button>
    </div>
</div>

<?php if ($semDados): ?>
    <section class="card">
        <div class="estado-vazio">
            <span class="estado-vazio__icone" aria-hidden="true">🔒</span>
            <b>Nenhum bloqueio ou guia registrado ainda</b>
            <p>Registre o primeiro bloqueio judicial ou a primeira guia de depósito. Os totais e gráficos aparecem aqui assim que houver lançamentos.</p>
            <button type="button" class="btn btn--primario btn--sm" data-modal-abrir="modalBloqueio" data-modal-titulo="Registrar bloqueio"
                    data-modal-dados='{"tipo":"BLOQUEIO"}'>▼ Registrar bloqueio</button>
        </div>
    </section>
<?php else: ?>

<form class="barra-ferramentas painel-filtros" data-painel-filtros onsubmit="return false" hidden>
    <div class="segmentado" role="group" aria-label="Período">
        <?php foreach (['12m' => '12 meses', '24m' => '24 meses', '60m' => '5 anos', 'tudo' => 'Tudo', 'personalizado' => 'Personalizado'] as $valor => $rotulo): ?>
            <button type="button" class="segmentado__item" data-periodo="<?= e($valor) ?>" aria-pressed="false"><?= e($rotulo) ?></button>
        <?php endforeach; ?>
    </div>
    <span class="painel-filtros__datas" data-periodo-datas hidden>
        <label class="sr-only" for="filtroDe">De</label>
        <input class="input input--sm" type="date" id="filtroDe" data-filtro-de>
        <span aria-hidden="true">até</span>
        <label class="sr-only" for="filtroAte">Até</label>
        <input class="input input--sm" type="date" id="filtroAte" data-filtro-ate>
    </span>
    <span class="barra-ferramentas__separador" aria-hidden="true"></span>
    <label class="sr-only" for="filtroProcesso">Processo</label>
    <select class="select select--sm painel-filtros__processo" id="filtroProcesso" data-filtro-processo>
        <option value="">Todos os processos (<?= count($processos) ?>)</option>
        <?php foreach ($processos as $pid => $cnj): ?>
            <option value="<?= $pid ?>" <?= $pid === $processoInicial ? 'selected' : '' ?>><?= e($cnj) ?></option>
        <?php endforeach; ?>
    </select>
    <span class="painel-filtros__resumo texto-secundario" data-filtro-resumo aria-live="polite"></span>
</form>

<section class="indicadores indicadores--valores" aria-label="Totais">
    <article class="indicador indicador--judicial">
        <span class="indicador__rotulo">Bloqueado</span>
        <span class="indicador__valor" data-kpi="bloqueado"><?= e(Dinheiro::formatar($totais['bloqueado'])) ?></span>
        <span class="indicador__nota" data-kpi-nota="bloqueado"><?= count(array_filter($lancamentos, static fn($l) => $l['tipo'] === 'BLOQUEIO')) ?> bloqueio(s) · total geral</span>
    </article>
    <article class="indicador indicador--desbloqueado">
        <span class="indicador__rotulo">Desbloqueado</span>
        <span class="indicador__valor" data-kpi="desbloqueado"><?= e(Dinheiro::formatar($totais['desbloqueado'])) ?></span>
        <span class="indicador__nota" data-kpi-nota="desbloqueado"><?= count(array_filter($lancamentos, static fn($l) => $l['tipo'] === 'DESBLOQUEIO')) ?> desbloqueio(s) · total geral</span>
    </article>
    <article class="indicador indicador--saldo">
        <span class="indicador__rotulo">Saldo retido</span>
        <span class="indicador__valor" data-kpi="saldo"><?= e(Dinheiro::formatar($totais['saldo'])) ?></span>
        <span class="indicador__nota" data-kpi-nota="saldo">Bloqueado menos desbloqueado, hoje</span>
    </article>
    <article class="indicador indicador--guias">
        <span class="indicador__rotulo">Guias emitidas</span>
        <span class="indicador__valor" data-kpi="emitidas"><?= e(Dinheiro::formatar($somaGuias($guiasEmitidas))) ?></span>
        <span class="indicador__nota" data-kpi-nota="emitidas"><?= count($guiasEmitidas) ?> guia(s), exceto canceladas</span>
    </article>
    <article class="indicador indicador--acoes">
        <span class="indicador__rotulo">Guias pagas</span>
        <span class="indicador__valor" data-kpi="pagas"><?= e(Dinheiro::formatar($somaGuias($guiasPagas))) ?></span>
        <span class="indicador__nota" data-kpi-nota="pagas"><?= count($guiasPagas) ?> guia(s)</span>
    </article>
    <article class="indicador <?= $guiasVencidas !== [] ? 'indicador--alerta' : 'indicador--designados' ?>" data-kpi-cartao="abertas">
        <span class="indicador__rotulo">Guias em aberto</span>
        <span class="indicador__valor" data-kpi="abertas"><?= e(Dinheiro::formatar($somaGuias($guiasAbertas))) ?></span>
        <span class="indicador__nota" data-kpi-nota="abertas"><?= count($guiasAbertas) ?> guia(s) · <?= count($guiasVencidas) ?> vencida(s)</span>
    </article>
</section>

<section class="card grafico-card" data-grafico-serie hidden>
    <div class="card__cabecalho grafico-card__cabecalho">
        <div>
            <h2 class="card__titulo">Série histórica</h2>
            <p class="card__subtitulo" data-serie-subtitulo>Valores por período</p>
        </div>
        <div class="grafico-card__controles">
            <div class="segmentado" role="group" aria-label="Agrupamento">
                <button type="button" class="segmentado__item" data-agrupamento="mes" aria-pressed="false">Mês</button>
                <button type="button" class="segmentado__item" data-agrupamento="trimestre" aria-pressed="false">Trimestre</button>
                <button type="button" class="segmentado__item" data-agrupamento="ano" aria-pressed="false">Ano</button>
            </div>
            <div class="segmentado" role="group" aria-label="Forma do gráfico">
                <button type="button" class="segmentado__item" data-forma="colunas" aria-pressed="false">▮ Colunas</button>
                <button type="button" class="segmentado__item" data-forma="linhas" aria-pressed="false">⟋ Linhas</button>
            </div>
            <label class="barra-ferramentas__check" title="Soma dos valores desde o primeiro registro, ao fim de cada período">
                <input type="checkbox" data-acumulado> Acumulado
            </label>
        </div>
    </div>
    <div class="card__corpo">
        <div class="grafico-legenda" role="group" aria-label="Séries exibidas (clique para mostrar ou ocultar)" data-serie-legenda></div>
        <div class="grafico" data-serie-area>
            <div class="grafico__dica" role="status" aria-live="polite" data-serie-dica hidden></div>
        </div>
        <details class="grafico-tabela">
            <summary>Ver os dados em tabela</summary>
            <div class="tabela-wrap" data-serie-tabela></div>
        </details>
    </div>
</section>

<section class="card grafico-card" data-grafico-ranking hidden>
    <div class="card__cabecalho grafico-card__cabecalho">
        <div>
            <h2 class="card__titulo">Ranking por processo</h2>
            <p class="card__subtitulo" data-ranking-subtitulo>Processos com os maiores valores</p>
        </div>
        <div class="grafico-card__controles">
            <label class="sr-only" for="rankingMedida">Medida</label>
            <select class="select select--sm" id="rankingMedida" data-ranking-medida>
                <option value="saldo">Saldo retido (ao fim do período)</option>
                <option value="bloqueado">Bloqueado no período</option>
                <option value="desbloqueado">Desbloqueado no período</option>
                <option value="emitidas">Guias emitidas no período</option>
            </select>
            <label class="sr-only" for="rankingQuantidade">Quantidade</label>
            <select class="select select--sm" id="rankingQuantidade" data-ranking-quantidade>
                <option value="5">5 maiores</option>
                <option value="10">10 maiores</option>
                <option value="20">20 maiores</option>
            </select>
        </div>
    </div>
    <div class="card__corpo">
        <div class="grafico grafico--ranking" data-ranking-area></div>
    </div>
</section>

<section class="card" id="lancamentos">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Bloqueios e desbloqueios</h2>
            <p class="card__subtitulo" data-contagem-lancamentos><?= count($lancamentos) ?> lançamento(s)</p>
        </div>
    </div>
    <div class="eventos-wrap painel-tabela" data-tabela-lancamentos>
        <?php
        $retorno = 'painel';
        $mostrarProcesso = true;
        $saldoApos = null;
        require APP_ROOT . '/Views/partials/tabela-bloqueios.php';
        ?>
    </div>
</section>

<section class="card" id="guias">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Guias de depósito judicial</h2>
            <p class="card__subtitulo" data-contagem-guias><?= count($guias) ?> guia(s)</p>
        </div>
    </div>
    <div class="eventos-wrap painel-tabela" data-tabela-guias>
        <?php require APP_ROOT . '/Views/partials/tabela-guias-deposito.php'; ?>
    </div>
</section>

<script type="application/json" id="dadosBloqueios"><?= json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>

<?php
$processoFixo = null;
$retorno = 'painel';
require APP_ROOT . '/Views/partials/modais-bloqueios.php';
?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
