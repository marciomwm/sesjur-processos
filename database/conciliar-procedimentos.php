<?php
declare(strict_types=1);

/**
 * Relatorio de conciliacao do Procedimento: textos livres antigos
 * (processos_administrativos.procedimento) x catalogo (procedimentos).
 *
 *   php database/conciliar-procedimentos.php            (somente relatorio)
 *   php database/conciliar-procedimentos.php --aplicar  (vincula os conciliados)
 *   php database/conciliar-procedimentos.php --csv=relatorio.csv
 *
 * So ha correspondencia quando o texto, com espacos normalizados, e igual
 * ao nome de um item do catalogo sem distincao de caixa e acentos - nunca
 * por aproximacao. O texto original nunca e alterado. Os pendentes ficam
 * para revisao: cadastre o item no catalogo (se for um procedimento) ou
 * classifique o processo pela edicao. Somente linha de comando.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\ProcedimentoService;

$aplicar = in_array('--aplicar', $argv, true);
$csv = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--csv=')) {
        $csv = substr($arg, 6);
    }
}

$resultado = (new ProcedimentoService())->conciliarTextosLegados($aplicar);

echo "\nConciliação de procedimentos — " . ($aplicar ? 'APLICADA' : 'prévia (nada foi gravado)') . "\n";
echo str_repeat('─', 72) . "\n";
echo "Correspondências exatas: " . count($resultado['conciliados']) . " grafia(s)\n";
foreach ($resultado['conciliados'] as $c) {
    printf("  ✓ %-50s → %s (%d processo(s))\n", mb_strimwidth($c['texto'], 0, 50, '…'), $c['procedimento'], $c['vinculados'] ?? $c['total']);
}
echo "\nSem correspondência segura (revisar): " . count($resultado['pendentes']) . " grafia(s)\n";
foreach ($resultado['pendentes'] as $p) {
    printf("  ? %s (%d processo(s))\n", $p['texto'], $p['total']);
}
if ($aplicar) {
    echo "\nProcessos vinculados: {$resultado['vinculados']} (registrado na auditoria)\n";
} elseif ($resultado['conciliados'] !== []) {
    echo "\nPara vincular as correspondências exatas, rode novamente com --aplicar.\n";
}

if ($csv !== null) {
    $arquivo = fopen($csv, 'wb');
    if ($arquivo === false) {
        fwrite(STDERR, "Não foi possível criar {$csv}\n");
        exit(1);
    }
    fwrite($arquivo, "\xEF\xBB\xBF");
    fputcsv($arquivo, ['situacao', 'texto_legado', 'processos', 'procedimento_catalogo'], ';');
    foreach ($resultado['conciliados'] as $c) {
        fputcsv($arquivo, [$aplicar ? 'vinculado' : 'conciliavel', $c['texto'], $c['vinculados'] ?? $c['total'], $c['procedimento']], ';');
    }
    foreach ($resultado['pendentes'] as $p) {
        fputcsv($arquivo, ['pendente', $p['texto'], $p['total'], ''], ';');
    }
    fclose($arquivo);
    echo "\nRelatório gravado em {$csv}\n";
}
echo "\n";
