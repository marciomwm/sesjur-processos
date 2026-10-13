<?php
declare(strict_types=1);

/**
 * Exclui uma anotação. Somente POST com CSRF; a permissão é verificada no
 * AnotacaoService (administrador, gestor ou quem criou a anotação).
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\AnotacaoService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('anotacoes.php');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
$retorno = Auth::retornoSeguro(entrada('retorno') ?: 'anotacoes.php');

try {
    if ($id === null) {
        throw new ValidationException([], 'Anotação não informada.');
    }
    (new AnotacaoService())->excluir($id);
    flash('sucesso', 'Anotação excluída.');
} catch (ValidationException $e) {
    flash('erro', implode(' ', array_values($e->erros())) ?: $e->getMessage());
} catch (AcessoNegadoException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'anotacao.excluir');
    flash('erro', "Não foi possível excluir a anotação (código {$codigo}).");
}

redirecionar($retorno . (str_starts_with($retorno, 'processo-administrativo.php') ? '#anotacoes' : ''));
