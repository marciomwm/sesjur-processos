<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Dinheiro;
use App\Core\ValidationException;
use App\Repositories\EntregadorRepository;
use App\Repositories\LotacaoRepository;
use App\Repositories\PagamentoIndenizatorioRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Validators\Normalizador;

/**
 * Dados do Pagamento (guia da ficha do processo administrativo), exigidos
 * quando o procedimento do processo e "Pagamento Indenizatorio":
 *
 *  - Demandante: unidade administrativa da SES-MT;
 *  - Credor: entregador (pessoa fisica ou juridica) que entregou o objeto;
 *  - Objeto: bem fornecido ou servico prestado;
 *  - Data de Execucao: data da entrega do objeto;
 *  - Competencia: AAAA-MM, sempre derivada da Data de Execucao;
 *  - Valor: preco em reais pago pelo objeto.
 *
 * O procedimento e reconhecido pelo nome no catalogo, sem distincao de
 * caixa, acentos e espacos. Se o procedimento do processo mudar depois, os
 * dados gravados sao mantidos e exibidos, mas nao podem mais ser alterados.
 */
final class PagamentoIndenizatorioService
{
    public const PROCEDIMENTO = 'Pagamento Indenizatório';

    /** Instituicao (sigla) das unidades que podem ser Demandante. */
    public const INSTITUICAO_DEMANDANTE = 'SES-MT';

    public const OBJETO_MAX = 2000;

    public const ROTULOS_CAMPO = [
        'demandante'    => 'Demandante',
        'credor'        => 'Credor',
        'objeto'        => 'Objeto',
        'data_execucao' => 'Data de Execução',
        'competencia'   => 'Competência',
        'valor'         => 'Valor',
    ];

