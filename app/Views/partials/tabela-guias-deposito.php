<?php
/**
 * Tabela de Guias de Deposito Judicial (ficha do processo e painel).
 * Variaveis: $guias (GuiaDepositoRepository), $retorno ('processo' ou 'painel'),
 *            $mostrarProcesso (bool)
 *
 * As linhas levam data-data (emissao) e data-processo para o filtro do painel.
 */
use App\Core\Csrf;
use App\Core\Dinheiro;
use App\Services\GuiaDepositoService;

$colunas = 8 + (!empty($mostrarProcesso) ? 1 : 0);
$classeSituacao = ['EMITIDA' => 'badge--azul', 'VENCIDA' => 'badge--vermelho', 'PAGA' => 'badge--verde', 'CANCELADA' => 'badge--neutro'];
?>
<div class="tabela-wrap">
    <table class="tabela tabela--providencias tabela--valores">
        <thead>
            <tr>
                <th>Emissão</th>
                <?php if (!empty($mostrarProcesso)): ?><th>Processo</th><?php endif; ?>
                <th>Número</th>
                <th class="texto-direita">Valor</th>
                <th>Vencimento</th>
                <th>Situação</th>
                <th>Pagamento</th>
                <th>Finalidade</th>
                <th class="texto-direita">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($guias === []): ?>
            <tr><td colspan="<?= $colunas ?>" class="tabela__vazia">Nenhuma guia de depósito registrada.</td></tr>
        <?php else: foreach ($guias as $g): ?>
            <?php $situacao = GuiaDepositoService::situacao($g); ?>
            <tr id="guia-<?= (int)$g['id'] ?>" data-linha-filtravel
                data-data="<?= e($g['data_emissao']) ?>" data-processo="<?= (int)$g['processo_judicial_id'] ?>"
                class="<?= in_array($situacao, ['PAGA', 'CANCELADA'], true) ? 'is-finalizada' : '' ?> <?= $situacao === 'VENCIDA' ? 'is-vencida' : '' ?>">
                <td class="nowrap"><?= e(data_br($g['data_emissao'])) ?></td>
                <?php if (!empty($mostrarProcesso)): ?>
                    <td class="nowrap"><a class="mono" href="<?= e(url('processo-judicial.php?id=' . $g['processo_judicial_id'])) ?>#secao-bloqueios"><?= e($g['numero_cnj']) ?></a></td>
                <?php endif; ?>
                <td class="mono"><?= ou_traco($g['numero']) ?></td>
                <td class="texto-direita nowrap valor"><?= e(moeda_br($g['valor'])) ?></td>
                <td class="nowrap"><?= e(data_br($g['vencimento'])) ?></td>
                <td class="nowrap"><span class="badge <?= $classeSituacao[$situacao] ?>"><?= e(GuiaDepositoService::SITUACOES[$situacao]) ?></span></td>
                <td class="nowrap"><?= e(data_br($g['data_pagamento'])) ?></td>
                <td class="tabela__texto">
                    <?= ou_traco($g['finalidade']) ?>
                    <?php if (!empty($g['observacao'])): ?><br><span class="texto-secundario"><?= nl2br(e($g['observacao'])) ?></span><?php endif; ?>
                    <br><span class="texto-secundario" title="Registrada em <?= e(datahora_br($g['created_at'])) ?>">
                        <?= $g['criado_por_nome'] !== null ? 'Por ' . e($g['criado_por_nome']) : 'Pelo sistema' ?>
                    </span>
                </td>
                <td class="texto-direita nowrap">
                    <?php if (GuiaDepositoService::podeAlterar($g)): ?>
                        <div class="acoes-linha tabela__comandos">
                            <button type="button" class="btn btn--ghost btn--sm" data-modal-abrir="modalGuia"
                                    data-modal-titulo="Editar guia de depósito"
                                    data-processo-rotulo="<?= e($g['numero_cnj']) ?>"
                                    data-modal-dados="<?= e(json_encode([
                                        'id'                   => (int)$g['id'],
                                        'processo_judicial_id' => (int)$g['processo_judicial_id'],
                                        'numero'               => $g['numero'],
                                        'data_emissao'         => $g['data_emissao'],
                                        'vencimento'           => $g['vencimento'],
                                        'valor'                => Dinheiro::paraCampo($g['valor']),
                                        'finalidade'           => $g['finalidade'],
                                        'status'               => $g['status'],
                                        'data_pagamento'       => $g['data_pagamento'],
                                        'observacao'           => $g['observacao'],
                                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">Editar</button>
                            <form method="post" action="<?= e(url('excluir-guia-deposito.php')) ?>"
                                  data-confirmar="Excluir a guia <?= e($g['numero'] ?? 's/nº') ?> de <?= e(moeda_br($g['valor'])) ?>? Esta operação não pode ser desfeita.">
                                <?= Csrf::campo() ?>
                                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                                <input type="hidden" name="retorno" value="<?= e($retorno) ?>">
                                <button type="submit" class="btn btn--ghost btn--sm acao__excluir" aria-label="Excluir guia">🗑</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
