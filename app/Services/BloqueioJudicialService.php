<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Dinheiro;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\BloqueioJudicialRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Validators\Normalizador;

/**
 * Lancamentos de Bloqueio e Desbloqueio de valores de um Processo Judicial.
 *
 * O Saldo retido e a soma dos bloqueios menos a dos desbloqueios e nunca
 * fica negativo em ordem cronologica: nao se desbloqueia o que ainda nao foi
 * bloqueado. A regra e conferida depois de cada gravacao, dentro da mesma
 * transacao; se falhar, nada e gravado.
 *
 * Qualquer usuario lanca; so Administrador, Gestor ou quem lancou altera ou
 * exclui (mesma regra da Acao e do Recurso). Tudo fica na auditoria.
 */
final class BloqueioJudicialService
{
    public const TIPOS = [
        'BLOQUEIO'    => 'Bloqueio',
        'DESBLOQUEIO' => 'Desbloqueio',
    ];

    public const IDENTIFICADOR_MAX = 60;
    public const OBSERVACAO_MAX    = 1000;

    public const MSG_SEM_PERMISSAO = 'Somente administradores, gestores ou quem registrou o lançamento podem alterá-lo.';

    public function __construct(
        private readonly BloqueioJudicialRepository $repo = new BloqueioJudicialRepository(),
        private readonly ProcessoJudicialRepository $processos = new ProcessoJudicialRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    /** O usuario autenticado pode alterar o lancamento (administrador, gestor ou autor). */
    public static function podeAlterar(array $registro): bool
    {
        if (Auth::autorizado(Perfil::ADMINISTRADOR, Perfil::GESTOR)) {
            return true;
        }
        $eu = usuario_logado_id();
        return $eu !== null && (int)($registro['criado_por'] ?? 0) === $eu;
    }

    /**
     * @param array $entrada tipo, data_lancamento, valor, identificador, observacao
     * @throws ValidationException
     */
    public function criar(?int $processoId, array $entrada): int
    {
        $erroProcesso = $processoId === null || $this->processos->buscarPorId($processoId) === null
            ? ['processo_judicial_id' => 'Escolha o processo judicial do lançamento.'] : [];
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
            $this->exigirSaldoNaoNegativo($processoId, 'valor');
            $this->auditoria->registrar('BLOQUEIO_JUDICIAL_CRIADO', 'bloqueios_judiciais', $id, null,
                self::resumo($dados) + ['processo_judicial_id' => $processoId]);
            return $id;
        });
    }

    /**
     * O processo do lancamento nao muda na edicao.
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
            $this->exigirSaldoNaoNegativo((int)$atual['processo_judicial_id'], 'valor');
            $this->auditoria->registrar('BLOQUEIO_JUDICIAL_EDITADO', 'bloqueios_judiciais', $id, $antes, $depois);
        });
    }

    /**
     * @return array o lancamento excluido
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function excluir(int $id): array
    {
        $atual = $this->exigirAlteravel($id);
        Database::transacao(function () use ($id, $atual): void {
            $this->repo->excluir($id);
            $this->exigirSaldoNaoNegativo((int)$atual['processo_judicial_id'], null);
            $this->auditoria->registrar('BLOQUEIO_JUDICIAL_EXCLUIDO', 'bloqueios_judiciais', $id,
                self::resumo($atual) + ['processo_judicial_id' => (int)$atual['processo_judicial_id']], null);
        });
        return $atual;
    }

    /**
     * Valida e normaliza a entrada.
     * @return array{tipo:string,data_lancamento:string,valor:string,identificador:?string,observacao:?string}
     * @throws ValidationException
     */
    public function validar(array $entrada, ?string $hoje = null): array
    {
        $hoje ??= date('Y-m-d');
        $erros = [];

        $tipo = strtoupper(is_string($entrada['tipo'] ?? null) ? trim($entrada['tipo']) : '');
        if (!isset(self::TIPOS[$tipo])) {
            $erros['tipo'] = 'Escolha se o lançamento é um bloqueio ou um desbloqueio.';
        }

        $dataBruta = is_string($entrada['data_lancamento'] ?? null) ? $entrada['data_lancamento'] : null;
        $data = Normalizador::dataIso($dataBruta);
        if (Normalizador::texto($dataBruta) === null) {
            $erros['data_lancamento'] = 'Informe a data do lançamento.';
        } elseif ($data === null) {
            $erros['data_lancamento'] = 'Data inválida.';
        } elseif ($data > $hoje) {
            $erros['data_lancamento'] = 'A data não pode ser futura.';
        }

        $centavos = Dinheiro::centavos($entrada['valor'] ?? null);
        if ($centavos === null) {
            $erros['valor'] = Normalizador::texto(is_string($entrada['valor'] ?? null) ? $entrada['valor'] : null) === null
                ? 'Informe o valor.'
                : 'Valor inválido: use o formato 1.234,56 e um valor maior que zero.';
        }

        $identificador = Normalizador::texto(is_string($entrada['identificador'] ?? null) ? $entrada['identificador'] : null);
        if ($identificador !== null && mb_strlen($identificador) > self::IDENTIFICADOR_MAX) {
            $erros['identificador'] = 'O identificador deve ter no máximo ' . self::IDENTIFICADOR_MAX . ' caracteres.';
        }

        $observacao = Normalizador::textoLongo($entrada['observacao'] ?? null);
        if ($observacao !== null && mb_strlen($observacao) > self::OBSERVACAO_MAX) {
            $erros['observacao'] = 'A observação deve ter no máximo ' . self::OBSERVACAO_MAX . ' caracteres.';
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }
        return [
            'tipo'            => $tipo,
            'data_lancamento' => $data,
            'valor'           => Dinheiro::paraDecimal($centavos),
            'identificador'   => $identificador,
            'observacao'      => $observacao,
        ];
    }

    /**
     * Totais (em centavos) e o saldo depois de cada lancamento, percorrendo-os
     * na ordem cronologica em que o repositorio os devolve.
     * @param list<array> $lancamentos
     * @return array{bloqueado:int,desbloqueado:int,saldo:int,saldo_apos:array<int,int>,negativo:?array{data:string,saldo:int}}
     */
    public static function saldos(array $lancamentos): array
    {
        $bloqueado = $desbloqueado = $saldo = 0;
        $saldoApos = [];
        $negativo = null;
        foreach ($lancamentos as $l) {
            $valor = Dinheiro::deDecimal($l['valor']);
            if ($l['tipo'] === 'BLOQUEIO') {
                $bloqueado += $valor;
                $saldo += $valor;
            } else {
                $desbloqueado += $valor;
                $saldo -= $valor;
            }
            $saldoApos[(int)($l['id'] ?? 0)] = $saldo;
            if ($saldo < 0 && $negativo === null) {
                $negativo = ['data' => (string)$l['data_lancamento'], 'saldo' => $saldo];
            }
        }
        return ['bloqueado' => $bloqueado, 'desbloqueado' => $desbloqueado, 'saldo' => $saldo,
                'saldo_apos' => $saldoApos, 'negativo' => $negativo];
    }

    /**
     * Confere o saldo do processo ja com a alteracao gravada (dentro da
     * transacao, que desfaz tudo se a regra falhar).
     * @throws ValidationException
     */
    private function exigirSaldoNaoNegativo(int $processoId, ?string $campo): void
    {
        $negativo = self::saldos($this->repo->listarPorProcesso($processoId))['negativo'];
        if ($negativo === null) {
            return;
        }
        $mensagem = 'Em ' . data_br($negativo['data']) . ' os desbloqueios superariam os bloqueios registrados até então ('
            . 'saldo de ' . Dinheiro::formatar($negativo['saldo']) . '). Registre antes o bloqueio correspondente ou revise a data e o valor.';
        throw $campo === null ? new ValidationException([], $mensagem) : new ValidationException([$campo => $mensagem]);
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    private function exigirAlteravel(int $id): array
    {
        $registro = $this->repo->buscarPorId($id);
        if ($registro === null) {
            throw new ValidationException([], 'Lançamento não encontrado.');
        }
        if (!self::podeAlterar($registro)) {
            throw new AcessoNegadoException(self::MSG_SEM_PERMISSAO);
        }
        return $registro;
    }

    /** Campos auditados; `descricao` da o resumo legivel do historico. */
    private static function resumo(array $dados): array
    {
        $tipo = (string)($dados['tipo'] ?? '');
        return [
            'tipo'            => $tipo,
            'data_lancamento' => $dados['data_lancamento'] ?? null,
            'valor'           => isset($dados['valor']) ? Dinheiro::paraDecimal(Dinheiro::deDecimal($dados['valor'])) : null,
            'identificador'   => $dados['identificador'] ?? null,
            'observacao'      => $dados['observacao'] ?? null,
            'descricao'       => (self::TIPOS[$tipo] ?? $tipo) . ' de ' . Dinheiro::formatar(Dinheiro::deDecimal($dados['valor'] ?? '0'))
                . ' em ' . data_br($dados['data_lancamento'] ?? null),
        ];
    }
}