    public function __construct(
        private readonly PagamentoIndenizatorioRepository $repo = new PagamentoIndenizatorioRepository(),
        private readonly ProcessoAdministrativoRepository $processos = new ProcessoAdministrativoRepository(),
        private readonly LotacaoRepository $lotacoes = new LotacaoRepository(),
        private readonly EntregadorRepository $entregadores = new EntregadorRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    /** O nome do procedimento e o de Pagamento Indenizatorio? */
    public static function ehProcedimento(?string $nomeProcedimento): bool
    {
        $nome = Normalizador::texto($nomeProcedimento);
        return $nome !== null && Normalizador::semAcento($nome) === Normalizador::semAcento(self::PROCEDIMENTO);
    }

    /** Processo (com procedimento_nome) exige os Dados do Pagamento? */
    public static function exigidoNoProcesso(array $processo): bool
    {
        return self::ehProcedimento($processo['procedimento_nome'] ?? null);
    }

    /** "2026-01-15" -> "2026-01"; null se a data for invalida. */
    public static function competencia(?string $dataIso): ?string
    {
        $data = Normalizador::dataIso($dataIso);
        return $data !== null ? substr($data, 0, 7) : null;
    }

    /**
     * Registra ou altera os Dados do Pagamento do processo.
     * @return bool true quando o registro foi criado; false quando alterado
     * @throws ValidationException
     */
    public function salvar(int $processoId, array $entrada): bool
    {
        $processo = $this->processos->buscarPorId($processoId);
        if ($processo === null) {
            throw new ValidationException([], 'Processo administrativo não encontrado.');
        }
        if (!self::exigidoNoProcesso($processo)) {
            throw new ValidationException([], 'Os Dados do Pagamento só podem ser registrados em processo com o procedimento “'
                . self::PROCEDIMENTO . '”.');
        }

        return Database::transacao(function () use ($processoId, $entrada): bool {
            $atual = $this->repo->buscarParaAtualizar($processoId);
            $dados = $this->validar($entrada, $atual);
            $autor = usuario_logado_id();

            if ($atual === null) {
                $this->repo->inserir($processoId, $dados, $autor);
                $this->auditoria->registrar('PAGAMENTO_REGISTRADO', 'processos_administrativos', $processoId, null, $this->resumo($dados));
                return true;
            }

            [$antes, $depois] = AuditoriaService::diferencas($this->resumo($atual), $this->resumo($dados));
            if ($depois !== []) {
                $this->repo->atualizar($processoId, $dados, $autor);
                $this->auditoria->registrar('PAGAMENTO_EDITADO', 'processos_administrativos', $processoId, $antes, $depois);
            }
            return false;
        });
    }

    /**
     * @param ?array $atual registro ja gravado (a unidade gravada continua
     *                      aceita mesmo se inativada depois)
     * @throws ValidationException
     */
    public function validar(array $entrada, ?array $atual = null): array
    {
        $erros = [];

        $demandanteId = self::id($entrada['demandante_lotacao_id'] ?? null);
        $atualDemandante = $atual !== null ? (int)$atual['demandante_lotacao_id'] : null;
        if ($demandanteId === null) {
            $erros['demandante_lotacao_id'] = 'Selecione a unidade demandante.';
        } else {
            $validas = array_map(
                static fn(array $l): int => (int)$l['id'],
                $this->lotacoes->opcoesDaInstituicao(self::INSTITUICAO_DEMANDANTE, $atualDemandante)
            );
            if (!in_array($demandanteId, $validas, true)) {
                $erros['demandante_lotacao_id'] = 'Demandante inválido: selecione uma unidade administrativa ativa da '
                    . self::INSTITUICAO_DEMANDANTE . '.';
            }
        }

        $entregadorId = self::id($entrada['entregador_id'] ?? null);
        if ($entregadorId === null) {
            $erros['entregador_id'] = 'Selecione o credor.';
        } elseif ($this->entregadores->buscarPorId($entregadorId) === null) {
            $erros['entregador_id'] = 'Credor inválido: selecione um entregador cadastrado.';
        }

        $objeto = Normalizador::textoLongo($entrada['objeto'] ?? null);
        if ($objeto === null) {
            $erros['objeto'] = 'Descreva o objeto (bem fornecido ou serviço prestado).';
        } elseif (mb_strlen($objeto) > self::OBJETO_MAX) {
            $erros['objeto'] = 'O objeto deve ter no máximo ' . self::OBJETO_MAX . ' caracteres.';
        }

        $bruto = is_string($entrada['data_execucao'] ?? null) ? trim($entrada['data_execucao']) : '';
        $dataExecucao = Normalizador::dataIso($bruto);
        if ($bruto === '') {
            $erros['data_execucao'] = 'Informe a data de execução (entrega do objeto).';
        } elseif ($dataExecucao === null) {
            $erros['data_execucao'] = 'Data de execução inválida.';
        } elseif ($dataExecucao > date('Y-m-d')) {
            $erros['data_execucao'] = 'A data de execução não pode ser futura: é a data em que o objeto foi entregue.';
        }

        $valorBruto = is_string($entrada['valor'] ?? null) ? trim($entrada['valor']) : '';
        $centavos = Dinheiro::centavos($valorBruto);
        if ($valorBruto === '') {
            $erros['valor'] = 'Informe o valor.';
        } elseif ($centavos === null) {
            $erros['valor'] = 'Valor inválido. Use o formato 1.234,56 (maior que zero).';
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }
        return [
            'demandante_lotacao_id' => $demandanteId,
            'entregador_id'         => $entregadorId,
            'objeto'                => $objeto,
            'data_execucao'         => $dataExecucao,
            'competencia'           => self::competencia($dataExecucao),
            'valor'                 => Dinheiro::paraDecimal((int)$centavos),
        ];
    }

    /** Dados legiveis gravados na auditoria do processo (nomes, nao ids). */
    private function resumo(array $dados): array
    {
        $unidade = $this->lotacoes->buscarPorId((int)$dados['demandante_lotacao_id']);
        $credor = $this->entregadores->buscarPorId((int)$dados['entregador_id']);
        return [
            'demandante'    => $unidade['nome'] ?? '#' . $dados['demandante_lotacao_id'],
            'credor'        => $credor !== null ? EntregadorService::rotulo($credor) : '#' . $dados['entregador_id'],
            'objeto'        => $dados['objeto'],
            'data_execucao' => $dados['data_execucao'],
            'competencia'   => $dados['competencia'],
            'valor'         => Dinheiro::paraDecimal(Dinheiro::deDecimal($dados['valor'])),
        ];
    }

    private static function id(mixed $valor): ?int
    {
        $texto = is_string($valor) || is_int($valor) ? trim((string)$valor) : '';
        return ctype_digit($texto) && (int)$texto > 0 ? (int)$texto : null;
    }
}
