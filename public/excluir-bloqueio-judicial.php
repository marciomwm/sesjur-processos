<?php
declare(strict_types=1);

/**
 * Exclui um lancamento de bloqueio (administrador, gestor ou quem o
 * registrou). Somente POST com CSRF; recusado se o saldo ficar negativo.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\BloqueioJudicialRepository;
use App\Services\BloqueioJudicialService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('bloqueios-judiciais.php');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
$registro = $id !== null ? (new BloqueioJudicialRepository())->buscarPorId($id) : null;
$destino = destino_bloqueios(entrada('retorno'), $registro !== null ? (int)$registro['processo_judicial_id'] : null);
if ($registro === null) {
    flash('erro', 'Lançamento não encontrado.');
    redirecionar($destino);
}

try {
    $excluido = (new BloqueioJudicialService())->excluir($id);
    flash('sucesso', (BloqueioJudicialService::TIPOS[$excluido['tipo']] ?? 'Lançamento') . ' de '
        . moeda_br($excluido['valor']) . ' em ' . data_br($excluido['data_lancamento']) . ' excluído.');
} catch (ValidationException | AcessoNegadoException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'bloqueio.excluir');
    flash('erro', "Não foi possível excluir o lançamento (código {$codigo}).");
}
redirecionar($destino);
