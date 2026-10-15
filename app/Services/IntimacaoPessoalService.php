<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\Prazo;
use App\Core\ValidationException;
use App\Repositories\AgentePublicoRepository;
use App\Repositories\IntimacaoPessoalRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\ProcessoExtrajudicialRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Validators\Normalizador;

/**
 * Intimacoes Pessoais dirigidas a Agentes Publicos.
 *
 * - Toda intimacao tem um processo ja cadastrado (judicial, extrajudicial ou
 *   administrativo) e dois agentes do cadastro unico: a pessoa intimada e
 *   quem recebeu (podem ser pessoas diferentes). Quem registrou no sistema
 *   fica em criado_por, independentemente de quem recebeu.
 * - O Status (Pendente, Em andamento, Cumprida, Cancelada) e informado pelo
 *   usuario; a Situacao temporal (dentro do prazo, proxima do vencimento,
 *   vencida, cumprida no prazo ou fora dele) e calculada, nunca gravada.
 * - O prazo e a data-limite informada: nenhuma regra de contagem processual.
 *   Alterar o prazo de uma intimacao exige justificativa (vai para a auditoria).
 * - Mesma combinacao processo + intimado + recebimento + prazo de outra
 *   intimacao ativa: so grava com confirmacao expressa (possivel duplicidade).
 * - Exclusao logica, com motivo.
 * - Acesso restrito aos perfis Administrador e Gestor (PERFIS): so eles veem
 *   o painel e as intimacoes, registram, alteram, registram cumprimento ou
 *   excluem. O perfil Usuario nao ve o modulo.
 */
final class IntimacaoPessoalService
{
    public const ENTIDADE = 'intimacoes_pessoais';

    public const TIPOS_PROCESSO = [
        'JUDICIAL'       => 'Judicial',
        'EXTRAJUDICIAL'  => 'Extrajudicial',
        'ADMINISTRATIVO' => 'Administrativo',
    ];

    public const STATUS = [
        'PENDENTE'     => 'Pendente',
        'EM_ANDAMENTO' => 'Em andamento',
        'CUMPRIDA'     => 'Cumprida',
        'CANCELADA'    => 'Cancelada',
    ];

    public const SITUACOES = [
        'NO_PRAZO'            => 'Dentro do prazo',
        'PROXIMA'             => 'Próxima do vencimento',
        'VENCIDA'             => 'Vencida',
        'CUMPRIDA_NO_PRAZO'   => 'Cumprida no prazo',
        'CUMPRIDA_FORA_PRAZO' => 'Cumprida fora do prazo',
    ];

    /** Dias antes do prazo em que a intimacao passa a "proxima do vencimento". */
    public const DIAS_ALERTA = Prazo::DIAS_ALERTA;

    public const DOCUMENTO_MAX     = 120;
    public const OBSERVACOES_MAX   = 4000;
    public const JUSTIFICATIVA_MAX = 500;
    public const MOTIVO_MAX        = 500;

    /** Perfis com acesso ao modulo (consulta e alteracao). */
    public const PERFIS = [Perfil::ADMINISTRADOR, Perfil::GESTOR];

    public const MSG_SEM_PERMISSAO = 'As intimações pessoais são restritas a administradores e gestores.';

