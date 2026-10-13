<?php
/**
 * Bloqueios judiciais e guias de deposito na ficha do Processo Judicial.
 * Variaveis: $registro (processo judicial), $lancamentos, $guias.
 */
use App\Core\Dinheiro;
use App\Services\BloqueioJudicialService;
use App\Services\GuiaDepositoService;

$totais = BloqueioJudicialService::saldos($lancamentos);
$guiasAbertas = array_filter($guias, static fn(array $g): bool => in_array(GuiaDepositoService::situacao($g), ['EMITIDA', 'VENCIDA'], true));
$guiasVencidas = array_filter($guias, static fn(array $g): bool => GuiaDepositoService::situacao($g) === 'VENCIDA');
$somaGuias = static fn(array $lista): int => array_sum(array_map(static fn(array $g): int => Dinheiro::deDecimal($g['valor']), $lista));
$guiasPagas = array_filter($guias, static fn(array $g): bool => $g['status'] === 'PAGA');
?>
<section class="card" id="secao-bloqueios">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Bloqueios e depósitos judiciais</h2>
            <p class="card__subtitulo"><?= count($lancamentos) ?> lançamento(s) · <?= count($guias) ?> guia(s) de depósito ·
                <a href="<?= e(url('bloqueios-judiciais.php?processo=' . (int)$registro['id'])) ?>">ver no painel</a></p>
        </div>
        <div class="acoes-linha">
            <button type="button" class="btn btn--secundario btn--sm" data-modal-abrir="modalBloqueio" data-modal-titulo="Registrar bloqueio"
                    data-modal-dados='{"tipo":"BLOQUEIO"}'>▼ Bloqueio</button>
            <button type="button" class="btn btn--secundario btn--sm" data-modal-abrir="modalBloqueio" data-modal-titulo="Registrar desbloqueio"
                    data-modal-dados='{"tipo":"DESBLOQUEIO"}' <?= $totais['saldo'] <= 0 ? 'title="Não há saldo retido a desbloquear."' : '' ?>>▲ Desbloqueio</button>
            <button type="button" class="btn btn--primario btn--sm" data-modal-abrir="modalGuia">＋ Guia de depósito</button>
        </div>
    </div>
    <div class="card__corpo">
        <div class="resumo-importacao resumo-valores">
            <div class="resumo-importacao__item">
                <span class="resumo-importacao__rotulo">Total bloqueado</span>
                <span class="resumo-importacao__valor"><?= e(Dinheiro::formatar($totais['bloqueado'])) ?></span>
            </div>
            <div class="resumo-importacao__item">
                <span class="resumo-importacao__rotulo">Total desbloqueado</span>
                <span class="resumo-importacao__valor"><?= e(Dinheiro::formatar($totais['desbloqueado'])) ?></span>
            </div>
            <div class="resumo-importacao__item resumo-valores__destaque">
                <span class="resumo-importacao__rotulo">Saldo retido</span>
                <span class="resumo-importacao__valor"><?= e(Dinheiro::formatar($totais['saldo'])) ?></span>
            </div>
            <div class="resumo-importacao__item">
                <span class="resumo-importacao__rotulo">Guias pagas</span>
                <span class="resumo-importacao__valor"><?= e(Dinheiro::formatar($somaGuias($guiasPagas))) ?></span>
                <span class="resumo-importacao__nota"><?= count($guiasPagas) ?> guia(s)</span>
            </div>
            <div class="resumo-importacao__item <?= $guiasVencidas !== [] ? 'resumo-valores__alerta' : '' ?>">
                <span class="resumo-importacao__rotulo">Guias em aberto</span>
                <span class="resumo-importacao__valor"><?= e(Dinheiro::formatar($somaGuias($guiasAbertas))) ?></span>
                <span class="resumo-importacao__nota"><?= count($guiasAbertas) ?> guia(s)<?= $guiasVencidas !== [] ? ' · ' . count($guiasVencidas) . ' vencida(s)' : '' ?></span>
            </div>
        </div>

        <h3 class="subtitulo-secao mt-16">Bloqueios e desbloqueios</h3>
        <?php
        $retorno = 'processo';
        $mostrarProcesso = false;
        $saldoApos = $totais['saldo_apos'];
        require APP_ROOT . '/Views/partials/tabela-bloqueios.php';
        ?>

        <h3 class="subtitulo-secao mt-16">Guias de depósito judicial</h3>
        <?php require APP_ROOT . '/Views/partials/tabela-guias-deposito.php'; ?>
    </div>
</section>
