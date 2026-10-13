<?php
/**
 * Cartao de uma Acao (gerenciador de tarefas e ficha do processo).
 * Variaveis: $acao (AcaoRepository::buscarPorId/listar), $retorno (pagina
 *            para voltar apos alterar), $mostrarProcesso (bool)
 *
 * Os botoes de alteracao so aparecem para quem pode alterar; a mesma regra
 * e aplicada no AcaoService.
 */
use App\Core\Csrf;
use App\Core\Prazo;
use App\Services\AcaoService;

$finalizada = AcaoService::finalizada($acao['status']);
$situacaoPrazo = $finalizada ? null : Prazo::situacao($acao['prazo'], $acao['prazo_hora']);
$podeAlterar = AcaoService::podeAlterar($acao);

// Proximos passos mais comuns, como botoes de um clique.
$transicoes = match ($acao['status']) {
    'PENDENTE'     => ['EM_ANDAMENTO' => '▶ Iniciar', 'CONCLUIDA' => '✓ Concluir'],
    'EM_ANDAMENTO' => ['CONCLUIDA' => '✓ Concluir', 'PENDENTE' => '↺ Pendente'],
    default        => ['PENDENTE' => '↺ Reabrir'],
};
?>
<article class="acao <?= $finalizada ? 'is-finalizada' : '' ?> <?= $situacaoPrazo === 'vencido' ? 'is-vencida' : '' ?>" id="acao-<?= (int)$acao['id'] ?>">
    <div class="acao__topo">
        <span class="badge <?= e(AcaoService::classeBadge($acao['status'])) ?>"><?= e(AcaoService::STATUS[$acao['status']] ?? $acao['status']) ?></span>
        <?php if ($acao['prazo'] !== null): ?>
            <span class="badge <?= e($finalizada ? 'badge--neutro' : Prazo::classeBadge($situacaoPrazo)) ?>"
                  title="<?= e($finalizada ? 'Prazo' : (Prazo::ROTULOS_SITUACAO[$situacaoPrazo] ?? 'Prazo')) ?>">
                ⏱ <?= e(Prazo::formatar($acao['prazo'], $acao['prazo_hora'])) ?>
            </span>
        <?php else: ?>
            <span class="badge badge--neutro">Sem prazo</span>
        <?php endif; ?>
    </div>

    <p class="acao__descricao"><?= e($acao['descricao']) ?></p>

    <?php if (!empty($mostrarProcesso)): ?>
        <a class="acao__processo mono" href="<?= e(url('processo-administrativo.php?id=' . $acao['processo_administrativo_id'])) ?>#acoes"
           title="<?= e(mb_substr((string)($acao['processo_objeto'] ?? ''), 0, 200)) ?>"><?= e($acao['protocolo']) ?></a>
    <?php endif; ?>

    <?php if ($acao['observacao'] !== null && $acao['observacao'] !== ''): ?>
        <p class="acao__obs"><?= nl2br(e($acao['observacao'])) ?></p>
    <?php endif; ?>

    <p class="acao__autoria">
        <?= $acao['criado_por_nome'] !== null ? 'Criada por ' . e($acao['criado_por_nome']) : 'Criada pelo sistema' ?>
        em <?= e(datahora_br($acao['created_at'])) ?>
        <?php if ($finalizada && $acao['finalizada_em'] !== null): ?>
            · finalizada em <?= e(datahora_br($acao['finalizada_em'])) ?>
        <?php endif; ?>
    </p>

    <?php if ($podeAlterar): ?>
        <div class="acao__comandos">
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
                  data-confirmar="Excluir a ação “<?= e(mb_substr($acao['descricao'], 0, 80)) ?>”? Esta operação não pode ser desfeita.">
                <?= Csrf::campo() ?>
                <input type="hidden" name="id" value="<?= (int)$acao['id'] ?>">
                <input type="hidden" name="retorno" value="<?= e($retorno) ?>">
                <button type="submit" class="btn btn--ghost btn--sm acao__excluir" aria-label="Excluir ação">🗑</button>
            </form>
        </div>
    <?php endif; ?>
</article>
