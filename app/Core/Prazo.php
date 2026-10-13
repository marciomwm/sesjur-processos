<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/**
 * Regras de prazo (data com hora opcional) do Processo Administrativo e das
 * suas Acoes. Funcoes puras: nada aqui acessa o banco.
 *
 * Um prazo sem hora vale ate o fim do dia (23:59:59).
 */
final class Prazo
{
    /** Dias do prazo padrao do processo, contados da data de abertura. */
    public const DIAS_PADRAO = 30;

    /** Dias que um prazo e considerado "proximo" do vencimento. */
    public const DIAS_ALERTA = 3;

    /** Prazo padrao do processo: abertura (ou hoje, sem abertura) + 30 dias. */
    public static function padrao(?string $dataAbertura, ?string $hoje = null): string
    {
        $base = $dataAbertura !== null && $dataAbertura !== '' ? $dataAbertura : ($hoje ?? date('Y-m-d'));
        return (new DateTimeImmutable(substr($base, 0, 10)))
            ->modify('+' . self::DIAS_PADRAO . ' days')
            ->format('Y-m-d');
    }

    /**
     * Hora digitada ("14:30", "14:30:00", "9h") para TIME do banco.
     * Vazio => null; formato invalido => false.
     */
    public static function hora(mixed $valor): string|null|false
    {
        $valor = is_string($valor) ? trim($valor) : '';
        if ($valor === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,2})(?:[:hH](\d{2})?)?(?::(\d{2}))?$/', $valor, $m)) {
            return false;
        }
        $h = (int)$m[1];
        $min = (int)($m[2] ?? 0);
        if ($h > 23 || $min > 59) {
            return false;
        }
        return sprintf('%02d:%02d:00', $h, $min);
    }

    /** Instante que encerra o prazo ('Y-m-d H:i:s'), ou null sem data. */
    public static function momento(?string $data, ?string $hora): ?string
    {
        if ($data === null || $data === '') {
            return null;
        }
        $hora = $hora !== null && $hora !== '' ? substr($hora, 0, 8) : '23:59:59';
        if (strlen($hora) === 5) {
            $hora .= ':00';
        }
        return substr($data, 0, 10) . ' ' . $hora;
    }

    /**
     * Prazo vigente do processo: o mais proximo do vencimento entre o prazo
     * proprio e os das acoes ainda nao finalizadas (que o usuario ja filtra).
     *
     * @param list<array{id?:int|string,prazo:?string,prazo_hora:?string}> $acoesAbertas
     * @return array{prazo:?string,prazo_hora:?string,acao_id:?int}
     */
    public static function vigente(?string $prazo, ?string $prazoHora, array $acoesAbertas): array
    {
        $vigente = ['prazo' => $prazo ?: null, 'prazo_hora' => $prazo ? ($prazoHora ?: null) : null, 'acao_id' => null];
        $menor = self::momento($prazo, $prazoHora);

        foreach ($acoesAbertas as $acao) {
            $momento = self::momento($acao['prazo'] ?? null, $acao['prazo_hora'] ?? null);
            if ($momento !== null && ($menor === null || $momento < $menor)) {
                $menor = $momento;
                $vigente = [
                    'prazo'      => substr((string)$acao['prazo'], 0, 10),
                    'prazo_hora' => $acao['prazo_hora'] ?: null,
                    'acao_id'    => isset($acao['id']) ? (int)$acao['id'] : null,
                ];
            }
        }
        return $vigente;
    }

    /**
     * Situacao do prazo para exibicao: 'vencido', 'hoje', 'proximo' (ate
     * DIAS_ALERTA dias), 'no_prazo' ou null sem prazo.
     */
    public static function situacao(?string $data, ?string $hora, ?DateTimeImmutable $agora = null): ?string
    {
        $momento = self::momento($data, $hora);
        if ($momento === null) {
            return null;
        }
        $agora ??= new DateTimeImmutable();
        if ($momento < $agora->format('Y-m-d H:i:s')) {
            return 'vencido';
        }
        $dia = substr($momento, 0, 10);
        if ($dia === $agora->format('Y-m-d')) {
            return 'hoje';
        }
        if ($dia <= $agora->modify('+' . self::DIAS_ALERTA . ' days')->format('Y-m-d')) {
            return 'proximo';
        }
        return 'no_prazo';
    }

    /** "17/10/2026" ou "17/10/2026 14:30". */
    public static function formatar(?string $data, ?string $hora): string
    {
        if ($data === null || $data === '') {
            return '—';
        }
        $texto = data_br($data);
        return $hora !== null && $hora !== '' ? $texto . ' ' . substr($hora, 0, 5) : $texto;
    }

    /** Classe de badge por situacao do prazo. */
    public static function classeBadge(?string $situacao): string
    {
        return match ($situacao) {
            'vencido' => 'badge--vermelho',
            'hoje', 'proximo' => 'badge--ambar',
            'no_prazo' => 'badge--verde',
            default => 'badge--neutro',
        };
    }

    public const ROTULOS_SITUACAO = [
        'vencido'  => 'Vencido',
        'hoje'     => 'Vence hoje',
        'proximo'  => 'Vence em breve',
        'no_prazo' => 'No prazo',
    ];
}
