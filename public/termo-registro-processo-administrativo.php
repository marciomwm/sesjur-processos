<?php
declare(strict_types=1);

/**
 * Termo de Registro do Processo Administrativo: documento autonomo (sem o
 * layout da aplicacao), formatado em A4 para o usuario salvar em PDF pela
 * impressao do navegador — sem biblioteca de PDF nem dependencia externa
 * (ADR 0004).
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\AssuntoProcessualRepository;
use App\Repositories\DesignacaoRepository;
use App\Repositories\EventoAdministrativoRepository;
use App\Repositories\ParteProcessualRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\VinculoRepository;
use App\Validators\Normalizador;

$id = entrada_int('id');
if ($id === null) {
    flash('erro', 'Processo administrativo não informado.');
    redirecionar('processos-administrativos.php');
}

$registro = (new ProcessoAdministrativoRepository())->buscarPorId($id);
if ($registro === null) {
    flash('erro', 'Processo administrativo não encontrado.');
    redirecionar('processos-administrativos.php');
}

$eventos    = (new EventoAdministrativoRepository())->listarPorProcesso($id);
$designados = (new DesignacaoRepository())->listarPorProcesso('administrativo', $id);
$ultimo     = $eventos[0] ?? null;

$partesRepo   = new ParteProcessualRepository();
$assuntosRepo = new AssuntoProcessualRepository();
$judiciais = array_map(static fn(array $pj): array => $pj + [
    'partes'   => $partesRepo->listarPorProcesso((int)$pj['id']),
    'assuntos' => $assuntosRepo->listarPorProcesso((int)$pj['id']),
], (new VinculoRepository())->judiciaisDoAdministrativo($id));

$polos = ['ATIVO' => 'Polo ativo', 'PASSIVO' => 'Polo passivo', 'TERCEIRO' => 'Outros interessados', 'OUTRO' => 'Outros'];

$situacao = $registro['situacao'] ?? null;
$lotacao  = implode(' — ', array_filter([
    $registro['responsavel_lotacao'] ?? null,
    $registro['responsavel_lotacao_nome'] ?? null,
], static fn($v) => $v !== null && trim((string)$v) !== ''));

$emitidoEm  = new DateTimeImmutable();
$emissor    = Auth::usuario();
// O navegador usa o <title> como nome sugerido do PDF: sem barras.
$nomeArquivo = 'Termo de Registro - ' . str_replace(['/', '\\'], '-', (string)$registro['protocolo']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($nomeArquivo) ?></title>
    <style>
        @page { size: A4; margin: 18mm 16mm 20mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: #e9ecf2; color: #152238;
            font: 10.5pt/1.45 "Segoe UI", system-ui, -apple-system, Arial, sans-serif;
        }
        .barra {
            position: sticky; top: 0; z-index: 1; display: flex; gap: 8px; justify-content: center;
            flex-wrap: wrap; padding: 10px 16px; background: #152238;
        }
        .barra button, .barra a {
            font: inherit; font-size: 13px; padding: 7px 14px; border-radius: 6px; cursor: pointer;
            border: 1px solid #fff; background: #fff; color: #152238; text-decoration: none;
        }
        .barra a { background: transparent; color: #fff; }
        .folha {
            width: 210mm; max-width: calc(100% - 32px); margin: 20px auto; padding: 18mm 16mm;
            background: #fff; box-shadow: 0 2px 12px rgba(0, 0, 0, .15);
        }
        .cabecalho { text-align: center; border-bottom: 2px solid #152238; padding-bottom: 10px; margin-bottom: 16px; }
        .cabecalho__orgao { margin: 0; font-size: 9pt; letter-spacing: .08em; text-transform: uppercase; color: #4a5568; }
        .cabecalho__titulo { margin: 6px 0 2px; font-size: 15pt; letter-spacing: .04em; text-transform: uppercase; }
        .cabecalho__numero { margin: 0; font-family: Consolas, "Courier New", monospace; font-size: 12pt; font-weight: 600; }
        .preambulo { text-align: justify; margin: 0 0 14px; }
        h2 {
            font-size: 10.5pt; text-transform: uppercase; letter-spacing: .05em; margin: 18px 0 6px;
            padding: 4px 8px; background: #edf1f7; border-left: 4px solid #152238;
            break-after: avoid; page-break-after: avoid;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #c9d1dd; padding: 5px 8px; vertical-align: top; text-align: left; }
        th { background: #f6f8fb; font-weight: 600; }
        .dados th { width: 32%; }
        .mono { font-family: Consolas, "Courier New", monospace; }
        .secundario { color: #4a5568; font-size: 9pt; }
        .vazio { color: #4a5568; font-style: italic; margin: 4px 0; }
        .judicial { margin-top: 10px; break-inside: avoid; page-break-inside: avoid; }
        .judicial + .judicial { margin-top: 14px; }
        .judicial__titulo { margin: 0 0 4px; font-size: 10.5pt; }
        ul { margin: 0; padding-left: 16px; }
        li + li { margin-top: 2px; }
        .rodape { margin-top: 16px; break-inside: avoid; padding-top: 8px; border-top: 1px solid #c9d1dd; font-size: 9pt; color: #4a5568; }
        @media (max-width: 640px) {
            .folha { padding: 16px; margin: 16px auto; }
            .dados th { width: 40%; }
        }
        @media print {
            body { background: #fff; }
            .barra { display: none; }
            .folha { width: auto; max-width: none; margin: 0; padding: 0; box-shadow: none; }
            h2, th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<div class="barra">
    <button type="button" onclick="window.print()">⤓ Salvar em PDF / Imprimir</button>
    <a href="<?= e(url('processo-administrativo.php?id=' . $id)) ?>">← Voltar ao processo</a>
</div>

<main class="folha">
    <header class="cabecalho">
        <p class="cabecalho__orgao"><?= e(config('app.nome')) ?> · <?= e(config('app.unidade')) ?></p>
        <h1 class="cabecalho__titulo">Termo de Registro de Processo Administrativo</h1>
        <p class="cabecalho__numero"><?= e($registro['protocolo']) ?></p>
    </header>

    <p class="preambulo">
        Certifica-se, para os devidos fins, que o Processo Administrativo abaixo identificado encontra-se
        registrado no sistema <?= e(config('app.nome')) ?>, com as informações constantes nesta data,
        conforme segue.
    </p>

    <h2>1. Identificação do processo</h2>
    <table class="dados">
        <tr><th>Número do Protocolo SIGADOC</th><td class="mono"><?= e($registro['protocolo']) ?></td></tr>
        <tr><th>Data da Abertura</th><td><?= e(data_br($registro['data_abertura'])) ?></td></tr>
        <tr><th>Objeto</th><td><?= nl2br(ou_traco($registro['objeto'])) ?></td></tr>
    </table>

    <h2>2. Designação</h2>
    <?php if ($designados === []): ?>
        <p class="vazio">Nenhum assessor designado para este processo.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Nome do Assessor Designado</th><th style="width:30%">Data de Designação</th></tr></thead>
            <tbody>
                <?php foreach ($designados as $d): ?>
                    <tr>
                        <td>
                            <?= e($d['nome']) ?>
                            <br><span class="secundario"><?= e($d['cargo']) ?> · <?= e($d['lotacao']) ?></span>
                        </td>
                        <td><?= e(data_br($d['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>3. Situação no SIGADOC</h2>
    <table class="dados">
        <tr>
            <th>Situação</th>
            <td>
                <?= $situacao !== null ? e(Normalizador::SITUACOES[$situacao] ?? $situacao) : '—' ?>
                <?php if (!empty($registro['situacao_descricao'])): ?>
                    <br><span class="secundario">No SIGADOC: <?= e($registro['situacao_descricao']) ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <tr><th>Lotação</th><td><?= ou_traco($lotacao) ?></td></tr>
        <tr>
            <th>Última movimentação</th>
            <td>
                <?php if ($ultimo !== null): ?>
                    <?= e($ultimo['tipo_descricao'] ?: $ultimo['tipo']) ?> em <?= e(data_br($ultimo['data_evento'])) ?>
                    <?php if (!empty($ultimo['lotacao']) || !empty($ultimo['responsavel'])): ?>
                        <br><span class="secundario"><?= e(implode(' · ', array_filter([$ultimo['lotacao'], $ultimo['responsavel']]))) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($ultimo['descricao'])): ?>
                        <br><span class="secundario"><?= e($ultimo['descricao']) ?></span>
                    <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
        </tr>
    </table>

    <h2>4. Processos Judiciais Relacionados</h2>
    <?php if ($judiciais === []): ?>
        <p class="vazio">Nenhum processo judicial vinculado a este processo administrativo.</p>
    <?php else: ?>
        <?php foreach ($judiciais as $i => $pj): ?>
            <div class="judicial">
                <p class="judicial__titulo"><strong>4.<?= $i + 1 ?>.</strong> <span class="mono"><?= e($pj['numero_cnj']) ?></span></p>
                <table class="dados">
                    <tr><th>Número CNJ</th><td class="mono"><?= e($pj['numero_cnj']) ?></td></tr>
                    <tr><th>Tribunal</th><td><?= ou_traco($pj['tribunal']) ?></td></tr>
                    <tr><th>Órgão Julgador</th><td><?= ou_traco($pj['orgao_julgador']) ?></td></tr>
                    <tr>
                        <th>Partes Processuais</th>
                        <td>
                            <?php if ($pj['partes'] === []): ?>—<?php else: ?>
                                <ul>
                                    <?php foreach ($pj['partes'] as $parte): ?>
                                        <li>
                                            <?= e($parte['nome']) ?>
                                            <span class="secundario">(<?= e($parte['tipo'] ?: ($polos[$parte['polo']] ?? $parte['polo'])) ?>)</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Assuntos</th>
                        <td>
                            <?php if ($pj['assuntos'] === []): ?>—<?php else: ?>
                                <ul>
                                    <?php foreach ($pj['assuntos'] as $assunto): ?>
                                        <li>
                                            <?= e($assunto['descricao']) ?><?= !empty($assunto['codigo']) ? ' (' . e($assunto['codigo']) . ')' : '' ?>
                                            <?php if ((int)$assunto['principal'] === 1): ?><span class="secundario">— principal</span><?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <footer class="rodape">
        Termo emitido em <?= e($emitidoEm->format('d/m/Y')) ?> às <?= e($emitidoEm->format('H:i'))
            . ($emissor !== null ? ' por ' . e($emissor['nome']) : '') ?>, a partir dos dados registrados no sistema <?= e(config('app.nome')) ?>.
        <?php if (!empty($registro['data_extracao'])): ?>
            Dados do SIGADOC conforme extração de <?= e(datahora_br($registro['data_extracao'])) ?>.
        <?php endif; ?>
    </footer>
</main>

<script>
    // Abre a caixa de impressao ao carregar; nela o usuario escolhe "Salvar como PDF".
    window.addEventListener('load', function () { window.print(); });
</script>
</body>
</html>
