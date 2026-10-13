<?php
declare(strict_types=1);

/**
 * Exclui uma solicitação. Somente POST com CSRF; a permissão é verificada
 * no SolicitacaoService (administrador, gestor ou quem criou a solicitação).
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\SolicitacaoService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('solicitacoes.php');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
$retorno = Auth::retornoSeguro(entrada('retorno') ?: 'solicitacoes.php');

try {
    if ($id === null) {
        throw new ValidationException([], 'Solicitação não informada.');
    }
    (new SolicitacaoService())->excluir($id);
    flash('sucesso', 'Solicitação excluída.');
} catch (ValidationException $e) {
    flash('erro', implode(' ', array_values($e->erros())) ?: $e->getMessage());
} catch (AcessoNegadoException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'solicitacao.excluir');
    flash('erro', "Não foi possível excluir a solicitação (código {$codigo}).");
}

redirecionar($retorno . (str_starts_with($retorno, 'processo-administrativo.php') ? '#solicitacoes' : ''));
