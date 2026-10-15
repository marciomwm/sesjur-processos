<?php
declare(strict_types=1);

/**
 * Exclui uma tarefa. Somente POST com CSRF; a permissão é verificada no
 * AcaoService (administrador, gestor ou quem criou a tarefa).
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\AcaoService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('acoes.php');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
$retorno = Auth::retornoSeguro(entrada('retorno') ?: 'acoes.php');

try {
    if ($id === null) {
        throw new ValidationException([], 'Tarefa não informada.');
    }
    (new AcaoService())->excluir($id);
    flash('sucesso', 'Tarefa excluída.');
} catch (ValidationException $e) {
    flash('erro', implode(' ', array_values($e->erros())) ?: $e->getMessage());
} catch (AcessoNegadoException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'acao.excluir');
    flash('erro', "Não foi possível excluir a tarefa (código {$codigo}).");
}

redirecionar($retorno . (str_starts_with($retorno, 'processo-administrativo.php') ? '#acoes' : ''));
