<?php
/**
 * Campo da Anotacao (sem o processo), dentro de um .form-grid. Usado no
 * formulario de pagina (form-anotacao.php) e no modal de providencias rapidas.
 * Variaveis: $dados, $erros, $prefixoId (opcional: evita ids repetidos na pagina)
 */
use App\Services\AnotacaoService;

$p = $prefixoId ?? '';
?>
<div class="campo">
    <label class="campo__rotulo" for="<?= $p ?>texto">Texto <span class="obrigatorio">*</span></label>
    <textarea class="textarea <?= isset($erros['texto']) ? 'is-invalido' : '' ?>" id="<?= $p ?>texto" name="texto" rows="8" required
              maxlength="<?= AnotacaoService::TEXTO_MAX ?>"
              placeholder="Observação interna, registro de contato, informação relevante…"><?= e($dados['texto'] ?? '') ?></textarea>
    <?php if (isset($erros['texto'])): ?><span class="campo__erro"><?= e($erros['texto']) ?></span><?php endif; ?>
</div>
