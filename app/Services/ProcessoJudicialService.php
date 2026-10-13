<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\ValidationException;
use App\Repositories\AssuntoProcessualRepository;
use App\Repositories\BloqueioJudicialRepository;
use App\Repositories\DocumentoJudicialRepository;
use App\Repositories\GuiaDepositoRepository;
use App\Repositories\MovimentacaoJudicialRepository;
use App\Repositories\ParteProcessualRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Repositories\VinculoRepository;
use App\Validators\CnjValidator;
use App\Validators\Normalizador;
use App\Validators\UrlExternaValidator;

/**
 * Regras de negocio do Processo Judicial, incluindo partes, assuntos e vinculos.
 * Toda a persistencia ocorre em uma unica transacao.
 */
final class ProcessoJudicialService
{
    public function __construct(
        private readonly ProcessoJudicialRepository $repo = new ProcessoJudicialRepository(),
        private readonly ParteProcessualRepository $partes = new ParteProcessualRepository(),
        private readonly AssuntoProcessualRepository $assuntos = new AssuntoProcessualRepository(),
        private readonly VinculoRepository $vinculos = new VinculoRepository(),
        private readonly MovimentacaoJudicialRepository $movimentacoes = new MovimentacaoJudicialRepository(),
        private readonly DocumentoJudicialRepository $documentos = new DocumentoJudicialRepository()
    ) {
    }

    /** Campos escalares do formulario de processo judicial (novo e editar). */
    private const CAMPOS_FORMULARIO = [
        'numero_cnj', 'tribunal', 'orgao_julgador', 'cargo_judicial', 'competencia',
        'jurisdicao', 'classe_judicial', 'classe_codigo', 'classe_abreviada',
        'assunto_principal', 'assunto_principal_codigo', 'resumo_partes',
        'data_autuacao', 'data_distribuicao', 'valor_causa', 'situacao',
        'segredo_justica', 'justica_gratuita', 'tutela_liminar', 'prioridade', 'prioridade_descricao',
        'data_ultima_atualizacao', 'resumo', 'observacoes', 'url_origem',
        'data_extracao', 'extrator_versao',
    ];

    /**
     * Monta a entrada do servico a partir do POST do formulario.
     *
     * @param array|null $registro registro atual (edicao): preserva a origem e
     *        o sinalizador de historico completo quando nao ha nova extracao
     */
    public static function entradaDoFormulario(array $post, ?array $registro = null): array
    {
        $texto = static fn(string $campo): string => is_string($post[$campo] ?? null) ? trim($post[$campo]) : '';

        $dados = [];
        foreach (self::CAMPOS_FORMULARIO as $campo) {
            $dados[$campo] = $texto($campo);
        }

        $dados['assuntos_secundarios'] = (string)($post['assuntos_secundarios'] ?? '');
        $dados['origem'] = $texto('origem') ?: ($registro['origem'] ?? 'MANUAL');
        // Sem nova extracao o campo volta vazio e a cronologia gravada e mantida;
        // o sinalizador tambem precisa preservar o valor ja registrado.
        $dados['historico_completo'] = $texto('historico_completo') !== ''
            ? $texto('historico_completo')
            : (string)(int)($registro['historico_completo'] ?? 0);
        $dados['movimentacoes_json'] = (string)($post['movimentacoes_json'] ?? '');
        $dados['documentos_json'] = (string)($post['documentos_json'] ?? '');
        $dados['partes'] = (array)($post['partes'] ?? []);
        $dados['administrativos_ids'] = array_map('intval', (array)($post['administrativos_ids'] ?? []));

        return $dados;
    }

    public function criar(array $entrada): int
    {
        $dados   = $this->validar($entrada, null);
        $partes  = $this->validarPartes($entrada['partes'] ?? []);
        $assunts = $this->montarAssuntos($entrada, $dados['assunto_principal'], $entrada['assunto_principal_codigo'] ?? null);
        $movs    = self::movimentacoesDaEntrada($entrada);
        $docs    = self::documentosDaEntrada($entrada);

        return Database::transacao(function () use ($dados, $partes, $assunts, $movs, $docs, $entrada): int {
            $id = $this->repo->inserir($dados);

            $ordem = 0;
            foreach ($partes as $parte) {
                $this->partes->inserir($id, $parte, $ordem++);
            }

            $this->assuntos->substituir($id, $assunts);
            $this->gravarCronologia($id, $movs, $docs);
            $this->vinculos->sincronizarPorJudicial($id, $entrada['administrativos_ids'] ?? []);

            return $id;
        });
    }

