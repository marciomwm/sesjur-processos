<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginacao;
use PDO;

/**
 * Intimacoes Pessoais: intimacao dirigida a um Agente Publico num processo
 * judicial, extrajudicial ou administrativo (exatamente um, por chave
 * estrangeira). Exclusao logica: as consultas de acompanhamento ignoram as
 * excluidas; buscarPorId as devolve (rastreabilidade).
 */
final class IntimacaoPessoalRepository
{
    /** Tipo de processo => coluna da chave estrangeira (lista fechada). */
    public const COLUNAS_PROCESSO = [
        'JUDICIAL'       => 'processo_judicial_id',
        'EXTRAJUDICIAL'  => 'processo_extrajudicial_id',
        'ADMINISTRATIVO' => 'processo_administrativo_id',
    ];

    private const ABERTAS = "i.status IN ('PENDENTE','EM_ANDAMENTO')";

    /** Ordenacoes da listagem (lista fechada). */
    private const ORDENACOES = [
        // Abertas primeiro, do prazo mais antigo (vencidas) ao mais distante.
        'prioridade'  => self::ABERTAS . ' DESC, i.data_prazo ASC, i.id DESC',
        'prazo'       => 'i.data_prazo ASC, i.id DESC',
        'prazo_desc'  => 'i.data_prazo DESC, i.id DESC',
        'recebimento' => 'i.data_recebimento DESC, i.id DESC',
        'recentes'    => 'i.id DESC',
    ];

    private const SELECT =
        "SELECT i.*,
                CASE WHEN i.processo_judicial_id IS NOT NULL THEN 'JUDICIAL'
                     WHEN i.processo_extrajudicial_id IS NOT NULL THEN 'EXTRAJUDICIAL'
                     ELSE 'ADMINISTRATIVO' END AS processo_tipo,
                COALESCE(i.processo_judicial_id, i.processo_extrajudicial_id, i.processo_administrativo_id) AS processo_id,
                COALESCE(pj.numero_cnj, pe.protocolo, pa.protocolo) AS processo_numero,
                ie.sigla AS processo_instituicao_sigla,
                COALESCE(ai.nome_social, ai.nome) AS intimado_nome, ai.matricula AS intimado_matricula,
                ci.nome AS intimado_cargo, li.nome AS intimado_lotacao, ai.situacao AS intimado_situacao,
                COALESCE(ar.nome_social, ar.nome) AS recebedor_nome, ar.matricula AS recebedor_matricula,
                cr.nome AS recebedor_cargo, lr.nome AS recebedor_lotacao,
                uc.nome AS criado_por_nome, ua.nome AS atualizado_por_nome, ue.nome AS excluida_por_nome
           FROM intimacoes_pessoais i
           LEFT JOIN processos_judiciais pj ON pj.id = i.processo_judicial_id
           LEFT JOIN processos_extrajudiciais pe ON pe.id = i.processo_extrajudicial_id
           LEFT JOIN instituicoes_externas ie ON ie.id = pe.instituicao_externa_id
           LEFT JOIN processos_administrativos pa ON pa.id = i.processo_administrativo_id
           JOIN agentes_publicos ai ON ai.id = i.agente_intimado_id
           LEFT JOIN cargos ci ON ci.id = ai.cargo_id
           LEFT JOIN lotacoes li ON li.id = ai.lotacao_id
           JOIN agentes_publicos ar ON ar.id = i.agente_recebedor_id
           LEFT JOIN cargos cr ON cr.id = ar.cargo_id
           LEFT JOIN lotacoes lr ON lr.id = ar.lotacao_id
           LEFT JOIN usuarios uc ON uc.id = i.criado_por
           LEFT JOIN usuarios ua ON ua.id = i.atualizado_por
           LEFT JOIN usuarios ue ON ue.id = i.excluida_por";

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    /** Inclusive excluida (logicamente). */
    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE i.id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Intimacoes do processo (exceto excluidas), abertas primeiro. */
    public function listarPorProcesso(string $tipo, int $processoId): array
    {
        $coluna = self::coluna($tipo);
        $stmt = $this->db->prepare(
            self::SELECT . " WHERE i.{$coluna} = :id AND i.excluida_em IS NULL ORDER BY " . self::ORDENACOES['prioridade']
        );
        $stmt->execute([':id' => $processoId]);
        return $stmt->fetchAll();
    }

