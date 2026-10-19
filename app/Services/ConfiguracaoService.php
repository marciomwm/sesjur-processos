<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
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
    /** Entidade dos eventos de auditoria das configuracoes. */
    public const ENTIDADE = 'configuracoes';

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

    /**
     * Grava os parametros da integracao com a extensao (somente
     * administradores), em transacao, registrando na auditoria os valores
     * anteriores e novos de cada chave alterada. Nenhum parametro e segredo:
     * sao dominios publicos e um modelo de URL.
     *
     * @param array $entrada sigadoc_hosts, pje_hosts, exigir_https (bool), sigadoc_url_template
     * @return bool false quando nada mudou
     * @throws ValidationException
     */
    public static function salvarIntegracao(array $entrada): bool
    {
        Auth::exigirPerfil(Perfil::ADMINISTRADOR);

        $exigirHttps = !empty($entrada['exigir_https']);
        $template = trim(is_string($entrada['sigadoc_url_template'] ?? null) ? $entrada['sigadoc_url_template'] : '');
        if ($template !== '') {
            $esquemaValido = $exigirHttps ? str_starts_with($template, 'https://') : (bool)preg_match('#^https?://#', $template);
            if (!$esquemaValido || mb_strlen($template) > 500) {
                throw new ValidationException(['sigadoc_url_template' => $exigirHttps
                    ? 'Informe uma URL iniciada por https:// (até 500 caracteres).'
                    : 'Informe uma URL iniciada por http:// ou https:// (até 500 caracteres).']);
            }
            if (!str_contains($template, '{protocolo}')) {
                throw new ValidationException(['sigadoc_url_template' => 'A URL precisa conter {protocolo}, que a extensão substitui pelo protocolo do processo.']);
            }
        }

        $novos = [
            'sigadoc_hosts'        => implode(',', self::normalizarListaHosts((string)($entrada['sigadoc_hosts'] ?? ''))),
            'pje_hosts'            => implode(',', self::normalizarListaHosts((string)($entrada['pje_hosts'] ?? ''))),
            'exigir_https'         => $exigirHttps ? '1' : '0',
            'sigadoc_url_template' => $template,
        ];

        return Database::transacao(function () use ($novos): bool {
            $repo = new ConfiguracaoRepository();
            $atuais = array_map(static fn(array $l): ?string => $l['valor'], $repo->todas());
            $antes = array_intersect_key($atuais, $novos) + array_fill_keys(array_keys($novos), null);
            [$anteriores, $alterados] = AuditoriaService::diferencas($antes, $novos);
            if ($alterados === []) {
                return false;
            }
            foreach ($alterados as $chave => $valor) {
                $repo->definir($chave, $valor);
            }
            (new AuditoriaService())->registrar('CONFIGURACAO_ALTERADA', self::ENTIDADE, null, $anteriores, $alterados);
            self::$cache = null;
            return true;
        });
    }

    /** Versao da extensao distribuida com a aplicacao (chrome-extension/manifest.json). */
    public static function versaoExtensaoDistribuida(): ?string
    {
        $manifesto = @file_get_contents(PROJECT_ROOT . '/chrome-extension/manifest.json');
        $dados = is_string($manifesto) ? json_decode($manifesto, true) : null;
        return is_array($dados) && is_string($dados['version'] ?? null) ? $dados['version'] : null;
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
