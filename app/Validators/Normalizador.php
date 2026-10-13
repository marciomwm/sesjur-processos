<?php
declare(strict_types=1);

namespace App\Validators;

use DateTimeImmutable;

/**
 * Normalizacao de valores vindos do usuario ou dos extratores.
 * Regra: nunca fabricar dado. Entrada irreconhecivel resulta em null.
 */
final class Normalizador
{
    /** Colapsa espacos e remove caracteres de controle. */
    public static function texto(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $valor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $valor) ?? '';
        // Normaliza NBSP e espacos repetidos.
        $valor = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $valor);
        $valor = preg_replace('/\s+/u', ' ', $valor) ?? '';
        $valor = trim($valor);
        return $valor === '' ? null : $valor;
    }

    /**
     * Texto de varias linhas (anotacoes, competencias): remove caracteres de
     * controle e espacos nas pontas de cada linha, e reduz sequencias de
     * linhas em branco a uma. Vazio resulta em null.
     */
    public static function textoLongo(mixed $valor): ?string
    {
        if (!is_string($valor)) {
            return null;
        }
        $valor = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $valor);
        $valor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $valor) ?? '';
        $linhas = array_map(static fn(string $l): string => trim(preg_replace('/[ \t]+/u', ' ', $l) ?? $l), explode("\n", $valor));
        $valor = trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $linhas)) ?? '');
        return $valor === '' ? null : $valor;
    }

    /**
     * Converte data para ISO (Y-m-d).
     * Aceita dd/mm/aaaa, dd/mm/aaaa hh:mm, aaaa-mm-dd.
     */
    public static function dataIso(?string $valor): ?string
    {
        $valor = self::texto($valor);
        if ($valor === null) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $m)) {
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }

        if (preg_match('#(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})#', $valor, $m)) {
            $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mes = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $a = $m[3];
            return checkdate((int)$mes, (int)$d, (int)$a) ? "$a-$mes-$d" : null;
        }

        return null;
    }

    /** Meses abreviados como o PJe os escreve ("14 dez 2023"). */
    private const MESES_ABREVIADOS = [
        'jan' => '01', 'fev' => '02', 'mar' => '03', 'abr' => '04',
        'mai' => '05', 'jun' => '06', 'jul' => '07', 'ago' => '08',
        'set' => '09', 'out' => '10', 'nov' => '11', 'dez' => '12',
    ];

    /**
     * Como dataIso, aceitando tambem o mes por extenso abreviado - formato
     * usado pelo PJe na <dl> de detalhes e nos separadores da cronologia.
     * Espelha normalizers.dataIsoExtensa() da extensao.
     */
    public static function dataIsoExtensa(?string $valor): ?string
    {
        $completa = self::dataIso($valor);
        if ($completa !== null) {
            return $completa;
        }

        $valor = self::texto($valor);
        if ($valor === null) {
            return null;
        }

        if (!preg_match('/\b(\d{1,2})\s+([\p{L}]{3,})\.?\s+(\d{4})\b/u', $valor, $m)) {
            return null;
        }

        $mes = self::MESES_ABREVIADOS[mb_substr(self::semAcento($m[2]), 0, 3)] ?? null;
        if ($mes === null) {
            return null;
        }

        $dia = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        return checkdate((int)$mes, (int)$dia, (int)$m[3]) ? "{$m[3]}-{$mes}-{$dia}" : null;
    }

    /** "15:14" ou "15:14:16" -> "HH:MM:00" (coluna TIME). Fora do formato -> null. */
    public static function hora(?string $valor): ?string
    {
        $valor = self::texto($valor);
        if ($valor === null) {
            return null;
        }
        if (!preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?\b/', $valor, $m)) {
            return null;
        }
        return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2] . ':' . ($m[3] ?? '00');
    }

    /** Converte data/hora para formato DATETIME do MySQL. */
    public static function dataHora(?string $valor): ?string
    {
        $valor = self::texto($valor);
        if ($valor === null) {
            return null;
        }
        try {
            return (new DateTimeImmutable($valor))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Converte valor monetario brasileiro para float.
     * "R$ 1.234,56" -> 1234.56 | "1234.56" -> 1234.56
     */
    public static function valorMonetario(mixed $valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (is_float($valor) || is_int($valor)) {
            return (float)$valor;
        }
        $bruto = (string)$valor;
        $limpo = preg_replace('/[^\d,.\-]/u', '', $bruto) ?? '';
        if ($limpo === '' || $limpo === '-') {
            return null;
        }

        $temVirgula = str_contains($limpo, ',');
        $temPonto   = str_contains($limpo, '.');

        if ($temVirgula && $temPonto) {
            // Formato pt-BR: ponto e milhar, virgula e decimal.
            $limpo = str_replace('.', '', $limpo);
            $limpo = str_replace(',', '.', $limpo);
        } elseif ($temVirgula) {
            $limpo = str_replace(',', '.', $limpo);
        }

        if (!is_numeric($limpo)) {
            return null;
        }
        return (float)$limpo;
    }

    /** Normaliza booleano tri-estado (null = nao informado). */
    public static function booleanoOuNulo(mixed $valor): ?bool
    {
        if ($valor === null || $valor === '' || $valor === 'null') {
            return null;
        }
        if (is_bool($valor)) {
            return $valor;
        }
        $texto = mb_strtolower(trim((string)$valor));
        return match ($texto) {
            '1', 'true', 'sim', 's', 'yes' => true,
            '0', 'false', 'nao', 'não', 'n', 'no' => false,
            default => null,
        };
    }

    /** Normaliza o polo de uma parte processual. */
    public static function polo(?string $valor): string
    {
        $texto = mb_strtoupper(self::texto($valor) ?? '');
        $texto = strtr($texto, ['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Õ'=>'O','Ô'=>'O','Ú'=>'U','Ç'=>'C']);

        if ($texto === '') {
            return 'OUTRO';
        }
        if (str_contains($texto, 'ATIVO')) {
            return 'ATIVO';
        }
        if (str_contains($texto, 'PASSIVO')) {
            return 'PASSIVO';
        }
        if (str_contains($texto, 'TERCEIRO')) {
            return 'TERCEIRO';
        }
        return in_array($texto, ['ATIVO', 'PASSIVO', 'TERCEIRO', 'OUTRO'], true) ? $texto : 'OUTRO';
    }

    /** Situacoes do processo administrativo (codigo => rotulo). */
    public const SITUACOES = [
        'AGUARDANDO_ANDAMENTO' => 'Aguardando andamento',
        'SOBRESTADO'           => 'Sobrestado',
        'ARQUIVADO'            => 'Arquivado',
        'EM_TRANSITO'          => 'Em trânsito',
        'OUTRA'                => 'Outra',
    ];

    /**
     * Procedimentos sugeridos para o processo administrativo. Lista aberta:
     * o campo aceita qualquer texto digitado, estas sao apenas sugestoes
     * exibidas no autocomplete (datalist).
     */
    public const PROCEDIMENTOS_SUGERIDOS = [
        'Fornecimento de Produto de Saúde',
        'Prestação de Informações Institucionais',
        'Ressarcimento Interfederativo',
    ];

    /** Tipos de movimentacao do SIGADOC (codigo => rotulo). */
    public const TIPOS_EVENTO = [
        'JUNTADA'          => 'Juntada',
        'ANOTACAO'         => 'Anotação',
        'TRANSFERENCIA'    => 'Transferência',
        'RECEBIMENTO'      => 'Recebimento',
        'SOBRESTAMENTO'    => 'Sobrestamento',
        'DESSOBRESTAMENTO' => 'Dessobrestamento',
        'ARQUIVAMENTO'     => 'Arquivamento',
        'DESARQUIVAMENTO'  => 'Desarquivamento',
        'APENSACAO'        => 'Apensação',
        'DESAPENSACAO'     => 'Desapensação',
        'DESENTRANHAMENTO' => 'Desentranhamento',
        'CRIACAO'          => 'Criação',
        'OUTRO'            => 'Outro',
    ];

    /** Limite de movimentacoes aceitas por processo (espelha a extensao). */
    public const LIMITE_EVENTOS = 1000;

    private static function semAcento(string $valor): string
    {
        return strtr(mb_strtolower($valor), [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i',
            'ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ü'=>'u','ç'=>'c',
        ]);
    }

    /**
     * Codigo da situacao a partir de um codigo ja valido ou do rotulo do
     * SIGADOC. Espelha normalizers.situacaoAdministrativa() da extensao.
     */
    public static function situacaoAdministrativa(?string $valor): ?string
    {
        $valor = self::texto($valor);
        if ($valor === null) {
            return null;
        }
        if (isset(self::SITUACOES[strtoupper($valor)])) {
            return strtoupper($valor);
        }
        $k = self::semAcento($valor);
        return match (true) {
            str_contains($k, 'sobrestad')                                          => 'SOBRESTADO',
            str_contains($k, 'arquiv') && !str_contains($k, 'desarquiv')           => 'ARQUIVADO',
            str_contains($k, 'aguardando andamento') || str_contains($k, 'em andamento') => 'AGUARDANDO_ANDAMENTO',
            (bool)preg_match('/caixa de entrada|a receber|em transito|transferido/u', $k) => 'EM_TRANSITO',
            default                                                                => 'OUTRA',
        };
    }

    /** Texto limitado ao tamanho da coluna (sem quebrar multibyte). */
    public static function textoLimitado(mixed $valor, int $max): ?string
    {
        $texto = self::texto(is_scalar($valor) ? (string)$valor : null);
        return $texto === null ? null : mb_substr($texto, 0, $max);
    }

    /**
     * Normaliza a lista de movimentacoes para o formato das colunas de
     * processo_administrativo_eventos. Aceita as chaves do contrato da
     * extensao (camelCase) e as do banco (snake_case). Descarta itens sem
     * tipo nem descricao; nunca fabrica valores.
     *
     * @return list<array<string, ?string>>
     */
    public static function eventos(mixed $lista): array
    {
        if (!is_array($lista)) {
            return [];
        }
        $saida = [];
        foreach (array_values($lista) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pegar = static fn(string $camel, string $snake): mixed => $item[$camel] ?? $item[$snake] ?? null;

            $tipoDescricao = self::textoLimitado($pegar('tipoDescricao', 'tipo_descricao'), 80);
            $descricao     = self::textoLimitado($item['descricao'] ?? null, 10000);
            $tipo          = strtoupper((string)($item['tipo'] ?? ''));
            if (!isset(self::TIPOS_EVENTO[$tipo])) {
                $tipo = 'OUTRO';
            }
            if ($tipoDescricao === null && $descricao === null && $tipo === 'OUTRO') {
                continue;
            }

            $saida[] = [
                'data_evento'       => self::dataIso(is_string($d = $pegar('data', 'data_evento')) ? $d : null),
                'tipo'              => $tipo,
                'tipo_descricao'    => $tipoDescricao ?? self::TIPOS_EVENTO[$tipo],
                'lotacao'           => self::textoLimitado($item['lotacao'] ?? null, 60),
                'responsavel'       => self::textoLimitado($item['responsavel'] ?? null, 180),
                'atendente_lotacao' => self::textoLimitado($pegar('atendenteLotacao', 'atendente_lotacao'), 60),
                'atendente'         => self::textoLimitado($item['atendente'] ?? null, 180),
                'documento'         => self::textoLimitado($item['documento'] ?? null, 60),
                'descricao'         => $descricao,
            ];
            if (count($saida) >= self::LIMITE_EVENTOS) {
                break;
            }
        }
        return $saida;
    }

    /**
     * Separa a descricao do codigo entre parenteses, como o PJe apresenta
     * classes e assuntos: "Fornecimento de medicamentos (12484)".
     * Espelha normalizers.descricaoComCodigo() da extensao.
     *
     * @return array{descricao: ?string, codigo: ?string}
     */
    public static function descricaoComCodigo(?string $valor): array
    {
        $texto = self::texto($valor);
        if ($texto === null) {
            return ['descricao' => null, 'codigo' => null];
        }
        if (preg_match('/^(.*?)\s*\((\d{1,10})\)$/u', $texto, $m)) {
            return ['descricao' => self::texto($m[1]), 'codigo' => $m[2]];
        }
        return ['descricao' => $texto, 'codigo' => null];
    }

    /** Tipos de item da cronologia do PJe (codigo => rotulo). */
    public const TIPOS_MOVIMENTACAO = [
        'MOVIMENTO' => 'Movimento processual',
        'DOCUMENTO' => 'Documento juntado',
    ];

    /**
     * Normaliza a cronologia do processo judicial para as colunas de
     * processo_judicial_movimentacoes. Aceita as chaves do contrato da
     * extensao (camelCase) e as do banco (snake_case).
     *
     * Descarta itens sem descricao e sem documento - um item vazio e ruido de
     * layout, nao movimentacao. Nunca fabrica valores.
     *
     * @return list<array<string, ?string>>
     */
    public static function movimentacoes(mixed $lista): array
    {
        if (!is_array($lista)) {
            return [];
        }

        $saida = [];
        foreach (array_values($lista) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pegar = static fn(string $camel, string $snake): mixed => $item[$camel] ?? $item[$snake] ?? null;

            $descricao     = self::textoLimitado($item['descricao'] ?? null, 10000);
            $documentoId   = self::textoLimitado($pegar('documentoId', 'documento_id'), 30);
            $documentoTipo = self::textoLimitado($pegar('documentoTipo', 'documento_tipo'), 120);

            if ($descricao === null && $documentoId === null && $documentoTipo === null) {
                continue;
            }

            $tipo = strtoupper((string)($item['tipo'] ?? ''));
            if (!isset(self::TIPOS_MOVIMENTACAO[$tipo])) {
                // Sem tipo declarado, a presenca do documento e o que classifica.
                $tipo = ($documentoId !== null || $documentoTipo !== null) ? 'DOCUMENTO' : 'MOVIMENTO';
            }

            $data = $pegar('data', 'data_movimentacao');

            $origem = strtoupper((string)($item['origem'] ?? ''));

            $saida[] = [
                'data_movimentacao' => self::dataIsoExtensa(is_string($data) ? $data : null),
                'hora'              => self::hora(is_string($item['hora'] ?? null) ? $item['hora'] : null),
                'tipo'              => $tipo,
                'descricao'         => $descricao,
                'documento_id'      => $documentoId,
                'documento_tipo'    => $documentoTipo,
                'natureza'          => self::textoLimitado($item['natureza'] ?? null, 60),
                'origem'            => isset(self::ORIGENS_EVENTO[$origem]) ? $origem : null,
                // Todos os documentos do evento (o primeiro repete documento_id).
                // Nao e coluna: serve para ligar o registro de documentos ao evento.
                'documentos'        => self::documentosDoEvento($item['documentos'] ?? null, $documentoId, $documentoTipo),
            ];

            if (count($saida) >= self::LIMITE_EVENTOS) {
                break;
            }
        }

        return $saida;
    }

    /** Quem originou o evento na timeline do PJe (codigo => rotulo). */
    public const ORIGENS_EVENTO = [
        'SISTEMA'      => 'Sistema',
        'INTERNO'      => 'Interno',
        'POLO_ATIVO'   => 'Polo ativo',
        'POLO_PASSIVO' => 'Polo passivo',
        'EXTERNO'      => 'Externo',
        'OUTROS'       => 'Outros',
    ];

    /**
     * Pecas decisorias cuja integra e extraida (codigo => rotulo). A ordem e a
     * das palavras-chave: a primeira que casar define a classificacao.
     * Espelha PJE_SELECTORS.integra.classificacao da extensao.
     */
    public const CLASSIFICACOES_DOCUMENTO = [
        'EMBARGOS' => 'Embargos de declaração',
        'ACORDAO'  => 'Acórdão',
        'SENTENCA' => 'Sentença',
        'DECISAO'  => 'Decisão',
        'DESPACHO' => 'Despacho',
    ];

    private const CHAVES_CLASSIFICACAO = [
        'EMBARGOS' => 'embargos de declaracao',
        'ACORDAO'  => 'acordao',
        'SENTENCA' => 'sentenca',
        'DECISAO'  => 'decisao',
        'DESPACHO' => 'despacho',
    ];

    /** Como a integra do documento foi (ou nao) obtida (codigo => rotulo). */
    public const METODOS_EXTRACAO = [
        'IFRAME_HTML'    => 'Lida do visualizador do PJe',
        'FETCH_HTML'     => 'Lida da página do documento',
        'PDF_SEM_TEXTO'  => 'PDF sem texto legível',
        'NAO_LOCALIZADO' => 'Não localizado na cronologia carregada',
        'FALHA'          => 'O PJe não exibiu o documento a tempo',
    ];

    /** Tamanho maximo da integra guardada por documento (espelha a extensao). */
    public const LIMITE_INTEGRA = 500000;

    /** Classifica o rotulo de um documento como peca decisoria, ou null. */
    public static function classificarDocumento(?string $rotulo): ?string
    {
        $chave = self::semAcento(self::texto($rotulo) ?? '');
        if ($chave === '') {
            return null;
        }
        foreach (self::CHAVES_CLASSIFICACAO as $codigo => $palavra) {
            if (str_contains($chave, $palavra)) {
                return $codigo;
            }
        }
        return null;
    }

    /**
     * Integra de um documento: preserva paragrafos (ao contrario de texto(),
     * que colapsa quebras de linha), remove controles e limita o tamanho.
     */
    public static function textoIntegral(mixed $valor): ?string
    {
        if (!is_string($valor)) {
            return null;
        }
        $texto = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $valor);
        $texto = preg_replace('/[^\P{C}\n\t]+/u', '', $texto) ?? '';
        $texto = preg_replace("/[ \t]+\n/", "\n", $texto) ?? '';
        $texto = preg_replace("/\n{3,}/", "\n\n", $texto) ?? '';
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }
        return mb_substr($texto, 0, self::LIMITE_INTEGRA);
    }

    /** Numero de documento do PJe: so digitos, 4 a 30. */
    private static function numeroDocumento(mixed $valor): ?string
    {
        $texto = is_scalar($valor) ? self::texto((string)$valor) : null;
        return ($texto !== null && preg_match('/^\d{4,30}$/', $texto)) ? $texto : null;
    }

    /** @return list<array{id: string, tipo: ?string}> */
    private static function documentosDoEvento(mixed $lista, ?string $primeiroId, ?string $primeiroTipo): array
    {
        $saida = [];
        $vistos = [];
        foreach (is_array($lista) ? $lista : [] as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $id = self::numeroDocumento($doc['id'] ?? $doc['documento_id'] ?? null);
            if ($id === null || isset($vistos[$id])) {
                continue;
            }
            $vistos[$id] = true;
            $saida[] = ['id' => $id, 'tipo' => self::textoLimitado($doc['tipo'] ?? null, 120)];
        }
        $primeiro = self::numeroDocumento($primeiroId);
        if ($saida === [] && $primeiro !== null) {
            $saida[] = ['id' => $primeiro, 'tipo' => $primeiroTipo];
        }
        return $saida;
    }

    /**
     * Normaliza os documentos do processo judicial para as colunas de
     * processo_judicial_documentos. Aceita camelCase (contrato da extensao) e
     * snake_case (banco). Documento sem numero valido e descartado.
     *
     * `ordem_movimentacao` (nao e coluna) liga o documento ao evento da
     * cronologia enviada na mesma extracao.
     *
     * @return list<array<string, mixed>>
     */
    public static function documentosJudiciais(mixed $lista): array
    {
        if (!is_array($lista)) {
            return [];
        }

        $saida = [];
        $vistos = [];
        foreach (array_values($lista) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pegar = static fn(string $camel, string $snake): mixed => $item[$camel] ?? $item[$snake] ?? null;

            $id = self::numeroDocumento($pegar('id', 'documento_id'));
            if ($id === null || isset($vistos[$id])) {
                continue;
            }
            $vistos[$id] = true;

            $tipo = self::textoLimitado($item['tipo'] ?? null, 120);
            $classificacao = strtoupper((string)($item['classificacao'] ?? ''));
            if (!isset(self::CLASSIFICACOES_DOCUMENTO[$classificacao])) {
                $classificacao = self::classificarDocumento($tipo);
            }

            $metodo = strtoupper((string)$pegar('metodoExtracao', 'metodo_extracao'));
            $texto = self::textoIntegral($pegar('integra', 'texto_integral'));
            $data = $pegar('data', 'data_documento');
            $dataJuntada = $pegar('dataJuntada', 'data_juntada');
            $ordemMov = $pegar('ordemMovimentacao', 'ordem_movimentacao');

            $saida[] = [
                'documento_id'       => $id,
                'tipo'               => $tipo,
                'classificacao'      => $classificacao,
                'data_documento'     => self::dataIsoExtensa(is_string($data) ? $data : null),
                'hora'               => self::hora(is_string($item['hora'] ?? null) ? $item['hora'] : null),
                'juntado_por'        => self::textoLimitado($pegar('juntadoPor', 'juntado_por'), 180),
                'data_juntada'       => self::dataHora(is_string($dataJuntada) ? $dataJuntada : null),
                'texto_integral'     => $texto,
                'metodo_extracao'    => isset(self::METODOS_EXTRACAO[$metodo]) ? $metodo : null,
                'ordem_movimentacao' => is_numeric($ordemMov) && (int)$ordemMov >= 0 ? (int)$ordemMov : null,
            ];

            if (count($saida) >= self::LIMITE_EVENTOS) {
                break;
            }
        }

        return $saida;
    }

    /** Mantem apenas digitos. */
    public static function digitos(?string $valor): string
    {
        return preg_replace('/\D+/', '', (string)$valor) ?? '';
    }
}