    public function __construct(
        private readonly IntimacaoPessoalRepository $repo = new IntimacaoPessoalRepository(),
        private readonly AgentePublicoRepository $agentes = new AgentePublicoRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    /** O usuario autenticado ve e mantem intimacoes (administrador ou gestor). */
    public static function podeAcessar(): bool
    {
        return Auth::autorizado(...self::PERFIS);
    }

    /** @throws AcessoNegadoException */
    public static function exigirAcesso(): void
    {
        if (!self::podeAcessar()) {
            throw new AcessoNegadoException(self::MSG_SEM_PERMISSAO);
        }
    }

    /** O usuario autenticado pode alterar a intimacao: tem acesso e ela nao foi excluida. */
    public static function podeAlterar(array $registro): bool
    {
        return empty($registro['excluida_em']) && self::podeAcessar();
    }

    /**
     * Processo com intimacoes (mesmo excluidas, guardadas para historico) nao
     * pode ser excluido: as intimacoes nunca somem em cascata (FK RESTRICT).
     * @throws ValidationException
     */
    public static function exigirProcessoSemIntimacoes(string $tipo, int $processoId): void
    {
        $total = (new IntimacaoPessoalRepository())->contarDoProcesso($tipo, $processoId);
        if ($total > 0) {
            throw new ValidationException([], "O processo tem {$total} intimação(ões) pessoal(is) registrada(s), inclusive as excluídas, "
                . 'que são mantidas para o histórico. Por isso ele não pode ser excluído.');
        }
    }

    /** Ultimo dia em que uma intimacao aberta ainda conta como "proxima do vencimento". */
    public static function limiteAlerta(?string $hoje = null): string
    {
        return date('Y-m-d', strtotime(($hoje ?? date('Y-m-d')) . ' +' . self::DIAS_ALERTA . ' days'));
    }

    /**
     * Situacao temporal calculada (chave de SITUACOES), ou null quando cancelada.
     * @param array{status:string,data_prazo:string,data_cumprimento?:?string} $registro
     */
    public static function situacao(array $registro, ?string $hoje = null): ?string
    {
        $hoje ??= date('Y-m-d');
        $prazo = substr((string)$registro['data_prazo'], 0, 10);
        return match ($registro['status']) {
            'CANCELADA' => null,
            'CUMPRIDA'  => substr((string)($registro['data_cumprimento'] ?? ''), 0, 10) > $prazo ? 'CUMPRIDA_FORA_PRAZO' : 'CUMPRIDA_NO_PRAZO',
            default     => match (true) {
                $prazo < $hoje                      => 'VENCIDA',
                $prazo <= self::limiteAlerta($hoje) => 'PROXIMA',
                default                             => 'NO_PRAZO',
            },
        };
    }

    /** Dias corridos ate o prazo (negativo = vencida ha N dias); null se cumprida ou cancelada. */
    public static function diasRestantes(array $registro, ?string $hoje = null): ?int
    {
        if (!in_array($registro['status'], ['PENDENTE', 'EM_ANDAMENTO'], true)) {
            return null;
        }
        $hoje = new \DateTimeImmutable($hoje ?? date('Y-m-d'));
        $prazo = new \DateTimeImmutable(substr((string)$registro['data_prazo'], 0, 10));
        return (int)$hoje->diff($prazo)->format('%r%a');
    }

    /** "Vence hoje", "Faltam 5 dias", "Vencida há 2 dias" ou "—". */
    public static function textoDiasRestantes(?int $dias): string
    {
        return match (true) {
            $dias === null => '—',
            $dias === 0    => 'Vence hoje',
            $dias === 1    => 'Falta 1 dia',
            $dias > 1      => "Faltam {$dias} dias",
            $dias === -1   => 'Vencida há 1 dia',
            default        => 'Vencida há ' . abs($dias) . ' dias',
        };
    }

    public static function classeBadgeSituacao(?string $situacao): string
    {
        return match ($situacao) {
            'VENCIDA', 'CUMPRIDA_FORA_PRAZO' => 'badge--vermelho',
            'PROXIMA'                        => 'badge--ambar',
            'CUMPRIDA_NO_PRAZO'              => 'badge--verde',
            'NO_PRAZO'                       => 'badge--azul',
            default                          => 'badge--neutro',
        };
    }

    public static function classeBadgeStatus(string $status): string
    {
        return match ($status) {
            'PENDENTE'     => 'badge--ambar',
            'EM_ANDAMENTO' => 'badge--azul',
            'CUMPRIDA'     => 'badge--verde',
            default        => 'badge--neutro',
        };
    }

    /** Pagina da ficha do processo vinculado. */
    public static function urlProcesso(string $tipo, int $id): string
    {
        return match ($tipo) {
            'JUDICIAL'      => 'processo-judicial.php?id=' . $id,
            'EXTRAJUDICIAL' => 'processo-extrajudicial.php?id=' . $id,
            default         => 'processo-administrativo.php?id=' . $id,
        };
    }

    /**
     * Identificacao de um processo cadastrado para exibicao e confirmacao, ou
     * null se o processo nao existe.
     * @return ?array{tipo:string,id:int,rotulo:string,detalhe:string}
     */
    public static function descreverProcesso(string $tipo, int $id): ?array
    {
        $p = match ($tipo) {
            'JUDICIAL'       => (new ProcessoJudicialRepository())->buscarPorId($id),
            'EXTRAJUDICIAL'  => (new ProcessoExtrajudicialRepository())->buscarPorId($id),
            'ADMINISTRATIVO' => (new ProcessoAdministrativoRepository())->buscarPorId($id),
            default          => null,
        };
        if ($p === null) {
            return null;
        }
        $detalhe = match ($tipo) {
            'JUDICIAL'      => [$p['orgao_julgador'] ?? null, $p['classe_judicial'] ?? null],
            'EXTRAJUDICIAL' => [$p['instituicao_sigla'] ?: $p['instituicao_nome'], mb_strimwidth((string)$p['assunto'], 0, 90, '…')],
            default         => [mb_strimwidth((string)($p['objeto'] ?? ''), 0, 90, '…')],
        };
        return [
            'tipo'    => $tipo,
            'id'      => $id,
            'rotulo'  => $tipo === 'JUDICIAL' ? $p['numero_cnj'] : $p['protocolo'],
            'detalhe' => implode(' · ', array_filter([self::TIPOS_PROCESSO[$tipo], ...$detalhe])),
        ];
    }

    /**
     * Identificacao de um agente para exibicao (autocomplete e formulario).
     * @return array{id:int,rotulo:string,detalhe:string}
     */
    public static function descreverAgente(array $a): array
    {
        return [
            'id'      => (int)$a['id'],
            'rotulo'  => (string)($a['nome_social'] ?: $a['nome']),
            'detalhe' => implode(' · ', array_filter([
                $a['matricula'] ? 'Mat. ' . $a['matricula'] : null,
                $a['cargo'] ?? null,
                $a['lotacao'] ?? null,
                ($a['situacao'] ?? '') === 'DESLIGADO' ? 'Desligado' : null,
            ])),
        ];
    }

    /**
     * Rotulos do processo e dos agentes escolhidos, para remontar os campos
     * de pesquisa do formulario (valores invalidos ficam sem rotulo).
     * @return array{processo:?array,intimado:?array,recebedor:?array}
     */
    public function rotulosFormulario(array $dados): array
    {
        $tipo = (string)($dados['processo_tipo'] ?? '');
        $processoId = (int)($dados['processo_id'] ?? 0);
        $agente = function (mixed $id): ?array {
            $registro = (int)$id > 0 ? $this->agentes->buscarPorId((int)$id) : null;
            return $registro !== null ? self::descreverAgente($registro) : null;
        };
        return [
            'processo'  => $processoId > 0 && isset(self::TIPOS_PROCESSO[$tipo]) ? self::descreverProcesso($tipo, $processoId) : null,
            'intimado'  => $agente($dados['agente_intimado_id'] ?? null),
            'recebedor' => $agente($dados['agente_recebedor_id'] ?? null),
        ];
    }

    /**
     * @param array $entrada processo_tipo, processo_id, agente_intimado_id, agente_recebedor_id, data_recebimento,
     *                       data_prazo, status, data_cumprimento, documento_referencia, observacoes, confirmar_duplicidade
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function criar(array $entrada): int
    {
        self::exigirAcesso();
        $dados = $this->validar($entrada);
        return Database::transacao(function () use ($dados, $entrada): int {
            $this->exigirSemDuplicidade($dados, null, $entrada);
            $id = $this->repo->inserir($dados, usuario_logado_id());
            $this->auditoria->registrar('INTIMACAO_CRIADA', self::ENTIDADE, $id, null, $this->resumo($dados));
            return $id;
        });
    }

    /**
     * Alteracao completa. Mudar o prazo exige justificativa.
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function atualizar(int $id, array $entrada): void
    {
        $atual = $this->exigirAlteravel($id);
        $dados = $this->validar($entrada);

        $justificativa = Normalizador::textoLongo($entrada['justificativa'] ?? null);
        $prazoMudou = $dados['data_prazo'] !== substr((string)$atual['data_prazo'], 0, 10);
        if ($prazoMudou && $justificativa === null) {
            throw new ValidationException(['justificativa' => 'Informe a justificativa da retificação do prazo.']);
        }
        if ($justificativa !== null && mb_strlen($justificativa) > self::JUSTIFICATIVA_MAX) {
            throw new ValidationException(['justificativa' => 'A justificativa deve ter no máximo ' . self::JUSTIFICATIVA_MAX . ' caracteres.']);
        }

        Database::transacao(function () use ($id, $atual, $dados, $entrada, $prazoMudou, $justificativa): void {
            [$antes, $depois] = AuditoriaService::diferencas($this->resumo($this->dadosDoRegistro($atual)), $this->resumo($dados));
            unset($antes['descricao'], $depois['descricao']);
            if ($depois === []) {
                return;
            }
            $this->exigirSemDuplicidade($dados, $id, $entrada);
            $this->repo->atualizar($id, $dados, usuario_logado_id());
            if ($prazoMudou) {
                $depois['justificativa'] = $justificativa;
            }
            $this->auditoria->registrar('INTIMACAO_EDITADA', self::ENTIDADE, $id, $antes, $depois);
        });
    }

    /**
     * Marca como Cumprida na data informada, mantendo os demais campos.
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function registrarCumprimento(int $id, ?string $dataCumprimento): void
    {
        $atual = $this->exigirAlteravel($id);
        if ($atual['status'] === 'CANCELADA') {
            throw new ValidationException([], 'A intimação está cancelada: altere o status pela edição, se for o caso.');
        }
        $this->atualizar($id, ['status' => 'CUMPRIDA', 'data_cumprimento' => (string)$dataCumprimento,
                               'confirmar_duplicidade' => '1'] + $this->dadosDoRegistro($atual));
    }

    /**
     * Exclusao logica (o registro e o historico permanecem).
     * @return array a intimacao excluida
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function excluir(int $id, ?string $motivo): array
    {
        $atual = $this->exigirAlteravel($id);
        $motivo = Normalizador::textoLongo($motivo);
        if ($motivo === null) {
            throw new ValidationException(['motivo' => 'Informe o motivo da exclusão.']);
        }
        if (mb_strlen($motivo) > self::MOTIVO_MAX) {
            throw new ValidationException(['motivo' => 'O motivo deve ter no máximo ' . self::MOTIVO_MAX . ' caracteres.']);
        }
        Database::transacao(function () use ($id, $atual, $motivo): void {
            $this->repo->excluirLogicamente($id, $motivo, usuario_logado_id());
            $this->auditoria->registrar('INTIMACAO_EXCLUIDA', self::ENTIDADE, $id,
                $this->resumo($this->dadosDoRegistro($atual)), ['motivo' => $motivo]);
        });
        return $atual;
    }

    /**
     * Valida e normaliza a entrada.
     * @throws ValidationException
     */
    public function validar(array $entrada, ?string $hoje = null): array
    {
        $hoje ??= date('Y-m-d');
        $erros = [];
        // Escalares: o registro de cumprimento reaproveita os ids gravados (int).
        $texto = static fn(string $campo): ?string => Normalizador::texto(
            is_scalar($entrada[$campo] ?? null) && !is_bool($entrada[$campo]) ? (string)$entrada[$campo] : null);

        $tipo = strtoupper((string)$texto('processo_tipo'));
        $processoId = (int)($texto('processo_id') ?? 0);
        if (!isset(self::TIPOS_PROCESSO[$tipo]) || $processoId <= 0) {
            $erros['processo_id'] = 'Escolha o processo judicial, extrajudicial ou administrativo da intimação.';
        } elseif (self::descreverProcesso($tipo, $processoId) === null) {
            $erros['processo_id'] = 'O processo escolhido não está cadastrado.';
        }

        foreach (['agente_intimado_id' => 'a pessoa intimada', 'agente_recebedor_id' => 'quem recebeu a intimação'] as $campo => $rotulo) {
            $agenteId = (int)($texto($campo) ?? 0);
            if ($agenteId <= 0) {
                $erros[$campo] = "Escolha {$rotulo} no cadastro de agentes públicos.";
            } elseif ($this->agentes->buscarPorId($agenteId) === null) {
                $erros[$campo] = 'Agente público não encontrado no cadastro.';
            }
        }

        $data = static function (string $campo, string $rotulo, bool $obrigatoria) use ($texto, &$erros): ?string {
            $bruta = $texto($campo);
            if ($bruta === null) {
                if ($obrigatoria) {
                    $erros[$campo] = "Informe a {$rotulo}.";
                }
                return null;
            }
            $iso = Normalizador::dataIso($bruta);
            if ($iso === null) {
                $erros[$campo] = 'Data inválida.';
            }
            return $iso;
        };

        $recebimento = $data('data_recebimento', 'data do recebimento', true);
        if ($recebimento !== null && $recebimento > $hoje) {
            $erros['data_recebimento'] = 'A data do recebimento não pode ser futura.';
        }
        $prazo = $data('data_prazo', 'data do prazo', true);
        if ($prazo !== null && $recebimento !== null && $prazo < $recebimento) {
            $erros['data_prazo'] = 'O prazo não pode ser anterior ao recebimento (' . data_br($recebimento) . ').';
        }

        $status = strtoupper((string)$texto('status'));
        if (!isset(self::STATUS[$status])) {
            $erros['status'] = 'Escolha o status do cumprimento.';
        }

        // A data do cumprimento so vale para intimacao Cumprida (e entao e obrigatoria).
        $cumprimento = null;
        if ($status === 'CUMPRIDA') {
            $cumprimento = $data('data_cumprimento', 'data do cumprimento', true);
            if ($cumprimento !== null && $cumprimento > $hoje) {
                $erros['data_cumprimento'] = 'A data do cumprimento não pode ser futura.';
            } elseif ($cumprimento !== null && $recebimento !== null && $cumprimento < $recebimento) {
                $erros['data_cumprimento'] = 'O cumprimento não pode ser anterior ao recebimento (' . data_br($recebimento) . ').';
            }
        }

        $documento = $texto('documento_referencia');
        if ($documento !== null && mb_strlen($documento) > self::DOCUMENTO_MAX) {
            $erros['documento_referencia'] = 'A referência deve ter no máximo ' . self::DOCUMENTO_MAX . ' caracteres.';
        }
        $observacoes = Normalizador::textoLongo($entrada['observacoes'] ?? null);
        if ($observacoes !== null && mb_strlen($observacoes) > self::OBSERVACOES_MAX) {
            $erros['observacoes'] = 'As observações devem ter no máximo ' . self::OBSERVACOES_MAX . ' caracteres.';
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }
        return [
            'processo_tipo'        => $tipo,
            'processo_id'          => $processoId,
            'agente_intimado_id'   => (int)$texto('agente_intimado_id'),
            'agente_recebedor_id'  => (int)$texto('agente_recebedor_id'),
            'data_recebimento'     => $recebimento,
            'data_prazo'           => $prazo,
            'data_cumprimento'     => $cumprimento,
            'status'               => $status,
            'documento_referencia' => $documento,
            'observacoes'          => $observacoes,
        ];
    }

    /**
     * RN10: mesma combinacao de processo, intimado, recebimento e prazo de
     * outra intimacao ativa so e gravada com confirmacao expressa.
     * @throws ValidationException
     */
    private function exigirSemDuplicidade(array $dados, ?int $ignorarId, array $entrada): void
    {
        if (($entrada['confirmar_duplicidade'] ?? '') === '1') {
            return;
        }
        $existente = $this->repo->possivelDuplicada($dados['processo_tipo'], $dados['processo_id'], $dados['agente_intimado_id'],
            $dados['data_recebimento'], $dados['data_prazo'], $ignorarId);
        if ($existente !== null) {
            throw new ValidationException(['confirmar_duplicidade' => "Possível duplicidade: a intimação nº {$existente} já registra "
                . 'este agente neste processo, com o mesmo recebimento e o mesmo prazo. Se for outra intimação, confirme e salve de novo.']);
        }
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    private function exigirAlteravel(int $id): array
    {
        $registro = $this->repo->buscarPorId($id);
        if ($registro === null || !empty($registro['excluida_em'])) {
            throw new ValidationException([], 'Intimação não encontrada.');
        }
        if (!self::podeAlterar($registro)) {
            throw new AcessoNegadoException(self::MSG_SEM_PERMISSAO);
        }
        return $registro;
    }

    /** Linha do repositorio no formato devolvido por validar(). */
    private function dadosDoRegistro(array $r): array
    {
        return [
            'processo_tipo'        => (string)$r['processo_tipo'],
            'processo_id'          => (int)$r['processo_id'],
            'agente_intimado_id'   => (int)$r['agente_intimado_id'],
            'agente_recebedor_id'  => (int)$r['agente_recebedor_id'],
            'data_recebimento'     => substr((string)$r['data_recebimento'], 0, 10),
            'data_prazo'           => substr((string)$r['data_prazo'], 0, 10),
            'data_cumprimento'     => $r['data_cumprimento'] !== null ? substr((string)$r['data_cumprimento'], 0, 10) : null,
            'status'               => (string)$r['status'],
            'documento_referencia' => $r['documento_referencia'],
            'observacoes'          => $r['observacoes'],
        ];
    }

    /**
     * Campos auditados, legiveis no historico (processo e agentes pelo nome);
     * `descricao` da o resumo dos eventos de cadastro e exclusao.
     */
    private function resumo(array $dados): array
    {
        $processo = self::descreverProcesso($dados['processo_tipo'], (int)$dados['processo_id']);
        $agente = function (int $id): string {
            $a = $this->agentes->buscarPorId($id);
            return $a === null ? '#' . $id : ($a['nome_social'] ?: $a['nome']) . ($a['matricula'] ? ' (mat. ' . $a['matricula'] . ')' : '');
        };
        $intimado = $agente((int)$dados['agente_intimado_id']);
        $processoTexto = (self::TIPOS_PROCESSO[$dados['processo_tipo']] ?? '') . ' ' . ($processo['rotulo'] ?? '#' . $dados['processo_id']);
        return [
            'processo'             => $processoTexto,
            'agente_intimado'      => $intimado,
            'agente_recebedor'     => $agente((int)$dados['agente_recebedor_id']),
            'data_recebimento'     => $dados['data_recebimento'],
            'data_prazo'           => $dados['data_prazo'],
            'data_cumprimento'     => $dados['data_cumprimento'],
            'status'               => self::STATUS[$dados['status']] ?? $dados['status'],
            'documento_referencia' => $dados['documento_referencia'],
            'observacoes'          => $dados['observacoes'],
            'descricao'            => "Intimação de {$intimado} no processo {$processoTexto}, prazo " . data_br($dados['data_prazo']),
        ];
    }
}
