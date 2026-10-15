<?php
declare(strict_types=1);

/**
 * Mudança rápida de status de uma tarefa (gerenciador e ficha do processo).
 * Somente POST com CSRF; a permissão é verificada no AcaoService.
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
$status = entrada('status');
$retorno = Auth::retornoSeguro(entrada('retorno') ?: 'acoes.php');

try {
    if ($id === null) {
        throw new ValidationException([], 'Tarefa não informada.');
    }
    (new AcaoService())->alterarStatus($id, $status);
    flash('sucesso', 'Tarefa marcada como “' . (AcaoService::STATUS[$status] ?? $status) . '”.');
} catch (ValidationException $e) {
    flash('erro', implode(' ', array_values($e->erros())) ?: $e->getMessage());
} catch (AcessoNegadoException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'acao.status');
    flash('erro', "Não foi possível alterar a tarefa (código {$codigo}).");
}

redirecionar($retorno . ($id !== null ? '#acao-' . $id : ''));
