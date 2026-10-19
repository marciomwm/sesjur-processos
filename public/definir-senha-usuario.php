<?php
declare(strict_types=1);

/**
 * Administrador define (ou redefine) a senha provisoria de um usuario.
 * Somente POST com CSRF; a permissao e verificada tambem no AuthService.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\AuthService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('configuracoes.php?aba=usuarios');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
if ($id === null) {
    flash('erro', 'Usuário não informado.');
    redirecionar('configuracoes.php?aba=usuarios');
}
if ($id === usuario_logado_id()) {
    flash('info', 'Para trocar a sua própria senha, use “Alterar senha”.');
    redirecionar('alterar-senha.php');
}

try {
    (new AuthService())->definirSenhaProvisoria($id, $_POST['senha'] ?? '', $_POST['senha_confirmacao'] ?? '');
    flash('sucesso', 'Senha provisória definida. O usuário deverá trocá-la no próximo acesso.');
} catch (ValidationException $e) {
    // Os erros voltam para a ficha sem expor a senha digitada.
    $_SESSION['erros_senha'] = array_values($e->erros() ?: [$e->getMessage()]);
} catch (Throwable $e) {
    $codigo = log_erro($e, 'usuario.senha');
    flash('erro', "Não foi possível definir a senha (código {$codigo}).");
}

redirecionar('usuario.php?id=' . $id);
