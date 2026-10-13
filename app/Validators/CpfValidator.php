<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * CPF: normalizacao para os 11 digitos gravados e conferencia dos digitos
 * verificadores. Nao acessa o banco (unicidade fica no servico).
 */
final class CpfValidator
{
    /** Somente os digitos; null quando vazio. */
    public static function normalizar(mixed $valor): ?string
    {
        $digitos = Normalizador::digitos(is_scalar($valor) ? (string)$valor : null);
        return $digitos === '' ? null : $digitos;
    }

    public static function valido(?string $cpf): bool
    {
        $cpf = self::normalizar($cpf);
        if ($cpf === null || strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($posicao = 9; $posicao <= 10; $posicao++) {
            $soma = 0;
            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int)$cpf[$i] * ($posicao + 1 - $i);
            }
            $digito = ($soma * 10) % 11 % 10;
            if ((int)$cpf[$posicao] !== $digito) {
                return false;
            }
        }
        return true;
    }

    /** "12345678909" -> "123.456.789-09". */
    public static function formatar(?string $cpf): string
    {
        $cpf = self::normalizar($cpf);
        if ($cpf === null || strlen($cpf) !== 11) {
            return (string)$cpf;
        }
        return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
    }
}
