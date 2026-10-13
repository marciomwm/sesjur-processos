<?php
/**
 * Tabela de processos designados a um usuario (painel e ficha do usuario).
 * Variaveis: $designacoes (DesignacaoRepository::doUsuario)
 */
use App\Validators\Normalizador;
?>
<div class="tabela-wrap">
    <table class="tabela">
        <thead><tr><th>Processo</th><th>Tipo</th><th>Descrição</th><th>Situação</th><th>Designado em</th></tr></thead>
        <tbody>
        <?php foreach ($designacoes as $d): ?>
            <?php $adm = $d['tipo'] === 'administrativo'; ?>
            <tr>
                <td class="nowrap">
                    <a class="mono" href="<?= e(url(($adm ? 'processo-administrativo.php?id=' : 'processo-judicial.php?id=') . $d['id'])) ?>"><?= e($d['numero']) ?></a>
                </td>
                <td><span class="badge <?= $adm ? 'badge--azul' : '' ?>"><?= $adm ? 'Administrativo' : 'Judicial' ?></span></td>
                <td><span class="tabela__truncado" title="<?= e($d['descricao']) ?>"><?= ou_traco($d['descricao']) ?></span></td>
                <td><?= $adm
                        ? ou_traco(Normalizador::SITUACOES[$d['situacao'] ?? ''] ?? $d['situacao'])
                        : ou_traco($d['situacao']) ?></td>
                <td class="nowrap texto-secundario"><?= e(datahora_br($d['designado_em'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
