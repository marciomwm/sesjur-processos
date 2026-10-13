<?php
/**
 * Documentos do processo judicial, com o estado da íntegra.
 *
 * @var array<int, array<string, mixed>> $documentos  linhas gravadas
 *      (DocumentoJudicialRepository::listarPorProcesso, com `id` e
 *      `tamanho_integra`) ou recém-normalizadas (Normalizador::
 *      documentosJudiciais, com `texto_integral`)
 * @var string|null $documentosVazio  mensagem quando não há documentos
 *
 * A mesma marcação é produzida em JS por form-processo-judicial.js
 * (renderizarDocumentos) após a extração — mantenha as duas em sincronia.
 */

use App\Validators\Normalizador;

$documentos = $documentos ?? [];
?>
<?php if ($documentos === []): ?>
    <p class="texto-secundario mb-0" data-documentos-vazio>
        <?= e($documentosVazio ?? 'Nenhum documento registrado.') ?>
    </p>
<?php else: ?>
    <div class="tabela-wrap eventos-wrap">
        <table class="tabela tabela--compacta tabela--documentos">
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Data</th>
                    <th>Peça</th>
                    <th>Juntada</th>
                    <th>Íntegra</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($documentos as $doc): ?>
                    <?php
                    $classificacao = $doc['classificacao'] ?? null;
                    $metodo = $doc['metodo_extracao'] ?? null;
                    $tamanho = (int)($doc['tamanho_integra'] ?? 0);
                    $textoNovo = $doc['texto_integral'] ?? null;
                    ?>
                    <tr data-decisoria="<?= $classificacao ? '1' : '0' ?>">
                        <td>
                            <span class="mono"><?= e((string)$doc['documento_id']) ?></span>
                            <?php if (!empty($doc['tipo'])): ?><br><span class="texto-secundario"><?= e($doc['tipo']) ?></span><?php endif; ?>
                        </td>
                        <td class="nowrap">
                            <?= e(data_br($doc['data_documento'] ?? null)) ?>
                            <?php if (!empty($doc['hora'])): ?><span class="mono texto-secundario"><?= e(substr((string)$doc['hora'], 0, 5)) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($classificacao): ?>
                                <span class="badge badge--azul"><?= e(Normalizador::CLASSIFICACOES_DOCUMENTO[$classificacao] ?? $classificacao) ?></span>
                            <?php else: ?>
                                <span class="texto-secundario">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($doc['juntado_por'])): ?>
                                <?= e($doc['juntado_por']) ?>
                                <?php if (!empty($doc['data_juntada'])): ?><br><span class="texto-secundario"><?= e(datahora_br($doc['data_juntada'])) ?></span><?php endif; ?>
                            <?php else: ?>
                                <span class="texto-secundario">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tamanho > 0 && !empty($doc['id'])): ?>
                                <a class="btn btn--secundario btn--sm" href="<?= e(url('documento-judicial.php?id=' . (int)$doc['id'])) ?>">Ler íntegra</a>
                                <span class="texto-secundario nowrap"><?= e(number_format($tamanho, 0, ',', '.')) ?> caracteres</span>
                            <?php elseif (is_string($textoNovo) && $textoNovo !== ''): ?>
                                <details class="integra">
                                    <summary>Prévia da íntegra</summary>
                                    <div class="integra__texto"><?= e($textoNovo) ?></div>
                                </details>
                            <?php elseif ($metodo !== null): ?>
                                <span class="badge badge--ambar" title="<?= e(Normalizador::METODOS_EXTRACAO[$metodo] ?? $metodo) ?>">
                                    <?= e(Normalizador::METODOS_EXTRACAO[$metodo] ?? $metodo) ?>
                                </span>
                            <?php else: ?>
                                <span class="texto-secundario">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
