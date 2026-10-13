<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Valores em reais digitados pelo usuario e somas exatas. Os calculos usam
 * centavos inteiros (nunca float): um saldo de bloqueios precisa fechar no
 * centavo.
 */
final class Dinheiro
{
    /** Teto da coluna DECIMAL(15,2): 13 digitos inteiros. */
    public const MAX_CENTAVOS = 999_999_999_999_999;

    /**
     * Le um valor no formato brasileiro ("1.234,56", "1234,5", "R$ 10") ou
     * com ponto decimal ("1234.56"). "1.234" e milhar, nunca 1,234.
     * @return int|null centavos; null se ilegivel, zero/negativo ou acima do teto
     */
    public static function centavos(mixed $valor): ?int
    {
        if (is_int($valor)) {
            $valor = (string)$valor;
        }
        if (!is_string($valor)) {
            return null;
        }
        $texto = str_replace(['R$', ' ', "\u{00A0}"], '', trim($valor));

        if (preg_match('/^(\d{1,3}(?:\.\d{3})+)(?:,(\d{1,2}))?$/', $texto, $m)) {
            [$inteiro, $fracao] = [str_replace('.', '', $m[1]), $m[2] ?? ''];
        } elseif (preg_match('/^(\d+)(?:[,.](\d{1,2}))?$/', $texto, $m)) {
            [$inteiro, $fracao] = [$m[1], $m[2] ?? ''];
        } else {
            return null;
        }

        $inteiro = ltrim($inteiro, '0');
        if (strlen($inteiro) > 13) {
            return null;
        }
        $centavos = (int)($inteiro === '' ? '0' : $inteiro) * 100 + (int)str_pad($fracao, 2, '0');
        return $centavos > 0 && $centavos <= self::MAX_CENTAVOS ? $centavos : null;
    }

    /** Centavos do valor gravado (DECIMAL chega como string "1234.56"). */
    public static function deDecimal(mixed $valor): int
    {
        $texto = (string)$valor;
        $negativo = str_starts_with($texto, '-');
        [$inteiro, $fracao] = array_pad(explode('.', ltrim($texto, '-'), 2), 2, '');
        $centavos = (int)$inteiro * 100 + (int)str_pad(substr($fracao, 0, 2), 2, '0');
        return $negativo ? -$centavos : $centavos;
    }

    /** Centavos para gravar em DECIMAL(15,2): 123456 -> "1234.56". */
    public static function paraDecimal(int $centavos): string
    {
        $sinal = $centavos < 0 ? '-' : '';
        $abs = abs($centavos);
        return $sinal . intdiv($abs, 100) . '.' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Valor gravado como o usuario o digita: "1234.56" -> "1.234,56" (campo do formulario). */
    public static function paraCampo(mixed $decimal): string
    {
        return substr(self::formatar(Dinheiro::deDecimal($decimal)), 3);
    }

    /** Centavos para exibicao: 123456 -> "R$ 1.234,56". */
    public static function formatar(int $centavos): string
    {
        $sinal = $centavos < 0 ? '-' : '';
        $abs = abs($centavos);
        return $sinal . 'R$ ' . number_format(intdiv($abs, 100), 0, ',', '.') . ','
            . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
