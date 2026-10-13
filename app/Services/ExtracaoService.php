<?php
declare(strict_types=1);

namespace App\Services;

use App\Validators\CnjValidator;
use App\Validators\Normalizador;
use App\Validators\ProtocoloValidator;
use App\Validators\UrlExternaValidator;

/**
 * Validacao, no servidor, dos payloads produzidos pela extensao do Chrome.
 *
 * A aplicacao PHP nunca acessa SIGADOC/PJe. Ela apenas:
 *  1. autoriza o pedido de extracao (formato do identificador, allowlist de dominio);
 *  2. revalida o payload devolvido pela extensao antes de preencher o formulario;
 *  3. garante que o identificador retornado corresponde ao identificador solicitado.
 */
final class ExtracaoService
{
    public const CONTRATO_VERSAO = '1.0';

    /** Autoriza um pedido de extracao no SIGADOC. */
    public static function autorizarSigadoc(?string $protocolo): array
    {
        $normalizado = ProtocoloValidator::normalizar($protocolo);
        if ($normalizado === null || !ProtocoloValidator::valido($normalizado)) {
            return self::falha('SIGADOC', 'INVALID_PROTOCOL', 'Protocolo inválido. Formato esperado: SEPLAG-PRO-2026/16696.');
        }

        $hosts = ConfiguracaoService::hostsSigadoc();
        if ($hosts === []) {
            return self::falha(
                'SIGADOC',
                'ALLOWLIST_EMPTY',
                'Nenhum domínio do SIGADOC está autorizado. Cadastre o domínio institucional em Sistema > Configurações.'
            );
        }

        return [
            'success' => true,
            'source'  => 'SIGADOC',
            'request' => [
                'protocolo'     => $normalizado,
                'allowedHosts'  => $hosts,
                'contrato'      => self::CONTRATO_VERSAO,
            ],
        ];
    }

    /** Autoriza um pedido de extracao no PJe (CNJ + URL). */
    public static function autorizarPje(?string $numeroCnj, ?string $url): array
    {
        $cnj = CnjValidator::normalizar($numeroCnj);
        if ($cnj === null || !CnjValidator::formatoValido($cnj)) {
            return self::falha('PJE', 'INVALID_CNJ', 'Número CNJ inválido. Formato esperado: 1008695-17.2026.8.11.0006.');
        }
        if (!CnjValidator::digitoVerificadorValido($cnj)) {
            return self::falha('PJE', 'INVALID_CNJ_DV', 'Número CNJ inválido: o dígito verificador não confere.');
        }

        $hosts = ConfiguracaoService::hostsPje();
        $check = UrlExternaValidator::validar($url, $hosts, ConfiguracaoService::exigirHttps());
        if (!$check['ok']) {
            return self::falha('PJE', $check['code'], $check['message']);
        }

        return [
            'success' => true,
            'source'  => 'PJE',
            'request' => [
                'numeroCNJ'    => $cnj,
                'url'          => $check['url'],
                'host'         => $check['host'],
                'allowedHosts' => $hosts,
                'contrato'     => self::CONTRATO_VERSAO,
            ],
        ];
    }

