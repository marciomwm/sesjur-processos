<?php
/**
 * Formulario de Anotacao (nova e editar).
 * Variaveis: $dados, $erros, $processo (array|null: processo ja definido),
 *            $retorno, $rotuloSalvar
 */
use App\Core\Csrf;
?>
<form method="post" class="form-acao" novalidate>
    <?= Csrf::campo() ?>
    <input type="hidden" name="retorno" value="<?= e($retorno) ?>">

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Anotação</h2>
                <p class="card__subtitulo">Nota interna da equipe sobre o processo. Não é enviada ao SIGADOC.</p>
            </div>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <div class="campo">
                    <span class="campo__rotulo">Processo administrativo <span class="obrigatorio">*</span></span>
                    <?php if ($processo !== null): ?>
                        <input type="hidden" name="processo_id" value="<?= (int)$processo['id'] ?>">
                        <p class="form-acao__processo">
                            <a class="mono" href="<?= e(url('processo-administrativo.php?id=' . $processo['id'])) ?>"><?= e($processo['protocolo']) ?></a>
                            <?php if (!empty($processo['objeto'])): ?>
                                <span class="texto-secundario"><?= e(mb_substr((string)$processo['objeto'], 0, 160)) ?></span>
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <input class="input mono <?= isset($erros['processo']) ? 'is-invalido' : '' ?>" type="text" id="protocolo" name="protocolo"
                               list="sugestoesProtocolo" data-autocompletar-protocolo autocomplete="off"
                               placeholder="SES-PRO-2026/23509" value="<?= e($dados['protocolo'] ?? '') ?>" aria-label="Protocolo do processo administrativo">
                        <datalist id="sugestoesProtocolo"></datalist>
                        <?php if (isset($erros['processo'])): ?>
                            <span class="campo__erro"><?= e($erros['processo']) ?></span>
                        <?php else: ?>
                            <span class="campo__ajuda">Digite parte do protocolo ou do objeto e escolha uma sugestão.</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php require __DIR__ . '/campos-anotacao.php'; ?>
            </div>
        </div>
        <div class="card__rodape">
            <button type="submit" class="btn btn--primario">💾 <?= e($rotuloSalvar) ?></button>
            <a class="btn btn--ghost" href="<?= e(url($retorno)) ?>">Cancelar</a>
        </div>
    </section>
</form>
