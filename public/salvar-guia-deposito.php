<?php
declare(strict_types=1);

/**
 * Registra (sem id) ou altera (com id) uma Guia de Deposito Judicial.
 * Mesmo fluxo do lancamento de bloqueio: modal da ficha ou do painel.
 */

if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
    define('GJ_API', true);
}
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\GuiaDepositoRepository;
use App\Services\GuiaDepositoService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('bloqueios-judiciais.php');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
$processoId = entrada_int('processo_judicial_id', $_POST);
if ($id !== null) {
    $processoId = (int)((new GuiaDepositoRepository())->buscarPorId($id)['processo_judicial_id'] ?? 0) ?: $processoId;
}
$destino = destino_bloqueios(entrada('retorno'), $processoId);
$entrada = [];
foreach (['numero', 'data_emissao', 'vencimento', 'valor', 'finalidade', 'status', 'data_pagamento'] as $campo) {
    $entrada[$campo] = entrada($campo);
}
$entrada['observacao'] = (string)($_POST['observacao'] ?? '');

$servico = new GuiaDepositoService();
try {
    if ($id === null) {
        $servico->criar($processoId, $entrada);
        responder_formulario(true, 'Guia de depósito registrada.', $destino);
    }
    $servico->atualizar($id, $entrada);
    responder_formulario(true, 'Guia de depósito atualizada.', $destino);
} catch (ValidationException $e) {
    responder_formulario(false, $e->erros() === [] ? $e->getMessage() : 'Corrija os campos destacados.', $destino, $e->erros());
} catch (AcessoNegadoException $e) {
    responder_formulario(false, $e->getMessage(), $destino, [], 403);
} catch (Throwable $e) {
    $codigo = log_erro($e, 'guia.salvar');
    responder_formulario(false, "Não foi possível salvar a guia (código {$codigo}).", $destino, [], 500);
}
