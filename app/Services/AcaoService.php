<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\Prazo;
use App\Core\ValidationException;
use App\Repositories\AcaoRepository;
use App\Validators\Normalizador;

/**
 * Acoes (tarefas) dos processos administrativos.
 *
 * Qualquer usuario cria uma acao; alterar, mudar o status ou excluir cabe
 * ao administrador, ao gestor ou a quem a criou - regra aplicada aqui, e
 * nao apenas escondendo botoes na tela. Toda gravacao recalcula o prazo
 * vigente e a atividade do processo na mesma transacao.
 */
final class AcaoService
{
    public const STATUS = [
        'PENDENTE'     => 'Pendente',
        'EM_ANDAMENTO' => 'Em andamento',
        'CONCLUIDA'    => 'Concluída',
        'CANCELADA'    => 'Cancelada',
    ];

    /** Status que encerram a acao: ela deixa de contar para prazo e atividade. */
    public const FINALIZADAS = ['CONCLUIDA', 'CANCELADA'];

    public const DESCRICAO_MAX  = 500;
    public const OBSERVACAO_MAX = 5000;

    public const MSG_SEM_PERMISSAO = 'Somente administradores, gestores ou quem criou a ação podem alterá-la.';

    public function __construct(
        private readonly AcaoRepository $repo = new AcaoRepository(),
        private readonly ProcessoAdministrativoService $processoServico = new ProcessoAdministrativoService(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    public static function finalizada(?string $status): bool
    {
        return in_array($status, self::FINALIZADAS, true);
    }

    public static function classeBadge(?string $status): string
    {
        return match ($status) {
            'EM_ANDAMENTO' => 'badge--azul',
            'CONCLUIDA'    => 'badge--verde',
            'CANCELADA'    => 'badge--neutro',
            default        => 'badge--ambar',
        };
    }

    /** O usuario autenticado pode alterar a acao (administrador, gestor ou autor). */
    public static function podeAlterar(array $acao): bool
    {
        if (Auth::autorizado(Perfil::ADMINISTRADOR, Perfil::GESTOR)) {
            return true;
        }
        $eu = usuario_logado_id();
        return $eu !== null && (int)($acao['criado_por'] ?? 0) === $eu;
    }

    /**
     * @param array $entrada processo_id ou protocolo, descricao, prazo, prazo_hora, status, observacao
     * @return int id da acao criada
     * @throws ValidationException
     */
    public function criar(array $entrada): int
    {
        $processoId = $this->processoServico->resolverId($entrada);
        $dados = $this->validar($entrada);

        return Database::transacao(function () use ($processoId, $dados): int {
            $dados['processo_administrativo_id'] = $processoId;
            $dados['criado_por'] = usuario_logado_id();
            $dados['finalizada_em'] = self::finalizada($dados['status']) ? date('Y-m-d H:i:s') : null;

            $id = $this->repo->inserir($dados);
            $this->processoServico->recalcularSituacaoDasAcoes($processoId);
            $this->auditoria->registrar(
                AuditoriaService::ACAO_CRIADA,
                'processos_administrativos',
                $processoId,
                null,
                ['acao_id' => $id] + self::resumo($dados)
            );
            return $id;
        });
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function atualizar(int $id, array $entrada): void
    {
        $atual = $this->exigirAlteravel($id);
        $dados = $this->validar($entrada);
        $dados['finalizada_em'] = $this->finalizadaEm($atual, $dados['status']);

        Database::transacao(function () use ($id, $atual, $dados): void {
            $this->repo->atualizar($id, $dados);
            $processoId = (int)$atual['processo_administrativo_id'];
            $this->processoServico->recalcularSituacaoDasAcoes($processoId);

            [$antes, $depois] = AuditoriaService::diferencas(self::resumo($atual), self::resumo($dados));
            if ($depois !== []) {
                $this->auditoria->registrar(
                    AuditoriaService::ACAO_EDITADA,
                    'processos_administrativos',
                    $processoId,
                    ['acao_id' => $id] + $antes,
                    ['acao_id' => $id] + $depois
                );
            }
        });
    }

    /**
     * Mudanca rapida de status (gerenciador de tarefas).
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function alterarStatus(int $id, mixed $status): void
    {
        $atual = $this->exigirAlteravel($id);
        if (!is_string($status) || !isset(self::STATUS[$status])) {
            throw new ValidationException(['status' => 'Status inválido.']);
        }
        if ($status === $atual['status']) {
            return;
        }
        $this->atualizar($id, [
            'descricao'  => $atual['descricao'],
            'prazo'      => $atual['prazo'],
            'prazo_hora' => $atual['prazo_hora'],
            'observacao' => $atual['observacao'],
            'status'     => $status,
        ]);
    }

    /**
     * @return int id do processo da acao excluida
     * @throws AcessoNegadoException
     * @throws ValidationException
     */
    public function excluir(int $id): int
    {
        $atual = $this->exigirAlteravel($id);
        $processoId = (int)$atual['processo_administrativo_id'];

        Database::transacao(function () use ($id, $atual, $processoId): void {
            $this->repo->excluir($id);
            $this->processoServico->recalcularSituacaoDasAcoes($processoId);
            $this->auditoria->registrar(
                AuditoriaService::ACAO_EXCLUIDA,
                'processos_administrativos',
                $processoId,
                ['acao_id' => $id] + self::resumo($atual),
                null
            );
        });
        return $processoId;
    }

    /**
     * Valida e normaliza os campos editaveis.
     * @throws ValidationException
     */
    public function validar(array $entrada): array
    {
        $erros = [];

        $descricao = Normalizador::texto($entrada['descricao'] ?? null);
        if ($descricao === null) {
            $erros['descricao'] = 'Descreva a ação.';
        } elseif (mb_strlen($descricao) > self::DESCRICAO_MAX) {
            $erros['descricao'] = 'A descrição deve ter no máximo ' . self::DESCRICAO_MAX . ' caracteres.';
        }

        $prazo = null;
        $prazoBruto = Normalizador::texto($entrada['prazo'] ?? null);
        if ($prazoBruto !== null) {
            $prazo = Normalizador::dataIso($prazoBruto);
            if ($prazo === null) {
                $erros['prazo'] = 'Prazo inválido.';
            }
        }
        $hora = Prazo::hora($entrada['prazo_hora'] ?? null);
        if ($hora === false) {
            $erros['prazo_hora'] = 'Hora inválida. Use o formato 14:30.';
        } elseif ($hora !== null && $prazoBruto === null) {
            $erros['prazo_hora'] = 'Informe a data do prazo para usar a hora.';
        }

        $status = Normalizador::texto($entrada['status'] ?? null) ?? 'PENDENTE';
        if (!isset(self::STATUS[$status])) {
            $erros['status'] = 'Status inválido.';
        }

        $observacao = Normalizador::texto($entrada['observacao'] ?? null);
        if ($observacao !== null && mb_strlen($observacao) > self::OBSERVACAO_MAX) {
            $erros['observacao'] = 'A observação deve ter no máximo ' . self::OBSERVACAO_MAX . ' caracteres.';
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }

        return [
            'descricao'  => $descricao,
            'prazo'      => $prazo,
            'prazo_hora' => $hora ?: null,
            'status'     => $status,
            'observacao' => $observacao,
        ];
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    private function exigirAlteravel(int $id): array
    {
        $acao = $this->repo->buscarPorId($id);
        if ($acao === null) {
            throw new ValidationException([], 'Ação não encontrada.');
        }
        if (!self::podeAlterar($acao)) {
            throw new AcessoNegadoException(self::MSG_SEM_PERMISSAO);
        }
        return $acao;
    }

    /** Data de finalizacao: registrada ao finalizar, preservada enquanto finalizada, limpa ao reabrir. */
    private function finalizadaEm(array $atual, string $novoStatus): ?string
    {
        if (!self::finalizada($novoStatus)) {
            return null;
        }
        return self::finalizada($atual['status']) && $atual['finalizada_em'] !== null
            ? $atual['finalizada_em']
            : date('Y-m-d H:i:s');
    }

    /** Campos da acao registrados na auditoria. */
    private static function resumo(array $dados): array
    {
        return [
            'descricao'  => $dados['descricao'] ?? null,
            'prazo'      => $dados['prazo'] ?? null,
            'prazo_hora' => $dados['prazo_hora'] ?? null,
            'status'     => $dados['status'] ?? null,
            'observacao' => $dados['observacao'] ?? null,
        ];
    }
}
