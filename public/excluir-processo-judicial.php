<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\ProcessoJudicialService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('processos-judiciais.php');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
if ($id === null) {
    flash('erro', 'Processo judicial não informado.');
    redirecionar('processos-judiciais.php');
}

try {
    (new ProcessoJudicialService())->excluir($id);
    flash('sucesso', 'Processo judicial excluído. Os processos administrativos vinculados foram preservados.');
} catch (ValidationException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    flash('erro', 'Não foi possível excluir: ' . $e->getMessage());
}

redirecionar('processos-judiciais.php');
