<?php
/**
 * Tabela da cronologia do processo judicial (espelho da timeline do PJe).
 *
 * @var array<int, array<string, mixed>> $movimentacoes  linhas no formato do
 *      banco (data_movimentacao, hora, tipo, descricao, documento_id,
 *      documento_tipo, natureza, origem) — ou recém-normalizadas, que trazem
 *      também `documentos` (todos os documentos do evento)
 * @var array<int, list<array>>|null $documentosPorMovimentacao  documentos
 *      gravados, agrupados por movimentacao_id (eventos com N documentos)
 * @var string|null $movimentacoesVazio  mensagem quando não há cronologia
 *
 * A mesma marcação é produzida em JS por form-processo-judicial.js
 * (renderizarCronologia) após a extração — mantenha as duas em sincronia.
 */

use App\Validators\Normalizador;

$movimentacoes = $movimentacoes ?? [];
$documentosPorMovimentacao = $documentosPorMovimentacao ?? [];

/** Documentos do evento: lista da extração, gravados por evento ou o par simples. */
$documentosDoEvento = static function (array $mov) use ($documentosPorMovimentacao): array {
    if (!empty($mov['documentos']) && is_array($mov['documentos'])) {
        return array_map(static fn(array $d): array => ['id' => $d['id'] ?? null, 'tipo' => $d['tipo'] ?? null], $mov['documentos']);
    }
    $gravados = $documentosPorMovimentacao[(int)($mov['id'] ?? 0)] ?? [];
    if ($gravados !== []) {
        return array_map(static fn(array $d): array => ['id' => $d['documento_id'], 'tipo' => $d['tipo']], $gravados);
    }
    return !empty($mov['documento_id'])
        ? [['id' => $mov['documento_id'], 'tipo' => $mov['documento_tipo'] ?? null]]
        : [];
};
?>
<?php if ($movimentacoes === []): ?>
    <p class="texto-secundario mb-0" data-movimentacoes-vazio>
        <?= e($movimentacoesVazio ?? 'Nenhuma movimentação registrada.') ?>
    </p>
<?php else: ?>
    <div class="tabela-wrap eventos-wrap">
        <table class="tabela tabela--compacta tabela--cronologia">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Hora</th>
                    <th>Origem</th>
                    <th>Movimentação</th>
                    <th>Documentos</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movimentacoes as $mov): ?>
                    <?php
                    $tipo = (string)($mov['tipo'] ?? 'MOVIMENTO');
                    $docs = $documentosDoEvento($mov);
                    $origem = $mov['origem'] ?? null;
                    $rotuloOrigem = $origem !== null ? (Normalizador::ORIGENS_EVENTO[$origem] ?? $origem) : ($mov['natureza'] ?? null);
                    ?>
                    <tr data-tipo="<?= e($tipo) ?>">
                        <td class="nowrap"><?= e(data_br($mov['data_movimentacao'] ?? null)) ?></td>
                        <td class="nowrap mono"><?= e(substr((string)($mov['hora'] ?? ''), 0, 5) ?: '—') ?></td>
                        <td class="nowrap">
                            <?php if ($rotuloOrigem): ?>
                                <span class="origem origem--<?= e(strtolower((string)($origem ?? 'outro'))) ?>"
                                      <?= !empty($mov['natureza']) ? 'title="' . e($mov['natureza']) . '"' : '' ?>><?= e($rotuloOrigem) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= ou_traco($mov['descricao'] ?? null) ?></td>
                        <td>
                            <?php if ($docs === []): ?>
                                <span class="texto-secundario">—</span>
                            <?php else: ?>
                                <ul class="docs-evento">
                                    <?php foreach ($docs as $d): ?>
                                        <li>
                                            <span class="mono"><?= e((string)$d['id']) ?></span>
                                            <?php if (!empty($d['tipo'])): ?>
                                                <span class="texto-secundario"><?= e($d['tipo']) ?></span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