    /**
     * Revalida o payload devolvido pela extensao para o SIGADOC e o converte
     * nos campos do formulario. Nunca preenche dado ausente.
     */
    public static function conferirRetornoSigadoc(array $payload, string $protocoloSolicitado): array
    {
        if (($payload['success'] ?? false) !== true) {
            $erro = $payload['error'] ?? [];
            return self::falha('SIGADOC', (string)($erro['code'] ?? 'EXTRACTION_FAILED'), (string)($erro['message'] ?? 'Falha na extração.'));
        }

        $dados = $payload['data'] ?? [];
        $protocoloEncontrado = $dados['protocolo'] ?? null;

        if (!ProtocoloValidator::equivalentes($protocoloSolicitado, $protocoloEncontrado)) {
            return self::falha(
                'SIGADOC',
                'IDENTIFIER_MISMATCH',
                'O identificador encontrado na página não corresponde ao processo informado.',
                ['solicitado' => $protocoloSolicitado, 'encontrado' => $protocoloEncontrado]
            );
        }

        $situacao = is_array($dados['situacao'] ?? null) ? $dados['situacao'] : [];
        $codigoSituacao = Normalizador::situacaoAdministrativa(
            is_string($situacao['codigo'] ?? null) ? $situacao['codigo'] : (is_string($situacao['descricao'] ?? null) ? $situacao['descricao'] : null)
        );

        $resp = is_array($dados['responsavel'] ?? null) ? $dados['responsavel'] : [];
        $responsavel = [
            'nome'        => Normalizador::textoLimitado($resp['nome'] ?? null, 180),
            'matricula'   => Normalizador::textoLimitado($resp['matricula'] ?? null, 30),
            'lotacao'     => Normalizador::textoLimitado($resp['lotacao'] ?? null, 60),
            'lotacaoNome' => Normalizador::textoLimitado($resp['lotacaoNome'] ?? null, 180),
        ];

        // Eventos voltam em camelCase, como os demais campos do contrato.
        $eventos = array_map(static fn(array $e): array => [
            'data'             => $e['data_evento'],
            'tipo'             => $e['tipo'],
            'tipoDescricao'    => $e['tipo_descricao'],
            'lotacao'          => $e['lotacao'],
            'responsavel'      => $e['responsavel'],
            'atendenteLotacao' => $e['atendente_lotacao'],
            'atendente'        => $e['atendente'],
            'documento'        => $e['documento'],
            'descricao'        => $e['descricao'],
        ], Normalizador::eventos($dados['eventos'] ?? []));

        return [
            'success'     => true,
            'source'      => 'SIGADOC',
            'extractedAt' => Normalizador::texto($payload['extractedAt'] ?? null),
            'extractorVersion' => Normalizador::textoLimitado($payload['extractorVersion'] ?? null, 20),
            'sourceUrl'   => self::urlSigadocConfiavel($payload['sourceUrl'] ?? null),
            'data'        => [
                'protocolo'     => ProtocoloValidator::normalizar($protocoloEncontrado),
                'dataAbertura'  => Normalizador::dataIso($dados['dataAbertura'] ?? null),
                'objeto'        => Normalizador::texto($dados['objeto'] ?? null),
                'situacao'      => $codigoSituacao,
                'situacaoDescricao' => Normalizador::textoLimitado($situacao['descricao'] ?? null, 120),
                'responsavel'   => array_filter($responsavel, static fn($v) => $v !== null) === [] ? null : $responsavel,
                'eventos'       => $eventos,
                'historicoCompleto' => ($dados['historicoCompleto'] ?? false) === true,
            ],
            'warnings'    => array_values(array_filter((array)($payload['warnings'] ?? []), 'is_string')),
        ];
    }

