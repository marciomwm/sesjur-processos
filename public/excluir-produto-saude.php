<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\ProdutoSaudeService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('processos-administrativos.php');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
$processoId = entrada_int('processo_id', $_POST);
$destino = $processoId !== null ? 'processo-administrativo.php?id=' . $processoId . '#produtos-saude' : 'processos-administrativos.php';

if ($id === null) {
    flash('erro', 'Produto de saúde não informado.');
    redirecionar($destino);
}

try {
    $processoId = (new ProdutoSaudeService())->excluir($id);
    flash('sucesso', 'Produto de saúde excluído do processo.');
    $destino = 'processo-administrativo.php?id=' . $processoId . '#produtos-saude';
} catch (ValidationException | AcessoNegadoException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'produto-saude.excluir');
    flash('erro', "Não foi possível excluir o produto (código {$codigo}).");
}

redirecionar($destino);
