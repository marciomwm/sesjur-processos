<?php
declare(strict_types=1);

/**
 * Modelos do Gerador de Expedientes (App\Services\ModeloExpedienteService).
 *
 * GET                                              -> modelos visiveis ao usuario
 * POST   {acao:"criar", modelo:{...}}              -> cria
 * POST   {acao:"atualizar", id, modelo:{...}}      -> altera os campos informados
 * POST   {acao:"duplicar", id}                     -> copia individual
 * POST   {acao:"favoritar", id, favorito}          -> favorito do usuario
 * POST   {acao:"usar", id}                         -> conta um uso do usuario
 * DELETE {id}                                      -> exclui
 *
 * modelo = { nome, tipo, descricao, visibilidade, conteudo:{ base, html,
 *            personalizadas, selecao, formato, assuntoPadrao } }
 * As escritas devolvem o modelo como o gerador o le (campo `modelo`).
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\ValidationException;
use App\Services\ModeloExpedienteService;

exigir_metodo('GET', 'POST', 'DELETE');

$servico = new ModeloExpedienteService();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        responder_json(['success' => true, 'modelos' => $servico->visiveis()]);
    }

    exigir_csrf_api();
    $corpo = corpo_json();
    $id = (int)($corpo['id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $servico->excluir($id);
        responder_json(['success' => true]);
    }

    $modelo = is_array($corpo['modelo'] ?? null) ? $corpo['modelo'] : [];
    switch ($corpo['acao'] ?? '') {
        case 'criar':
            $id = $servico->criar($modelo);
            break;
        case 'atualizar':
            $servico->atualizar($id, $modelo);
            break;
        case 'duplicar':
            $id = $servico->duplicar($id);
            break;
        case 'favoritar':
            $servico->marcarFavorito($id, (bool)($corpo['favorito'] ?? false));
            break;
        case 'usar':
            $servico->registrarUso($id);
            break;
        default:
            responder_json([
                'success' => false,
                'error'   => ['code' => 'INVALID_ACTION', 'message' => 'Ação inválida.'],
            ], 422);
    }
    responder_json(['success' => true, 'modelo' => $servico->obter($id)]);
} catch (AcessoNegadoException $e) {
    responder_json([
        'success' => false,
        'error'   => ['code' => 'FORBIDDEN', 'message' => $e->getMessage()],
    ], 403);
} catch (ValidationException $e) {
    responder_json([
        'success' => false,
        'error'   => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage(), 'details' => $e->erros()],
    ], 422);
} catch (Throwable $e) {
    responder_json([
        'success' => false,
        'error'   => ['code' => 'INTERNAL_ERROR', 'message' => 'Falha ao gravar o modelo.'],
    ], 500);
}
