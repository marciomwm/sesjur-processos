<?php
declare(strict_types=1);

/**
 * Ativa ou inativa um usuario (exclusao logica). Somente POST com CSRF:
 * alteracoes de estado nunca sao feitas por link (GET).
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\UsuarioService;
use App\Validators\UsuarioValidator;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('usuarios.php');
}
Csrf::exigir();

$id    = entrada_int('id', $_POST);
$ativo = UsuarioValidator::ativo(entrada('ativo'));

// Retorno: ficha do usuario ou listagem com os mesmos filtros. A query de
// retorno e remontada apenas com chaves conhecidas (sem redirecionamento aberto).
parse_str(entrada('retorno'), $retorno);
$retorno = array_intersect_key($retorno, array_flip(['busca', 'cargo_id', 'lotacao_id', 'perfil', 'status', 'pagina']));
$destino = entrada('origem') === 'ficha' && $id !== null
    ? 'usuario.php?id=' . $id
    : 'usuarios.php' . ($retorno !== [] ? '?' . http_build_query($retorno) : '');

if ($id === null || $ativo === null) {
    flash('erro', 'Requisição inválida.');
    redirecionar('usuarios.php');
}

try {
    $alterou = (new UsuarioService())->alterarStatus($id, $ativo);
    if (!$alterou) {
        flash('info', $ativo ? 'O usuário já estava ativo.' : 'O usuário já estava inativo.');
    } else {
        flash('sucesso', $ativo ? 'Usuário reativado com sucesso.' : 'Usuário inativado. O cadastro e o histórico foram preservados.');
    }
} catch (ValidationException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'usuario.status');
    flash('erro', "Não foi possível alterar o status do usuário (código {$codigo}).");
}

redirecionar($destino);