    /**
     * Cronologia (substituida) e documentos (acumulados), na mesma transacao:
     * os documentos se ligam aos eventos recem-gravados pela ordem na lista.
     */
    private function gravarCronologia(int $id, ?array $movs, ?array $docs): void
    {
        $idsMovimentacoes = $movs !== null ? $this->movimentacoes->substituir($id, $movs) : null;
        if ($docs !== null) {
            $this->documentos->sincronizar($id, $docs, $idsMovimentacoes);
        }
    }

    /**
     * Documentos enviados pelo formulario (`documentos_json`, preenchido pela
     * extracao do PJe). null = o formulario nao os enviou.
     *
     * @throws ValidationException
     */
    public static function documentosDaEntrada(array $entrada): ?array
    {
        if (isset($entrada['documentos']) && is_array($entrada['documentos'])) {
            return Normalizador::documentosJudiciais($entrada['documentos']);
        }

        $json = trim((string)($entrada['documentos_json'] ?? ''));
        if ($json === '') {
            return null;
        }

        $lista = json_decode($json, true);
        if (!is_array($lista)) {
            throw new ValidationException(['documentos' => 'Os documentos extraídos do PJe estão corrompidos. Refaça a extração.']);
        }

        return Normalizador::documentosJudiciais($lista);
    }

    /**
     * Cronologia enviada pelo formulario: `movimentacoes` (array) ou
     * `movimentacoes_json` (campo oculto preenchido pela extracao do PJe).
     *
     * null = manter a cronologia atual (o formulario nao a enviou). Distinguir
     * "nao enviado" de "lista vazia" evita que salvar uma edicao manual apague
     * um historico ja importado.
     *
     * @throws ValidationException
     */
    public static function movimentacoesDaEntrada(array $entrada): ?array
    {
        if (isset($entrada['movimentacoes']) && is_array($entrada['movimentacoes'])) {
            return Normalizador::movimentacoes($entrada['movimentacoes']);
        }

        $json = trim((string)($entrada['movimentacoes_json'] ?? ''));
        if ($json === '') {
            return null;
        }

        $lista = json_decode($json, true);
        if (!is_array($lista)) {
            throw new ValidationException(['movimentacoes' => 'As movimentações extraídas do PJe estão corrompidas. Refaça a extração.']);
        }

        return Normalizador::movimentacoes($lista);
    }

    public function atualizar(int $id, array $entrada): void
    {
        if ($this->repo->buscarPorId($id) === null) {
            throw new ValidationException([], 'Processo judicial não encontrado.');
        }

        $dados   = $this->validar($entrada, $id);
        $partes  = $this->validarPartes($entrada['partes'] ?? []);
        $assunts = $this->montarAssuntos($entrada, $dados['assunto_principal'], $entrada['assunto_principal_codigo'] ?? null);
        $movs    = self::movimentacoesDaEntrada($entrada);
        $docs    = self::documentosDaEntrada($entrada);

        Database::transacao(function () use ($id, $dados, $partes, $assunts, $movs, $docs, $entrada): void {
            $this->repo->atualizar($id, $dados);

            $idsMantidos = [];
            $ordem = 0;
            foreach ($partes as $parte) {
                if (!empty($parte['id'])) {
                    $existente = $this->partes->buscarPorId((int)$parte['id']);
                    if ($existente !== null && (int)$existente['processo_judicial_id'] === $id) {
                        $this->partes->atualizar((int)$parte['id'], $parte, $ordem++);
                        $idsMantidos[] = (int)$parte['id'];
                        continue;
                    }
                }
                $idsMantidos[] = $this->partes->inserir($id, $parte, $ordem++);
            }
            $this->partes->excluirAusentes($id, $idsMantidos);

            $this->assuntos->substituir($id, $assunts);
            $this->gravarCronologia($id, $movs, $docs);
            $this->vinculos->sincronizarPorJudicial($id, $entrada['administrativos_ids'] ?? []);
        });
    }

