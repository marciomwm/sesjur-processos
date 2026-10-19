<?php
declare(strict_types=1);

/**
 * Registra ou altera os Dados do Pagamento (Pagamento Indenizatório) do
 * processo administrativo. Somente POST com CSRF; volta para a guia Dados
 * do Pagamento da ficha. Com erro de validação, o que foi digitado e os
 * erros por campo voltam à ficha pela sessão (uma única leitura).
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\PagamentoIndenizatorioService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('processos-administrativos.php');
}
Csrf::exigir();

$processoId = entrada_int('processo_id', $_POST);
if ($processoId === null) {
    flash('erro', 'Processo administrativo não informado.');
    redirecionar('processos-administrativos.php');
}
$destino = 'processo-administrativo.php?id=' . $processoId . '#dados-pagamento';

$entrada = [
    'demandante_lotacao_id' => entrada('demandante_lotacao_id'),
    'entregador_id'         => entrada('entregador_id'),
    'objeto'                => is_string($_POST['objeto'] ?? null) ? $_POST['objeto'] : '',
    'data_execucao'         => entrada('data_execucao'),
    'valor'                 => entrada('valor'),
];

try {
    $criado = (new PagamentoIndenizatorioService())->salvar($processoId, $entrada);
    flash('sucesso', $criado ? 'Dados do pagamento registrados.' : 'Dados do pagamento atualizados.');
} catch (ValidationException $e) {
    $erros = $e->erros();
    if ($erros === []) {
        flash('erro', $e->getMessage());
    } else {
        $_SESSION['dados_pagamento_form'] = ['processo_id' => $processoId, 'dados' => $entrada, 'erros' => $erros];
        flash('erro', 'Não foi possível salvar os dados do pagamento. Corrija os campos destacados.');
    }
} catch (Throwable $e) {
    $codigo = log_erro($e, 'processo.pagamento');
    flash('erro', "Não foi possível salvar os dados do pagamento (código {$codigo}).");
}

redirecionar($destino);
