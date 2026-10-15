<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\ValidationException;
use App\Repositories\InstituicaoExternaRepository;
use App\Repositories\OrgaoExternoRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\ProcessoExtrajudicialRepository;
use App\Repositories\VinculoExtrajudicialRepository;
use App\Validators\Normalizador;
use PDOException;

/**
 * Processos Extrajudiciais: procedimentos de instituicoes externas ao Poder
 * Executivo estadual, vinculados N:N aos processos administrativos (como o
 * Processo Judicial). O vinculo e auditado nos dois processos; remove-lo
 * nunca exclui nenhum deles.
 *
 * O orgao, quando informado, pertence a instituicao escolhida. Um orgao
 * ainda nao cadastrado pode ser criado no proprio formulario (orgao_novo),
 * como o nivel no cadastro da Unidade Administrativa.
 */
final class ProcessoExtrajudicialService
{
    public const SITUACOES = [
        'EM_ANDAMENTO'      => 'Em andamento',
        'PENDENTE_RESPOSTA' => 'Pendente de resposta',
        'RESPONDIDO'        => 'Respondido',
        'ARQUIVADO'         => 'Arquivado',
        'OUTRA'             => 'Outra',
    ];

    public const PROTOCOLO_MAX = 80;
    public const ASSUNTO_MAX   = 255;
    public const TEXTO_MAX     = 10000;
    public const ORGAO_MAX     = 150;

    public const MSG_PROTOCOLO_DUPLICADO = 'Já existe um processo extrajudicial com este protocolo nesta instituição.';

    private const ENTIDADE = 'processos_extrajudiciais';

