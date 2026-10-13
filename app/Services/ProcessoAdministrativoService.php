<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Prazo;
use App\Core\ValidationException;
use App\Repositories\AcaoRepository;
use App\Repositories\EventoAdministrativoRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\VinculoRepository;
use App\Validators\Normalizador;
use App\Validators\ProtocoloValidator;

/**
 * Regras de negocio do Processo Administrativo.
 * Operacoes multi-tabela sao executadas em transacao.
 */
final class ProcessoAdministrativoService
{
    public function __construct(
        private readonly ProcessoAdministrativoRepository $repo = new ProcessoAdministrativoRepository(),
        private readonly VinculoRepository $vinculos = new VinculoRepository(),
        private readonly EventoAdministrativoRepository $eventos = new EventoAdministrativoRepository(),
        private readonly AcaoRepository $acoes = new AcaoRepository(),
        private ?ProcessoExtrajudicialService $extrajudiciais = null
    ) {
    }

    /**
     * Vinculos extrajudiciais enviados pelo formulario (`extrajudiciais_ids`).
     * Sem o campo, os vinculos ficam como estao. Chamar em transacao.
     */
    private function sincronizarExtrajudiciais(int $id, array $entrada): void
    {
        if (!array_key_exists('extrajudiciais_ids', $entrada)) {
            return;
        }
        $this->extrajudiciais ??= new ProcessoExtrajudicialService();
        $ids = is_array($entrada['extrajudiciais_ids']) ? $entrada['extrajudiciais_ids'] : [];
        $this->extrajudiciais->sincronizarDoAdministrativo($id, $ids);
    }

    /**
     * Recalcula os campos que dependem das acoes do processo:
     *  - prazo vigente: o mais proximo entre o prazo proprio e os das acoes
     *    nao finalizadas (a acao deixa de contar ao ser finalizada);
     *  - atividade: ativo enquanto houver acao nao finalizada; sem acoes,
     *    ou com todas finalizadas, o processo fica inativo.
     * Chamado na mesma transacao de qualquer alteracao do processo ou das acoes.
     */
    public function recalcularSituacaoDasAcoes(int $processoId): void
    {
        Database::transacao(function () use ($processoId): void {
            $processo = $this->repo->bloquear($processoId);
            if ($processo === null) {
                return;
            }
            $abertas = $this->acoes->abertasDoProcesso($processoId);
            $vigente = Prazo::vigente($processo['prazo'], $processo['prazo_hora'], $abertas);
            $this->repo->gravarSituacaoDasAcoes($processoId, $vigente['prazo'], $vigente['prazo_hora'], $abertas !== []);
        });
    }

    /**
     * Processo de um registro filho (acao, solicitacao, anotacao): por id,
     * quando vem da ficha, ou pelo protocolo digitado nas paginas gerais.
     * @throws ValidationException
     */
    public function resolverId(array $entrada): int
    {
        $id = isset($entrada['processo_id']) && is_numeric($entrada['processo_id']) ? (int)$entrada['processo_id'] : 0;
        if ($id > 0) {
            if ($this->repo->buscarPorId($id) === null) {
                throw new ValidationException(['processo' => 'Processo administrativo não encontrado.']);
            }
            return $id;
        }

        $protocolo = ProtocoloValidator::normalizar($entrada['protocolo'] ?? null);
        if ($protocolo === null) {
            throw new ValidationException(['processo' => 'Informe o protocolo do processo administrativo.']);
        }
        $processo = $this->repo->buscarPorProtocolo($protocolo);
        if ($processo === null) {
            throw new ValidationException(['processo' => 'Nenhum processo administrativo cadastrado com o protocolo ' . $protocolo . '.']);
        }
        return (int)$processo['id'];
    }

    /**
     * @param array $entrada protocolo, data_abertura, objeto, procedimento, origem, data_extracao, url_origem,
     *                       situacao, situacao_descricao, responsavel_*, judiciais_ids[],
     *                       extrajudiciais_ids[] (opcional),
     *                       eventos (lista; null = manter as movimentacoes atuais)
     * @return int id criado
     */
    public function criar(array $entrada): int
    {
        $dados = $this->validar($entrada, null);
        $eventos = self::eventosDaEntrada($entrada);

        return Database::transacao(function () use ($dados, $entrada, $eventos): int {
            $id = $this->repo->inserir($dados);
            $this->vinculos->sincronizarPorAdministrativo($id, $entrada['judiciais_ids'] ?? []);
            $this->sincronizarExtrajudiciais($id, $entrada);
            if ($eventos !== null) {
                $this->eventos->substituir($id, $eventos);
            }
            $this->recalcularSituacaoDasAcoes($id);
            return $id;
        });
    }

    /**
     * Movimentacoes enviadas pelo formulario: `eventos` (array) ou
     * `eventos_json` (campo oculto preenchido pela extracao do SIGADOC).
     * Ausente/vazio => null, isto e, as movimentacoes gravadas sao mantidas.
     *
     * @return list<array<string, ?string>>|null
     */
    public static function eventosDaEntrada(array $entrada): ?array
    {
        if (isset($entrada['eventos']) && is_array($entrada['eventos'])) {
            return Normalizador::eventos($entrada['eventos']);
        }
        $json = trim((string)($entrada['eventos_json'] ?? ''));
        if ($json === '') {
            return null;
        }
        $lista = json_decode($json, true);
        if (!is_array($lista)) {
            throw new ValidationException(['eventos' => 'As movimentações extraídas do SIGADOC estão corrompidas. Refaça a extração.']);
        }
        return Normalizador::eventos($lista);
    }

