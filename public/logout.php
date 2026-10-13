<?php
declare(strict_types=1);

/**
 * Encerra a sessao. Somente POST com CSRF (um link GET permitiria
 * deslogar o usuario a partir de outro site).
 */

define('GJ_ROTA_PUBLICA', true);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Services\AuthService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirecionar(usuario_logado_id() !== null ? 'index.php' : 'login.php');
}
Csrf::exigir();

try {
    (new AuthService())->sair();
} catch (Throwable $e) {
    log_erro($e, 'logout');
    App\Core\Auth::encerrarSessao();
}

flash('sucesso', 'Você saiu do sistema.');
redirecionar('login.php');
