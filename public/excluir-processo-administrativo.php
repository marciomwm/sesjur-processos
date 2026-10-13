<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\ProcessoAdministrativoService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('processos-administrativos.php');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
$retorno = entrada('retorno') ?: 'processos-administrativos.php';

if ($id === null) {
    flash('erro', 'Processo administrativo não informado.');
    redirecionar($retorno);
}

try {
    (new ProcessoAdministrativoService())->excluir($id);
    flash('sucesso', 'Processo administrativo excluído. Os processos judiciais vinculados foram preservados.');
} catch (ValidationException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    flash('erro', 'Não foi possível excluir: ' . $e->getMessage());
}

redirecionar('processos-administrativos.php');
