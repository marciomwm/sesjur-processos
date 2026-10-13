<?php
/**
 * Campos da Acao (sem o processo), dentro de um .form-grid. Usados no
 * formulario de pagina (form-acao.php) e no modal de providencias rapidas.
 * Variaveis: $dados, $erros, $prefixoId (opcional: evita ids repetidos na pagina)
 */
use App\Services\AcaoService;

$p = $prefixoId ?? '';
$erroAcao = static fn(string $campo): string => isset($erros[$campo]) ? 'is-invalido' : '';
?>
<div class="campo">
    <label class="campo__rotulo" for="<?= $p ?>descricao">Descrição <span class="obrigatorio">*</span></label>
    <input class="input <?= $erroAcao('descricao') ?>" type="text" id="<?= $p ?>descricao" name="descricao" required
           maxlength="<?= AcaoService::DESCRICAO_MAX ?>" value="<?= e($dados['descricao'] ?? '') ?>"
           placeholder="Ex.: Solicitar informações à Superintendência de Assistência Farmacêutica">
    <?php if (isset($erros['descricao'])): ?><span class="campo__erro"><?= e($erros['descricao']) ?></span><?php endif; ?>
</div>

<div class="campo campo--4">
    <label class="campo__rotulo" for="<?= $p ?>prazo">Prazo</label>
    <input class="input <?= $erroAcao('prazo') ?>" type="date" id="<?= $p ?>prazo" name="prazo" value="<?= e($dados['prazo'] ?? '') ?>">
    <?php if (isset($erros['prazo'])): ?><span class="campo__erro"><?= e($erros['prazo']) ?></span><?php endif; ?>
</div>
<div class="campo campo--4">
    <label class="campo__rotulo" for="<?= $p ?>prazo_hora">Hora <span class="texto-secundario">(opcional)</span></label>
    <input class="input <?= $erroAcao('prazo_hora') ?>" type="time" id="<?= $p ?>prazo_hora" name="prazo_hora"
           value="<?= e(substr((string)($dados['prazo_hora'] ?? ''), 0, 5)) ?>">
    <?php if (isset($erros['prazo_hora'])): ?>
        <span class="campo__erro"><?= e($erros['prazo_hora']) ?></span>
    <?php else: ?>
        <span class="campo__ajuda">Sem hora: até o fim do dia</span>
    <?php endif; ?>
</div>
<div class="campo campo--4">
    <label class="campo__rotulo" for="<?= $p ?>status">Status</label>
    <select class="select <?= $erroAcao('status') ?>" id="<?= $p ?>status" name="status">
        <?php foreach (AcaoService::STATUS as $codigo => $rotulo): ?>
            <option value="<?= e($codigo) ?>" <?= ($dados['status'] ?? 'PENDENTE') === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (isset($erros['status'])): ?><span class="campo__erro"><?= e($erros['status']) ?></span><?php endif; ?>
</div>

<div class="campo">
    <label class="campo__rotulo" for="<?= $p ?>observacao">Observação</label>
    <textarea class="textarea <?= $erroAcao('observacao') ?>" id="<?= $p ?>observacao" name="observacao" rows="4"
              maxlength="<?= AcaoService::OBSERVACAO_MAX ?>"><?= e($dados['observacao'] ?? '') ?></textarea>
    <?php if (isset($erros['observacao'])): ?><span class="campo__erro"><?= e($erros['observacao']) ?></span><?php endif; ?>
</div>
