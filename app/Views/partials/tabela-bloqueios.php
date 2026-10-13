<?php
/**
 * Tabela de lancamentos de Bloqueio/Desbloqueio (ficha do processo e painel).
 * Variaveis: $lancamentos (BloqueioJudicialRepository, ordem cronologica),
 *            $retorno ('processo' ou 'painel'), $mostrarProcesso (bool),
 *            $saldoApos (array<int,int> id => centavos, opcional - so na ficha)
 *
 * Exibe do mais recente ao mais antigo. As linhas levam data-data e
 * data-processo para o filtro do painel.
 */
use App\Core\Csrf;
use App\Core\Dinheiro;
use App\Services\BloqueioJudicialService;

$saldoApos ??= null;
$colunas = 6 + (!empty($mostrarProcesso) ? 1 : 0) + ($saldoApos !== null ? 1 : 0);
?>
<div class="tabela-wrap">
    <table class="tabela tabela--providencias tabela--valores">
        <thead>
            <tr>
                <th>Data</th>
                <?php if (!empty($mostrarProcesso)): ?><th>Processo</th><?php endif; ?>
                <th>Tipo</th>
                <th class="texto-direita">Valor</th>
                <?php if ($saldoApos !== null): ?><th class="texto-direita">Saldo retido após</th><?php endif; ?>
                <th>Identificador</th>
                <th>Observação</th>
                <th class="texto-direita">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($lancamentos === []): ?>
            <tr><td colspan="<?= $colunas ?>" class="tabela__vazia">Nenhum bloqueio ou desbloqueio registrado.</td></tr>
        <?php else: foreach (array_reverse($lancamentos) as $l): ?>
            <?php $bloqueio = $l['tipo'] === 'BLOQUEIO'; ?>
            <tr id="lancamento-<?= (int)$l['id'] ?>" data-linha-filtravel
                data-data="<?= e($l['data_lancamento']) ?>" data-processo="<?= (int)$l['processo_judicial_id'] ?>">
                <td class="nowrap"><?= e(data_br($l['data_lancamento'])) ?></td>
                <?php if (!empty($mostrarProcesso)): ?>
                    <td class="nowrap"><a class="mono" href="<?= e(url('processo-judicial.php?id=' . $l['processo_judicial_id'])) ?>#secao-bloqueios"><?= e($l['numero_cnj']) ?></a></td>
                <?php endif; ?>
                <td class="nowrap">
                    <span class="badge <?= $bloqueio ? 'badge--azul' : 'badge--ambar' ?>"><?= $bloqueio ? '▼ Bloqueio' : '▲ Desbloqueio' ?></span>
                </td>
                <td class="texto-direita nowrap valor"><?= $bloqueio ? '' : '− ' ?><?= e(moeda_br($l['valor'])) ?></td>
                <?php if ($saldoApos !== null): ?>
                    <td class="texto-direita nowrap valor texto-secundario"><?= e(Dinheiro::formatar($saldoApos[(int)$l['id']] ?? 0)) ?></td>
                <?php endif; ?>
                <td class="mono"><?= ou_traco($l['identificador']) ?></td>
                <td class="tabela__texto">
                    <?= nl2br(ou_traco($l['observacao'])) ?>
                    <br><span class="texto-secundario" title="Registrado em <?= e(datahora_br($l['created_at'])) ?>">
                        <?= $l['criado_por_nome'] !== null ? 'Por ' . e($l['criado_por_nome']) : 'Pelo sistema' ?>
                    </span>
                </td>
                <td class="texto-direita nowrap">
                    <?php if (BloqueioJudicialService::podeAlterar($l)): ?>
                        <div class="acoes-linha tabela__comandos">
                            <button type="button" class="btn btn--ghost btn--sm" data-modal-abrir="modalBloqueio"
                                    data-modal-titulo="Editar lançamento"
                                    data-processo-rotulo="<?= e($l['numero_cnj']) ?>"
                                    data-modal-dados="<?= e(json_encode([
                                        'id'                   => (int)$l['id'],
                                        'processo_judicial_id' => (int)$l['processo_judicial_id'],
                                        'tipo'                 => $l['tipo'],
                                        'data_lancamento'      => $l['data_lancamento'],
                                        'valor'                => Dinheiro::paraCampo($l['valor']),
                                        'identificador'        => $l['identificador'],
                                        'observacao'           => $l['observacao'],
                                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">Editar</button>
                            <form method="post" action="<?= e(url('excluir-bloqueio-judicial.php')) ?>"
                                  data-confirmar="Excluir o <?= $bloqueio ? 'bloqueio' : 'desbloqueio' ?> de <?= e(moeda_br($l['valor'])) ?> em <?= e(data_br($l['data_lancamento'])) ?>? Esta operação não pode ser desfeita.">
                                <?= Csrf::campo() ?>
                                <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                <input type="hidden" name="retorno" value="<?= e($retorno) ?>">
                                <button type="submit" class="btn btn--ghost btn--sm acao__excluir" aria-label="Excluir lançamento">🗑</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
