<?php
/**
 * Controles de paginacao.
 * Variaveis: $paginacao (App\Core\Paginacao), $query (array)
 */
/** @var \App\Core\Paginacao $paginacao */
$query = $query ?? [];
unset($query['pagina']);
if ($paginacao->totalPaginas <= 1) {
    return;
}
$inicio = max(1, $paginacao->pagina - 2);
$fim    = min($paginacao->totalPaginas, $inicio + 4);
$inicio = max(1, $fim - 4);
?>
<nav class="paginacao" aria-label="Paginação">
    <span class="paginacao__info">
        <?= e((string)$paginacao->total) ?> registro(s) · página <?= e((string)$paginacao->pagina) ?> de <?= e((string)$paginacao->totalPaginas) ?>
    </span>
    <div class="paginacao__botoes">
        <a class="btn btn--ghost btn--sm <?= $paginacao->pagina <= 1 ? 'is-desabilitado' : '' ?>"
           href="<?= e($paginacao->urlPagina(max(1, $paginacao->pagina - 1), $query)) ?>">‹ Anterior</a>
        <?php for ($p = $inicio; $p <= $fim; $p++): ?>
            <a class="btn btn--sm <?= $p === $paginacao->pagina ? 'btn--primario' : 'btn--ghost' ?>"
               href="<?= e($paginacao->urlPagina($p, $query)) ?>"><?= e((string)$p) ?></a>
        <?php endfor; ?>
        <a class="btn btn--ghost btn--sm <?= $paginacao->pagina >= $paginacao->totalPaginas ? 'is-desabilitado' : '' ?>"
           href="<?= e($paginacao->urlPagina(min($paginacao->totalPaginas, $paginacao->pagina + 1), $query)) ?>">Próxima ›</a>
    </div>
</nav>
