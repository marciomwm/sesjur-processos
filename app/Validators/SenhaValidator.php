<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * Regras da senha de acesso. O limite de 72 bytes vem do bcrypt
 * (PASSWORD_DEFAULT), que ignora o que passar disso.
 */
final class SenhaValidator
{
    public const MIN_CARACTERES = 8;
    public const MAX_BYTES      = 72;

    /** Mensagem de erro ou null quando a senha e aceitavel. */
    public static function validar(mixed $senha, mixed $confirmacao): ?string
    {
        if (!is_string($senha) || $senha === '') {
            return 'Informe a nova senha.';
        }
        if (mb_strlen($senha) < self::MIN_CARACTERES) {
            return 'A senha deve ter pelo menos ' . self::MIN_CARACTERES . ' caracteres.';
        }
        if (strlen($senha) > self::MAX_BYTES) {
            return 'A senha deve ter no máximo ' . self::MAX_BYTES . ' caracteres.';
        }
        if (!preg_match('/\p{L}/u', $senha) || !preg_match('/\d/', $senha)) {
            return 'A senha deve conter letras e números.';
        }
        if (!is_string($confirmacao) || !hash_equals($senha, $confirmacao)) {
            return 'A confirmação não confere com a senha.';
        }
        return null;
    }
}
