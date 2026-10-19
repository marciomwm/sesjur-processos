<?php
/**
 * Guia Usuarios de Configuracoes. Reaproveita UsuarioRepository e os
 * endpoints existentes (novo-usuario.php, usuario.php, editar-usuario.php,
 * alterar-status-usuario.php). Dados pessoais vem do Agente Publico.
 * Variaveis: $paginacao, $filtros, $cargos, $lotacoes, $query, $urlAba
 */
use App\Core\Csrf;
use App\Core\Perfil;
use App\Repositories\UsuarioRepository;
?>
<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Usuários</h2>
            <p class="card__subtitulo">Contas de acesso ao sistema: cada uma pertence a um <a href="<?= e(url('agentes-publicos.php')) ?>">Agente Público</a>. Usuários não são excluídos: são inativados. O último administrador ativo não pode ser inativado nem perder o perfil.</p>
        </div>
        <a class="btn btn--primario btn--sm" href="<?= e(url('novo-usuario.php')) ?>">＋ Criar usuário</a>
    </div>
    <div class="card__corpo">
        <form method="get" action="<?= e(url('configuracoes.php')) ?>" class="filtros filtros--usuarios" role="search">
            <input type="hidden" name="aba" value="usuarios">
            <div class="campo filtros__busca">
                <label class="campo__rotulo" for="busca">Pesquisar usuário</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>"
                       maxlength="150" placeholder="Nome, matrícula ou e-mail">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="lotacao_id">Unidade Administrativa</label>
                <select class="select" id="lotacao_id" name="lotacao_id">
                    <option value="">Todas</option>
                    <?php foreach ($lotacoes as $l): ?>
                        <option value="<?= e((string)$l['id']) ?>" <?= $filtros['lotacao_id'] === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['rotulo'] ?? $l['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="cargo_id">Cargo</label>
                <select class="select" id="cargo_id" name="cargo_id">
                    <option value="">Todos</option>
                    <?php foreach ($cargos as $c): ?>
                        <option value="<?= e((string)$c['id']) ?>" <?= $filtros['cargo_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="perfil">Perfil</label>
                <select class="select" id="perfil" name="perfil">
                    <option value="">Todos</option>
                    <?php foreach (Perfil::ROTULOS as $valor => $rotulo): ?>
                        <option value="<?= e($valor) ?>" <?= $filtros['perfil'] === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="status">Status</label>
                <select class="select" id="status" name="status">
                    <?php foreach (UsuarioRepository::STATUS as $valor => $rotulo): ?>
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
        <table class="tabela tabela--usuarios">
            <thead>
                <tr>
                    <th>Nome</th><th>Matrícula</th><th>Cargo</th><th>Unidade</th><th>Perfil</th><th>E-mail</th><th>Status</th>
                    <th class="texto-direita">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($paginacao->vazio()): ?>
                <tr><td colspan="8" class="tabela__vazia">Nenhum usuário encontrado com os filtros informados.</td></tr>
            <?php else: foreach ($paginacao->itens as $u): ?>
                <?php $ativo = (int)$u['ativo'] === 1; ?>
                <tr class="<?= $ativo ? '' : 'is-inativo' ?>">
                    <td data-rotulo="Nome"><a href="<?= e(url('usuario.php?id=' . $u['id'])) ?>"><strong><?= e($u['nome']) ?></strong></a></td>
                    <td data-rotulo="Matrícula" class="mono nowrap"><?= e($u['matricula']) ?></td>
                    <td data-rotulo="Cargo"><?= e($u['cargo']) ?></td>
                    <td data-rotulo="Unidade"><?= e($u['lotacao']) ?></td>
                    <td data-rotulo="Perfil"><span class="badge <?= e(Perfil::classeBadge($u['perfil'])) ?>"><?= e(Perfil::rotulo($u['perfil'])) ?></span></td>
                    <td data-rotulo="E-mail"><span class="tabela__truncado" title="<?= e($u['email']) ?>"><?= e($u['email']) ?></span></td>
                    <td data-rotulo="Status"><span class="badge <?= $ativo ? 'badge--verde' : 'badge--neutro' ?>"><?= $ativo ? 'Ativo' : 'Inativo' ?></span></td>
                    <td class="texto-direita nowrap">
                        <div class="acoes-linha" style="justify-content:flex-end">
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('usuario.php?id=' . $u['id'])) ?>">Visualizar</a>
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-usuario.php?id=' . $u['id'])) ?>">Editar</a>
                            <?php if ((int)$u['id'] !== usuario_logado_id()): ?>
                                <form method="post" action="<?= e(url('alterar-status-usuario.php')) ?>" style="display:inline"
                                      data-confirmar="<?= e(($ativo ? 'Inativar' : 'Reativar') . ' o usuário ' . $u['nome'] . '?') ?>">
                                    <?= Csrf::campo() ?>
                                    <input type="hidden" name="id" value="<?= e((string)$u['id']) ?>">
                                    <input type="hidden" name="ativo" value="<?= $ativo ? '0' : '1' ?>">
                                    <input type="hidden" name="retorno" value="<?= e(http_build_query($query)) ?>">
                                    <button type="submit" class="btn btn--ghost btn--sm <?= $ativo ? 'texto-perigo' : 'texto-sucesso' ?>"><?= $ativo ? 'Inativar' : 'Ativar' ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php require APP_ROOT . '/Views/partials/paginacao.php'; ?>
</section>
