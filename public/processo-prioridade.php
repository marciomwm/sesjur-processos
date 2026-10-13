<?php
declare(strict_types=1);

/**
 * Atribui (acao=adicionar) ou remove (acao=remover) uma prioridade do
 * processo administrativo. Somente POST com CSRF; volta para a guia
 * Prioridades da ficha.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\PrioridadeService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('processos-administrativos.php');
}
Csrf::exigir();

$processoId   = entrada_int('processo_id', $_POST);
$prioridadeId = entrada_int('prioridade_id', $_POST);
if ($processoId === null) {
    flash('erro', 'Processo administrativo não informado.');
    redirecionar('processos-administrativos.php');
}
$destino = 'processo-administrativo.php?id=' . $processoId . '#prioridades';
$servico = new PrioridadeService();

try {
    if (entrada('acao') === 'remover') {
        $servico->remover($processoId, (int)$prioridadeId);
        flash('sucesso', 'Prioridade removida do processo. O cadastro da prioridade foi mantido.');
    } else {
        $servico->atribuir([
            'processo_id'   => $processoId,
            'prioridade_id' => (string)($prioridadeId ?? ''),
            'observacao'    => entrada('observacao'),
        ]);
        flash('sucesso', 'Prioridade atribuída ao processo.');
    }
} catch (ValidationException $e) {
    flash('erro', implode(' ', $e->erros()) ?: $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'processo.prioridade');
    flash('erro', "Não foi possível alterar as prioridades (código {$codigo}).");
}

redirecionar($destino);
