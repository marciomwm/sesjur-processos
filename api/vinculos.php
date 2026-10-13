<?php
declare(strict_types=1);

/**
 * Criacao e remocao de vinculos N:N por JSON.
 * POST   {administrativo_id, judicial_id, observacao?}  -> cria
 * DELETE {administrativo_id, judicial_id}               -> remove (nao exclui processos)
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Core\ValidationException;
use App\Services\VinculoService;

exigir_metodo('POST', 'DELETE');
exigir_csrf_api();

$corpo = corpo_json();
$administrativoId = (int)($corpo['administrativo_id'] ?? 0);
$judicialId       = (int)($corpo['judicial_id'] ?? 0);

if ($administrativoId <= 0 || $judicialId <= 0) {
    responder_json([
        'success' => false,
        'error'   => ['code' => 'INVALID_IDS', 'message' => 'Informe os identificadores dos dois processos.'],
    ], 422);
}

$servico = new VinculoService();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $r = $servico->vincular($administrativoId, $judicialId, $corpo['observacao'] ?? null);
        responder_json([
            'success'  => true,
            'criado'   => $r['criado'],
            'mensagem' => $r['criado'] ? 'Vínculo criado.' : 'O vínculo já existia.',
        ]);
    }

    $removido = $servico->desvincular($administrativoId, $judicialId);
    responder_json([
        'success'  => true,
        'removido' => $removido,
        'mensagem' => $removido ? 'Vínculo removido. Nenhum processo foi excluído.' : 'O vínculo informado não existia.',
    ]);
} catch (ValidationException $e) {
    responder_json([
        'success' => false,
        'error'   => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage(), 'details' => $e->erros()],
    ], 422);
} catch (Throwable $e) {
    responder_json([
        'success' => false,
        'error'   => ['code' => 'INTERNAL_ERROR', 'message' => 'Falha ao processar o vínculo.'],
    ], 500);
}
