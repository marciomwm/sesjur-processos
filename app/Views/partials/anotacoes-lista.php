<?php
/**
 * Lista de Anotacoes (ficha do processo e listagem geral).
 * Variaveis: $anotacoes (AnotacaoRepository), $retorno (pagina para voltar
 *            apos alterar), $mostrarProcesso (bool)
 *
 * Os botoes de alteracao so aparecem para quem pode alterar; a mesma regra
 * e aplicada no AnotacaoService.
 */
use App\Core\Csrf;
use App\Services\AnotacaoService;
?>
<?php if ($anotacoes === []): ?>
    <p class="texto-secundario mb-0">Nenhuma anotação encontrada.</p>
<?php else: ?>
    <ol class="anotacoes">
        <?php foreach ($anotacoes as $n): ?>
            <li class="anotacao" id="anotacao-<?= (int)$n['id'] ?>">
                <div class="anotacao__meta">
                    <span class="avatar avatar--sm" aria-hidden="true"><?= e(iniciais($n['criado_por_nome'] ?? 'Sistema')) ?></span>
                    <span>
                        <strong><?= e($n['criado_por_nome'] ?? 'Sistema') ?></strong>
                        <span class="texto-secundario">· <?= e(datahora_br($n['created_at'])) ?></span>
                        <?php if ($n['updated_at'] !== $n['created_at']): ?>
                            <span class="texto-secundario" title="Editada em <?= e(datahora_br($n['updated_at'])) ?>">· editada</span>
                        <?php endif; ?>
                        <?php if (!empty($mostrarProcesso)): ?>
                            · <a class="mono" href="<?= e(url('processo-administrativo.php?id=' . $n['processo_administrativo_id'])) ?>#anotacao-<?= (int)$n['id'] ?>"
                                 title="<?= e(mb_substr((string)($n['processo_objeto'] ?? ''), 0, 200)) ?>"><?= e($n['protocolo']) ?></a>
                        <?php endif; ?>
                    </span>
                    <?php if (AnotacaoService::podeAlterar($n)): ?>
                        <span class="acoes-linha anotacao__comandos">
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-anotacao.php?id=' . $n['id'] . '&retorno=' . rawurlencode($retorno))) ?>">Editar</a>
                            <form method="post" action="<?= e(url('excluir-anotacao.php')) ?>"
                                  data-confirmar="Excluir esta anotação? Esta operação não pode ser desfeita.">
                                <?= Csrf::campo() ?>
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                <input type="hidden" name="retorno" value="<?= e($retorno) ?>">
                                <button type="submit" class="btn btn--ghost btn--sm acao__excluir" aria-label="Excluir anotação">🗑</button>
                            </form>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="anotacao__texto"><?= nl2br(e($n['texto'])) ?></div>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
