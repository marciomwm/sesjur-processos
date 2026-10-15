<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Acoes (tarefas) dos processos administrativos.
 */
final class AcaoRepository
{
    /** Maximo de acoes exibidas no quadro do gerenciador. */
    public const LIMITE_QUADRO = 300;

    /** Instante do prazo em SQL: sem hora, vale ate o fim do dia. */
    private const MOMENTO_PRAZO = "TIMESTAMP(a.prazo, COALESCE(a.prazo_hora, '23:59:59'))";

    private const SELECT_BASE =
        'SELECT a.*, pa.protocolo, pa.objeto AS processo_objeto, u.nome AS criado_por_nome
           FROM processo_administrativo_acoes a
           JOIN processos_administrativos pa ON pa.id = a.processo_administrativo_id
           LEFT JOIN usuarios u ON u.id = a.criado_por';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT_BASE . ' WHERE a.id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function inserir(array $dados): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO processo_administrativo_acoes
                (processo_administrativo_id, descricao, prazo, prazo_hora, status, observacao, criado_por, finalizada_em)
             VALUES (:processo, :descricao, :prazo, :prazo_hora, :status, :observacao, :criado_por, :finalizada_em)'
        );
        $stmt->execute([
            ':processo'      => $dados['processo_administrativo_id'],
            ':descricao'     => $dados['descricao'],
            ':prazo'         => $dados['prazo'],
            ':prazo_hora'    => $dados['prazo_hora'],
            ':status'        => $dados['status'],
            ':observacao'    => $dados['observacao'],
            ':criado_por'    => $dados['criado_por'],
            ':finalizada_em' => $dados['finalizada_em'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $stmt = $this->db->prepare(
            'UPDATE processo_administrativo_acoes SET
                descricao = :descricao, prazo = :prazo, prazo_hora = :prazo_hora,
                status = :status, observacao = :observacao, finalizada_em = :finalizada_em
              WHERE id = :id'
        );
        $stmt->execute([
            ':descricao'     => $dados['descricao'],
            ':prazo'         => $dados['prazo'],
            ':prazo_hora'    => $dados['prazo_hora'],
            ':status'        => $dados['status'],
            ':observacao'    => $dados['observacao'],
            ':finalizada_em' => $dados['finalizada_em'],
            ':id'            => $id,
        ]);
    }

    public function excluir(int $id): void
    {
        $this->db->prepare('DELETE FROM processo_administrativo_acoes WHERE id = :id')->execute([':id' => $id]);
    }

    /** Acoes de um processo: abertas primeiro, por prazo; depois as finalizadas. */
    public function listarPorProcesso(int $processoId): array
    {
        $stmt = $this->db->prepare(
            self::SELECT_BASE . "
             WHERE a.processo_administrativo_id = :p
             ORDER BY a.status IN ('CONCLUIDA', 'CANCELADA'), a.prazo IS NULL, " . self::MOMENTO_PRAZO . ', a.id'
        );
        $stmt->execute([':p' => $processoId]);
        return $stmt->fetchAll();
    }

    /** Prazos das acoes nao finalizadas do processo. */
    public function abertasDoProcesso(int $processoId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, prazo, prazo_hora FROM processo_administrativo_acoes
              WHERE processo_administrativo_id = :p AND status IN ('PENDENTE', 'EM_ANDAMENTO')"
        );
        $stmt->execute([':p' => $processoId]);
        return $stmt->fetchAll();
    }

    /**
     * Acoes para o gerenciador de tarefas.
     * @param array{busca?:string,status?:string,processo_id?:int|null,criado_por?:int|null,prazo?:string,prazo_de?:string,prazo_ate?:string} $filtros
     */
    public function listar(array $filtros, int $limite = self::LIMITE_QUADRO): array
    {
        $condicoes = [];
        $params = [];

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $condicoes[] = '(a.descricao LIKE :b1 OR a.observacao LIKE :b2 OR pa.protocolo LIKE :b3)';
            $params[':b1'] = $params[':b2'] = $params[':b3'] = '%' . $busca . '%';
        }

        $status = (string)($filtros['status'] ?? '');
        if ($status === 'abertas') {
            $condicoes[] = "a.status IN ('PENDENTE', 'EM_ANDAMENTO')";
        } elseif (in_array($status, ['PENDENTE', 'EM_ANDAMENTO', 'CONCLUIDA', 'CANCELADA'], true)) {
            $condicoes[] = 'a.status = :status';
            $params[':status'] = $status;
        }

        if (!empty($filtros['processo_id'])) {
            $condicoes[] = 'a.processo_administrativo_id = :processo';
            $params[':processo'] = (int)$filtros['processo_id'];
        }
        if (!empty($filtros['criado_por'])) {
            $condicoes[] = 'a.criado_por = :criado_por';
            $params[':criado_por'] = (int)$filtros['criado_por'];
        }

        // Filtros de prazo valem so para acoes abertas: finalizada nao vence.
        $momento = self::MOMENTO_PRAZO;
        $abertas = "a.status IN ('PENDENTE', 'EM_ANDAMENTO')";
        switch ($filtros['prazo'] ?? '') {
            case 'vencidas':
                $condicoes[] = "{$abertas} AND {$momento} < :agora";
                $params[':agora'] = date('Y-m-d H:i:s');
                break;
            case 'hoje':
                $condicoes[] = "{$abertas} AND a.prazo = :hoje AND {$momento} >= :agora";
                $params[':hoje'] = date('Y-m-d');
                $params[':agora'] = date('Y-m-d H:i:s');
                break;
            case 'semana':
                $condicoes[] = "{$abertas} AND {$momento} >= :agora AND a.prazo <= :limite_semana";
                $params[':agora'] = date('Y-m-d H:i:s');
                $params[':limite_semana'] = date('Y-m-d', strtotime('+7 days'));
                break;
            case 'sem_prazo':
                $condicoes[] = 'a.prazo IS NULL';
                break;
        }

        // Intervalo de datas do prazo (calendario), de qualquer status.
        if (!empty($filtros['prazo_de'])) {
            $condicoes[] = 'a.prazo >= :prazo_de';
            $params[':prazo_de'] = $filtros['prazo_de'];
        }
        if (!empty($filtros['prazo_ate'])) {
            $condicoes[] = 'a.prazo <= :prazo_ate';
            $params[':prazo_ate'] = $filtros['prazo_ate'];
        }

        $where = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);
        $stmt = $this->db->prepare(
            self::SELECT_BASE . $where . "
             ORDER BY a.prazo IS NULL, {$momento}, a.id DESC
             LIMIT :limite"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Acoes abertas com prazo vencido (painel). */
    public function contarVencidas(?int $criadoPor = null): int
    {
        $sql = "SELECT COUNT(*) FROM processo_administrativo_acoes a
                 WHERE a.status IN ('PENDENTE', 'EM_ANDAMENTO') AND " . self::MOMENTO_PRAZO . ' < :agora';
        $params = [':agora' => date('Y-m-d H:i:s')];
        if ($criadoPor !== null) {
            $sql .= ' AND a.criado_por = :u';
            $params[':u'] = $criadoPor;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function contarAbertas(): int
    {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM processo_administrativo_acoes WHERE status IN ('PENDENTE', 'EM_ANDAMENTO')"
        )->fetchColumn();
    }
}
