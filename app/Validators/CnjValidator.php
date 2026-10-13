<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * Validacao do numero unico de processo (Resolucao CNJ 65/2008).
 * Formato: NNNNNNN-DD.AAAA.J.TR.OOOO
 * Exemplo: 1008695-17.2026.8.11.0006
 */
final class CnjValidator
{
    public const PADRAO = '/^\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}$/';

    /** Normaliza qualquer entrada com 20 digitos para o formato com mascara. */
    public static function normalizar(?string $valor): ?string
    {
        $digitos = Normalizador::digitos(Normalizador::texto($valor));
        if (strlen($digitos) !== 20) {
            return null;
        }
        return sprintf(
            '%s-%s.%s.%s.%s.%s',
            substr($digitos, 0, 7),
            substr($digitos, 7, 2),
            substr($digitos, 9, 4),
            substr($digitos, 13, 1),
            substr($digitos, 14, 2),
            substr($digitos, 16, 4)
        );
    }

    /** Valida o formato (sem conferir o digito verificador). */
    public static function formatoValido(?string $valor): bool
    {
        $n = self::normalizar($valor);
        return $n !== null && preg_match(self::PADRAO, $n) === 1;
    }

    /**
     * Confere o digito verificador (modulo 97, base 10 - ISO 7064).
     * DD = 98 - ((NNNNNNN AAAA J TR OOOO) * 100 mod 97)
     */
    public static function digitoVerificadorValido(?string $valor): bool
    {
        $n = self::normalizar($valor);
        if ($n === null) {
            return false;
        }
        $d = Normalizador::digitos($n);

        $sequencial = substr($d, 0, 7);
        $dv         = substr($d, 7, 2);
        $resto      = substr($d, 9, 11); // AAAA J TR OOOO

        $base = $sequencial . $resto . '00';
        $mod  = self::modulo97($base);
        $esperado = 98 - $mod;

        return (int)$dv === $esperado;
    }

    public static function valido(?string $valor): bool
    {
        return self::formatoValido($valor) && self::digitoVerificadorValido($valor);
    }

    public static function equivalentes(?string $a, ?string $b): bool
    {
        $na = self::normalizar($a);
        $nb = self::normalizar($b);
        return $na !== null && $nb !== null && $na === $nb;
    }

    /** Modulo 97 para numeros maiores que PHP_INT_MAX, processado em blocos. */
    private static function modulo97(string $numero): int
    {
        $resto = 0;
        foreach (str_split($numero) as $digito) {
            $resto = ($resto * 10 + (int)$digito) % 97;
        }
        return $resto;
    }

    /** Extrai numeros CNJ presentes em um texto livre (ex.: objeto do processo). */
    public static function extrairDeTexto(string $texto): array
    {
        preg_match_all('/\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}/', $texto, $m);
        return array_values(array_unique($m[0] ?? []));
    }
}
