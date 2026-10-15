<?php
/**
 * Modais de Intimacao Pessoal: registrar cumprimento e excluir (logicamente).
 * Variavel: $retornoIntimacao (pagina interna para onde voltar, ex.:
 *           'processo-judicial.php?id=5').
 *
 * Gatilhos (modal.js): data-modal-abrir="modalCumprimentoIntimacao" ou
 * "modalExcluirIntimacao", com data-modal-dados='{"id": 12}'. No
 * cumprimento, data-prazo/data-recebimento/data-intimado dao o contexto
 * (intimacoes.js).
 */
use App\Core\Csrf;
use App\Services\IntimacaoPessoalService;
?>
<dialog class="modal" id="modalCumprimentoIntimacao" aria-labelledby="modalCumprimentoIntimacaoTitulo">
    <form method="post" action="<?= e(url('registrar-cumprimento-intimacao.php')) ?>" class="modal__form" data-modal-form data-form-cumprimento novalidate>
        <?= Csrf::campo() ?>
        <input type="hidden" name="id" value="" data-padrao="">
        <input type="hidden" name="retorno" value="<?= e($retornoIntimacao) ?>">
        <header class="modal__cabecalho">
            <div>
                <h2 class="modal__titulo" id="modalCumprimentoIntimacaoTitulo" data-modal-titulo>Registrar cumprimento</h2>
                <p class="modal__contexto" data-cumprimento-contexto>A intimação passa para o status Cumprida.</p>
            </div>
            <button type="button" class="modal__fechar" data-modal-fechar aria-label="Fechar">×</button>
        </header>
        <div class="modal__corpo">
            <div class="alert alert--erro" data-modal-erro tabindex="-1" role="alert" hidden></div>
            <div class="form-grid">
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="cumprimento-data">Data do cumprimento <span class="obrigatorio">*</span></label>
                    <input class="input" type="date" id="cumprimento-data" name="data_cumprimento" required max="<?= e(date('Y-m-d')) ?>">
                    <span class="campo__ajuda" data-cumprimento-aviso>Data efetiva da providência, não a data deste registro.</span>
                </div>
            </div>
        </div>
        <footer class="modal__rodape">
            <button type="button" class="btn btn--ghost" data-modal-fechar>Cancelar</button>
            <button type="submit" class="btn btn--primario">✓ Registrar cumprimento</button>
        </footer>
    </form>
</dialog>

<dialog class="modal" id="modalExcluirIntimacao" aria-labelledby="modalExcluirIntimacaoTitulo">
    <form method="post" action="<?= e(url('excluir-intimacao.php')) ?>" class="modal__form" data-modal-form novalidate>
        <?= Csrf::campo() ?>
        <input type="hidden" name="id" value="" data-padrao="">
        <input type="hidden" name="retorno" value="<?= e($retornoIntimacao) ?>">
        <header class="modal__cabecalho">
            <div>
                <h2 class="modal__titulo" id="modalExcluirIntimacaoTitulo" data-modal-titulo>Excluir intimação</h2>
                <p class="modal__contexto">O registro sai do acompanhamento, mas continua guardado com o histórico e o motivo informado.
                    Para uma intimação que não precisa mais ser cumprida, prefira o status <strong>Cancelada</strong>.</p>
            </div>
            <button type="button" class="modal__fechar" data-modal-fechar aria-label="Fechar">×</button>
        </header>
        <div class="modal__corpo">
            <div class="alert alert--erro" data-modal-erro tabindex="-1" role="alert" hidden></div>
            <div class="form-grid">
                <div class="campo">
                    <label class="campo__rotulo" for="exclusao-motivo">Motivo da exclusão <span class="obrigatorio">*</span></label>
                    <textarea class="textarea textarea--curta" id="exclusao-motivo" name="motivo" required
                              maxlength="<?= IntimacaoPessoalService::MOTIVO_MAX ?>" placeholder="Ex.: registro em duplicidade com a intimação nº 12."></textarea>
                </div>
            </div>
        </div>
        <footer class="modal__rodape">
            <button type="button" class="btn btn--ghost" data-modal-fechar>Cancelar</button>
            <button type="submit" class="btn btn--perigo">🗑 Excluir intimação</button>
        </footer>
    </form>
</dialog>
