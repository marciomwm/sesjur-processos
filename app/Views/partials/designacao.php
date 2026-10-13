<?php
/**
 * Secao "Designacao" das fichas de processo.
 * Variaveis: $tipoProcesso ('administrativo'|'judicial'), $id (processo),
 *            $designados (DesignacaoRepository::listarPorProcesso)
 *
 * Administradores e gestores designam e removem; os demais apenas veem.
 * A mesma regra e aplicada no DesignacaoService.
 */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Perfil;
use App\Repositories\UsuarioRepository;
use App\Services\DesignacaoService;

$podeDesignar = Auth::podeDesignar();
$idsDesignados = array_map('intval', array_column($designados, 'usuario_id'));
$disponiveis = $podeDesignar
    ? array_filter((new UsuarioRepository())->opcoesAtivos(), static fn($u) => !in_array((int)$u['id'], $idsDesignados, true))
    : [];
$meuId = usuario_logado_id();
?>
<section class="card" id="designacao">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Designação</h2>
            <p class="card__subtitulo">
                <?= $podeDesignar
                    ? 'Usuários responsáveis pelo acompanhamento deste processo'
                    : 'Usuários responsáveis pelo acompanhamento. Somente administradores e gestores alteram a designação.' ?>
            </p>
        </div>
        <?php if (in_array((int)$meuId, $idsDesignados, true)): ?>
            <span class="badge badge--verde">Designado a você</span>
        <?php endif; ?>
    </div>
    <div class="card__corpo">
        <?php if ($designados === []): ?>
            <p class="texto-secundario <?= $podeDesignar ? '' : 'mb-0' ?>">Nenhum usuário designado para este processo.</p>
        <?php else: ?>
            <ul class="designados">
                <?php foreach ($designados as $d): ?>
                    <li class="designados__item">
                        <span class="avatar avatar--sm" aria-hidden="true"><?= e(iniciais($d['nome'])) ?></span>
                        <div class="designados__dados">
                            <strong><?= e($d['nome']) ?></strong>
                            <?php if ((int)$d['ativo'] !== 1): ?><span class="badge badge--neutro">Inativo</span><?php endif; ?>
                            <span class="texto-secundario"><?= e($d['cargo']) ?> · <?= e($d['lotacao']) ?></span>
                            <?php if ($d['observacao'] !== null): ?>
                                <span class="designados__obs"><?= e($d['observacao']) ?></span>
                            <?php endif; ?>
                            <span class="texto-secundario">
                                Designado em <?= e(datahora_br($d['created_at'])) ?><?= $d['designado_por_nome'] !== null ? ' por ' . e($d['designado_por_nome']) : '' ?>
                            </span>
                        </div>
                        <?php if ($podeDesignar): ?>
                            <form method="post" action="<?= e(url('designacao.php')) ?>"
                                  data-confirmar="<?= e('Remover a designação de ' . $d['nome'] . ' deste processo?') ?>">
                                <?= Csrf::campo() ?>
                                <input type="hidden" name="acao" value="remover">
                                <input type="hidden" name="tipo" value="<?= e($tipoProcesso) ?>">
                                <input type="hidden" name="processo_id" value="<?= e((string)$id) ?>">
                                <input type="hidden" name="designacao_id" value="<?= e((string)$d['id']) ?>">
                                <button type="submit" class="btn btn--ghost btn--sm">Remover</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($podeDesignar): ?>
            <form method="post" action="<?= e(url('designacao.php')) ?>" class="designacao__form" novalidate>
                <?= Csrf::campo() ?>
                <input type="hidden" name="acao" value="designar">
                <input type="hidden" name="tipo" value="<?= e($tipoProcesso) ?>">
                <input type="hidden" name="processo_id" value="<?= e((string)$id) ?>">
                <div class="campo">
                    <label class="campo__rotulo" for="designar_usuario">Designar usuário</label>
                    <select class="select" id="designar_usuario" name="usuario_id" required <?= $disponiveis === [] ? 'disabled' : '' ?>>
                        <option value=""><?= $disponiveis === [] ? 'Todos os usuários ativos já estão designados' : 'Selecione o usuário' ?></option>
                        <?php foreach ($disponiveis as $u): ?>
                            <option value="<?= e((string)$u['id']) ?>">
                                <?= e($u['nome']) ?> — <?= e($u['lotacao']) ?><?= $u['perfil'] !== Perfil::USUARIO ? ' (' . e(Perfil::rotulo($u['perfil'])) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label class="campo__rotulo" for="designar_observacao">Observação <span class="texto-secundario">(opcional)</span></label>
                    <input class="input" type="text" id="designar_observacao" name="observacao"
                           maxlength="<?= DesignacaoService::OBSERVACAO_MAX ?>" placeholder="Ex.: acompanhar prazo de resposta">
                </div>
                <div class="designacao__acao">
                    <button type="submit" class="btn btn--primario" <?= $disponiveis === [] ? 'disabled' : '' ?>>＋ Designar</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>
