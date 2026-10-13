<?php
declare(strict_types=1);

/**
 * Bootstrap comum dos endpoints JSON.
 * Aplica cabecalhos de seguranca, restringe metodos e valida CSRF nas escritas.
 */

// Sinaliza ao bootstrap que as respostas de login/permissao devem ser JSON.
define('GJ_API', true);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

/** Garante que o metodo HTTP e um dos permitidos. */
function exigir_metodo(string ...$metodos): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'], $metodos, true)) {
        responder_json([
            'success' => false,
            'error'   => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Método não permitido.'],
        ], 405);
    }
}

/** Le e decodifica o corpo JSON da requisicao. */
function corpo_json(): array
{
    $bruto = file_get_contents('php://input') ?: '';
    if (trim($bruto) === '') {
        return [];
    }
    $dados = json_decode($bruto, true);
    if (!is_array($dados)) {
        responder_json([
            'success' => false,
            'error'   => ['code' => 'INVALID_JSON', 'message' => 'Corpo da requisição inválido.'],
        ], 400);
    }
    return $dados;
}

/** Valida o token CSRF enviado no cabecalho X-CSRF-Token. */
function exigir_csrf_api(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null);
    if (!Csrf::valido(is_string($token) ? $token : null)) {
        responder_json([
            'success' => false,
            'error'   => ['code' => 'CSRF_INVALID', 'message' => 'Token de segurança inválido. Recarregue a página.'],
        ], 419);
    }
}