    /** Revalida o payload devolvido pela extensao para o PJe. */
    public static function conferirRetornoPje(array $payload, string $cnjSolicitado): array
    {
        if (($payload['success'] ?? false) !== true) {
            $erro = $payload['error'] ?? [];
            return self::falha('PJE', (string)($erro['code'] ?? 'EXTRACTION_FAILED'), (string)($erro['message'] ?? 'Falha na extração.'));
        }

        $dados = $payload['data'] ?? [];
        $cnjEncontrado = $dados['numeroCNJ'] ?? null;

        if (!CnjValidator::equivalentes($cnjSolicitado, $cnjEncontrado)) {
            return self::falha(
                'PJE',
                'IDENTIFIER_MISMATCH',
                'O identificador encontrado na página não corresponde ao processo informado.',
                ['solicitado' => $cnjSolicitado, 'encontrado' => $cnjEncontrado]
            );
        }

        $partes = [];
        foreach ((array)($dados['partes'] ?? []) as $parte) {
            if (!is_array($parte)) {
                continue;
            }
            $nome = Normalizador::texto($parte['nome'] ?? null);
            if ($nome === null) {
                continue;
            }
            $partes[] = [
                'nome'          => $nome,
                'documento'     => Normalizador::texto($parte['documento'] ?? null),
                'polo'          => Normalizador::polo($parte['polo'] ?? null),
                'tipo'          => Normalizador::texto($parte['tipo'] ?? null),
                'representacao' => Normalizador::texto($parte['representacao'] ?? null),
            ];
        }

        // Assuntos secundarios: a extensao envia {descricao, codigo}; versoes
        // anteriores enviavam apenas a string. Ambos os formatos sao aceitos.
        $assuntosSecundarios = [];
        foreach ((array)($dados['assuntosSecundarios'] ?? []) as $assunto) {
            $ehArray = is_array($assunto);
            $texto = Normalizador::texto($ehArray ? ($assunto['descricao'] ?? null) : (string)$assunto);
            if ($texto === null) {
                continue;
            }
            $assuntosSecundarios[] = [
                'descricao' => $texto,
                'codigo'    => $ehArray ? Normalizador::textoLimitado($assunto['codigo'] ?? null, 40) : null,
            ];
        }

        // Movimentacoes so sao aceitas quando a extensao as enviou; a ausencia
        // da chave significa "nao extraido", nao "processo sem movimentacoes".
        $movimentacoes = array_key_exists('movimentacoes', $dados)
            ? array_map(static fn(array $m): array => [
                'data'          => $m['data_movimentacao'],
                'hora'          => $m['hora'],
                'tipo'          => $m['tipo'],
                'descricao'     => $m['descricao'],
                'documentoId'   => $m['documento_id'],
                'documentoTipo' => $m['documento_tipo'],
                'natureza'      => $m['natureza'],
                'origem'        => $m['origem'],
                'documentos'    => $m['documentos'],
            ], Normalizador::movimentacoes($dados['movimentacoes']))
            : [];

        // Registro de documentos (com a integra das pecas decisorias, quando
        // solicitada). Mesma regra: ausencia da chave = nao extraido.
        $documentos = array_map(static fn(array $d): array => [
            'id'                => $d['documento_id'],
            'tipo'              => $d['tipo'],
            'classificacao'     => $d['classificacao'],
            'data'              => $d['data_documento'],
            'hora'              => $d['hora'],
            'juntadoPor'        => $d['juntado_por'],
            'dataJuntada'       => $d['data_juntada'],
            'integra'           => $d['texto_integral'],
            'metodoExtracao'    => $d['metodo_extracao'],
            'ordemMovimentacao' => $d['ordem_movimentacao'],
        ], Normalizador::documentosJudiciais($dados['documentos'] ?? []));

        $prioridadeDescricao = Normalizador::textoLimitado($dados['prioridadeDescricao'] ?? null, 100);
        $prioridade = Normalizador::booleanoOuNulo($dados['prioridade'] ?? null);
        if ($prioridade === null && $prioridadeDescricao !== null) {
            $prioridade = true; // o motivo so existe quando ha prioridade
        }

        return [
            'success'     => true,
            'source'      => 'PJE',
            'extractedAt' => Normalizador::texto($payload['extractedAt'] ?? null),
            'extractorVersion' => Normalizador::textoLimitado($payload['extractorVersion'] ?? null, 20),
            'data'        => [
                'numeroCNJ'           => CnjValidator::normalizar($cnjEncontrado),
                'classeAbreviada'     => Normalizador::textoLimitado($dados['classeAbreviada'] ?? null, 30),
                'resumoPartes'        => Normalizador::textoLimitado($dados['resumoPartes'] ?? null, 500),
                'tribunal'            => Normalizador::textoLimitado($dados['tribunal'] ?? null, 120),
                'orgaoJulgador'       => Normalizador::textoLimitado($dados['orgaoJulgador'] ?? null, 180),
                'cargoJudicial'       => Normalizador::textoLimitado($dados['cargoJudicial'] ?? null, 120),
                'competencia'         => Normalizador::textoLimitado($dados['competencia'] ?? null, 255),
                'jurisdicao'          => Normalizador::textoLimitado($dados['jurisdicao'] ?? null, 180),
                'classeJudicial'      => Normalizador::textoLimitado($dados['classeJudicial'] ?? null, 180),
                'classeCodigo'        => Normalizador::textoLimitado($dados['classeCodigo'] ?? null, 20),
                'assuntoPrincipal'    => Normalizador::textoLimitado($dados['assuntoPrincipal'] ?? null, 255),
                'assuntoPrincipalCodigo' => Normalizador::textoLimitado($dados['assuntoPrincipalCodigo'] ?? null, 40),
                'assuntosSecundarios' => $assuntosSecundarios,
                'dataAutuacao'        => Normalizador::dataIsoExtensa($dados['dataAutuacao'] ?? null),
                'dataDistribuicao'    => Normalizador::dataIsoExtensa($dados['dataDistribuicao'] ?? null),
                'valorCausa'          => Normalizador::valorMonetario($dados['valorCausa'] ?? null),
                'situacao'            => Normalizador::textoLimitado($dados['situacao'] ?? null, 120),
                'segredoJustica'      => Normalizador::booleanoOuNulo($dados['segredoJustica'] ?? null),
                'justicaGratuita'     => Normalizador::booleanoOuNulo($dados['justicaGratuita'] ?? null),
                'tutelaLiminar'       => Normalizador::booleanoOuNulo($dados['tutelaLiminar'] ?? null),
                'prioridade'          => $prioridade,
                'prioridadeDescricao' => $prioridadeDescricao,
                'partes'              => $partes,
                'movimentacoes'       => $movimentacoes,
                'historicoCompleto'   => ($dados['historicoCompleto'] ?? false) === true,
                'documentos'          => $documentos,
                'integraSolicitada'   => ($dados['integraSolicitada'] ?? false) === true,
            ],
            'warnings'    => array_values(array_filter((array)($payload['warnings'] ?? []), 'is_string')),
        ];
    }

    /** URL de origem apenas quando pertence a um host autorizado do SIGADOC. */
    private static function urlSigadocConfiavel(mixed $url): ?string
    {
        if (!is_string($url) || mb_strlen($url) > 500) {
            return null;
        }
        $check = UrlExternaValidator::validar($url, ConfiguracaoService::hostsSigadoc(), ConfiguracaoService::exigirHttps());
        return $check['ok'] ? $check['url'] : null;
    }

    public static function falha(string $origem, string $codigo, string $mensagem, array $detalhes = []): array
    {
        $resposta = [
            'success' => false,
            'source'  => $origem,
            'error'   => ['code' => $codigo, 'message' => $mensagem],
        ];
        if ($detalhes !== []) {
            $resposta['error']['details'] = $detalhes;
        }
        return $resposta;
    }
}
