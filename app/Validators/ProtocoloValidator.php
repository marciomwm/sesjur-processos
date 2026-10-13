<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * Validacao e normalizacao do protocolo administrativo do SIGADOC.
 * Exemplo: SEPLAG-PRO-2026/16696
 *
 * A estrutura aceita e <SIGLA>-<ESPECIE>-<ANO>/<NUMERO>, tolerando
 * espacos, caixa baixa e separadores alternativos na entrada do usuario.
 */
final class ProtocoloValidator
{
    /** Formato canonico aceito apos a normalizacao. */
    public const PADRAO = '/^[A-Z0-9]{2,20}-[A-Z]{2,10}-\d{4}\/\d{1,10}$/';

    /**
     * Normaliza a entrada para o formato canonico.
     * Retorna null quando a entrada nao pode ser interpretada.
     */
    public static function normalizar(?string $valor): ?string
    {
        $texto = Normalizador::texto($valor);
        if ($texto === null) {
            return null;
        }

        $texto = mb_strtoupper($texto, 'UTF-8');
        // Remove espacos ao redor dos separadores.
        $texto = preg_replace('/\s*([-\/])\s*/u', '$1', $texto) ?? $texto;
        // Aceita "." ou espaco como separador do numero final.
        $texto = preg_replace('/(\d{4})[\s.](\d{1,10})$/u', '$1/$2', $texto) ?? $texto;
        $texto = str_replace(['–', '—', '_'], '-', $texto);
        $texto = preg_replace('/-{2,}/', '-', $texto) ?? $texto;

        return $texto;
    }

    public static function valido(?string $valor): bool
    {
        $normalizado = self::normalizar($valor);
        return $normalizado !== null && preg_match(self::PADRAO, $normalizado) === 1;
    }

    /** Comparacao usada na validacao "protocolo informado = protocolo do DOM". */
    public static function equivalentes(?string $a, ?string $b): bool
    {
        $na = self::normalizar($a);
        $nb = self::normalizar($b);
        return $na !== null && $nb !== null && $na === $nb;
    }
}