    public function atualizar(int $id, array $entrada): void
    {
        $existente = $this->repo->buscarPorId($id);
        if ($existente === null) {
            throw new ValidationException([], 'Processo administrativo não encontrado.');
        }

        $dados = $this->validar($entrada, $id);
        $eventos = self::eventosDaEntrada($entrada);

        Database::transacao(function () use ($id, $dados, $entrada, $eventos): void {
            $this->repo->atualizar($id, $dados);
            $this->vinculos->sincronizarPorAdministrativo($id, $entrada['judiciais_ids'] ?? []);
            $this->sincronizarExtrajudiciais($id, $entrada);
            if ($eventos !== null) {
                $this->eventos->substituir($id, $eventos);
            }
            $this->recalcularSituacaoDasAcoes($id);
        });
    }

    /** Exclui o processo administrativo. Os vinculos caem por ON DELETE CASCADE; os judiciais permanecem. */
    public function excluir(int $id): void
    {
        $existente = $this->repo->buscarPorId($id);
        if ($existente === null) {
            throw new ValidationException([], 'Processo administrativo não encontrado.');
        }
        Database::transacao(fn() => $this->repo->excluir($id));
    }

    /**
     * Valida e normaliza a entrada.
     * @throws ValidationException
     */
    public function validar(array $entrada, ?int $ignorarId): array
    {
        $erros = [];

        $protocolo = ProtocoloValidator::normalizar($entrada['protocolo'] ?? null);
        if ($protocolo === null) {
            $erros['protocolo'] = 'Informe o protocolo do processo administrativo.';
        } elseif (!ProtocoloValidator::valido($protocolo)) {
            $erros['protocolo'] = 'Protocolo inválido. Formato esperado: SEPLAG-PRO-2026/16696.';
        } elseif ($this->repo->protocoloEmUso($protocolo, $ignorarId)) {
            $erros['protocolo'] = 'Já existe um processo administrativo com este protocolo.';
        }

        $dataAbertura = null;
        $dataBruta = Normalizador::texto($entrada['data_abertura'] ?? null);
        if ($dataBruta !== null) {
            $dataAbertura = Normalizador::dataIso($dataBruta);
            if ($dataAbertura === null) {
                $erros['data_abertura'] = 'Data de abertura inválida.';
            } elseif ($dataAbertura > date('Y-m-d', strtotime('+1 day'))) {
                // Data futura e aceita (a especificacao usa 2026), apenas nao pode ser absurda.
                if ((int)substr($dataAbertura, 0, 4) > (int)date('Y') + 10) {
                    $erros['data_abertura'] = 'Data de abertura fora de um intervalo plausível.';
                }
            }
        }

        $objeto = Normalizador::texto($entrada['objeto'] ?? null);
        if ($objeto !== null && mb_strlen($objeto) > 10000) {
            $erros['objeto'] = 'O objeto excede o tamanho máximo permitido.';
        }

        $procedimento = Normalizador::textoLimitado($entrada['procedimento'] ?? null, 160);

        $origemInformada = (string)($entrada['origem'] ?? 'MANUAL');
        $origem = in_array($origemInformada, ['MANUAL', 'SIGADOC'], true) ? $origemInformada : 'MANUAL';

        $situacao = Normalizador::texto($entrada['situacao'] ?? null);
        if ($situacao !== null && !isset(Normalizador::SITUACOES[$situacao])) {
            $erros['situacao'] = 'Situação inválida.';
        }

        // Prazo proprio: sem data informada, vale o padrao (abertura + 30 dias).
        $prazo = null;
        $prazoBruto = Normalizador::texto($entrada['prazo'] ?? null);
        if ($prazoBruto !== null) {
            $prazo = Normalizador::dataIso($prazoBruto);
            if ($prazo === null) {
                $erros['prazo'] = 'Prazo inválido.';
            }
        }
        $prazoHora = Prazo::hora($entrada['prazo_hora'] ?? null);
        if ($prazoHora === false) {
            $erros['prazo_hora'] = 'Hora inválida. Use o formato 14:30.';
        }
        if (!isset($erros['data_abertura']) && !isset($erros['prazo']) && $prazo === null) {
            $prazo = Prazo::padrao($dataAbertura);
        }
        if ($prazo !== null && $dataAbertura !== null && $prazo < $dataAbertura) {
            $erros['prazo'] = 'O prazo não pode ser anterior à data de abertura.';
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }

        // Sem codigo de situacao, o rotulo do SIGADOC nao tem a que se referir.
        $situacaoDescricao = $situacao === null ? null : Normalizador::textoLimitado($entrada['situacao_descricao'] ?? null, 120);

        return [
            'situacao'                 => $situacao,
            'situacao_descricao'       => $situacaoDescricao,
            'responsavel_nome'         => Normalizador::textoLimitado($entrada['responsavel_nome'] ?? null, 180),
            'responsavel_matricula'    => Normalizador::textoLimitado($entrada['responsavel_matricula'] ?? null, 30),
            'responsavel_lotacao'      => Normalizador::textoLimitado($entrada['responsavel_lotacao'] ?? null, 60),
            'responsavel_lotacao_nome' => Normalizador::textoLimitado($entrada['responsavel_lotacao_nome'] ?? null, 180),
            'protocolo'       => $protocolo,
            'data_abertura'   => $dataAbertura,
            'objeto'          => $objeto,
            'procedimento'    => $procedimento,
            'prazo'           => $prazo,
            'prazo_hora'      => $prazoHora ?: null,
            'origem'          => $origem,
            'url_origem'      => Normalizador::texto($entrada['url_origem'] ?? null),
            'extrator_versao' => Normalizador::texto($entrada['extrator_versao'] ?? null),
            'data_extracao'   => Normalizador::dataHora($entrada['data_extracao'] ?? null),
        ];
    }
}
