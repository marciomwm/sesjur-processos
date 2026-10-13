<?php
/**
 * Seção "Situação, responsável e movimentações" do formulário de processo
 * administrativo. Compartilhada por novo- e editar-processo-administrativo.php.
 *
 * @var array  $dados        situacao, situacao_descricao, responsavel_*, eventos_json
 * @var array  $erros
 * @var array  $eventos      movimentações a exibir (formato do banco)
 * @var bool   $eventosSalvos true quando $eventos vem do banco (edição)
 */

use App\Validators\Normalizador;

$eventosVazio = 'Nenhuma movimentação registrada. Use “Completar cadastro pelo SIGADOC” para importar o histórico.';
?>
<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Situação no SIGADOC</h2>
            <p class="card__subtitulo">Status do processo e agente público com quem ele está no momento da extração</p>
        </div>
    </div>
    <div class="card__corpo">
        <div class="form-grid">
            <div class="campo campo--4">
                <label class="campo__rotulo" for="situacao">Situação</label>
                <select class="select <?= isset($erros['situacao']) ? 'is-invalido' : '' ?>" id="situacao" name="situacao">
                    <option value="">— Não informada —</option>
                    <?php foreach (Normalizador::SITUACOES as $codigo => $rotulo): ?>
                        <option value="<?= e($codigo) ?>" <?= ($dados['situacao'] ?? '') === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($erros['situacao'])): ?>
                    <span class="campo__erro"><?= e($erros['situacao']) ?></span>
                <?php else: ?>
                    <span class="campo__ajuda" data-situacao-oficial>
                        <?= ($dados['situacao_descricao'] ?? '') !== ''
                            ? 'No SIGADOC: ' . e($dados['situacao_descricao'])
                            : 'Aguardando andamento, sobrestado, arquivado…' ?>
                    </span>
                <?php endif; ?>
                <input type="hidden" name="situacao_descricao" value="<?= e($dados['situacao_descricao'] ?? '') ?>">
            </div>

            <div class="campo campo--8">
                <label class="campo__rotulo" for="responsavel_nome">Servidor responsável (com o processo)</label>
                <input class="input" type="text" id="responsavel_nome" name="responsavel_nome" maxlength="180"
                       value="<?= e($dados['responsavel_nome'] ?? '') ?>" placeholder="Nome do agente público">
            </div>

            <div class="campo campo--4">
                <label class="campo__rotulo" for="responsavel_matricula">Matrícula</label>
                <input class="input mono" type="text" id="responsavel_matricula" name="responsavel_matricula" maxlength="30"
                       value="<?= e($dados['responsavel_matricula'] ?? '') ?>" placeholder="SES000000">
            </div>

            <div class="campo campo--3">
                <label class="campo__rotulo" for="responsavel_lotacao">Lotação</label>
                <input class="input mono" type="text" id="responsavel_lotacao" name="responsavel_lotacao" maxlength="60"
                       value="<?= e($dados['responsavel_lotacao'] ?? '') ?>" placeholder="UNIJUR">
            </div>

            <div class="campo campo--5">
                <label class="campo__rotulo" for="responsavel_lotacao_nome">Unidade</label>
                <input class="input" type="text" id="responsavel_lotacao_nome" name="responsavel_lotacao_nome" maxlength="180"
                       value="<?= e($dados['responsavel_lotacao_nome'] ?? '') ?>" placeholder="Unidade Jurídica">
            </div>
        </div>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Movimentações</h2>
            <p class="card__subtitulo" data-eventos-resumo>
                Juntadas, anotações, tramitações, sobrestamentos e arquivamentos registrados no SIGADOC
            </p>
        </div>
    </div>
    <div class="card__corpo">
        <?php if (isset($erros['eventos'])): ?>
            <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erros['eventos']) ?></span></div>
        <?php endif; ?>
        <!-- Preenchido pela extração; vazio = manter as movimentações já gravadas. -->
        <input type="hidden" name="eventos_json" value="<?= e($dados['eventos_json'] ?? '') ?>">
        <div data-eventos>
            <?php require APP_ROOT . '/Views/partials/eventos-administrativos.php'; ?>
        </div>
        <?php if (!empty($eventosSalvos) && $eventos !== []): ?>
            <p class="texto-secundario mt-16 mb-0" data-eventos-nota>
                As movimentações são somente leitura e são substituídas a cada nova extração do SIGADOC.
            </p>
        <?php endif; ?>
    </div>
</section>
