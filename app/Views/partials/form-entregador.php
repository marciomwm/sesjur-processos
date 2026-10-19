<?php
/**
 * Formulario de Entregador (cadastro e edicao).
 * Variaveis: $dados (documento, nome), $erros, ?$processoRetorno (cadastro
 *            a partir dos Dados do Pagamento), ?$urlCancelar (edicao)
 */
use App\Core\Csrf;
use App\Services\EntregadorService;
use App\Validators\CnpjValidator;

$processoRetorno = $processoRetorno ?? null;
$urlCancelar = $urlCancelar ?? null;
$documento = (string)($dados['documento'] ?? '');
// Gravado (so letras e digitos): exibe com a mascara do tipo.
$documentoExibido = in_array(strlen($documento), [11, 14], true) && CnpjValidator::normalizar($documento) === $documento
    ? EntregadorService::formatarDocumento($documento)
    : $documento;
?>
<form method="post" class="form-grid" novalidate>
    <?= Csrf::campo() ?>
    <?php if ($processoRetorno !== null): ?>
        <input type="hidden" name="processo_id" value="<?= (int)$processoRetorno['id'] ?>">
    <?php endif; ?>
    <div class="campo campo--4">
        <label class="campo__rotulo" for="documento">CPF/CNPJ <span class="obrigatorio">*</span></label>
        <input class="input mono <?= isset($erros['documento']) ? 'is-invalido' : '' ?>" type="text" id="documento" name="documento" required
               maxlength="18" autocomplete="off" placeholder="000.000.000-00 ou 00.000.000/0000-00"
               value="<?= e($documentoExibido) ?>" data-mascara="cpf-cnpj" aria-describedby="documentoAjuda">
        <?php if (isset($erros['documento'])): ?>
            <span class="campo__erro" id="documentoAjuda"><?= e($erros['documento']) ?></span>
        <?php else: ?>
            <span class="campo__ajuda" id="documentoAjuda">CPF para pessoa física; CNPJ (inclusive alfanumérico) para pessoa jurídica.</span>
        <?php endif; ?>
    </div>
    <div class="campo campo--8">
        <label class="campo__rotulo" for="nome">Nome <span class="obrigatorio">*</span></label>
        <input class="input <?= isset($erros['nome']) ? 'is-invalido' : '' ?>" type="text" id="nome" name="nome" required
               maxlength="<?= EntregadorService::NOME_MAX ?>" autocomplete="off" placeholder="Nome completo ou razão social"
               value="<?= e((string)($dados['nome'] ?? '')) ?>">
        <?php if (isset($erros['nome'])): ?><span class="campo__erro"><?= e($erros['nome']) ?></span><?php endif; ?>
    </div>
    <div class="campo">
        <div class="acoes-linha">
            <?php if ($urlCancelar !== null): ?>
                <a class="btn btn--ghost" href="<?= e($urlCancelar) ?>">Cancelar</a>
                <button type="submit" class="btn btn--primario">Salvar alterações</button>
            <?php else: ?>
                <button type="submit" class="btn btn--primario">＋ Cadastrar entregador</button>
                <?php if ($processoRetorno !== null): ?>
                    <a class="btn btn--ghost" href="<?= e(url('processo-administrativo.php?id=' . (int)$processoRetorno['id'] . '#dados-pagamento')) ?>">Voltar ao processo</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</form>
