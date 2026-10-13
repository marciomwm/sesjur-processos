<?php
declare(strict_types=1);

/**
 * Ativa ou inativa uma unidade administrativa. Somente POST com CSRF.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\UnidadeAdministrativaService;
use App\Validators\UsuarioValidator;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('unidades-administrativas.php');
}
Csrf::exigir();

$id    = entrada_int('id', $_POST);
$ativo = UsuarioValidator::ativo(entrada('ativo'));
$retorno = Auth::retornoSeguro(entrada('retorno') ?: 'unidades-administrativas.php');

if ($id === null || $ativo === null) {
    flash('erro', 'Requisição inválida.');
    redirecionar('unidades-administrativas.php');
}

try {
    if (!(new UnidadeAdministrativaService())->alterarStatus($id, $ativo)) {
        flash('info', 'A unidade já estava ' . ($ativo ? 'ativa' : 'inativa') . '.');
    } elseif ($ativo) {
        flash('sucesso', 'Unidade reativada.');
    } else {
        flash('sucesso', 'Unidade inativada. Usuários e solicitações que já a usam não foram alterados.');
    }
} catch (ValidationException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'unidade.status');
    flash('erro', "Não foi possível alterar o status (código {$codigo}).");
}

redirecionar($retorno);
