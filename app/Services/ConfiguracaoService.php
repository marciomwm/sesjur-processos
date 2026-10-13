<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConfiguracaoRepository;

/**
 * Configuracoes da integracao (allowlist de dominios).
 *
 * Precedencia: tabela `configuracoes` > app/config/config.php.
 * Quando o banco nao esta disponivel (ex.: execucao de testes em CLI),
 * a classe degrada para os valores do arquivo de configuracao.
 */
final class ConfiguracaoService
{
    private static ?array $cache = null;

    /** @return string[] */
    public static function hostsSigadoc(): array
    {
        return self::lista('sigadoc_hosts', (array)config('integracao.sigadoc_hosts', []));
    }

    /** @return string[] */
    public static function hostsPje(): array
    {
        return self::lista('pje_hosts', (array)config('integracao.pje_hosts', []));
    }

    /**
     * Template de URL para consulta direta de um protocolo no SIGADOC.
     * Vazio = a extensão usa o padrão embutido (observado no sistema real).
     * Substituições aceitas: {host} e {protocolo}.
     */
    public static function urlTemplateSigadoc(): string
    {
        $valor = self::doBanco('sigadoc_url_template');
        if ($valor !== null && trim($valor) !== '') {
            return trim($valor);
        }
        return (string)config('integracao.sigadoc_url_template', '');
    }

    public static function exigirHttps(): bool
    {
        $doBanco = self::doBanco('exigir_https');
        if ($doBanco !== null && $doBanco !== '') {
            return $doBanco === '1';
        }
        return (bool)config('integracao.exigir_https', true);
    }

    public static function salvar(string $chave, ?string $valor): void
    {
        (new ConfiguracaoRepository())->definir($chave, $valor);
        self::$cache = null;
    }

    /** Normaliza uma lista de hosts digitada pelo usuario. */
    public static function normalizarListaHosts(string $bruto): array
    {
        $itens = preg_split('/[\s,;\n]+/', $bruto) ?: [];
        $hosts = [];
        foreach ($itens as $item) {
            $item = strtolower(trim($item));
            if ($item === '') {
                continue;
            }
            // Aceita URL completa e extrai apenas o host.
            if (str_contains($item, '://')) {
                $item = (string)parse_url($item, PHP_URL_HOST);
            }
            $item = preg_replace('#[/:].*$#', '', $item) ?? $item;
            $item = ltrim($item, '.');
            if ($item !== '' && preg_match('/^[a-z0-9.*-]+$/', $item)) {
                $hosts[$item] = true;
            }
        }
        return array_keys($hosts);
    }

    /** Situacao da configuracao para exibicao na interface. */
    public static function diagnostico(): array
    {
        return [
            'sigadoc_hosts'         => self::hostsSigadoc(),
            'pje_hosts'             => self::hostsPje(),
            'exigir_https'          => self::exigirHttps(),
            'sigadoc_url_template'  => self::urlTemplateSigadoc(),
            'sigadoc_pronto'        => self::hostsSigadoc() !== [],
            'pje_pronto'            => self::hostsPje() !== [],
        ];
    }

    private static function lista(string $chave, array $padrao): array
    {
        $valor = self::doBanco($chave);
        if ($valor !== null && trim($valor) !== '') {
            return self::normalizarListaHosts($valor);
        }
        return array_values(array_filter(array_map('strtolower', array_map('trim', $padrao))));
    }

    private static function doBanco(string $chave): ?string
    {
        if (self::$cache === null) {
            try {
                self::$cache = array_map(
                    static fn(array $l): ?string => $l['valor'],
                    (new ConfiguracaoRepository())->todas()
                );
            } catch (\Throwable) {
                self::$cache = [];
            }
        }
        return self::$cache[$chave] ?? null;
    }
}
