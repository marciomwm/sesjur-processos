<?php
/**
 * Campos da Solicitacao (sem o processo), dentro de um .form-grid. Usados no
 * formulario de pagina (form-solicitacao.php) e no modal de providencias rapidas.
 * Variaveis: $dados, $erros, $lotacoes (LotacaoRepository::opcoes),
 *            $prefixoId (opcional: evita ids repetidos na pagina)
 */
use App\Services\SolicitacaoService;

$p = $prefixoId ?? '';
$erroSol = static fn(string $campo): string => isset($erros[$campo]) ? 'is-invalido' : '';
$mensagemSol = static function (string $campo) use ($erros): string {
    return isset($erros[$campo]) ? '<span class="campo__erro">' . e($erros[$campo]) . '</span>' : '';
};
?>
<div class="campo campo--4">
    <label class="campo__rotulo" for="<?= $p ?>data_solicitacao">Data <span class="obrigatorio">*</span></label>
    <input class="input <?= $erroSol('data_solicitacao') ?>" type="date" id="<?= $p ?>data_solicitacao" name="data_solicitacao" required
           value="<?= e($dados['data_solicitacao'] ?? '') ?>">
    <?= $mensagemSol('data_solicitacao') ?>
</div>
<div class="campo campo--4">
    <label class="campo__rotulo" for="<?= $p ?>expediente">Expediente (SIGADOC) <span class="obrigatorio">*</span></label>
    <input class="input mono <?= $erroSol('expediente') ?>" type="text" id="<?= $p ?>expediente" name="expediente" required
           maxlength="<?= SolicitacaoService::EXPEDIENTE_MAX ?>" value="<?= e($dados['expediente'] ?? '') ?>"
           placeholder="SES-OFI-2026/01234" autocomplete="off">
    <?= $mensagemSol('expediente') ?>
</div>
<div class="campo campo--4">
    <label class="campo__rotulo" for="<?= $p ?>destinatario_lotacao_id">Destinatário <span class="obrigatorio">*</span></label>
    <select class="select <?= $erroSol('destinatario_lotacao_id') ?>" id="<?= $p ?>destinatario_lotacao_id" name="destinatario_lotacao_id" required>
        <option value="">— Selecione a unidade —</option>
        <?php foreach ($lotacoes as $l): ?>
            <option value="<?= (int)$l['id'] ?>" <?= (string)($dados['destinatario_lotacao_id'] ?? '') === (string)$l['id'] ? 'selected' : '' ?>>
                <?= e($l['rotulo'] ?? $l['nome']) ?><?= (int)$l['ativo'] === 1 ? '' : ' (inativa)' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?= $mensagemSol('destinatario_lotacao_id') ?>
</div>

<div class="campo">
    <label class="campo__rotulo" for="<?= $p ?>assunto">Assunto <span class="obrigatorio">*</span></label>
    <textarea class="textarea textarea--curta <?= $erroSol('assunto') ?>" id="<?= $p ?>assunto" name="assunto" rows="3" required
              maxlength="<?= SolicitacaoService::ASSUNTO_MAX ?>"
              placeholder="Ex.: Informações sobre o estoque do medicamento solicitado"><?= e($dados['assunto'] ?? '') ?></textarea>
    <?= $mensagemSol('assunto') ?>
</div>

<div class="campo campo--4">
    <label class="campo__rotulo" for="<?= $p ?>prazo">Prazo para resposta</label>
    <input class="input <?= $erroSol('prazo') ?>" type="date" id="<?= $p ?>prazo" name="prazo" value="<?= e($dados['prazo'] ?? '') ?>">
    <?php if (isset($erros['prazo'])): ?>
        <?= $mensagemSol('prazo') ?>
    <?php else: ?>
        <span class="campo__ajuda">Opcional</span>
    <?php endif; ?>
</div>
<div class="campo campo--4">
    <label class="campo__rotulo" for="<?= $p ?>status">Status</label>
    <select class="select <?= $erroSol('status') ?>" id="<?= $p ?>status" name="status">
        <?php foreach (SolicitacaoService::STATUS as $codigo => $rotulo): ?>
            <option value="<?= e($codigo) ?>" <?= ($dados['status'] ?? 'AGUARDANDO') === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
        <?php endforeach; ?>
    </select>
    <?= $mensagemSol('status') ?>
</div>
