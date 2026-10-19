<?php
/**
 * Guia Prioridades de Configuracoes. Mesmas regras de sempre
 * (PrioridadeService/PrioridadeRepository, editar-prioridade.php,
 * alterar-status-prioridade.php): sem exclusao, so inativacao.
 * Variaveis: $paginacao, $filtros, $dados, $erros, $query, $urlAba
 */
use App\Core\Csrf;
use App\Services\PrioridadeService;
?>
<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Nova prioridade</h2>
            <p class="card__subtitulo">Critério que pode ser atribuído aos processos administrativos. O nível vai de 1 (baixa) a 5 (máxima).</p>
        </div>
    </div>
    <div class="card__corpo">
        <form method="post" action="<?= e($urlAba()) ?>" class="form-grid" novalidate>
            <?= Csrf::campo() ?>
            <div class="campo campo--6">
                <label class="campo__rotulo" for="nome">Nome <span class="obrigatorio">*</span></label>
                <input class="input <?= isset($erros['nome']) ? 'is-invalido' : '' ?>" type="text" id="nome" name="nome" required
                       maxlength="<?= PrioridadeService::NOME_MAX ?>" value="<?= e($dados['nome']) ?>" placeholder="Ex.: Idoso">
                <?php if (isset($erros['nome'])): ?><span class="campo__erro"><?= e($erros['nome']) ?></span><?php endif; ?>
            </div>
            <div class="campo campo--3">
                <label class="campo__rotulo" for="nivel">Nível <span class="obrigatorio">*</span></label>
                <select class="select <?= isset($erros['nivel']) ? 'is-invalido' : '' ?>" id="nivel" name="nivel" required>
                    <option value="">Selecione</option>
                    <?php foreach (PrioridadeService::NIVEIS as $n => $rotulo): ?>
                        <option value="<?= $n ?>" <?= (string)$dados['nivel'] === (string)$n ? 'selected' : '' ?>><?= $n ?> · <?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($erros['nivel'])): ?><span class="campo__erro"><?= e($erros['nivel']) ?></span><?php endif; ?>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="descricao">Descrição</label>
                <textarea class="textarea textarea--curta <?= isset($erros['descricao']) ? 'is-invalido' : '' ?>" id="descricao" name="descricao" rows="2"
                          maxlength="<?= PrioridadeService::DESCRICAO_MAX ?>" placeholder="Quando aplicar este critério"><?= e($dados['descricao']) ?></textarea>
                <?php if (isset($erros['descricao'])): ?><span class="campo__erro"><?= e($erros['descricao']) ?></span><?php endif; ?>
            </div>
            <div class="campo">
                <div><button type="submit" class="btn btn--primario">＋ Adicionar prioridade</button></div>
            </div>
        </form>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Prioridades cadastradas</h2>
            <p class="card__subtitulo">Não há exclusão: prioridade em desuso é inativada e continua valendo nos processos que já a possuem.</p>
        </div>
    </div>
    <div class="card__corpo">
        <form method="get" action="<?= e(url('configuracoes.php')) ?>" class="filtros" role="search">
            <input type="hidden" name="aba" value="prioridades">
            <div class="campo">
                <label class="campo__rotulo" for="busca">Nome</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>" maxlength="150" placeholder="Nome ou descrição">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="filtroNivel">Nível</label>
                <select class="select" id="filtroNivel" name="nivel">
                    <option value="">Todos</option>
                    <?php foreach (PrioridadeService::NIVEIS as $n => $rotulo): ?>
                        <option value="<?= $n ?>" <?= $filtros['nivel'] === (string)$n ? 'selected' : '' ?>><?= $n ?> · <?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="status">Status</label>
                <select class="select" id="status" name="status">
                    <?php foreach (['todos' => 'Todas', 'ativos' => 'Ativas', 'inativos' => 'Inativas'] as $valor => $rotulo): ?>
                        <option value="<?= e($valor) ?>" <?= $filtros['status'] === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filtros__acoes">
                <button type="submit" class="btn btn--primario">Buscar</button>
                <a class="btn btn--ghost" href="<?= e($urlAba()) ?>">Limpar</a>
            </div>
        </form>
    </div>

    <div class="tabela-wrap">
        <table class="tabela tabela--providencias">
            <thead>
                <tr><th>Prioridade</th><th>Nível</th><th>Descrição</th><th title="Processos com esta prioridade">Processos</th><th>Status</th><th class="texto-direita">Ações</th></tr>
            </thead>
            <tbody>
            <?php if ($paginacao->vazio()): ?>
                <tr><td colspan="6" class="tabela__vazia">Nenhuma prioridade encontrada.</td></tr>
            <?php else: foreach ($paginacao->itens as $p): ?>
                <?php $ativa = (int)$p['ativo'] === 1; ?>
                <tr class="<?= $ativa ? '' : 'is-inativo' ?>">
                    <td data-rotulo="Prioridade"><strong><?= e($p['nome']) ?></strong></td>
                    <td data-rotulo="Nível" class="nowrap"><span class="badge <?= e(PrioridadeService::classeBadge($p['nivel'])) ?>"><?= e(PrioridadeService::rotuloNivel($p['nivel'])) ?></span></td>
                    <td data-rotulo="Descrição" class="tabela__texto"><?= ou_traco($p['descricao'] !== null ? mb_strimwidth($p['descricao'], 0, 140, '…') : null) ?></td>
                    <td data-rotulo="Processos" class="texto-centro"><?= (int)$p['total_processos'] ?></td>
                    <td data-rotulo="Status"><span class="badge <?= $ativa ? 'badge--verde' : 'badge--neutro' ?>"><?= $ativa ? 'Ativa' : 'Inativa' ?></span></td>
                    <td class="texto-direita nowrap">
                        <div class="acoes-linha tabela__comandos">
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-prioridade.php?id=' . $p['id'])) ?>">Editar</a>
                            <form method="post" action="<?= e(url('alterar-status-prioridade.php')) ?>"
                                  data-confirmar="<?= e($ativa
                                      ? 'Inativar a prioridade “' . $p['nome'] . '”? Os ' . (int)$p['total_processos'] . ' processo(s) que já a possuem não serão alterados, mas ela deixará de poder ser atribuída.'
                                      : 'Reativar a prioridade “' . $p['nome'] . '”?') ?>">
                                <?= Csrf::campo() ?>
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <input type="hidden" name="ativo" value="<?= $ativa ? '0' : '1' ?>">
                                <input type="hidden" name="retorno" value="<?= e(http_build_query($query)) ?>">
                                <button type="submit" class="btn btn--ghost btn--sm <?= $ativa ? 'texto-perigo' : 'texto-sucesso' ?>"><?= $ativa ? 'Inativar' : 'Ativar' ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php require APP_ROOT . '/Views/partials/paginacao.php'; ?>
</section>
