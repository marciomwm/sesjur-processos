<?php
declare(strict_types=1);

/**
 * Exclui um recurso (administrador, gestor ou quem o cadastrou).
 * Somente POST com CSRF; a exclusao fica na auditoria.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\RecursoService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('recursos.php');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
if ($id === null) {
    flash('erro', 'Recurso não informado.');
    redirecionar('recursos.php');
}

try {
    $titulo = (new RecursoService())->excluir($id);
    flash('sucesso', 'Recurso “' . $titulo . '” excluído.');
} catch (ValidationException | AcessoNegadoException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'recurso.excluir');
    flash('erro', "Não foi possível excluir o recurso (código {$codigo}).");
}
redirecionar('recursos.php');
