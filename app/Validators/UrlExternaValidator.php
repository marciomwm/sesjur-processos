<?php
declare(strict_types=1);

namespace App\Validators;

use App\Services\ConfiguracaoService;

/**
 * Validacao das URLs externas (PJe / SIGADOC) informadas pelo usuario.
 *
 * Importante: esta validacao existe para (a) impedir esquemas perigosos
 * (javascript:, data:, file:) e (b) restringir a navegacao da extensao a
 * dominios institucionais autorizados. O backend PHP NUNCA faz requisicao
 * para a URL informada - a navegacao ocorre apenas no navegador do usuario.
 * Isso elimina a superficie de SSRF.
 */
final class UrlExternaValidator
{
    public const ESQUEMAS_PERMITIDOS = ['https', 'http'];
    public const ESQUEMAS_BLOQUEADOS = ['javascript', 'data', 'file', 'blob', 'ftp', 'chrome', 'about', 'vbscript'];

    /**
     * @param string[] $hostsPermitidos Allowlist de hosts (sufixos aceitos).
     * @return array{ok:bool, url?:string, host?:string, code?:string, message?:string}
     */
    public static function validar(?string $url, array $hostsPermitidos, bool $exigirHttps = true): array
    {
        $url = Normalizador::texto($url);
        if ($url === null) {
            return ['ok' => false, 'code' => 'URL_REQUIRED', 'message' => 'Informe a URL do processo.'];
        }

        $esquemaBruto = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($esquemaBruto === '' || $esquemaBruto === null) {
            return ['ok' => false, 'code' => 'URL_INVALID', 'message' => 'URL inválida: informe o endereço completo, iniciando por https://.'];
        }
        if (in_array($esquemaBruto, self::ESQUEMAS_BLOQUEADOS, true)) {
            return ['ok' => false, 'code' => 'URL_SCHEME_BLOCKED', 'message' => 'Esquema de URL não autorizado.'];
        }
        if (!in_array($esquemaBruto, self::ESQUEMAS_PERMITIDOS, true)) {
            return ['ok' => false, 'code' => 'URL_SCHEME_BLOCKED', 'message' => 'Somente URLs http(s) são aceitas.'];
        }

        $partes = parse_url($url);
        if ($partes === false || empty($partes['host'])) {
            return ['ok' => false, 'code' => 'URL_INVALID', 'message' => 'URL inválida.'];
        }

        if ($exigirHttps && $esquemaBruto !== 'https') {
            return ['ok' => false, 'code' => 'URL_NOT_HTTPS', 'message' => 'Somente URLs HTTPS são aceitas.'];
        }

        $host = strtolower($partes['host']);

        if ($hostsPermitidos === []) {
            return [
                'ok' => false,
                'code' => 'ALLOWLIST_EMPTY',
                'message' => 'Nenhum domínio autorizado está configurado. Cadastre os domínios institucionais em Sistema > Configurações.',
            ];
        }

        if (!self::hostPermitido($host, $hostsPermitidos)) {
            return [
                'ok' => false,
                'code' => 'DOMAIN_NOT_ALLOWED',
                'message' => 'O domínio "' . $host . '" não está na lista de domínios autorizados.',
            ];
        }

        return ['ok' => true, 'url' => $url, 'host' => $host];
    }

    /** Aceita o host exato ou um subdominio de um host autorizado. */
    public static function hostPermitido(string $host, array $hostsPermitidos): bool
    {
        $host = strtolower(trim($host));
        foreach ($hostsPermitidos as $permitido) {
            $permitido = strtolower(trim((string)$permitido));
            if ($permitido === '') {
                continue;
            }
            $permitido = preg_replace('#^\*\.#', '', $permitido) ?? $permitido;
            if ($host === $permitido || str_ends_with($host, '.' . $permitido)) {
                return true;
            }
        }
        return false;
    }

    /** Atalho que usa a allowlist do PJe configurada na aplicacao. */
    public static function validarPje(?string $url): array
    {
        return self::validar(
            $url,
            ConfiguracaoService::hostsPje(),
            ConfiguracaoService::exigirHttps()
        );
    }
}