    /**
     * Intimacoes em que o agente figura no papel indicado (exceto excluidas).
     * @param 'intimado'|'recebedor' $papel
     */
    public function listarPorAgente(int $agenteId, string $papel): array
    {
        $coluna = $papel === 'recebedor' ? 'agente_recebedor_id' : 'agente_intimado_id';
        $stmt = $this->db->prepare(
            self::SELECT . " WHERE i.{$coluna} = :id AND i.excluida_em IS NULL ORDER BY " . self::ORDENACOES['prioridade']
        );
        $stmt->execute([':id' => $agenteId]);
        return $stmt->fetchAll();
    }

    /** Todas as intimacoes do processo, inclusive excluidas (impedem a exclusao do processo). */
    public function contarDoProcesso(string $tipo, int $processoId): int
    {
        $coluna = self::coluna($tipo);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM intimacoes_pessoais WHERE {$coluna} = :id");
        $stmt->execute([':id' => $processoId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Intimacao ativa com a mesma combinacao processo + agente intimado +
     * recebimento + prazo (possivel duplicidade), ou null.
     */
    public function possivelDuplicada(string $tipo, int $processoId, int $agenteId, string $recebimento, string $prazo, ?int $ignorarId): ?int
    {
        $coluna = self::coluna($tipo);
        $sql = "SELECT id FROM intimacoes_pessoais
                 WHERE {$coluna} = :processo AND agente_intimado_id = :agente
                   AND data_recebimento = :recebimento AND data_prazo = :prazo AND excluida_em IS NULL";
        $params = [':processo' => $processoId, ':agente' => $agenteId, ':recebimento' => $recebimento, ':prazo' => $prazo];
        if ($ignorarId !== null) {
            $sql .= ' AND id <> :ignorar';
            $params[':ignorar'] = $ignorarId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY id LIMIT 1');
        $stmt->execute($params);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * Listagem com filtros combinaveis e paginacao (exceto excluidas).
     * @param array{busca?:string,tipo?:string,processo_tipo?:string,processo_id?:int|null,agente_id?:int|null,
     *              status?:string,situacao?:string,recebido_de?:?string,recebido_ate?:?string,
     *              prazo_de?:?string,prazo_ate?:?string,ordenar?:string,hoje?:string,limite_alerta?:string} $filtros
     */
    public function listar(array $filtros, int $pagina, int $porPagina): Paginacao
    {
        [$where, $params] = $this->condicoes($filtros);

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM intimacoes_pessoais i
               LEFT JOIN processos_judiciais pj ON pj.id = i.processo_judicial_id
               LEFT JOIN processos_extrajudiciais pe ON pe.id = i.processo_extrajudicial_id
               LEFT JOIN processos_administrativos pa ON pa.id = i.processo_administrativo_id
               JOIN agentes_publicos ai ON ai.id = i.agente_intimado_id
               JOIN agentes_publicos ar ON ar.id = i.agente_recebedor_id' . $where
        );
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $ordem = self::ORDENACOES[$filtros['ordenar'] ?? ''] ?? self::ORDENACOES['prioridade'];
        $stmt = $this->db->prepare(self::SELECT . $where . " ORDER BY {$ordem} LIMIT :limite OFFSET :offset");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, ($pagina - 1) * $porPagina), PDO::PARAM_INT);
        $stmt->execute();

        return new Paginacao($stmt->fetchAll(), $total, $pagina, $porPagina);
    }

    /**
     * Indicadores do painel sobre todas as intimacoes nao excluidas.
     * @return array{total:int,pendentes:int,em_andamento:int,proximas:int,vencidas:int,cumpridas:int,fora_prazo:int,canceladas:int}
     */
    public function indicadores(string $hoje, string $limiteAlerta): array
    {
        $abertas = "status IN ('PENDENTE','EM_ANDAMENTO')";
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'PENDENTE'), 0) AS pendentes,
                    COALESCE(SUM(status = 'EM_ANDAMENTO'), 0) AS em_andamento,
                    COALESCE(SUM({$abertas} AND data_prazo BETWEEN :h1 AND :limite), 0) AS proximas,
                    COALESCE(SUM({$abertas} AND data_prazo < :h2), 0) AS vencidas,
                    COALESCE(SUM(status = 'CUMPRIDA'), 0) AS cumpridas,
                    COALESCE(SUM(status = 'CUMPRIDA' AND data_cumprimento > data_prazo), 0) AS fora_prazo,
                    COALESCE(SUM(status = 'CANCELADA'), 0) AS canceladas
               FROM intimacoes_pessoais
              WHERE excluida_em IS NULL"
        );
        $stmt->execute([':h1' => $hoje, ':limite' => $limiteAlerta, ':h2' => $hoje]);
        return array_map('intval', $stmt->fetch());
    }

    public function inserir(array $dados, ?int $usuarioId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO intimacoes_pessoais
                (processo_judicial_id, processo_extrajudicial_id, processo_administrativo_id, agente_intimado_id,
                 agente_recebedor_id, data_recebimento, data_prazo, data_cumprimento, status, documento_referencia,
                 observacoes, criado_por, atualizado_por)
             VALUES (:judicial, :extrajudicial, :administrativo, :intimado, :recebedor, :recebimento, :prazo,
                     :cumprimento, :status, :documento, :observacoes, :criado_por, :atualizado_por)'
        );
        $stmt->execute(self::parametros($dados) + [':criado_por' => $usuarioId, ':atualizado_por' => $usuarioId]);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados, ?int $usuarioId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE intimacoes_pessoais
                SET processo_judicial_id = :judicial, processo_extrajudicial_id = :extrajudicial,
                    processo_administrativo_id = :administrativo, agente_intimado_id = :intimado,
                    agente_recebedor_id = :recebedor, data_recebimento = :recebimento, data_prazo = :prazo,
                    data_cumprimento = :cumprimento, status = :status, documento_referencia = :documento,
                    observacoes = :observacoes, atualizado_por = :usuario
              WHERE id = :id AND excluida_em IS NULL'
        );
        $stmt->execute(self::parametros($dados) + [':id' => $id, ':usuario' => $usuarioId]);
    }

    public function excluirLogicamente(int $id, string $motivo, ?int $usuarioId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE intimacoes_pessoais
                SET excluida_em = NOW(), excluida_por = :usuario, motivo_exclusao = :motivo
              WHERE id = :id AND excluida_em IS NULL'
        );
        $stmt->execute([':id' => $id, ':usuario' => $usuarioId, ':motivo' => $motivo]);
    }

    /** @return array{0:string,1:array<string,int|string>} */
    private function condicoes(array $filtros): array
    {
        $condicoes = ['i.excluida_em IS NULL'];
        $params = [];
        $hoje = (string)($filtros['hoje'] ?? date('Y-m-d'));
        $limite = (string)($filtros['limite_alerta'] ?? $hoje);

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            // Um marcador por coluna: prepared statements nativos nao repetem nomes.
            $like = '%' . addcslashes($busca, '%_\\') . '%';
            $colunas = ['pj.numero_cnj', 'pe.protocolo', 'pa.protocolo', 'ai.nome', 'ai.nome_social', 'ai.matricula',
                        'ar.nome', 'ar.nome_social', 'ar.matricula', 'i.documento_referencia', 'i.observacoes'];
            $partes = [];
            foreach ($colunas as $n => $coluna) {
                $partes[] = "{$coluna} LIKE :b{$n}";
                $params[":b{$n}"] = $like;
            }
            $condicoes[] = '(' . implode(' OR ', $partes) . ')';
        }

        $tipo = (string)($filtros['tipo'] ?? '');
        if (isset(self::COLUNAS_PROCESSO[$tipo])) {
            $condicoes[] = 'i.' . self::COLUNAS_PROCESSO[$tipo] . ' IS NOT NULL';
        }
        $processoTipo = (string)($filtros['processo_tipo'] ?? '');
        if (isset(self::COLUNAS_PROCESSO[$processoTipo]) && !empty($filtros['processo_id'])) {
            $condicoes[] = 'i.' . self::COLUNAS_PROCESSO[$processoTipo] . ' = :processo';
            $params[':processo'] = (int)$filtros['processo_id'];
        }
        if (!empty($filtros['agente_id'])) {
            $condicoes[] = '(i.agente_intimado_id = :agente1 OR i.agente_recebedor_id = :agente2)';
            $params[':agente1'] = $params[':agente2'] = (int)$filtros['agente_id'];
        }

        $status = (string)($filtros['status'] ?? '');
        if (in_array($status, ['PENDENTE', 'EM_ANDAMENTO', 'CUMPRIDA', 'CANCELADA'], true)) {
            $condicoes[] = 'i.status = :status';
            $params[':status'] = $status;
        }

        switch ((string)($filtros['situacao'] ?? '')) {
            case 'VENCIDA':
                $condicoes[] = self::ABERTAS . ' AND i.data_prazo < :hoje';
                $params[':hoje'] = $hoje;
                break;
            case 'PROXIMA':
                $condicoes[] = self::ABERTAS . ' AND i.data_prazo BETWEEN :hoje AND :alerta';
                $params[':hoje'] = $hoje;
                $params[':alerta'] = $limite;
                break;
            case 'NO_PRAZO':
                $condicoes[] = self::ABERTAS . ' AND i.data_prazo > :alerta';
                $params[':alerta'] = $limite;
                break;
            case 'CUMPRIDA_NO_PRAZO':
                $condicoes[] = "i.status = 'CUMPRIDA' AND i.data_cumprimento <= i.data_prazo";
                break;
            case 'CUMPRIDA_FORA_PRAZO':
                $condicoes[] = "i.status = 'CUMPRIDA' AND i.data_cumprimento > i.data_prazo";
                break;
        }

        foreach (['recebido_de' => ['i.data_recebimento', '>='], 'recebido_ate' => ['i.data_recebimento', '<='],
                  'prazo_de' => ['i.data_prazo', '>='], 'prazo_ate' => ['i.data_prazo', '<=']] as $chave => [$coluna, $operador]) {
            if (!empty($filtros[$chave])) {
                $condicoes[] = "{$coluna} {$operador} :{$chave}";
                $params[':' . $chave] = (string)$filtros[$chave];
            }
        }

        return [' WHERE ' . implode(' AND ', $condicoes), $params];
    }

    private static function coluna(string $tipo): string
    {
        return self::COLUNAS_PROCESSO[$tipo] ?? throw new \InvalidArgumentException('Tipo de processo inválido.');
    }

    private static function parametros(array $dados): array
    {
        $tipo = $dados['processo_tipo'];
        return [
            ':judicial'       => $tipo === 'JUDICIAL' ? $dados['processo_id'] : null,
            ':extrajudicial'  => $tipo === 'EXTRAJUDICIAL' ? $dados['processo_id'] : null,
            ':administrativo' => $tipo === 'ADMINISTRATIVO' ? $dados['processo_id'] : null,
            ':intimado'       => $dados['agente_intimado_id'],
            ':recebedor'      => $dados['agente_recebedor_id'],
            ':recebimento'    => $dados['data_recebimento'],
            ':prazo'          => $dados['data_prazo'],
            ':cumprimento'    => $dados['data_cumprimento'],
            ':status'         => $dados['status'],
            ':documento'      => $dados['documento_referencia'],
            ':observacoes'    => $dados['observacoes'],
        ];
    }
}
