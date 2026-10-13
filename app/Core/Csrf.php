<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Protecao CSRF por token de sessao (sincronizador).
 */
final class Csrf
{
    private const CHAVE = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::CHAVE])) {
            $_SESSION[self::CHAVE] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CHAVE];
    }

    public static function campo(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function valido(?string $token): bool
    {
        $esperado = $_SESSION[self::CHAVE] ?? '';
        return $esperado !== '' && is_string($token) && hash_equals($esperado, $token);
    }

    /** Valida o token da requisicao atual; encerra com 419 quando invalido. */
    public static function exigir(): void
    {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!self::valido(is_string($token) ? $token : null)) {
            if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
                responder_json([
                    'success' => false,
                    'error'   => ['code' => 'CSRF_INVALID', 'message' => 'Token de segurança inválido. Recarregue a página.'],
                ], 419);
            }
            http_response_code(419);
            exit('Token de segurança inválido. Recarregue a página e tente novamente.');
        }
    }
}
