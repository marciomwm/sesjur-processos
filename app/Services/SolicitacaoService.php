<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\LotacaoRepository;
use App\Repositories\SolicitacaoRepository;
use App\Validators\Normalizador;
use App\Validators\ProtocoloValidator;

/**
 * Solicitacoes dos processos administrativos: pedidos enviados a outra
 * unidade (lotacao) por expediente do SIGADOC.
 *
 * Qualquer usuario cria uma solicitacao; alterar ou excluir cabe ao
 * administrador, ao gestor ou a quem a criou. O prazo e informativo e o
 * status e manual: nada aqui altera o prazo vigente nem a atividade do
 * processo.
 */
final class SolicitacaoService
{
    public const STATUS = [
        'AGUARDANDO' => 'Aguardando',
        'ATENDIDO'   => 'Atendido',
        'ENCERRADO'  => 'Encerrado',
    ];

    public const EXPEDIENTE_MAX = 60;
    public const ASSUNTO_MAX    = 500;

    public const MSG_SEM_PERMISSAO = 'Somente administradores, gestores ou quem criou a solicitação podem alterá-la.';

    public function __construct(
        private readonly SolicitacaoRepository $repo = new SolicitacaoRepository(),
        private readonly LotacaoRepository $lotacoes = new LotacaoRepository(),
        private readonly ProcessoAdministrativoService $processoServico = new ProcessoAdministrativoService(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    public static function classeBadge(?string $status): string
    {
        return match ($status) {
            'ATENDIDO'  => 'badge--verde',
            'ENCERRADO' => 'badge--neutro',
            default     => 'badge--ambar',
        };
    }

    /** Aguardando resposta com o prazo (so data) ja expirado. */
    public static function vencida(array $solicitacao, ?string $hoje = null): bool
    {
        return ($solicitacao['status'] ?? null) === 'AGUARDANDO'
            && !empty($solicitacao['prazo'])
            && $solicitacao['prazo'] < ($hoje ?? date('Y-m-d'));
    }

    /** O usuario autenticado pode alterar a solicitacao (administrador, gestor ou autor). */
    public static function podeAlterar(array $solicitacao): bool
    {
        if (Auth::autorizado(Perfil::ADMINISTRADOR, Perfil::GESTOR)) {
            return true;
        }
        $eu = usuario_logado_id();
        return $eu !== null && (int)($solicitacao['criado_por'] ?? 0) === $eu;
    }

    /**
     * @param array $entrada processo_id ou protocolo, data_solicitacao, expediente,
     *                       destinatario_lotacao_id, assunto, prazo, status
     * @return int id da solicitacao criada
     * @throws ValidationException
     */
    public function criar(array $entrada): int
    {
        $processoId = $this->processoServico->resolverId($entrada);
        $dados = $this->validar($entrada);

        return Database::transacao(function () use ($processoId, $dados): int {
            $dados['processo_administrativo_id'] = $processoId;
            $dados['criado_por'] = usuario_logado_id();

            $id = $this->repo->inserir($dados);
            $this->auditoria->registrar(
                AuditoriaService::SOLICITACAO_CRIADA,
                'processos_administrativos',
                $processoId,
                null,
                ['solicitacao_id' => $id] + self::resumo($dados)
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
        $dados = $this->validar($entrada, (int)$atual['destinatario_lotacao_id']);

        Database::transacao(function () use ($id, $atual, $dados): void {
            $this->repo->atualizar($id, $dados);

            [$antes, $depois] = AuditoriaService::diferencas(self::resumo($atual), self::resumo($dados));
            if ($depois !== []) {
                $this->auditoria->registrar(
                    AuditoriaService::SOLICITACAO_EDITADA,
                    'processos_administrativos',
                    (int)$atual['processo_administrativo_id'],
                    ['solicitacao_id' => $id] + $antes,
                    ['solicitacao_id' => $id] + $depois
                );
            }
        });
    }

    /**
     * @return int id do processo da solicitacao excluida
     * @throws AcessoNegadoException
     * @throws ValidationException
     */
    public function excluir(int $id): int
    {
        $atual = $this->exigirAlteravel($id);
        $processoId = (int)$atual['processo_administrativo_id'];

        Database::transacao(function () use ($id, $atual, $processoId): void {
            $this->repo->excluir($id);
            $this->auditoria->registrar(
                AuditoriaService::SOLICITACAO_EXCLUIDA,
                'processos_administrativos',
                $processoId,
                ['solicitacao_id' => $id] + self::resumo($atual),
                null
            );
        });
        return $processoId;
    }

    /**
     * Valida e normaliza os campos editaveis.
     * @param int|null $destinatarioAtual na edicao, a lotacao ja gravada continua
     *                                    aceita mesmo que tenha sido inativada depois
     * @throws ValidationException
     */
    public function validar(array $entrada, ?int $destinatarioAtual = null): array
    {
        $erros = [];

        $data = null;
        $dataBruta = Normalizador::texto($entrada['data_solicitacao'] ?? null);
        if ($dataBruta === null) {
            $erros['data_solicitacao'] = 'Informe a data da solicitação.';
        } elseif (($data = Normalizador::dataIso($dataBruta)) === null) {
            $erros['data_solicitacao'] = 'Data inválida.';
        }

        $expediente = ProtocoloValidator::normalizar($entrada['expediente'] ?? null);
        if ($expediente === null) {
            $erros['expediente'] = 'Informe o número do expediente no SIGADOC.';
        } elseif (mb_strlen($expediente) > self::EXPEDIENTE_MAX) {
            $erros['expediente'] = 'O expediente deve ter no máximo ' . self::EXPEDIENTE_MAX . ' caracteres.';
        }

        $destinatario = $entrada['destinatario_lotacao_id'] ?? null;
        $destinatario = is_numeric($destinatario) && (int)$destinatario > 0 ? (int)$destinatario : null;
        if ($destinatario === null) {
            $erros['destinatario_lotacao_id'] = 'Escolha a unidade destinatária.';
        } else {
            $lotacao = $this->lotacoes->buscarPorId($destinatario);
            if ($lotacao === null) {
                $erros['destinatario_lotacao_id'] = 'Unidade destinatária não encontrada.';
            } elseif ((int)$lotacao['ativo'] !== 1 && $destinatario !== $destinatarioAtual) {
                $erros['destinatario_lotacao_id'] = 'A unidade ' . $lotacao['nome'] . ' está inativa.';
            }
        }

        $assunto = Normalizador::texto($entrada['assunto'] ?? null);
        if ($assunto === null) {
            $erros['assunto'] = 'Descreva o assunto da solicitação.';
        } elseif (mb_strlen($assunto) > self::ASSUNTO_MAX) {
            $erros['assunto'] = 'O assunto deve ter no máximo ' . self::ASSUNTO_MAX . ' caracteres.';
        }

        $prazo = null;
        $prazoBruto = Normalizador::texto($entrada['prazo'] ?? null);
        if ($prazoBruto !== null) {
            $prazo = Normalizador::dataIso($prazoBruto);
            if ($prazo === null) {
                $erros['prazo'] = 'Prazo inválido.';
            } elseif ($data !== null && $prazo < $data) {
                $erros['prazo'] = 'O prazo não pode ser anterior à data da solicitação.';
            }
        }

        $status = Normalizador::texto($entrada['status'] ?? null) ?? 'AGUARDANDO';
        if (!isset(self::STATUS[$status])) {
            $erros['status'] = 'Status inválido.';
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }

        return [
            'data_solicitacao'        => $data,
            'expediente'              => $expediente,
            'destinatario_lotacao_id' => $destinatario,
            'assunto'                 => $assunto,
            'prazo'                   => $prazo,
            'status'                  => $status,
        ];
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    private function exigirAlteravel(int $id): array
    {
        $solicitacao = $this->repo->buscarPorId($id);
        if ($solicitacao === null) {
            throw new ValidationException([], 'Solicitação não encontrada.');
        }
        if (!self::podeAlterar($solicitacao)) {
            throw new AcessoNegadoException(self::MSG_SEM_PERMISSAO);
        }
        return $solicitacao;
    }

    /** Campos da solicitacao registrados na auditoria. */
    private static function resumo(array $dados): array
    {
        return [
            'data_solicitacao'        => $dados['data_solicitacao'] ?? null,
            'expediente'              => $dados['expediente'] ?? null,
            'destinatario_lotacao_id' => $dados['destinatario_lotacao_id'] ?? null,
            'assunto'                 => $dados['assunto'] ?? null,
            'prazo'                   => $dados['prazo'] ?? null,
            'status'                  => $dados['status'] ?? null,
        ];
    }
}
