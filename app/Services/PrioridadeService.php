<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\PrioridadeRepository;
use App\Validators\Normalizador;
use PDOException;

/**
 * Prioridades: cadastro (somente administrador) e atribuicao aos processos
 * administrativos (qualquer usuario, como as demais alteracoes do processo).
 *
 * O nivel vai de 1 (baixa) a 5 (maxima) e e sempre exibido com numero e
 * rotulo, nunca so pela cor. Prioridade inativa continua valendo nos
 * processos que ja a possuem, mas nao pode ser atribuida a outros.
 */
final class PrioridadeService
{
    public const NIVEIS = [
        1 => 'Baixa',
        2 => 'Moderada',
        3 => 'Alta',
        4 => 'Muito alta',
        5 => 'Máxima',
    ];

    public const NOME_MIN      = 2;
    public const NOME_MAX      = 100;
    public const DESCRICAO_MAX = 500;
    public const OBSERVACAO_MAX = 255;

    public const MSG_NOME_DUPLICADO = 'Já existe uma prioridade cadastrada com este nome.';

    private const ENTIDADE = 'prioridades';

    public function __construct(
        private readonly PrioridadeRepository $repo = new PrioridadeRepository(),
        private readonly ProcessoAdministrativoService $processos = new ProcessoAdministrativoService(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    /** "Nível 3 · Alta". */
    public static function rotuloNivel(int|string|null $nivel): string
    {
        $nivel = (int)$nivel;
        return isset(self::NIVEIS[$nivel]) ? 'Nível ' . $nivel . ' · ' . self::NIVEIS[$nivel] : '—';
    }

    public static function classeBadge(int|string|null $nivel): string
    {
        return match ((int)$nivel) {
            5, 4    => 'badge--vermelho',
            3       => 'badge--ambar',
            2       => 'badge--azul',
            default => 'badge--neutro',
        };
    }

    /**
     * @return int id da prioridade criada
     * @throws ValidationException
     */
    public function criar(array $entrada): int
    {
        Auth::exigirPerfil(Perfil::ADMINISTRADOR);
        return $this->comTraducaoDeDuplicidade(fn(): int => Database::transacao(function () use ($entrada): int {
            $dados = $this->validar($entrada, null);
            $id = $this->repo->inserir($dados, usuario_logado_id());
            $this->auditoria->registrar('PRIORIDADE_CRIADA', self::ENTIDADE, $id, null, $dados + ['ativo' => 1]);
            return $id;
        }));
    }

    /** @throws ValidationException */
    public function atualizar(int $id, array $entrada): void
    {
        Auth::exigirPerfil(Perfil::ADMINISTRADOR);
        $this->comTraducaoDeDuplicidade(fn() => Database::transacao(function () use ($id, $entrada): void {
            $atual = $this->repo->buscarParaAtualizar($id);
            if ($atual === null) {
                throw new ValidationException([], 'Prioridade não encontrada.');
            }
            $dados = $this->validar($entrada, $id);
            [$antes, $depois] = AuditoriaService::diferencas($atual, $dados);
            if ($depois !== []) {
                $this->repo->atualizar($id, $dados, usuario_logado_id());
                $this->auditoria->registrar('PRIORIDADE_EDITADA', self::ENTIDADE, $id, $antes, $depois);
            }
        }));
    }

    /**
     * Ativa ou inativa. Retorna false quando ja estava no status pedido.
     * @throws ValidationException
     */
    public function alterarStatus(int $id, bool $ativo): bool
    {
        Auth::exigirPerfil(Perfil::ADMINISTRADOR);
        return Database::transacao(function () use ($id, $ativo): bool {
            $atual = $this->repo->buscarParaAtualizar($id);
            if ($atual === null) {
                throw new ValidationException([], 'Prioridade não encontrada.');
            }
            if (((int)$atual['ativo'] === 1) === $ativo) {
                return false;
            }
            $this->repo->definirAtivo($id, $ativo, usuario_logado_id());
            $this->auditoria->registrar(
                $ativo ? 'PRIORIDADE_ATIVADA' : 'PRIORIDADE_INATIVADA',
                self::ENTIDADE,
                $id,
                ['ativo' => $ativo ? 0 : 1],
                ['ativo' => $ativo ? 1 : 0]
            );
            return true;
        });
    }

    /** @throws ValidationException */
    public function validar(array $entrada, ?int $ignorarId): array
    {
        $erros = [];

        $nome = Normalizador::texto(is_string($entrada['nome'] ?? null) ? $entrada['nome'] : null);
        if ($nome === null) {
            $erros['nome'] = 'Informe o nome da prioridade.';
        } elseif (mb_strlen($nome) < self::NOME_MIN) {
            $erros['nome'] = 'O nome deve ter pelo menos ' . self::NOME_MIN . ' caracteres.';
        } elseif (mb_strlen($nome) > self::NOME_MAX) {
            $erros['nome'] = 'O nome deve ter no máximo ' . self::NOME_MAX . ' caracteres.';
        } elseif (!preg_match('/\p{L}/u', $nome)) {
            $erros['nome'] = 'O nome deve conter letras.';
        } elseif ($this->repo->nomeEmUso($nome, $ignorarId)) {
            $erros['nome'] = self::MSG_NOME_DUPLICADO;
        }

        $nivel = self::nivel($entrada['nivel'] ?? null);
        if ($nivel === null) {
            $erros['nivel'] = 'Escolha um nível entre 1 (baixa) e 5 (máxima).';
        }

        $descricao = Normalizador::textoLongo($entrada['descricao'] ?? null);
        if ($descricao !== null && mb_strlen($descricao) > self::DESCRICAO_MAX) {
            $erros['descricao'] = 'A descrição deve ter no máximo ' . self::DESCRICAO_MAX . ' caracteres.';
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }
        return ['nome' => $nome, 'nivel' => $nivel, 'descricao' => $descricao];
    }

    /** Nivel inteiro de 1 a 5, ou null. */
    public static function nivel(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return isset(self::NIVEIS[$valor]) ? $valor : null;
        }
        if (!is_string($valor) || !ctype_digit(trim($valor))) {
            return null;
        }
        $nivel = (int)trim($valor);
        return isset(self::NIVEIS[$nivel]) ? $nivel : null;
    }

    /**
     * Atribui uma prioridade ativa ao processo (sem repetir).
     * @param array $entrada processo_id, prioridade_id, observacao
     * @throws ValidationException
     */
    public function atribuir(array $entrada): int
    {
        $processoId = $this->processos->resolverId($entrada);

        $prioridadeId = is_numeric($entrada['prioridade_id'] ?? null) ? (int)$entrada['prioridade_id'] : 0;
        $prioridade = $prioridadeId > 0 ? $this->repo->buscarPorId($prioridadeId) : null;
        if ($prioridade === null) {
            throw new ValidationException(['prioridade_id' => 'Escolha a prioridade.']);
        }
        if ((int)$prioridade['ativo'] !== 1) {
            throw new ValidationException(['prioridade_id' => 'A prioridade “' . $prioridade['nome'] . '” está inativa e não pode ser atribuída.']);
        }
        if ($this->repo->atribuicaoExiste($processoId, $prioridadeId)) {
            throw new ValidationException(['prioridade_id' => 'O processo já possui a prioridade “' . $prioridade['nome'] . '”.']);
        }

        $observacao = Normalizador::texto(is_string($entrada['observacao'] ?? null) ? $entrada['observacao'] : null);
        if ($observacao !== null && mb_strlen($observacao) > self::OBSERVACAO_MAX) {
            throw new ValidationException(['observacao' => 'A observação deve ter no máximo ' . self::OBSERVACAO_MAX . ' caracteres.']);
        }

        try {
            return Database::transacao(function () use ($processoId, $prioridade, $observacao): int {
                $id = $this->repo->atribuir($processoId, (int)$prioridade['id'], $observacao, usuario_logado_id());
                $this->auditoria->registrar(
                    AuditoriaService::PRIORIDADE_ATRIBUIDA,
                    'processos_administrativos',
                    $processoId,
                    null,
                    self::resumo($prioridade) + ['observacao' => $observacao]
                );
                return $id;
            });
        } catch (PDOException $e) {
            // Atribuicao simultanea: o UNIQUE do par e a garantia final.
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new ValidationException(['prioridade_id' => 'O processo já possui a prioridade “' . $prioridade['nome'] . '”.']);
            }
            throw $e;
        }
    }

