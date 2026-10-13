<?php
/**
 * Modais de lancamento de Bloqueio/Desbloqueio e de Guia de Deposito.
 * Variaveis: $processoFixo (?array id, numero_cnj - na ficha do processo;
 *            null no painel, que mostra a busca de processo), $retorno
 *            ('processo' ou 'painel').
 *
 * Gatilhos: data-modal-abrir="modalBloqueio" | "modalGuia" (modal.js).
 * Na edicao, data-processo-rotulo do gatilho fixa o processo (ele nao muda).
 */
use App\Core\Csrf;
use App\Services\BloqueioJudicialService;
use App\Services\GuiaDepositoService;

$hoje = date('Y-m-d');
$idFixo = $processoFixo !== null ? (string)(int)$processoFixo['id'] : '';

// No painel o hidden fica dentro do campo de busca: o erro do processo aparece ali (modal.js).
$campoProcesso = static function (string $sufixo) use ($processoFixo, $idFixo): void { ?>
    <?php if ($processoFixo !== null): ?>
        <input type="hidden" name="processo_judicial_id" value="<?= e($idFixo) ?>" data-padrao="<?= e($idFixo) ?>">
    <?php else: ?>
        <div class="campo" data-processo-busca-campo>
            <label class="campo__rotulo" for="processo-busca-<?= e($sufixo) ?>">Processo judicial <span class="obrigatorio">*</span></label>
            <div class="vinculos__busca">
                <input class="input" type="search" id="processo-busca-<?= e($sufixo) ?>" data-processo-busca autocomplete="off"
                       placeholder="Digite o número CNJ (mín. 3 caracteres)…" aria-autocomplete="list" autofocus
                       aria-controls="processo-resultados-<?= e($sufixo) ?>" aria-expanded="false">
                <div class="vinculos__resultados" id="processo-resultados-<?= e($sufixo) ?>" data-processo-resultados role="listbox" hidden></div>
            </div>
            <input type="hidden" name="processo_judicial_id" value="" data-padrao="">
            <span class="campo__ajuda" data-processo-escolhido>Nenhum processo escolhido.</span>
        </div>
    <?php endif; ?>
<?php };
?>
<dialog class="modal" id="modalBloqueio" aria-labelledby="modalBloqueioTitulo">
    <form method="post" action="<?= e(url('salvar-bloqueio-judicial.php')) ?>" class="modal__form" data-modal-form data-form-bloqueio novalidate>
        <?= Csrf::campo() ?>
        <input type="hidden" name="id" value="" data-padrao="">
        <input type="hidden" name="retorno" value="<?= e($retorno) ?>">
        <header class="modal__cabecalho">
            <div>
                <h2 class="modal__titulo" id="modalBloqueioTitulo" data-modal-titulo>Novo lançamento</h2>
                <p class="modal__contexto">
                    <span data-processo-contexto <?= $processoFixo === null ? 'hidden' : '' ?>>Processo <strong class="mono" data-processo-contexto-rotulo><?= e($processoFixo['numero_cnj'] ?? '') ?></strong></span>
                    <span>O saldo retido é a soma dos bloqueios menos a dos desbloqueios.</span>
                </p>
            </div>
            <button type="button" class="modal__fechar" data-modal-fechar aria-label="Fechar">×</button>
        </header>
        <div class="modal__corpo">
            <div class="alert alert--erro" data-modal-erro tabindex="-1" role="alert" hidden></div>
            <div class="form-grid">
                <?php $campoProcesso('bloqueio'); ?>
                <fieldset class="campo campo--opcoes">
                    <legend class="campo__rotulo">Tipo <span class="obrigatorio">*</span></legend>
                    <div class="opcoes-tipo">
                        <?php foreach (BloqueioJudicialService::TIPOS as $codigo => $rotulo): ?>
                            <label class="opcoes-tipo__item opcoes-tipo__item--<?= strtolower($codigo) ?>">
                                <input type="radio" name="tipo" value="<?= e($codigo) ?>" <?= $codigo === 'BLOQUEIO' ? 'checked' : '' ?>>
                                <span><?= $codigo === 'BLOQUEIO' ? '▼' : '▲' ?> <?= e($rotulo) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="bloqueio-data">Data <span class="obrigatorio">*</span></label>
                    <input class="input" type="date" id="bloqueio-data" name="data_lancamento" required max="<?= e($hoje) ?>" value="<?= e($hoje) ?>">
                </div>
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="bloqueio-valor">Valor (R$) <span class="obrigatorio">*</span></label>
                    <input class="input input--valor" type="text" id="bloqueio-valor" name="valor" required inputmode="decimal"
                           placeholder="0,00" autocomplete="off" maxlength="22" data-campo-valor<?= $processoFixo !== null ? " autofocus" : "" ?>>
                </div>
                <div class="campo">
                    <label class="campo__rotulo" for="bloqueio-identificador">Identificador</label>
                    <input class="input" type="text" id="bloqueio-identificador" name="identificador" maxlength="<?= BloqueioJudicialService::IDENTIFICADOR_MAX ?>"
                           placeholder="Protocolo SISBAJUD, nº da ordem ou conta" autocomplete="off">
                </div>
                <div class="campo">
                    <label class="campo__rotulo" for="bloqueio-observacao">Observação</label>
                    <textarea class="textarea textarea--curta" id="bloqueio-observacao" name="observacao" maxlength="<?= BloqueioJudicialService::OBSERVACAO_MAX ?>"
                              placeholder="Conta atingida, decisão que determinou, destino do valor desbloqueado…"></textarea>
                </div>
            </div>
        </div>
        <footer class="modal__rodape">
            <button type="button" class="btn btn--ghost" data-modal-fechar>Cancelar</button>
            <button type="submit" class="btn btn--primario">💾 Salvar</button>
        </footer>
    </form>
