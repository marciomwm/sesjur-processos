<?php
/**
 * Tabela de Intimacoes Pessoais (listagem, ficha do processo e do agente).
 * Variaveis: $intimacoes (IntimacaoPessoalRepository), $mostrarProcesso
 *            (bool), $vazioIntimacoes (texto sem registros, opcional).
 *
 * Linhas vencidas e proximas do vencimento levam destaque. "Registrar
 * cumprimento" abre o modal #modalCumprimentoIntimacao (modais-intimacao.php).
 */
use App\Services\IntimacaoPessoalService as Intimacoes;

$hojeTabela = date('Y-m-d');
$colunasTabela = 8 + (!empty($mostrarProcesso) ? 1 : 0);
?>
<div class="tabela-wrap">
    <table class="tabela tabela--intimacoes">
        <thead>
            <tr>
                <?php if (!empty($mostrarProcesso)): ?><th>Processo</th><?php endif; ?>
                <th>Pessoa intimada</th>
                <th>Recebido por</th>
                <th>Recebimento</th>
                <th>Prazo</th>
                <th>Dias restantes</th>
                <th>Cumprimento</th>
                <th>Status</th>
                <th class="texto-direita">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($intimacoes === []): ?>
            <tr><td colspan="<?= $colunasTabela ?>" class="tabela__vazia"><?= e($vazioIntimacoes ?? 'Nenhuma intimação registrada.') ?></td></tr>
        <?php else: foreach ($intimacoes as $intimacao): ?>
            <?php
            $situacaoIntimacao = Intimacoes::situacao($intimacao, $hojeTabela);
            $diasIntimacao = Intimacoes::diasRestantes($intimacao, $hojeTabela);
            $podeAlterarIntimacao = Intimacoes::podeAlterar($intimacao);
            $abertaIntimacao = in_array($intimacao['status'], ['PENDENTE', 'EM_ANDAMENTO'], true);
            $rotuloIntimacao = 'Intimação de ' . $intimacao['intimado_nome'] . ' · ' . $intimacao['processo_numero'];
            ?>
            <tr id="intimacao-<?= (int)$intimacao['id'] ?>" class="<?= $situacaoIntimacao === 'VENCIDA' ? 'is-vencida' : ($situacaoIntimacao === 'PROXIMA' ? 'is-proxima' : '') ?>">
                <?php if (!empty($mostrarProcesso)): ?>
                    <td>
                        <a class="mono nowrap" href="<?= e(url(Intimacoes::urlProcesso($intimacao['processo_tipo'], (int)$intimacao['processo_id']))) ?>"><?= e($intimacao['processo_numero']) ?></a>
                        <span class="tabela__detalhe"><?= e(Intimacoes::TIPOS_PROCESSO[$intimacao['processo_tipo']]) ?><?= $intimacao['processo_instituicao_sigla'] ? ' · ' . e($intimacao['processo_instituicao_sigla']) : '' ?></span>
                    </td>
                <?php endif; ?>
                <td>
                    <?= e($intimacao['intimado_nome']) ?>
                    <span class="tabela__detalhe"><?= e(implode(' · ', array_filter([$intimacao['intimado_matricula'] ? 'Mat. ' . $intimacao['intimado_matricula'] : null, $intimacao['intimado_cargo']]))) ?></span>
                </td>
                <td>
                    <?= e($intimacao['recebedor_nome']) ?>
                    <?php if ((int)$intimacao['agente_recebedor_id'] === (int)$intimacao['agente_intimado_id']): ?>
                        <span class="tabela__detalhe">a própria pessoa intimada</span>
                    <?php endif; ?>
                </td>
                <td class="nowrap"><?= e(data_br($intimacao['data_recebimento'])) ?></td>
                <td class="nowrap"><strong><?= e(data_br($intimacao['data_prazo'])) ?></strong></td>
                <td class="nowrap">
                    <?php if ($diasIntimacao === null): ?>
                        <span class="texto-secundario">—</span>
                    <?php else: ?>
                        <span class="dias-restantes dias-restantes--<?= e(strtolower((string)$situacaoIntimacao)) ?>"><?= e(Intimacoes::textoDiasRestantes($diasIntimacao)) ?></span>
                    <?php endif; ?>
                </td>
                <td class="nowrap"><?= $intimacao['data_cumprimento'] !== null ? e(data_br($intimacao['data_cumprimento'])) : '<span class="texto-secundario">—</span>' ?></td>
                <td>
                    <div class="etiquetas">
                        <span class="badge <?= e(Intimacoes::classeBadgeStatus($intimacao['status'])) ?>"><?= e(Intimacoes::STATUS[$intimacao['status']]) ?></span>
                        <?php if ($situacaoIntimacao !== null): ?>
                            <span class="badge <?= e(Intimacoes::classeBadgeSituacao($situacaoIntimacao)) ?>"><?= e(Intimacoes::SITUACOES[$situacaoIntimacao]) ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="texto-direita">
                    <div class="acoes-linha acoes-linha--fim">
                        <a class="btn btn--ghost btn--sm" href="<?= e(url('intimacao.php?id=' . (int)$intimacao['id'])) ?>" title="Visualizar" aria-label="Visualizar <?= e($rotuloIntimacao) ?>">👁</a>
                        <?php if ($podeAlterarIntimacao): ?>
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-intimacao.php?id=' . (int)$intimacao['id'])) ?>" title="Editar" aria-label="Editar <?= e($rotuloIntimacao) ?>">✎</a>
                            <?php if ($abertaIntimacao): ?>
                                <button type="button" class="btn btn--secundario btn--sm nowrap" data-modal-abrir="modalCumprimentoIntimacao"
                                        data-modal-titulo="Registrar cumprimento — <?= e($intimacao['processo_numero']) ?>"
                                        data-modal-dados='<?= e(json_encode(['id' => (int)$intimacao['id'], 'data_cumprimento' => $hojeTabela])) ?>'
                                        data-prazo="<?= e($intimacao['data_prazo']) ?>" data-recebimento="<?= e($intimacao['data_recebimento']) ?>"
                                        data-intimado="<?= e($intimacao['intimado_nome']) ?>">✓ Cumprimento</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