    /** Exclui o processo judicial. Partes, assuntos e vinculos caem por CASCADE; os administrativos permanecem. */
    public function excluir(int $id): void
    {
        if ($this->repo->buscarPorId($id) === null) {
            throw new ValidationException([], 'Processo judicial não encontrado.');
        }
        // Registros financeiros nunca somem em cascata (FK RESTRICT no banco).
        $lancamentos = (new BloqueioJudicialRepository())->contarDoProcesso($id);
        $guias = (new GuiaDepositoRepository())->contarDoProcesso($id);
        if ($lancamentos + $guias > 0) {
            throw new ValidationException([], "O processo tem {$lancamentos} lançamento(s) de bloqueio e {$guias} guia(s) de depósito"
                . ' registrados. Exclua-os antes, se o processo realmente deve sair do sistema.');
        }
        Database::transacao(fn() => $this->repo->excluir($id));
    }

    /**
     * Cria um processo judicial minimo a partir apenas do numero CNJ.
     * Usado pelo atalho "Cadastrar novo Processo Judicial" durante a vinculacao.
     */
    public function criarMinimo(string $numeroCnj): int
    {
        return $this->criar(['numero_cnj' => $numeroCnj, 'origem' => 'MANUAL']);
    }

    /** @throws ValidationException */
    public function validar(array $entrada, ?int $ignorarId): array
    {
        $erros = [];

        $cnj = CnjValidator::normalizar($entrada['numero_cnj'] ?? null);
        if ($cnj === null) {
            $erros['numero_cnj'] = 'Informe o número do processo judicial (padrão CNJ).';
        } elseif (!CnjValidator::formatoValido($cnj)) {
            $erros['numero_cnj'] = 'Número CNJ inválido. Formato esperado: 1008695-17.2026.8.11.0006.';
        } elseif (!CnjValidator::digitoVerificadorValido($cnj)) {
            $erros['numero_cnj'] = 'Número CNJ inválido: dígito verificador não confere.';
        } elseif ($this->repo->cnjEmUso($cnj, $ignorarId)) {
            $erros['numero_cnj'] = 'Já existe um processo judicial com este número.';
        }

        $dataDistribuicao = null;
        if (Normalizador::texto($entrada['data_distribuicao'] ?? null) !== null) {
            $dataDistribuicao = Normalizador::dataIsoExtensa($entrada['data_distribuicao']);
            if ($dataDistribuicao === null) {
                $erros['data_distribuicao'] = 'Data de distribuição inválida.';
            }
        }

        $dataAutuacao = null;
        if (Normalizador::texto($entrada['data_autuacao'] ?? null) !== null) {
            $dataAutuacao = Normalizador::dataIsoExtensa($entrada['data_autuacao']);
            if ($dataAutuacao === null) {
                $erros['data_autuacao'] = 'Data de autuação inválida.';
            }
        }

        // Autuacao posterior a distribuicao indica confusao entre os dois campos:
        // o processo nao pode ser distribuido antes de existir.
        if ($dataAutuacao !== null && $dataDistribuicao !== null && $dataAutuacao > $dataDistribuicao) {
            $erros['data_autuacao'] = 'A autuação não pode ser posterior à data de distribuição.';
        }

        $dataUltimaAtualizacao = null;
        if (Normalizador::texto($entrada['data_ultima_atualizacao'] ?? null) !== null) {
            $dataUltimaAtualizacao = Normalizador::dataIso($entrada['data_ultima_atualizacao']);
            if ($dataUltimaAtualizacao === null) {
                $erros['data_ultima_atualizacao'] = 'Data da última atualização inválida.';
            }
        }

        $valorCausa = null;
        if (Normalizador::texto((string)($entrada['valor_causa'] ?? '')) !== null) {
            $valorCausa = Normalizador::valorMonetario($entrada['valor_causa']);
            if ($valorCausa === null) {
                $erros['valor_causa'] = 'Valor da causa inválido.';
            } elseif ($valorCausa < 0) {
                $erros['valor_causa'] = 'Valor da causa não pode ser negativo.';
            }
        }

        $urlOrigem = Normalizador::texto($entrada['url_origem'] ?? null);
        if ($urlOrigem !== null) {
            $check = UrlExternaValidator::validarPje($urlOrigem);
            // A URL de origem e apenas registro de proveniencia; quando a allowlist
            // ainda nao foi configurada, o cadastro nao e bloqueado - apenas o
            // acionamento da extensao exige allowlist valida.
            if (!$check['ok'] && in_array($check['code'] ?? '', ['URL_SCHEME_BLOCKED', 'URL_INVALID', 'URL_NOT_HTTPS'], true)) {
                $erros['url_origem'] = $check['message'];
            }
        }

        // Classe e assunto podem chegar no formato do PJe, "Descrição (código)".
        // A coluna guarda apenas a descrição; o código vai em coluna própria.
        $classe = Normalizador::descricaoComCodigo($entrada['classe_judicial'] ?? null);
        $assunto = Normalizador::descricaoComCodigo($entrada['assunto_principal'] ?? null);

        $origemInformada = (string)($entrada['origem'] ?? 'MANUAL');
        $origem = in_array($origemInformada, ['MANUAL', 'PJE'], true) ? $origemInformada : 'MANUAL';

        // O motivo so existe quando ha prioridade: "Não" descarta o motivo, e
        // motivo sem resposta explicita implica "Sim".
        $prioridade = Normalizador::booleanoOuNulo($entrada['prioridade'] ?? null);
        $prioridadeDescricao = Normalizador::textoLimitado($entrada['prioridade_descricao'] ?? null, 100);
        if ($prioridade === false) {
            $prioridadeDescricao = null;
        } elseif ($prioridade === null && $prioridadeDescricao !== null) {
            $prioridade = true;
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }

        return [
            'numero_cnj'             => $cnj,
            'tribunal'               => Normalizador::textoLimitado($entrada['tribunal'] ?? null, 120),
            'orgao_julgador'         => Normalizador::textoLimitado($entrada['orgao_julgador'] ?? null, 180),
            'cargo_judicial'         => Normalizador::textoLimitado($entrada['cargo_judicial'] ?? null, 120),
            'competencia'            => Normalizador::textoLimitado($entrada['competencia'] ?? null, 255),
            'jurisdicao'             => Normalizador::textoLimitado($entrada['jurisdicao'] ?? null, 180),
            'classe_judicial'        => Normalizador::textoLimitado($classe['descricao'], 180),
            'classe_codigo'          => Normalizador::textoLimitado($entrada['classe_codigo'] ?? $classe['codigo'], 20),
            'classe_abreviada'       => Normalizador::textoLimitado($entrada['classe_abreviada'] ?? null, 30),
            'assunto_principal'      => Normalizador::textoLimitado($assunto['descricao'], 255),
            'resumo_partes'          => Normalizador::textoLimitado($entrada['resumo_partes'] ?? null, 500),
            'data_autuacao'          => $dataAutuacao,
            'data_distribuicao'      => $dataDistribuicao,
            'valor_causa'            => $valorCausa,
            'situacao'               => Normalizador::textoLimitado($entrada['situacao'] ?? null, 120),
            'segredo_justica'        => self::booleanoParaBanco(Normalizador::booleanoOuNulo($entrada['segredo_justica'] ?? null)),
            'justica_gratuita'       => self::booleanoParaBanco(Normalizador::booleanoOuNulo($entrada['justica_gratuita'] ?? null)),
            'tutela_liminar'         => self::booleanoParaBanco(Normalizador::booleanoOuNulo($entrada['tutela_liminar'] ?? null)),
            'prioridade'             => self::booleanoParaBanco($prioridade),
            'prioridade_descricao'   => $prioridadeDescricao,
            'resumo'                 => Normalizador::texto($entrada['resumo'] ?? null),
            'observacoes'            => Normalizador::texto($entrada['observacoes'] ?? null),
            'origem'                 => $origem,
            'url_origem'             => $urlOrigem,
            'extrator_versao'        => Normalizador::textoLimitado($entrada['extrator_versao'] ?? null, 20),
            'data_extracao'          => Normalizador::dataHora($entrada['data_extracao'] ?? null),
            'data_ultima_atualizacao' => $dataUltimaAtualizacao,
            'historico_completo'     => (Normalizador::booleanoOuNulo($entrada['historico_completo'] ?? null) === true) ? 1 : 0,
        ];
    }

