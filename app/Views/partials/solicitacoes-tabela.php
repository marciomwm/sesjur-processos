<?php
/**
 * Tabela de Solicitacoes (ficha do processo e listagem geral).
 * Variaveis: $solicitacoes (SolicitacaoRepository), $retorno (pagina para
 *            voltar apos alterar), $mostrarProcesso (bool),
 *            $linkOrdenacao (callable(campo, rotulo): string|null - cabecalhos ordenaveis)
 *
 * Os botoes de alteracao so aparecem para quem pode alterar; a mesma regra
 * e aplicada no SolicitacaoService.
 */
use App\Core\Csrf;
use App\Services\SolicitacaoService;

$cabecalho = static function (string $campo, string $rotulo) use (&$linkOrdenacao): string {
    return isset($linkOrdenacao) && is_callable($linkOrdenacao) ? $linkOrdenacao($campo, $rotulo) : e($rotulo);
};
$colunas = !empty($mostrarProcesso) ? 8 : 7;
?>
<div class="tabela-wrap">
    <table class="tabela tabela--providencias">
        <thead>
            <tr>
                <th><?= $cabecalho('data_solicitacao', 'Data') ?></th>
                <?php if (!empty($mostrarProcesso)): ?>
                    <th><?= $cabecalho('protocolo', 'Processo') ?></th>
                <?php endif; ?>
                <th>Expediente</th>
                <th><?= $cabecalho('destinatario', 'Destinatário') ?></th>
                <th>Assunto</th>
                <th><?= $cabecalho('prazo', 'Prazo') ?></th>
                <th><?= $cabecalho('status', 'Status') ?></th>
                <th class="texto-direita">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($solicitacoes === []): ?>
            <tr><td colspan="<?= $colunas ?>" class="tabela__vazia">Nenhuma solicitação encontrada.</td></tr>
        <?php else: foreach ($solicitacoes as $s): ?>
            <?php $vencida = SolicitacaoService::vencida($s); ?>
            <tr id="solicitacao-<?= (int)$s['id'] ?>" class="<?= $s['status'] !== 'AGUARDANDO' ? 'is-finalizada' : '' ?> <?= $vencida ? 'is-vencida' : '' ?>">
                <td class="nowrap"><?= e(data_br($s['data_solicitacao'])) ?></td>
                <?php if (!empty($mostrarProcesso)): ?>
                    <td class="nowrap">
                        <a class="mono" href="<?= e(url('processo-administrativo.php?id=' . $s['processo_administrativo_id'])) ?>#solicitacao-<?= (int)$s['id'] ?>"
                           title="<?= e(mb_substr((string)($s['processo_objeto'] ?? ''), 0, 200)) ?>"><?= e($s['protocolo']) ?></a>
                    </td>
                <?php endif; ?>
                <td class="nowrap mono"><?= e($s['expediente']) ?></td>
                <td>
                    <?= e($s['destinatario_nome']) ?>
                    <?php if (!empty($s['destinatario_sigla'])): ?>
                        <br><span class="texto-secundario mono"><?= e($s['destinatario_sigla']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="tabela__texto">
                    <?= e($s['assunto']) ?>
                    <br><span class="texto-secundario" title="Cadastrada em <?= e(datahora_br($s['created_at'])) ?>">
                        <?= $s['criado_por_nome'] !== null ? 'Por ' . e($s['criado_por_nome']) : 'Pelo sistema' ?>
                    </span>
                </td>
                <td class="nowrap">
                    <?php if ($s['prazo'] !== null): ?>
                        <span class="badge <?= $vencida ? 'badge--vermelho' : 'badge--neutro' ?>" title="<?= $vencida ? 'Prazo expirado sem resposta' : 'Prazo para resposta' ?>">
                            ⏱ <?= e(data_br($s['prazo'])) ?>
                        </span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="nowrap">
                    <span class="badge <?= e(SolicitacaoService::classeBadge($s['status'])) ?>"><?= e(SolicitacaoService::STATUS[$s['status']] ?? $s['status']) ?></span>
                </td>
                <td class="texto-direita nowrap">
                    <?php if (SolicitacaoService::podeAlterar($s)): ?>
                        <div class="acoes-linha tabela__comandos">
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-solicitacao.php?id=' . $s['id'] . '&retorno=' . rawurlencode($retorno))) ?>">Editar</a>
                            <form method="post" action="<?= e(url('excluir-solicitacao.php')) ?>"
                                  data-confirmar="Excluir a solicitação <?= e($s['expediente']) ?>? Esta operação não pode ser desfeita.">
                                <?= Csrf::campo() ?>
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <input type="hidden" name="retorno" value="<?= e($retorno) ?>">
                                <button type="submit" class="btn btn--ghost btn--sm acao__excluir" aria-label="Excluir solicitação">🗑</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <span class="texto-secundario">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