    /**
     * Remove a prioridade do processo (a prioridade continua cadastrada).
     * @throws ValidationException
     */
    public function remover(int $processoId, int $prioridadeId): void
    {
        $processoId = $this->processos->resolverId(['processo_id' => $processoId]);
        $prioridade = $this->repo->buscarPorId($prioridadeId);
        if ($prioridade === null || !$this->repo->atribuicaoExiste($processoId, $prioridadeId)) {
            throw new ValidationException([], 'O processo não possui esta prioridade.');
        }
        Database::transacao(function () use ($processoId, $prioridade): void {
            $this->repo->removerAtribuicao($processoId, (int)$prioridade['id']);
            $this->auditoria->registrar(
                AuditoriaService::PRIORIDADE_REMOVIDA,
                'processos_administrativos',
                $processoId,
                self::resumo($prioridade),
                null
            );
        });
    }

    /** Dados da prioridade gravados na auditoria do processo. */
    private static function resumo(array $prioridade): array
    {
        return [
            'prioridade_id' => (int)$prioridade['id'],
            'prioridade'    => $prioridade['nome'],
            'nivel'         => (int)$prioridade['nivel'],
        ];
    }

    private function comTraducaoDeDuplicidade(callable $operacao): mixed
    {
        try {
            return $operacao();
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_prioridades_nome')) {
                throw new ValidationException(['nome' => self::MSG_NOME_DUPLICADO]);
            }
            throw $e;
        }
    }
}
