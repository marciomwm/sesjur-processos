<?php
declare(strict_types=1);

/** Exclui uma Guia de Deposito Judicial (administrador, gestor ou quem a registrou). */

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
$guia = $id !== null ? (new GuiaDepositoRepository())->buscarPorId($id) : null;
$destino = destino_bloqueios(entrada('retorno'), $guia !== null ? (int)$guia['processo_judicial_id'] : null);
if ($guia === null) {
    flash('erro', 'Guia não encontrada.');
    redirecionar($destino);
}

try {
    $excluida = (new GuiaDepositoService())->excluir($id);
    flash('sucesso', 'Guia ' . ($excluida['numero'] ?? 's/nº') . ' de ' . moeda_br($excluida['valor']) . ' excluída.');
} catch (ValidationException | AcessoNegadoException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'guia.excluir');
    flash('erro', "Não foi possível excluir a guia (código {$codigo}).");
}
redirecionar($destino);
