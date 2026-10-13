<?php
/**
 * Formulario de Unidade Administrativa (nova e editar).
 * Variaveis: $dados, $servidores (lotacionograma), $erros,
 *            $instituicoes, $niveis (TabelaApoioRepository::opcoes),
 *            $superiores (list<array{id,rotulo,instituicao_id,ativo}>),
 *            $voltar, $rotuloSalvar
 *
 * Sem JavaScript: o campo "novo nivel" fica visivel, todas as unidades
 * superiores aparecem e ha linhas em branco no lotacionograma.
 */
use App\Core\Csrf;
use App\Services\UnidadeAdministrativaService as U;

$erro = static fn(string $campo): string => isset($erros[$campo]) ? 'is-invalido' : '';
$msg = static fn(string $campo): string => isset($erros[$campo]) ? '<span class="campo__erro">' . e($erros[$campo]) . '</span>' : '';
$nivelNovo = (string)($dados['nivel_novo'] ?? '');
// Linhas do lotacionograma: as gravadas/enviadas e tres em branco.
$linhas = array_merge(array_values($servidores), array_fill(0, 3, ['nome' => '', 'matricula' => '', 'cargo' => '']));
?>
<form method="post" class="form-unidade" novalidate data-form-unidade>
    <?= Csrf::campo() ?>

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Identificação</h2>
                <p class="card__subtitulo">Posição da unidade na estrutura organizacional da instituição.</p>
            </div>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="instituicao_id">Instituição <span class="obrigatorio">*</span></label>
                    <select class="select <?= $erro('instituicao_id') ?>" id="instituicao_id" name="instituicao_id" required data-instituicao>
                        <option value="">Selecione a instituição</option>
                        <?php foreach ($instituicoes as $i): ?>
                            <option value="<?= (int)$i['id'] ?>" <?= (string)($dados['instituicao_id'] ?? '') === (string)$i['id'] ? 'selected' : '' ?>>
                                <?= e($i['nome']) ?><?= (int)$i['ativo'] === 1 ? '' : ' (inativa)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= $msg('instituicao_id') ?>
                    <a class="campo__link" href="<?= e(url('tabela-apoio.php?tipo=instituicoes')) ?>">Gerenciar instituições</a>
                </div>

                <div class="campo campo--6">
                    <label class="campo__rotulo" for="nivel_id">Nível</label>
                    <select class="select <?= $erro('nivel_id') ?>" id="nivel_id" name="nivel_id" data-nivel>
                        <option value="">Sem nível definido</option>
                        <?php foreach ($niveis as $n): ?>
                            <option value="<?= (int)$n['id'] ?>" <?= $nivelNovo === '' && (string)($dados['nivel_id'] ?? '') === (string)$n['id'] ? 'selected' : '' ?>>
                                <?= e($n['nome']) ?><?= (int)$n['ativo'] === 1 ? '' : ' (inativo)' ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="novo" <?= $nivelNovo !== '' ? 'selected' : '' ?>>＋ Adicionar novo nível…</option>
                    </select>
                    <div class="form-unidade__nivel-novo" data-nivel-novo>
                        <label class="sr-only" for="nivel_novo">Novo nível</label>
                        <input class="input" type="text" id="nivel_novo" name="nivel_novo" maxlength="100" value="<?= e($nivelNovo) ?>"
                               placeholder="Novo nível, ex.: Nível I - Decisão Colegiada">
                        <span class="campo__ajuda">Preenchido, cria o nível ao salvar (ou usa o existente com o mesmo nome).</span>
                    </div>
                    <?= $msg('nivel_id') ?>
                </div>

                <div class="campo">
                    <label class="campo__rotulo" for="unidade_superior_id">Unidade superior</label>
                    <select class="select <?= $erro('unidade_superior_id') ?>" id="unidade_superior_id" name="unidade_superior_id" data-superior>
                        <option value="">Nenhuma (topo da estrutura)</option>
                        <?php foreach ($superiores as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" data-instituicao="<?= (int)($s['instituicao_id'] ?? 0) ?>"
                                <?= (string)($dados['unidade_superior_id'] ?? '') === (string)$s['id'] ? 'selected' : '' ?>>
                                <?= e($s['rotulo']) ?><?= (int)$s['ativo'] === 1 ? '' : ' (inativa)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($erros['unidade_superior_id'])): ?>
                        <?= $msg('unidade_superior_id') ?>
                    <?php else: ?>
                        <span class="campo__ajuda">Unidade imediatamente acima, da mesma instituição.</span>
                    <?php endif; ?>
                </div>

                <div class="campo campo--8">
                    <label class="campo__rotulo" for="nome">Nome da unidade <span class="obrigatorio">*</span></label>
                    <input class="input <?= $erro('nome') ?>" type="text" id="nome" name="nome" required
                           maxlength="<?= U::NOME_MAX ?>" value="<?= e($dados['nome'] ?? '') ?>" placeholder="Ex.: Unidade Jurídica">
                    <?= $msg('nome') ?>
                </div>
                <div class="campo campo--4">
                    <label class="campo__rotulo" for="sigla">Sigla (SIGADOC)</label>
                    <input class="input mono <?= $erro('sigla') ?>" type="text" id="sigla" name="sigla"
                           maxlength="30" value="<?= e($dados['sigla'] ?? '') ?>" placeholder="SESUNIJUR">
                    <?= $msg('sigla') ?>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Gestor da unidade</h2>
                <p class="card__subtitulo">Servidor responsável pela gestão da unidade. Não precisa ser usuário do sistema.</p>
            </div>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <div class="campo campo--8">
                    <label class="campo__rotulo" for="gestor_nome">Nome</label>
                    <input class="input <?= $erro('gestor_nome') ?>" type="text" id="gestor_nome" name="gestor_nome"
                           maxlength="<?= U::PESSOA_MAX ?>" value="<?= e($dados['gestor_nome'] ?? '') ?>">
                    <?= $msg('gestor_nome') ?>
                </div>
                <div class="campo campo--4">
                    <label class="campo__rotulo" for="gestor_matricula">Matrícula</label>
                    <input class="input mono <?= $erro('gestor_matricula') ?>" type="text" id="gestor_matricula" name="gestor_matricula"
                           maxlength="<?= U::MATRICULA_MAX ?>" value="<?= e($dados['gestor_matricula'] ?? '') ?>">
                    <?= $msg('gestor_matricula') ?>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Lotacionograma</h2>
                <p class="card__subtitulo">Servidores lotados na unidade. Linhas em branco são ignoradas.</p>
            </div>
            <button type="button" class="btn btn--secundario btn--sm" data-servidor-adicionar hidden>＋ Adicionar servidor</button>
        </div>
        <?php if (isset($erros['servidores'])): ?>
            <div class="card__corpo"><div class="alert alert--erro mb-0"><span class="alert__icone">✕</span><span><?= e($erros['servidores']) ?></span></div></div>
        <?php endif; ?>
        <div class="tabela-wrap">
            <table class="tabela tabela--compacta form-unidade__servidores">
                <thead>
                    <tr><th>Nome <span class="obrigatorio">*</span></th><th>Matrícula</th><th>Cargo ou função</th><th><span class="sr-only">Remover</span></th></tr>
                </thead>
                <tbody data-servidores>
                <?php foreach ($linhas as $i => $s): ?>
                    <tr data-servidor>
                        <td><input class="input" type="text" name="servidores[<?= $i ?>][nome]" maxlength="<?= U::PESSOA_MAX ?>"
                                   value="<?= e($s['nome'] ?? '') ?>" aria-label="Nome do servidor"></td>
                        <td><input class="input mono" type="text" name="servidores[<?= $i ?>][matricula]" maxlength="<?= U::MATRICULA_MAX ?>"
                                   value="<?= e($s['matricula'] ?? '') ?>" aria-label="Matrícula"></td>
                        <td><input class="input" type="text" name="servidores[<?= $i ?>][cargo]" maxlength="<?= U::CARGO_MAX ?>"
                                   value="<?= e($s['cargo'] ?? '') ?>" aria-label="Cargo ou função"></td>
                        <td class="texto-direita"><button type="button" class="btn btn--ghost btn--sm acao__excluir" data-servidor-remover hidden aria-label="Remover servidor">🗑</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Competências</h2>
                <p class="card__subtitulo">Conforme o regimento interno da instituição.</p>
            </div>
        </div>
        <div class="card__corpo">
            <div class="campo">
                <label class="sr-only" for="competencias">Competências</label>
                <textarea class="textarea <?= $erro('competencias') ?>" id="competencias" name="competencias" rows="10"
                          maxlength="<?= U::COMPETENCIAS_MAX ?>"
                          placeholder="I - ...&#10;II - ..."><?= e($dados['competencias'] ?? '') ?></textarea>
                <?= $msg('competencias') ?>
            </div>
        </div>
        <div class="card__rodape">
            <button type="submit" class="btn btn--primario">💾 <?= e($rotuloSalvar) ?></button>
            <a class="btn btn--ghost" href="<?= e(url($voltar)) ?>">Cancelar</a>
        </div>
    </section>
</form>