</dialog>

<dialog class="modal" id="modalGuia" aria-labelledby="modalGuiaTitulo">
    <form method="post" action="<?= e(url('salvar-guia-deposito.php')) ?>" class="modal__form" data-modal-form data-form-guia novalidate>
        <?= Csrf::campo() ?>
        <input type="hidden" name="id" value="" data-padrao="">
        <input type="hidden" name="retorno" value="<?= e($retorno) ?>">
        <header class="modal__cabecalho">
            <div>
                <h2 class="modal__titulo" id="modalGuiaTitulo" data-modal-titulo>Nova guia de depósito judicial</h2>
                <p class="modal__contexto">
                    <span data-processo-contexto <?= $processoFixo === null ? 'hidden' : '' ?>>Processo <strong class="mono" data-processo-contexto-rotulo><?= e($processoFixo['numero_cnj'] ?? '') ?></strong></span>
                    <span>Guia para depósito de valores em juízo. Sem pagamento após o vencimento, aparece como Vencida.</span>
                </p>
            </div>
            <button type="button" class="modal__fechar" data-modal-fechar aria-label="Fechar">×</button>
        </header>
        <div class="modal__corpo">
            <div class="alert alert--erro" data-modal-erro tabindex="-1" role="alert" hidden></div>
            <div class="form-grid">
                <?php $campoProcesso('guia'); ?>
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="guia-numero">Número da guia</label>
                    <input class="input" type="text" id="guia-numero" name="numero" maxlength="<?= GuiaDepositoService::NUMERO_MAX ?>"
                           placeholder="Nº da guia ou ID do depósito" autocomplete="off">
                </div>
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="guia-valor">Valor (R$) <span class="obrigatorio">*</span></label>
                    <input class="input input--valor" type="text" id="guia-valor" name="valor" required inputmode="decimal"
                           placeholder="0,00" autocomplete="off" maxlength="22" data-campo-valor<?= $processoFixo !== null ? " autofocus" : "" ?>>
                </div>
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="guia-emissao">Data de emissão <span class="obrigatorio">*</span></label>
                    <input class="input" type="date" id="guia-emissao" name="data_emissao" required max="<?= e($hoje) ?>" value="<?= e($hoje) ?>">
                </div>
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="guia-vencimento">Vencimento</label>
                    <input class="input" type="date" id="guia-vencimento" name="vencimento">
                </div>
                <div class="campo">
                    <label class="campo__rotulo" for="guia-finalidade">Finalidade</label>
                    <input class="input" type="text" id="guia-finalidade" name="finalidade" maxlength="<?= GuiaDepositoService::FINALIDADE_MAX ?>"
                           placeholder="Ex.: custeio de tratamento determinado na decisão de 10/10/2026" autocomplete="off">
                </div>
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="guia-status">Situação <span class="obrigatorio">*</span></label>
                    <select class="select" id="guia-status" name="status" data-guia-status>
                        <?php foreach (GuiaDepositoService::STATUS as $codigo => $rotulo): ?>
                            <option value="<?= e($codigo) ?>"><?= e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo campo--6" data-guia-pagamento hidden>
                    <label class="campo__rotulo" for="guia-pagamento">Data do pagamento <span class="obrigatorio">*</span></label>
                    <input class="input" type="date" id="guia-pagamento" name="data_pagamento" max="<?= e($hoje) ?>">
                </div>
                <div class="campo">
                    <label class="campo__rotulo" for="guia-observacao">Observação</label>
                    <textarea class="textarea textarea--curta" id="guia-observacao" name="observacao" maxlength="<?= GuiaDepositoService::OBSERVACAO_MAX ?>"></textarea>
                </div>
            </div>
        </div>
        <footer class="modal__rodape">
            <button type="button" class="btn btn--ghost" data-modal-fechar>Cancelar</button>
            <button type="submit" class="btn btn--primario">💾 Salvar</button>
        </footer>
    </form>
</dialog>
