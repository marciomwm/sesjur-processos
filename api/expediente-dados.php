<?php
declare(strict_types=1);

/**
 * Valores das propriedades do Gerador de Expedientes para um Processo
 * Administrativo: os atributos dele e os dos Processos Judiciais e
 * Extrajudiciais vinculados.
 * GET ?id=<processo administrativo>
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\ExpedienteService;

exigir_metodo('GET');

$id = entrada_int('id');
if ($id === null) {
    responder_json([
        'success' => false,
        'error'   => ['code' => 'INVALID_ID', 'message' => 'Informe o processo administrativo.'],
    ], 422);
}

try {
    $pacote = (new ExpedienteService())->pacote($id);
} catch (Throwable $e) {
    responder_json([
        'success' => false,
        'error'   => ['code' => 'DB_ERROR', 'message' => 'Falha ao consultar o banco de dados.'],
    ], 500);
}

if ($pacote === null) {
    responder_json([
        'success' => false,
        'error'   => ['code' => 'NOT_FOUND', 'message' => 'Processo administrativo não encontrado.'],
    ], 404);
}

responder_json(['success' => true] + $pacote);
