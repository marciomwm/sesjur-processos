<?php
/**
 * Modal de inclusao/edicao de Produto de Saude na ficha do processo.
 * Os gatilhos informam os valores (data-modal-dados): vazio para incluir,
 * o produto para editar.
 * Variaveis: $registro (processo)
 */
use App\Core\Csrf;
use App\Services\ProdutoSaudeService;
?>
<dialog class="modal modal--largo" id="modalProdutoSaude" aria-labelledby="modalProdutoSaudeTitulo">
    <form method="post" action="<?= e(url('salvar-produto-saude.php')) ?>" class="modal__form" data-modal-form novalidate>
        <?= Csrf::campo() ?>
        <input type="hidden" name="processo_id" value="<?= (int)$registro['id'] ?>">
        <input type="hidden" name="id" value="" data-padrao="">
        <header class="modal__cabecalho">
            <div>
                <h2 class="modal__titulo" id="modalProdutoSaudeTitulo" data-modal-titulo>Incluir produto de saúde</h2>
                <p class="modal__contexto"><span>Processo <strong class="mono"><?= e($registro['protocolo']) ?></strong></span></p>
            </div>
            <button type="button" class="modal__fechar" data-modal-fechar aria-label="Fechar">×</button>
        </header>
        <div class="modal__corpo">
            <div class="alert alert--erro" data-modal-erro tabindex="-1" role="alert" hidden></div>
            <div class="form-grid">
                <div class="campo campo--4">
                    <label class="campo__rotulo" for="produto-categoria">Categoria <span class="obrigatorio">*</span></label>
                    <select class="select" id="produto-categoria" name="categoria" required>
                        <option value="">Selecione</option>
                        <?php foreach (ProdutoSaudeService::CATEGORIAS as $codigo => $rotulo): ?>
                            <option value="<?= e($codigo) ?>"><?= e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo campo--8">
                    <label class="campo__rotulo" for="produto-descricao">Descrição <span class="obrigatorio">*</span></label>
                    <input class="input" type="text" id="produto-descricao" name="descricao" required
                           maxlength="<?= ProdutoSaudeService::DESCRICAO_MAX ?>" placeholder="Ex.: Rituximabe 500 mg" autocomplete="off">
                </div>
            </div>

            <fieldset class="grupo-campos mt-16 mb-0">
                <legend>Classificação SIGTAP <span class="texto-secundario">(opcional)</span></legend>
                <div class="form-grid">
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="produto-sigtap-codigo">Código</label>
                        <input class="input mono" type="text" id="produto-sigtap-codigo" name="sigtap_codigo" maxlength="14"
                               placeholder="03.01.01.007-2" autocomplete="off" list="sugestoesSigtap" data-sigtap-codigo>
                        <datalist id="sugestoesSigtap"></datalist>
                        <span class="campo__ajuda">10 dígitos, com ou sem pontos</span>
                    </div>
                    <div class="campo campo--8">
                        <label class="campo__rotulo" for="produto-sigtap-nome">Descrição oficial do procedimento</label>
                        <input class="input" type="text" id="produto-sigtap-nome" name="sigtap_nome"
                               maxlength="<?= ProdutoSaudeService::SIGTAP_NOME_MAX ?>" autocomplete="off">
                        <span class="campo__ajuda">Preenchida ao escolher um código já cadastrado</span>
                    </div>
                </div>
                <details class="detalhes-sigtap" data-sigtap-estrutura>
                    <summary>Grupo, subgrupo e forma de organização</summary>
                    <div class="form-grid">
                        <div class="campo campo--4">
                            <label class="campo__rotulo" for="produto-sigtap-grupo">Grupo</label>
                            <input class="input" type="text" id="produto-sigtap-grupo" name="sigtap_grupo_nome" maxlength="<?= ProdutoSaudeService::ESTRUTURA_MAX ?>"
                                   placeholder="Ex.: Procedimentos clínicos">
                        </div>
                        <div class="campo campo--4">
                            <label class="campo__rotulo" for="produto-sigtap-subgrupo">Subgrupo</label>
                            <input class="input" type="text" id="produto-sigtap-subgrupo" name="sigtap_subgrupo_nome" maxlength="<?= ProdutoSaudeService::ESTRUTURA_MAX ?>">
                        </div>
                        <div class="campo campo--4">
                            <label class="campo__rotulo" for="produto-sigtap-forma">Forma de organização</label>
                            <input class="input" type="text" id="produto-sigtap-forma" name="sigtap_forma_organizacao_nome" maxlength="<?= ProdutoSaudeService::ESTRUTURA_MAX ?>">
                        </div>
                    </div>
                </details>
                <p class="campo__ajuda mb-0">Sem integração com a base oficial por enquanto: um código novo é cadastrado com a descrição informada e reaproveitado nos próximos produtos.</p>
            </fieldset>
        </div>
        <footer class="modal__rodape">
            <button type="button" class="btn btn--ghost" data-modal-fechar>Cancelar</button>
            <button type="submit" class="btn btn--primario">💾 Salvar produto</button>
        </footer>
    </form>
</dialog>
