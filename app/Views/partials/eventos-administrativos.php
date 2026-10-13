<?php
/**
 * Tabela de movimentações do processo administrativo (espelho do SIGADOC).
 *
 * @var array<int, array<string, ?string>> $eventos  linhas no formato do banco
 *      (data_evento, tipo, tipo_descricao, lotacao, responsavel,
 *       atendente_lotacao, atendente, documento, descricao)
 * @var string|null $eventosVazio  mensagem quando não há movimentações
 *
 * A mesma marcação é produzida em JS por form-processo-administrativo.js
 * (renderizarEventos) após a extração — mantenha as duas em sincronia.
 */

use App\Validators\Normalizador;

$eventos = $eventos ?? [];
$classeTipo = static fn(string $tipo): string => match ($tipo) {
    'SOBRESTAMENTO', 'ARQUIVAMENTO'          => 'badge--ambar',
    'DESSOBRESTAMENTO', 'DESARQUIVAMENTO'    => 'badge--verde',
    'JUNTADA', 'APENSACAO'                   => 'badge--azul',
    'DESENTRANHAMENTO', 'DESAPENSACAO'       => 'badge--vermelho',
    default                                  => 'badge--neutro',
};
?>
<?php if ($eventos === []): ?>
    <p class="texto-secundario mb-0" data-eventos-vazio><?= e($eventosVazio ?? 'Nenhuma movimentação registrada.') ?></p>
<?php else: ?>
    <div class="tabela-wrap eventos-wrap">
        <table class="tabela tabela--compacta">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Evento</th>
                    <th>Cadastrante</th>
                    <th>Atendente</th>
                    <th>Descrição</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventos as $ev): ?>
                    <?php $tipo = (string)($ev['tipo'] ?? 'OUTRO'); ?>
                    <tr data-tipo="<?= e($tipo) ?>">
                        <td class="nowrap"><?= e(data_br($ev['data_evento'] ?? null)) ?></td>
                        <td>
                            <span class="badge <?= e($classeTipo($tipo)) ?>">
                                <?= e($ev['tipo_descricao'] ?: (Normalizador::TIPOS_EVENTO[$tipo] ?? $tipo)) ?>
                            </span>
                        </td>
                        <td>
                            <?= ou_traco($ev['responsavel'] ?? null) ?>
                            <?php if (!empty($ev['lotacao'])): ?>
                                <br><span class="texto-secundario mono"><?= e($ev['lotacao']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= ou_traco($ev['atendente'] ?? null) ?>
                            <?php if (!empty($ev['atendente_lotacao'])): ?>
                                <br><span class="texto-secundario mono"><?= e($ev['atendente_lotacao']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($ev['documento'])): ?>
                                <span class="mono"><?= e($ev['documento']) ?></span><?= !empty($ev['descricao']) ? ' — ' : '' ?>
                            <?php endif; ?>
                            <?= !empty($ev['descricao']) ? e($ev['descricao']) : (empty($ev['documento']) ? '—' : '') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
