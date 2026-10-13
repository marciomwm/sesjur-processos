<?php
/**
 * Widget de vinculacao N:N (usado nos formularios dos processos).
 * Variaveis:
 *   $tipoAlvo     'judicial' | 'administrativo' | 'extrajudicial'  (o que sera pesquisado)
 *   $selecionados array<array{id:int,rotulo:string,detalhe:string}>
 *   $campoNome    nome do input hidden (ex.: judiciais_ids[])
 */
$tipoAlvo     = $tipoAlvo ?? 'judicial';
$selecionados = $selecionados ?? [];
$campoNome    = $campoNome ?? 'judiciais_ids[]';
$textosWidget = [
    'judicial'       => ['busca' => 'Pesquisar pelo número CNJ…', 'nome' => 'judicial', 'novo' => 'novo-processo-judicial.php', 'rotulo' => 'Processo Judicial'],
    'administrativo' => ['busca' => 'Pesquisar pelo protocolo…', 'nome' => 'administrativo', 'novo' => 'novo-processo-administrativo.php', 'rotulo' => 'Processo Administrativo'],
    'extrajudicial'  => ['busca' => 'Pesquisar pelo protocolo, assunto ou sigla da instituição…', 'nome' => 'extrajudicial', 'novo' => 'novo-processo-extrajudicial.php', 'rotulo' => 'Processo Extrajudicial'],
][$tipoAlvo] ?? null;
if ($textosWidget === null) {
    return;
}
?>
<div class="vinculos" data-vinculos data-tipo="<?= e($tipoAlvo) ?>" data-campo="<?= e($campoNome) ?>">
    <div class="vinculos__busca">
        <input type="text"
               class="input"
               data-vinculo-busca
               autocomplete="off"
               placeholder="<?= e($textosWidget['busca']) ?>"
               aria-label="Pesquisar processo para vincular">
        <div class="vinculos__resultados" data-vinculo-resultados hidden></div>
    </div>

    <ul class="vinculos__lista" data-vinculo-lista>
        <?php foreach ($selecionados as $item): ?>
            <li class="vinculos__item" data-id="<?= e((string)$item['id']) ?>">
                <input type="hidden" name="<?= e($campoNome) ?>" value="<?= e((string)$item['id']) ?>">
                <span class="vinculos__rotulo mono"><?= e($item['rotulo']) ?></span>
                <span class="vinculos__detalhe"><?= e($item['detalhe'] ?? '') ?></span>
                <button type="button" class="vinculos__remover" data-vinculo-remover aria-label="Remover vínculo">×</button>
            </li>
        <?php endforeach; ?>
    </ul>

    <p class="vinculos__vazio" data-vinculo-vazio <?= $selecionados !== [] ? 'hidden' : '' ?>>
        Nenhum processo <?= e($textosWidget['nome']) ?> vinculado.
    </p>

    <div class="vinculos__acoes">
        <a class="btn btn--ghost btn--sm" href="<?= e(url($textosWidget['novo'])) ?>" target="_blank" rel="noopener">
            ＋ Cadastrar novo <?= e($textosWidget['rotulo']) ?>
        </a>
        <span class="vinculos__dica">Digite ao menos 3 caracteres para pesquisar.</span>
    </div>
</div>