    public function __construct(
        private readonly ProcessoExtrajudicialRepository $repo = new ProcessoExtrajudicialRepository(),
        private readonly VinculoExtrajudicialRepository $vinculos = new VinculoExtrajudicialRepository(),
        private readonly InstituicaoExternaRepository $instituicoes = new InstituicaoExternaRepository(),
        private readonly OrgaoExternoRepository $orgaos = new OrgaoExternoRepository(),
        private readonly ProcessoAdministrativoRepository $administrativos = new ProcessoAdministrativoRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    public static function classeBadge(?string $situacao): string
    {
        return match ($situacao) {
            'EM_ANDAMENTO'      => 'badge--azul',
            'PENDENTE_RESPOSTA' => 'badge--ambar',
            'RESPONDIDO'        => 'badge--verde',
            default             => 'badge--neutro',
        };
    }

    /**
     * @param array $entrada protocolo, data_expediente, instituicao_externa_id, orgao_externo_id | orgao_novo,
     *                       assunto, descricao, situacao, observacoes, administrativos_ids[] (opcional)
     * @throws ValidationException
     */
    public function criar(array $entrada): int
    {
        $dados = $this->validar($entrada, null);
        $administrativos = $this->idsAdministrativos($entrada);

        return $this->comTraducaoDeDuplicidade(fn(): int => Database::transacao(function () use ($dados, $administrativos): int {
            $dados['orgao_externo_id'] = $this->resolverOrgao($dados);
            $id = $this->repo->inserir($dados, usuario_logado_id());
            $this->auditoria->registrar(
                AuditoriaService::PROCESSO_EXTRAJUDICIAL_CRIADO,
                self::ENTIDADE,
                $id,
                null,
                self::resumo($dados)
            );
            if ($administrativos !== null) {
                $this->sincronizarAdministrativos($id, $administrativos);
            }
            return $id;
        }));
    }

    /**
     * Sem `administrativos_ids` na entrada, os vinculos ficam como estao.
     * @throws ValidationException
     */
    public function atualizar(int $id, array $entrada): void
    {
        $atual = $this->repo->buscarPorId($id);
        if ($atual === null) {
            throw new ValidationException([], 'Processo extrajudicial não encontrado.');
        }
        $dados = $this->validar($entrada, $atual);
        $administrativos = $this->idsAdministrativos($entrada);

        $this->comTraducaoDeDuplicidade(fn() => Database::transacao(function () use ($id, $atual, $dados, $administrativos): void {
            $dados['orgao_externo_id'] = $this->resolverOrgao($dados);
            [$antes, $depois] = AuditoriaService::diferencas(self::resumo($atual), self::resumo($dados));
            if ($depois !== []) {
                $this->repo->atualizar($id, $dados, usuario_logado_id());
                $this->auditoria->registrar(AuditoriaService::PROCESSO_EXTRAJUDICIAL_EDITADO, self::ENTIDADE, $id, $antes, $depois);
            }
            if ($administrativos !== null) {
                $this->sincronizarAdministrativos($id, $administrativos);
            }
        }));
    }

    /**
     * Exclui o processo extrajudicial. Os vinculos caem em cascata e ficam
     * registrados no historico de cada processo administrativo.
     * @throws ValidationException
     */
    public function excluir(int $id): void
    {
        $atual = $this->repo->buscarPorId($id);
        if ($atual === null) {
            throw new ValidationException([], 'Processo extrajudicial não encontrado.');
        }
        IntimacaoPessoalService::exigirProcessoSemIntimacoes('EXTRAJUDICIAL', $id);
        Database::transacao(function () use ($id, $atual): void {
            foreach ($this->vinculos->idsAdministrativos($id) as $paId) {
                $this->auditoria->registrar(
                    AuditoriaService::EXTRAJUDICIAL_DESVINCULADO,
                    'processos_administrativos',
                    $paId,
                    self::resumoVinculo($atual) + ['motivo' => 'processo extrajudicial excluído'],
                    null
                );
            }
            $this->repo->excluir($id);
            $this->auditoria->registrar(AuditoriaService::PROCESSO_EXTRAJUDICIAL_EXCLUIDO, self::ENTIDADE, $id, self::resumo($atual), null);
        });
    }

    /**
     * Vincula um processo administrativo; false quando o vinculo ja existia.
     * @throws ValidationException
     */
    public function vincular(int $administrativoId, int $extrajudicialId): bool
    {
        [$pa, $pe] = $this->exigirProcessos($administrativoId, $extrajudicialId);
        return Database::transacao(fn(): bool => $this->criarVinculo($pa, $pe));
    }

    /**
     * Remove apenas o vinculo; false quando nao existia.
     * @throws ValidationException
     */
    public function desvincular(int $administrativoId, int $extrajudicialId): bool
    {
        [$pa, $pe] = $this->exigirProcessos($administrativoId, $extrajudicialId);
        return Database::transacao(fn(): bool => $this->removerVinculo($pa, $pe));
    }

    /**
     * Deixa o processo administrativo vinculado exatamente aos extrajudiciais
     * informados (edicao do processo administrativo). Chamar em transacao.
     * @param int[] $extrajudiciaisIds
     * @throws ValidationException
     */
    public function sincronizarDoAdministrativo(int $administrativoId, array $extrajudiciaisIds): void
    {
        $pa = $this->administrativos->buscarPorId($administrativoId);
        if ($pa === null) {
            throw new ValidationException([], 'Processo administrativo não encontrado.');
        }
        $novos = array_values(array_unique(array_filter(array_map('intval', $extrajudiciaisIds), static fn(int $i): bool => $i > 0)));
        $atuais = $this->vinculos->idsExtrajudiciais($administrativoId);
        foreach (array_diff($atuais, $novos) as $peId) {
            $pe = $this->repo->buscarPorId($peId);
            if ($pe !== null) {
                $this->removerVinculo($pa, $pe);
            }
        }
        foreach (array_diff($novos, $atuais) as $peId) {
            $pe = $this->repo->buscarPorId($peId);
            if ($pe === null) {
                throw new ValidationException(['extrajudiciais' => 'Processo extrajudicial não encontrado (#' . $peId . ').']);
            }
            $this->criarVinculo($pa, $pe);
        }
    }

    /** @param int[] $administrativosIds */
    private function sincronizarAdministrativos(int $extrajudicialId, array $administrativosIds): void
    {
        $pe = $this->repo->buscarPorId($extrajudicialId);
        $atuais = $this->vinculos->idsAdministrativos($extrajudicialId);
        foreach (array_diff($atuais, $administrativosIds) as $paId) {
            $pa = $this->administrativos->buscarPorId($paId);
            if ($pa !== null) {
                $this->removerVinculo($pa, $pe);
            }
        }
        foreach (array_diff($administrativosIds, $atuais) as $paId) {
            $pa = $this->administrativos->buscarPorId($paId);
            if ($pa === null) {
                throw new ValidationException(['administrativos' => 'Processo administrativo não encontrado (#' . $paId . ').']);
            }
            $this->criarVinculo($pa, $pe);
        }
    }

    private function criarVinculo(array $pa, array $pe): bool
    {
        if (!$this->vinculos->criar((int)$pa['id'], (int)$pe['id'], usuario_logado_id())) {
            return false;
        }
        $this->auditoria->registrar(AuditoriaService::EXTRAJUDICIAL_VINCULADO, 'processos_administrativos', (int)$pa['id'], null, self::resumoVinculo($pe));
        $this->auditoria->registrar(AuditoriaService::ADMINISTRATIVO_VINCULADO, self::ENTIDADE, (int)$pe['id'], null,
            ['processo_administrativo_id' => (int)$pa['id'], 'protocolo' => $pa['protocolo']]);
        return true;
    }

    private function removerVinculo(array $pa, array $pe): bool
    {
        if (!$this->vinculos->remover((int)$pa['id'], (int)$pe['id'])) {
            return false;
        }
        $this->auditoria->registrar(AuditoriaService::EXTRAJUDICIAL_DESVINCULADO, 'processos_administrativos', (int)$pa['id'], self::resumoVinculo($pe), null);
        $this->auditoria->registrar(AuditoriaService::ADMINISTRATIVO_DESVINCULADO, self::ENTIDADE, (int)$pe['id'],
            ['processo_administrativo_id' => (int)$pa['id'], 'protocolo' => $pa['protocolo']], null);
        return true;
    }

    /** @return array{0:array,1:array} */
    private function exigirProcessos(int $administrativoId, int $extrajudicialId): array
    {
        $pa = $this->administrativos->buscarPorId($administrativoId);
        $pe = $this->repo->buscarPorId($extrajudicialId);
        $erros = [];
        if ($pa === null) {
            $erros['processo_administrativo_id'] = 'Processo administrativo não encontrado.';
        }
        if ($pe === null) {
            $erros['processo_extrajudicial_id'] = 'Processo extrajudicial não encontrado.';
        }
        if ($erros !== []) {
            throw new ValidationException($erros, implode(' ', $erros));
        }
        return [$pa, $pe];
    }

    /**
     * Ids dos processos administrativos enviados pelo formulario, ou null
     * quando o campo nao veio (mantem os vinculos).
     * @return int[]|null
     */
    private function idsAdministrativos(array $entrada): ?array
    {
        if (!array_key_exists('administrativos_ids', $entrada)) {
            return null;
        }
        $ids = is_array($entrada['administrativos_ids']) ? $entrada['administrativos_ids'] : [];
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
    }

    /**
     * Valida e normaliza. `orgao_novo` (nome) fica em dados para ser criado
     * na transacao quando nenhum orgao existente foi escolhido.
     * @param array|null $atual registro gravado (edicao)
     * @throws ValidationException
     */
    public function validar(array $entrada, ?array $atual): array
    {
        $erros = [];
        $texto = static fn(string $campo): ?string => Normalizador::texto(is_string($entrada[$campo] ?? null) ? $entrada[$campo] : null);

        $protocolo = $texto('protocolo');
        if ($protocolo === null) {
            $erros['protocolo'] = 'Informe o protocolo do procedimento na instituição de origem.';
        } elseif (mb_strlen($protocolo) > self::PROTOCOLO_MAX) {
            $erros['protocolo'] = 'O protocolo deve ter no máximo ' . self::PROTOCOLO_MAX . ' caracteres.';
        }

        $data = null;
        $dataBruta = $texto('data_expediente');
        if ($dataBruta === null) {
            $erros['data_expediente'] = 'Informe a data do procedimento.';
        } elseif (($data = Normalizador::dataIso($dataBruta)) === null) {
            $erros['data_expediente'] = 'Data inválida.';
        } elseif ($data > date('Y-m-d')) {
            $erros['data_expediente'] = 'A data não pode ser futura.';
        } elseif ($data < '1900-01-01') {
            $erros['data_expediente'] = 'Data inválida.';
        }

        $instituicaoId = is_numeric($entrada['instituicao_externa_id'] ?? null) ? (int)$entrada['instituicao_externa_id'] : 0;
        $instituicao = $instituicaoId > 0 ? $this->instituicoes->buscarPorId($instituicaoId) : null;
        if ($instituicao === null) {
            $erros['instituicao_externa_id'] = 'Escolha a instituição de origem.';
        } elseif ((int)$instituicao['ativo'] !== 1 && $instituicaoId !== (int)($atual['instituicao_externa_id'] ?? 0)) {
            $erros['instituicao_externa_id'] = 'A instituição ' . $instituicao['nome'] . ' está inativa.';
        }

        $orgaoId = is_numeric($entrada['orgao_externo_id'] ?? null) && (int)$entrada['orgao_externo_id'] > 0 ? (int)$entrada['orgao_externo_id'] : null;
        $orgaoNovo = $texto('orgao_novo');
        if ($orgaoId !== null) {
            $orgao = $this->orgaos->buscarPorId($orgaoId);
            if ($orgao === null) {
                $erros['orgao_externo_id'] = 'Órgão não encontrado.';
            } elseif ($instituicao !== null && (int)$orgao['instituicao_externa_id'] !== $instituicaoId) {
                $erros['orgao_externo_id'] = 'O órgão escolhido não pertence a esta instituição.';
            } elseif ((int)$orgao['ativo'] !== 1 && $orgaoId !== (int)($atual['orgao_externo_id'] ?? 0)) {
                $erros['orgao_externo_id'] = 'O órgão ' . $orgao['nome'] . ' está inativo.';
            }
            $orgaoNovo = null;
        } elseif ($orgaoNovo !== null) {
            if (mb_strlen($orgaoNovo) > self::ORGAO_MAX) {
                $erros['orgao_novo'] = 'O nome do órgão deve ter no máximo ' . self::ORGAO_MAX . ' caracteres.';
            } elseif (!preg_match('/\p{L}/u', $orgaoNovo)) {
                $erros['orgao_novo'] = 'O nome do órgão deve conter letras.';
            }
        }

        if ($protocolo !== null && !isset($erros['protocolo']) && $instituicao !== null
            && $this->repo->protocoloEmUso($instituicaoId, $protocolo, isset($atual['id']) ? (int)$atual['id'] : null)) {
            $erros['protocolo'] = self::MSG_PROTOCOLO_DUPLICADO;
        }

        $assunto = $texto('assunto');
        if ($assunto === null) {
            $erros['assunto'] = 'Informe o assunto.';
        } elseif (mb_strlen($assunto) > self::ASSUNTO_MAX) {
            $erros['assunto'] = 'O assunto deve ter no máximo ' . self::ASSUNTO_MAX . ' caracteres.';
        }

        $descricao = Normalizador::textoLongo($entrada['descricao'] ?? null);
        if ($descricao !== null && mb_strlen($descricao) > self::TEXTO_MAX) {
            $erros['descricao'] = 'A descrição deve ter no máximo ' . self::TEXTO_MAX . ' caracteres.';
        }
        $observacoes = Normalizador::textoLongo($entrada['observacoes'] ?? null);
        if ($observacoes !== null && mb_strlen($observacoes) > self::TEXTO_MAX) {
            $erros['observacoes'] = 'As observações devem ter no máximo ' . self::TEXTO_MAX . ' caracteres.';
        }

        $situacao = strtoupper($texto('situacao') ?? 'EM_ANDAMENTO');
        if (!isset(self::SITUACOES[$situacao])) {
            $erros['situacao'] = 'Situação inválida.';
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }

        return [
            'protocolo'              => $protocolo,
            'data_expediente'        => $data,
            'instituicao_externa_id' => $instituicaoId,
            'orgao_externo_id'       => $orgaoId,
            'orgao_novo'             => $orgaoNovo,
            'assunto'                => $assunto,
            'descricao'              => $descricao,
            'situacao'               => $situacao,
            'observacoes'            => $observacoes,
        ];
    }

    /** Id do orgao escolhido, ou do digitado (reaproveita o de mesmo nome na instituicao). */
    private function resolverOrgao(array $dados): ?int
    {
        if ($dados['orgao_externo_id'] !== null || $dados['orgao_novo'] === null) {
            return $dados['orgao_externo_id'];
        }
        $existente = $this->orgaos->buscarPorNome($dados['instituicao_externa_id'], $dados['orgao_novo']);
        if ($existente !== null) {
            return (int)$existente['id'];
        }
        $novo = ['nome' => $dados['orgao_novo'], 'instituicao_externa_id' => $dados['instituicao_externa_id']];
        $id = $this->orgaos->inserir($novo);
        $this->auditoria->registrar('ORGAO_EXTERNO_CRIADO', 'orgaos_externos', $id, null, $novo + ['ativo' => 1]);
        return $id;
    }

    /** Campos registrados na auditoria do processo extrajudicial. */
    private static function resumo(array $dados): array
    {
        $resumo = [];
        foreach (ProcessoExtrajudicialRepository::CAMPOS as $campo) {
            $resumo[$campo] = $dados[$campo] ?? null;
        }
        return $resumo;
    }

    /** Identificacao do extrajudicial na auditoria do processo administrativo. */
    private static function resumoVinculo(array $pe): array
    {
        return [
            'processo_extrajudicial_id' => (int)$pe['id'],
            'protocolo'                 => $pe['protocolo'],
            'instituicao'               => $pe['instituicao_sigla'] ?? $pe['instituicao_nome'] ?? null,
        ];
    }

    private function comTraducaoDeDuplicidade(callable $operacao): mixed
    {
        try {
            return $operacao();
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_pe_protocolo')) {
                throw new ValidationException(['protocolo' => self::MSG_PROTOCOLO_DUPLICADO]);
            }
            throw $e;
        }
    }
}
