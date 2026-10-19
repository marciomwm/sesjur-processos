<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * CNPJ: normalizacao para os 14 caracteres gravados e conferencia dos
 * digitos verificadores. Aceita o CNPJ alfanumerico (IN RFB 2.229/2024):
 * os 12 primeiros caracteres podem ter letras e numeros, os 2 ultimos (DV)
 * sao sempre numericos. No calculo, cada caractere vale o seu codigo ASCII
 * menos 48 (digitos 0-9 valem 0-9; A = 17 ... Z = 42), o que mantem o
 * calculo do CNPJ numerico. Nao acessa o banco (unicidade fica no servico).
 */
final class CnpjValidator
{
    /** Letras e digitos, em maiusculas, sem mascara; null quando vazio. */
    public static function normalizar(mixed $valor): ?string
    {
        if (!is_scalar($valor)) {
            return null;
        }
        $texto = preg_replace('/[^0-9A-Z]/', '', strtoupper((string)$valor)) ?? '';
        return $texto === '' ? null : $texto;
    }

    public static function valido(?string $cnpj): bool
    {
        $cnpj = self::normalizar($cnpj);
        if ($cnpj === null || !preg_match('/^[0-9A-Z]{12}\d{2}$/', $cnpj) || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }
        foreach ([12, 13] as $tamanho) {
            $soma = 0;
            $peso = 2;
            for ($i = $tamanho - 1; $i >= 0; $i--) {
                $soma += (ord($cnpj[$i]) - 48) * $peso;
                $peso = $peso === 9 ? 2 : $peso + 1;
            }
            $resto = $soma % 11;
            $digito = $resto < 2 ? 0 : 11 - $resto;
            if ((int)$cnpj[$tamanho] !== $digito) {
                return false;
            }
        }
        return true;
    }

    /** "11222333000181" -> "11.222.333/0001-81" (tambem o alfanumerico). */
    public static function formatar(?string $cnpj): string
    {
        $cnpj = self::normalizar($cnpj);
        if ($cnpj === null || strlen($cnpj) !== 14) {
            return (string)$cnpj;
        }
        return substr($cnpj, 0, 2) . '.' . substr($cnpj, 2, 3) . '.' . substr($cnpj, 5, 3) . '/'
            . substr($cnpj, 8, 4) . '-' . substr($cnpj, 12, 2);
    }
}
