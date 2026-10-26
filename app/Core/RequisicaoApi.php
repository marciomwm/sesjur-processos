<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Origem da requisicao da API de Integracao: HTTPS e IP do cliente.
 *
 * Atras de um proxy reverso (ex.: o que publica o SESJUR na VPN da SES-MT),
 * o Apache recebe HTTP do proxy e REMOTE_ADDR e o IP do proxy. Os
 * cabecalhos X-Forwarded-Proto e X-Forwarded-For so sao considerados quando
 * a conexao vem de um proxy listado em api.proxies_confiaveis; de qualquer
 * outra origem sao ignorados, pois o cliente poderia forja-los.
 */
final class RequisicaoApi
{
    /** @param string[] $proxies IPs dos proxies reversos confiaveis */
    public static function https(array $server, array $proxies): bool
    {
        $https = strtolower((string)($server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if (!self::viaProxyConfiavel($server, $proxies)) {
            return false;
        }
        $proto = strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $proto === 'https';
    }

    /**
     * IP do cliente. Via proxy confiavel: o ultimo endereco do
     * X-Forwarded-For que nao seja de um proxy confiavel.
     * @param string[] $proxies
     */
    public static function ip(array $server, array $proxies): ?string
    {
        $remoto = (string)($server['REMOTE_ADDR'] ?? '');
        $remoto = filter_var($remoto, FILTER_VALIDATE_IP) !== false ? $remoto : null;
        if ($remoto === null || !self::viaProxyConfiavel($server, $proxies)) {
            return $remoto;
        }

        $cadeia = array_reverse(array_map('trim', explode(',', (string)($server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($cadeia as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!in_array($ip, $proxies, true)) {
                return $ip;
            }
        }
        return $remoto;
    }

    private static function viaProxyConfiavel(array $server, array $proxies): bool
    {
        return in_array((string)($server['REMOTE_ADDR'] ?? ''), $proxies, true);
    }
}
