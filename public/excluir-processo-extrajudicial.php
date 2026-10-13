<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\ProcessoExtrajudicialService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('processos-extrajudiciais.php');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
if ($id === null) {
    flash('erro', 'Processo extrajudicial não informado.');
    redirecionar('processos-extrajudiciais.php');
}

try {
    (new ProcessoExtrajudicialService())->excluir($id);
    flash('sucesso', 'Processo extrajudicial excluído. Os processos administrativos vinculados foram preservados.');
} catch (ValidationException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'extrajudicial.excluir');
    flash('erro', "Não foi possível excluir (código {$codigo}).");
}

redirecionar('processos-extrajudiciais.php');
