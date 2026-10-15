<?php
/**
 * Campo de pesquisa com autocomplete que grava o id escolhido num campo
 * oculto (assets/js/intimacoes.js). Sem escolha da lista, o id fica vazio.
 *
 * Variavel $ac:
 *   id          id do input visivel
 *   nome        name do campo oculto com o id escolhido
 *   nomeTipo    (opcional) name do campo oculto com o tipo do item (processos)
 *   rotulo      rotulo do campo
 *   endpoint    caminho da API (ex.: api/pesquisar-agentes-publicos.php)
 *   minimo      caracteres minimos para pesquisar
 *   placeholder, ajuda, vazio (mensagem sem resultados)
 *   valor       ?array{id,rotulo,detalhe,tipo?} item ja escolhido
 *   erro        ?string mensagem de erro do campo
 *   obrigatorio bool; classe (classe extra do .campo, ex.: campo--6)
 *   extra       (opcional) HTML de botoes ao lado do rotulo (ja escapado)
 */
$valorAc = $ac['valor'] ?? null;
$escolhidoAc = $valorAc !== null ? 'Escolhido: ' . $valorAc['rotulo'] . ($valorAc['detalhe'] !== '' ? ' · ' . $valorAc['detalhe'] : '') : 'Nenhum item escolhido.';
?>
<div class="campo campo-pesquisa <?= e($ac['classe'] ?? '') ?>" data-pesquisa data-endpoint="<?= e($ac['endpoint']) ?>"
     data-minimo="<?= (int)($ac['minimo'] ?? 3) ?>" data-vazio="<?= e($ac['vazio'] ?? 'Nenhum resultado.') ?>">
    <div class="campo-pesquisa__topo">
        <label class="campo__rotulo" for="<?= e($ac['id']) ?>"><?= e($ac['rotulo']) ?><?php if (!empty($ac['obrigatorio'])): ?> <span class="obrigatorio">*</span><?php endif; ?></label>
        <?= $ac['extra'] ?? '' ?>
    </div>
    <div class="vinculos__busca">
        <input class="input <?= !empty($ac['erro']) ? 'is-invalido' : '' ?>" type="search" id="<?= e($ac['id']) ?>" data-pesquisa-busca autocomplete="off"
               value="<?= e($valorAc['rotulo'] ?? '') ?>" placeholder="<?= e($ac['placeholder'] ?? '') ?>"
               aria-autocomplete="list" aria-controls="<?= e($ac['id']) ?>-resultados" aria-expanded="false"
               <?= !empty($ac['erro']) ? 'aria-invalid="true"' : '' ?>>
        <div class="vinculos__resultados" id="<?= e($ac['id']) ?>-resultados" data-pesquisa-resultados role="listbox" hidden></div>
    </div>
    <input type="hidden" name="<?= e($ac['nome']) ?>" value="<?= e((string)($valorAc['id'] ?? '')) ?>" data-pesquisa-valor>
    <?php if (!empty($ac['nomeTipo'])): ?>
        <input type="hidden" name="<?= e($ac['nomeTipo']) ?>" value="<?= e((string)($valorAc['tipo'] ?? '')) ?>" data-pesquisa-tipo>
    <?php endif; ?>
    <?php if (!empty($ac['erro'])): ?>
        <span class="campo__erro"><?= e($ac['erro']) ?></span>
    <?php endif; ?>
    <span class="campo__ajuda" data-pesquisa-escolhido><?= e($valorAc !== null ? $escolhidoAc : ($ac['ajuda'] ?? $escolhidoAc)) ?></span>
</div>
