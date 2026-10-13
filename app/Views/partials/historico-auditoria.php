<?php
/**
 * Tabela do historico de alteracoes de um registro (trilha de auditoria).
 * Variaveis:
 *   $historico      AuditoriaRepository::listarPorEntidade
 *   $rotulosCampo   array<campo, rotulo> para as edicoes
 *   $valorAuditoria callable(string $campo, mixed $valor): string (opcional)
 *   $historicoVazio texto quando nao ha eventos (opcional)
 */
use App\Services\AuditoriaService;

$rotulosCampo   = $rotulosCampo ?? [];
$valorAuditoria = $valorAuditoria ?? static fn(string $campo, mixed $valor): string =>
    $valor === null || $valor === '' ? '—' : (is_scalar($valor) ? (string)$valor : json_encode($valor, JSON_UNESCAPED_UNICODE));
?>
<?php if ($historico === []): ?>
    <div class="card__corpo">
        <p class="texto-secundario mb-0"><?= e($historicoVazio ?? 'Nenhum evento registrado.') ?></p>
    </div>
<?php else: ?>
    <div class="tabela-wrap">
        <table class="tabela tabela--compacta">
            <thead><tr><th>Data/hora</th><th>Evento</th><th>Alterações</th><th>Responsável</th></tr></thead>
            <tbody>
            <?php foreach ($historico as $h): ?>
                <?php
                $antes  = json_decode((string)($h['dados_anteriores'] ?? ''), true) ?: [];
                $depois = json_decode((string)($h['dados_novos'] ?? ''), true) ?: [];
                ?>
                <tr>
                    <td class="nowrap"><?= e(datahora_br($h['created_at'])) ?></td>
                    <td class="nowrap"><span class="badge"><?= e(AuditoriaService::rotulo($h['acao'])) ?></span></td>
                    <td>
                        <?php if (preg_match('/_EDITAD[OA]$/', $h['acao']) && $depois !== []): ?>
                            <ul class="lista-alteracoes">
                                <?php foreach (array_diff_key($depois, array_flip(['acao_id', 'solicitacao_id', 'anotacao_id', 'produto_id'])) as $campo => $novo): ?>
                                    <li><strong><?= e($rotulosCampo[$campo] ?? $campo) ?>:</strong>
                                        <span class="texto-secundario"><?= e($valorAuditoria((string)$campo, $antes[$campo] ?? null)) ?></span>
                                        → <?= e($valorAuditoria((string)$campo, $novo)) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php elseif (($resumo = AuditoriaService::resumo($antes, $depois)) !== ''): ?>
                            <?= e($resumo) ?>
                        <?php else: ?>
                            <span class="texto-secundario">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $h['autor_nome'] !== null ? e($h['autor_nome']) : '<span class="texto-secundario">Não identificado</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