    /**
     * Valida a colecao de partes. Linhas totalmente vazias sao descartadas.
     * @throws ValidationException
     */
    public function validarPartes(array $partes): array
    {
        $erros = [];
        $resultado = [];

        foreach (array_values($partes) as $indice => $parte) {
            if (!is_array($parte)) {
                continue;
            }
            $nome = Normalizador::texto($parte['nome'] ?? null);
            $documento = Normalizador::texto($parte['documento'] ?? null);
            $tipo = Normalizador::texto($parte['tipo'] ?? null);

            $linhaVazia = $nome === null && $documento === null && $tipo === null;
            if ($linhaVazia) {
                continue;
            }

            if ($nome === null) {
                $erros["partes.$indice.nome"] = 'Informe o nome da parte (linha ' . ($indice + 1) . ').';
                continue;
            }
            if (mb_strlen($nome) > 255) {
                $erros["partes.$indice.nome"] = 'Nome da parte excede 255 caracteres (linha ' . ($indice + 1) . ').';
                continue;
            }

            $resultado[] = [
                'id'            => isset($parte['id']) && is_numeric($parte['id']) ? (int)$parte['id'] : null,
                'nome'          => $nome,
                'documento'     => $documento,
                'polo'          => Normalizador::polo($parte['polo'] ?? null),
                'tipo'          => $tipo,
                'representacao' => Normalizador::textoLimitado($parte['representacao'] ?? null, 2000),
                'observacoes'   => Normalizador::texto($parte['observacoes'] ?? null),
            ];
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }

        return $resultado;
    }

