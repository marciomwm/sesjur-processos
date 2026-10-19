<?php
/**
 * Guia Procedimentos de Configuracoes: catalogo padronizado usado no
 * processo administrativo (ProcedimentoService). Sem exclusao: item em
 * desuso e inativado e continua valendo nos processos que ja o possuem.
 * Variaveis: $paginacao, $filtros, $dados, $erros, $query, $urlAba
 */
use App\Core\Csrf;
use App\Services\ProcedimentoService;

$inverter = $filtros['direcao'] === 'asc' ? 'desc' : 'asc';
?>
<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Novo procedimento</h2>
            <p class="card__subtitulo">Tipo de trâmite que os usuários escolhem ao cadastrar ou editar um processo administrativo. Não confundir com o objeto da demanda (produto de saúde).</p>
        </div>
    </div>
    <div class="card__corpo">
        <form method="post" action="<?= e($urlAba()) ?>" class="form-grid" novalidate>
            <?= Csrf::campo() ?>
            <div class="campo campo--6">
                <label class="campo__rotulo" for="nome">Nome do procedimento <span class="obrigatorio">*</span></label>
                <input class="input <?= isset($erros['nome']) ? 'is-invalido' : '' ?>" type="text" id="nome" name="nome" required
                       maxlength="<?= ProcedimentoService::NOME_MAX ?>" value="<?= e($dados['nome']) ?>"
                       placeholder="Ex.: Análise Prévia de Legalidade" aria-describedby="nomeAjuda">
                <?php if (isset($erros['nome'])): ?>
                    <span class="campo__erro" id="nomeAjuda"><?= e($erros['nome']) ?></span>
                <?php else: ?>
                    <span class="campo__ajuda" id="nomeAjuda">Único: maiúsculas, acentos e espaços repetidos não diferenciam nomes.</span>
                <?php endif; ?>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="descricao">Descrição</label>
                <textarea class="textarea textarea--curta <?= isset($erros['descricao']) ? 'is-invalido' : '' ?>" id="descricao" name="descricao" rows="2"
                          maxlength="<?= ProcedimentoService::DESCRICAO_MAX ?>" placeholder="Quando usar este procedimento"><?= e($dados['descricao']) ?></textarea>
                <?php if (isset($erros['descricao'])): ?><span class="campo__erro"><?= e($erros['descricao']) ?></span><?php endif; ?>
            </div>
            <div class="campo">
                <div><button type="submit" class="btn btn--primario">＋ Adicionar procedimento</button></div>
            </div>
        </form>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Procedimentos cadastrados</h2>
            <p class="card__subtitulo">Só os ativos aparecem no formulário de processos. Inativar não altera os processos que já o possuem; o item pode ser reativado.</p>
        </div>
    </div>
    <div class="card__corpo">
        <form method="get" action="<?= e(url('configuracoes.php')) ?>" class="filtros" role="search">
            <input type="hidden" name="aba" value="procedimentos">
            <div class="campo filtros__busca">
                <label class="campo__rotulo" for="busca">Nome</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>" maxlength="150" placeholder="Pesquisar pelo nome">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="status">Status</label>
                <select class="select" id="status" name="status">
                    <?php foreach (['todos' => 'Todos', 'ativos' => 'Ativos', 'inativos' => 'Inativos'] as $valor => $rotulo): ?>
                        <option value="<?= e($valor) ?>" <?= $filtros['status'] === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="direcao">Ordem</label>
                <select class="select" id="direcao" name="direcao">
                    <option value="asc" <?= $filtros['direcao'] === 'asc' ? 'selected' : '' ?>>A → Z</option>
                    <option value="desc" <?= $filtros['direcao'] === 'desc' ? 'selected' : '' ?>>Z → A</option>
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
                <tr>
                    <th aria-sort="<?= $filtros['direcao'] === 'asc' ? 'ascending' : 'descending' ?>">
                        <a href="<?= e($urlAba(array_diff_key($query, ['aba' => 1, 'pagina' => 1]) + ['direcao' => $inverter])) ?>">Procedimento <?= $filtros['direcao'] === 'asc' ? '▲' : '▼' ?></a>
                    </th>
                    <th>Descrição</th>
                    <th title="Processos administrativos com este procedimento">Processos</th>
                    <th>Status</th>
                    <th class="texto-direita">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($paginacao->vazio()): ?>
                <tr><td colspan="5" class="tabela__vazia">
                    <?= $filtros['busca'] === '' && $filtros['status'] === 'todos'
                        ? 'Nenhum procedimento cadastrado. Cadastre acima os procedimentos que os usuários poderão escolher.'
                        : 'Nenhum procedimento encontrado com os filtros informados.' ?>
                </td></tr>
            <?php else: foreach ($paginacao->itens as $p): ?>
                <?php $ativo = (int)$p['ativo'] === 1; $total = (int)$p['total_processos']; ?>
                <tr class="<?= $ativo ? '' : 'is-inativo' ?>">
                    <td data-rotulo="Procedimento"><strong><?= e($p['nome']) ?></strong></td>
                    <td data-rotulo="Descrição" class="tabela__texto"><?= ou_traco($p['descricao'] !== null ? mb_strimwidth($p['descricao'], 0, 140, '…') : null) ?></td>
                    <td data-rotulo="Processos" class="texto-centro">
                        <?php if ($total > 0): ?>
                            <a href="<?= e(url('processos-administrativos.php?procedimento_id=' . (int)$p['id'])) ?>" title="Ver os processos"><?= $total ?></a>
                        <?php else: ?>0<?php endif; ?>
                    </td>
                    <td data-rotulo="Status"><span class="badge <?= $ativo ? 'badge--verde' : 'badge--neutro' ?>"><?= $ativo ? 'Ativo' : 'Inativo' ?></span></td>
                    <td class="texto-direita nowrap">
                        <div class="acoes-linha tabela__comandos">
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-procedimento.php?id=' . $p['id'])) ?>">Editar</a>
                            <form method="post" action="<?= e(url('alterar-status-procedimento.php')) ?>"
                                  data-confirmar="<?= e($ativo
                                      ? 'Inativar o procedimento “' . $p['nome'] . '”? Os ' . $total . ' processo(s) que já o possuem não serão alterados, mas ele deixará de poder ser escolhido.'
                                      : 'Reativar o procedimento “' . $p['nome'] . '”?') ?>">
                                <?= Csrf::campo() ?>
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <input type="hidden" name="ativo" value="<?= $ativo ? '0' : '1' ?>">
                                <input type="hidden" name="retorno" value="<?= e(http_build_query($query)) ?>">
                                <button type="submit" class="btn btn--ghost btn--sm <?= $ativo ? 'texto-perigo' : 'texto-sucesso' ?>"><?= $ativo ? 'Inativar' : 'Ativar' ?></button>
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
