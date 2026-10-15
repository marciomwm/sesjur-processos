<?php
/**
 * Tabela de Tarefas (Acoes) da ficha do processo e da visao em lista do
 * gerenciador (o quadro usa acao-item.php).
 * Variaveis: $acoes (AcaoRepository::listarPorProcesso/listar), $retorno,
 *            $mostrarProcesso (bool, opcional: coluna do processo)
 *
 * Os botoes de alteracao so aparecem para quem pode alterar; a mesma regra
 * e aplicada no AcaoService. Cada linha mantem a ancora #acao-N.
 */
use App\Core\Csrf;
use App\Core\Prazo;
use App\Services\AcaoService;
?>
<div class="tabela-wrap">
    <table class="tabela tabela--providencias">
        <thead>
            <tr>
                <th>Descrição</th>
                <?php if (!empty($mostrarProcesso)): ?><th>Processo</th><?php endif; ?>
                <th>Prazo</th>
                <th>Status</th>
                <th>Criada por</th>
                <th class="texto-direita">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($acoes as $acao): ?>
            <?php
            $finalizada = AcaoService::finalizada($acao['status']);
            $situacaoPrazo = $finalizada ? null : Prazo::situacao($acao['prazo'], $acao['prazo_hora']);
            // Proximos passos mais comuns, como botoes de um clique.
            $transicoes = match ($acao['status']) {
                'PENDENTE'     => ['EM_ANDAMENTO' => '▶ Iniciar', 'CONCLUIDA' => '✓ Concluir'],
                'EM_ANDAMENTO' => ['CONCLUIDA' => '✓ Concluir', 'PENDENTE' => '↺ Pendente'],
                default        => ['PENDENTE' => '↺ Reabrir'],
            };
            ?>
            <tr id="acao-<?= (int)$acao['id'] ?>" class="<?= $finalizada ? 'is-finalizada' : '' ?> <?= $situacaoPrazo === 'vencido' ? 'is-vencida' : '' ?>">
                <td class="tabela__texto">
                    <strong><?= e($acao['descricao']) ?></strong>
                    <?php if ($acao['observacao'] !== null && $acao['observacao'] !== ''): ?>
                        <br><span class="texto-secundario"><?= nl2br(e($acao['observacao'])) ?></span>
                    <?php endif; ?>
                </td>
                <?php if (!empty($mostrarProcesso)): ?>
                    <td class="nowrap">
                        <a class="mono" href="<?= e(url('processo-administrativo.php?id=' . $acao['processo_administrativo_id'])) ?>#acoes"
                           title="<?= e(mb_substr((string)($acao['processo_objeto'] ?? ''), 0, 200)) ?>"><?= e($acao['protocolo']) ?></a>
                    </td>
                <?php endif; ?>
                <td class="nowrap">
                    <?php if ($acao['prazo'] !== null): ?>
                        <span class="badge <?= e($finalizada ? 'badge--neutro' : Prazo::classeBadge($situacaoPrazo)) ?>"
                              title="<?= e($finalizada ? 'Prazo' : (Prazo::ROTULOS_SITUACAO[$situacaoPrazo] ?? 'Prazo')) ?>">
                            ⏱ <?= e(Prazo::formatar($acao['prazo'], $acao['prazo_hora'])) ?>
                        </span>
                    <?php else: ?>
                        <span class="texto-secundario">Sem prazo</span>
                    <?php endif; ?>
                </td>
                <td class="nowrap">
                    <span class="badge <?= e(AcaoService::classeBadge($acao['status'])) ?>"><?= e(AcaoService::STATUS[$acao['status']] ?? $acao['status']) ?></span>
                    <?php if ($finalizada && $acao['finalizada_em'] !== null): ?>
                        <br><span class="texto-secundario"><?= e(datahora_br($acao['finalizada_em'])) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= $acao['criado_por_nome'] !== null ? e($acao['criado_por_nome']) : 'Sistema' ?>
                    <br><span class="texto-secundario nowrap"><?= e(datahora_br($acao['created_at'])) ?></span>
                </td>
                <td class="texto-direita">
                    <?php if (AcaoService::podeAlterar($acao)): ?>
                        <div class="acoes-linha tabela__comandos">
                            <form method="post" action="<?= e(url('alterar-status-acao.php')) ?>" class="acao__transicoes">
                                <?= Csrf::campo() ?>
                                <input type="hidden" name="id" value="<?= (int)$acao['id'] ?>">
                                <input type="hidden" name="retorno" value="<?= e($retorno) ?>">
                                <?php foreach ($transicoes as $status => $rotulo): ?>
                                    <button type="submit" name="status" value="<?= e($status) ?>"
                                            class="btn btn--sm <?= $status === 'CONCLUIDA' ? 'btn--primario' : 'btn--ghost' ?>"><?= e($rotulo) ?></button>
                                <?php endforeach; ?>
                            </form>
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-acao.php?id=' . $acao['id'] . '&retorno=' . rawurlencode($retorno))) ?>">Editar</a>
                            <form method="post" action="<?= e(url('excluir-acao.php')) ?>"
                                  data-confirmar="Excluir a tarefa “<?= e(mb_substr($acao['descricao'], 0, 80)) ?>”? Esta operação não pode ser desfeita.">
                                <?= Csrf::campo() ?>
                                <input type="hidden" name="id" value="<?= (int)$acao['id'] ?>">
                                <input type="hidden" name="retorno" value="<?= e($retorno) ?>">
                                <button type="submit" class="btn btn--ghost btn--sm acao__excluir" aria-label="Excluir tarefa">🗑</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <span class="texto-secundario">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
