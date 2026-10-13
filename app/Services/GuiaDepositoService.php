<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Dinheiro;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\GuiaDepositoRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Validators\Normalizador;

/**
 * Guias de Deposito Judicial: emissao e acompanhamento das guias para
 * deposito de valores em juizo de um Processo Judicial.
 *
 * Status manual: Emitida, Paga (com a data do pagamento) ou Cancelada.
 * **Vencida** e calculada: Emitida com o vencimento ja passado.
 * Mesma regra de alteracao do lancamento de bloqueio.
 */
final class GuiaDepositoService
{
    public const STATUS = [
        'EMITIDA'   => 'Emitida',
        'PAGA'      => 'Paga',
        'CANCELADA' => 'Cancelada',
    ];

    /** Situacao exibida (o status gravado mais a Vencida calculada). */
    public const SITUACOES = self::STATUS + ['VENCIDA' => 'Vencida'];

    public const NUMERO_MAX     = 60;
    public const FINALIDADE_MAX = 255;
    public const OBSERVACAO_MAX = 1000;

    public const MSG_SEM_PERMISSAO = 'Somente administradores, gestores ou quem registrou a guia podem alterá-la.';

    public function __construct(
        private readonly GuiaDepositoRepository $repo = new GuiaDepositoRepository(),
        private readonly ProcessoJudicialRepository $processos = new ProcessoJudicialRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    public static function podeAlterar(array $guia): bool
    {
        if (Auth::autorizado(Perfil::ADMINISTRADOR, Perfil::GESTOR)) {
            return true;
        }
        $eu = usuario_logado_id();
        return $eu !== null && (int)($guia['criado_por'] ?? 0) === $eu;
    }

    /** EMITIDA, VENCIDA, PAGA ou CANCELADA. */
    public static function situacao(array $guia, ?string $hoje = null): string
    {
        $hoje ??= date('Y-m-d');
        $status = (string)$guia['status'];
        if ($status === 'EMITIDA' && !empty($guia['vencimento']) && (string)$guia['vencimento'] < $hoje) {
            return 'VENCIDA';
        }
        return $status;
    }

    /** @throws ValidationException */
    public function criar(?int $processoId, array $entrada): int
    {
        $erroProcesso = $processoId === null || $this->processos->buscarPorId($processoId) === null
            ? ['processo_judicial_id' => 'Escolha o processo judicial da guia.'] : [];
        try {
            $dados = $this->validar($entrada);
        } catch (ValidationException $e) {
            throw new ValidationException($erroProcesso + $e->erros());
        }
        if ($erroProcesso !== []) {
            throw new ValidationException($erroProcesso);
        }
        return Database::transacao(function () use ($processoId, $dados): int {
            $id = $this->repo->inserir($processoId, $dados, usuario_logado_id());
            $this->auditoria->registrar('GUIA_DEPOSITO_CRIADA', 'guias_deposito_judicial', $id, null,
                self::resumo($dados) + ['processo_judicial_id' => $processoId]);
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
        Database::transacao(function () use ($id, $atual, $entrada): void {
            $dados = $this->validar($entrada);
            [$antes, $depois] = AuditoriaService::diferencas(self::resumo($atual), self::resumo($dados));
            if ($depois === []) {
                return;
            }
            $this->repo->atualizar($id, $dados, usuario_logado_id());
            $this->auditoria->registrar('GUIA_DEPOSITO_EDITADA', 'guias_deposito_judicial', $id, $antes, $depois);
        });
    }

    /**
     * @return array a guia excluida
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function excluir(int $id): array
    {
        $atual = $this->exigirAlteravel($id);
        Database::transacao(function () use ($id, $atual): void {
            $this->repo->excluir($id);
            $this->auditoria->registrar('GUIA_DEPOSITO_EXCLUIDA', 'guias_deposito_judicial', $id,
                self::resumo($atual) + ['processo_judicial_id' => (int)$atual['processo_judicial_id']], null);
        });
        return $atual;
    }

    /**
     * @return array{numero:?string,data_emissao:string,vencimento:?string,valor:string,finalidade:?string,status:string,data_pagamento:?string,observacao:?string}
     * @throws ValidationException
     */
    public function validar(array $entrada, ?string $hoje = null): array
    {
        $hoje ??= date('Y-m-d');
        $erros = [];
        $texto = static fn(string $campo): ?string => Normalizador::texto(is_string($entrada[$campo] ?? null) ? $entrada[$campo] : null);

        $emissao = Normalizador::dataIso($texto('data_emissao'));
        if ($texto('data_emissao') === null) {
            $erros['data_emissao'] = 'Informe a data de emissão.';
        } elseif ($emissao === null) {
            $erros['data_emissao'] = 'Data inválida.';
        } elseif ($emissao > $hoje) {
            $erros['data_emissao'] = 'A emissão não pode ser futura.';
        }

        $vencimento = Normalizador::dataIso($texto('vencimento'));
        if ($texto('vencimento') !== null && $vencimento === null) {
            $erros['vencimento'] = 'Data inválida.';
        } elseif ($vencimento !== null && $emissao !== null && $vencimento < $emissao) {
            $erros['vencimento'] = 'O vencimento não pode ser anterior à emissão.';
        }

        $centavos = Dinheiro::centavos($entrada['valor'] ?? null);
        if ($centavos === null) {
            $erros['valor'] = $texto('valor') === null
                ? 'Informe o valor da guia.'
                : 'Valor inválido: use o formato 1.234,56 e um valor maior que zero.';
        }

        $numero = $texto('numero');
        if ($numero !== null && mb_strlen($numero) > self::NUMERO_MAX) {
            $erros['numero'] = 'O número deve ter no máximo ' . self::NUMERO_MAX . ' caracteres.';
        }
        $finalidade = $texto('finalidade');
        if ($finalidade !== null && mb_strlen($finalidade) > self::FINALIDADE_MAX) {
            $erros['finalidade'] = 'A finalidade deve ter no máximo ' . self::FINALIDADE_MAX . ' caracteres.';
        }
        $observacao = Normalizador::textoLongo($entrada['observacao'] ?? null);
        if ($observacao !== null && mb_strlen($observacao) > self::OBSERVACAO_MAX) {
            $erros['observacao'] = 'A observação deve ter no máximo ' . self::OBSERVACAO_MAX . ' caracteres.';
        }

        $status = strtoupper((string)($texto('status') ?? 'EMITIDA'));
        if (!isset(self::STATUS[$status])) {
            $erros['status'] = 'Situação inválida.';
        }

        // A data de pagamento so existe na guia paga; nas demais e descartada.
        $pagamento = null;
        if ($status === 'PAGA') {
            $pagamento = Normalizador::dataIso($texto('data_pagamento'));
            if ($texto('data_pagamento') === null) {
                $erros['data_pagamento'] = 'Informe a data do pagamento.';
            } elseif ($pagamento === null) {
                $erros['data_pagamento'] = 'Data inválida.';
            } elseif ($pagamento > $hoje) {
                $erros['data_pagamento'] = 'O pagamento não pode ter data futura.';
            } elseif ($emissao !== null && $pagamento < $emissao) {
                $erros['data_pagamento'] = 'O pagamento não pode ser anterior à emissão.';
            }
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }
        return [
            'numero'         => $numero,
            'data_emissao'   => $emissao,
            'vencimento'     => $vencimento,
            'valor'          => Dinheiro::paraDecimal($centavos),
            'finalidade'     => $finalidade,
            'status'         => $status,
            'data_pagamento' => $pagamento,
            'observacao'     => $observacao,
        ];
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    private function exigirAlteravel(int $id): array
    {
        $guia = $this->repo->buscarPorId($id);
        if ($guia === null) {
            throw new ValidationException([], 'Guia não encontrada.');
        }
        if (!self::podeAlterar($guia)) {
            throw new AcessoNegadoException(self::MSG_SEM_PERMISSAO);
        }
        return $guia;
    }

    private static function resumo(array $dados): array
    {
        $centavos = Dinheiro::deDecimal($dados['valor'] ?? '0');
        return [
            'numero'         => $dados['numero'] ?? null,
            'data_emissao'   => $dados['data_emissao'] ?? null,
            'vencimento'     => $dados['vencimento'] ?? null,
            'valor'          => Dinheiro::paraDecimal($centavos),
            'finalidade'     => $dados['finalidade'] ?? null,
            'status'         => $dados['status'] ?? null,
            'data_pagamento' => $dados['data_pagamento'] ?? null,
            'observacao'     => $dados['observacao'] ?? null,
            'descricao'      => 'Guia ' . ($dados['numero'] ?? 's/nº') . ' de ' . Dinheiro::formatar($centavos)
                . ' emitida em ' . data_br($dados['data_emissao'] ?? null),
        ];
    }
}
