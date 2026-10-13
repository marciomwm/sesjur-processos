<?php
/**
 * Campos de prazo do formulario de processo administrativo (novo/editar).
 * Variaveis: $dados (prazo, prazo_hora), $erros
 *
 * O prazo padrao (abertura + 30 dias) e aplicado no servidor quando o campo
 * fica vazio; form-processo-administrativo.js o acompanha na tela ao mudar a
 * data de abertura, sem sobrescrever um prazo digitado pelo usuario.
 */
use App\Core\Prazo;
?>
<div class="campo campo--2">
    <label class="campo__rotulo" for="prazo">Prazo</label>
    <input class="input <?= isset($erros['prazo']) ? 'is-invalido' : '' ?>"
           type="date" id="prazo" name="prazo" value="<?= e($dados['prazo'] ?? '') ?>"
           data-prazo-padrao-dias="<?= Prazo::DIAS_PADRAO ?>">
    <?php if (isset($erros['prazo'])): ?>
        <span class="campo__erro"><?= e($erros['prazo']) ?></span>
    <?php else: ?>
        <span class="campo__ajuda">Padrão: abertura + <?= Prazo::DIAS_PADRAO ?> dias</span>
    <?php endif; ?>
</div>
<div class="campo campo--2">
    <label class="campo__rotulo" for="prazo_hora">Hora <span class="texto-secundario">(opcional)</span></label>
    <input class="input <?= isset($erros['prazo_hora']) ? 'is-invalido' : '' ?>"
           type="time" id="prazo_hora" name="prazo_hora" value="<?= e(substr((string)($dados['prazo_hora'] ?? ''), 0, 5)) ?>">
    <?php if (isset($erros['prazo_hora'])): ?>
        <span class="campo__erro"><?= e($erros['prazo_hora']) ?></span>
    <?php else: ?>
        <span class="campo__ajuda">Sem hora: até o fim do dia</span>
    <?php endif; ?>
</div>