    /**
     * Monta a colecao normalizada de assuntos (principal + secundarios).
     *
     * O codigo CNJ do assunto ("Fornecimento de medicamentos (12484)") so vem
     * da extracao; no preenchimento manual a descricao vai sozinha, e o codigo
     * fica null - nunca deduzido.
     */
    private function montarAssuntos(array $entrada, ?string $principal, mixed $codigoPrincipal = null): array
    {
        $lista = [];
        if ($principal !== null) {
            $lista[] = [
                'descricao' => $principal,
                // Código informado explicitamente ou, na falta dele, o que
                // vier entre parênteses no próprio texto do assunto.
                'codigo'    => Normalizador::textoLimitado($codigoPrincipal, 40)
                    ?? Normalizador::descricaoComCodigo($entrada['assunto_principal'] ?? null)['codigo'],
                'principal' => true,
            ];
        }

        $secundarios = $entrada['assuntos_secundarios'] ?? [];
        if (is_string($secundarios)) {
            $secundarios = preg_split('/\r\n|\r|\n|;/', $secundarios) ?: [];
        }
        if (!is_array($secundarios)) {
            $secundarios = [];
        }

        $vistos = $principal !== null ? [mb_strtolower($principal) => true] : [];
        foreach ($secundarios as $item) {
            if (is_array($item)) {
                $descricao = Normalizador::texto($item['descricao'] ?? null);
                $codigo    = Normalizador::textoLimitado($item['codigo'] ?? null, 40);
            } else {
                // Texto digitado ou vindo do textarea: o codigo pode estar
                // entre parenteses, no formato em que o PJe o exibe.
                $partes    = Normalizador::descricaoComCodigo((string)$item);
                $descricao = $partes['descricao'];
                $codigo    = $partes['codigo'];
            }
            if ($descricao === null) {
                continue;
            }
            $chave = mb_strtolower($descricao);
            if (isset($vistos[$chave])) {
                continue;
            }
            $vistos[$chave] = true;
            $lista[] = [
                'descricao' => $descricao,
                'codigo'    => $codigo,
                'principal' => false,
            ];
        }

        return $lista;
    }

    private static function booleanoParaBanco(?bool $valor): ?int
    {
        return $valor === null ? null : ($valor ? 1 : 0);
    }
}
