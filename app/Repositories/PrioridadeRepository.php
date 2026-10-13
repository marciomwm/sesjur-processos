<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginacao;
use PDO;

/**
 * Prioridades (criterios com nivel) e a atribuicao delas aos processos
 * administrativos (processo_administrativo_prioridades, N:N).
 * Prioridades nunca sao excluidas: sao inativadas.
 */
final class PrioridadeRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM prioridades WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Linha com bloqueio para atualizacao (usar em transacao). */
    public function buscarParaAtualizar(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM prioridades WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function nomeEmUso(string $nome, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT 1 FROM prioridades WHERE nome = :nome';
        $params = [':nome' => $nome];
        if ($ignorarId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $ignorarId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    public function inserir(array $dados, ?int $autorId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO prioridades (nome, nivel, descricao, criado_por, atualizado_por)
             VALUES (:nome, :nivel, :descricao, :autor, :autor2)'
        );
        $stmt->execute([
            ':nome'      => $dados['nome'],
            ':nivel'     => $dados['nivel'],
            ':descricao' => $dados['descricao'],
            ':autor'     => $autorId,
            ':autor2'    => $autorId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados, ?int $autorId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE prioridades SET nome = :nome, nivel = :nivel, descricao = :descricao, atualizado_por = :autor
              WHERE id = :id'
        );
        $stmt->execute([
            ':nome'      => $dados['nome'],
            ':nivel'     => $dados['nivel'],
            ':descricao' => $dados['descricao'],
            ':autor'     => $autorId,
            ':id'        => $id,
        ]);
    }

    public function definirAtivo(int $id, bool $ativo, ?int $autorId): void
    {
        $stmt = $this->db->prepare('UPDATE prioridades SET ativo = :ativo, atualizado_por = :autor WHERE id = :id');
        $stmt->bindValue(':ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':autor', $autorId, $autorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Listagem de manutencao com a quantidade de processos que usam cada uma.
     * @param array{busca?:string,nivel?:int|null,status?:string} $filtros
     */
    public function listar(array $filtros, int $pagina, int $porPagina): Paginacao
    {
        $condicoes = [];
        $params = [];

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $like = '%' . addcslashes($busca, '%_\\') . '%';
            $condicoes[] = '(p.nome LIKE :b1 OR p.descricao LIKE :b2)';
            $params[':b1'] = $params[':b2'] = $like;
        }
        if (!empty($filtros['nivel'])) {
            $condicoes[] = 'p.nivel = :nivel';
            $params[':nivel'] = (int)$filtros['nivel'];
        }
        $status = $filtros['status'] ?? 'todos';
        if ($status === 'ativos') {
            $condicoes[] = 'p.ativo = 1';
        } elseif ($status === 'inativos') {
            $condicoes[] = 'p.ativo = 0';
        }
        $where = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM prioridades p' . $where);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM processo_administrativo_prioridades x WHERE x.prioridade_id = p.id) AS total_processos
               FROM prioridades p' . $where . '
              ORDER BY p.ativo DESC, p.nivel DESC, p.nome
              LIMIT :limite OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, ($pagina - 1) * $porPagina), PDO::PARAM_INT);
        $stmt->execute();

        return new Paginacao($stmt->fetchAll(), $total, $pagina, $porPagina);
    }

    /** Prioridades ativas para atribuicao, da mais alta para a mais baixa. */
    public function opcoesAtivas(): array
    {
        return $this->db->query(
            'SELECT id, nome, nivel, descricao FROM prioridades WHERE ativo = 1 ORDER BY nivel DESC, nome'
        )->fetchAll();
    }

    public function totalProcessos(int $prioridadeId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM processo_administrativo_prioridades WHERE prioridade_id = :id');
        $stmt->execute([':id' => $prioridadeId]);
        return (int)$stmt->fetchColumn();
    }

    /** Prioridades de um processo, da mais alta para a mais baixa. */
    public function doProcesso(int $processoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT x.id AS atribuicao_id, x.observacao, x.created_at AS atribuida_em, u.nome AS atribuida_por_nome,
                    p.id, p.nome, p.nivel, p.descricao, p.ativo
               FROM processo_administrativo_prioridades x
               JOIN prioridades p ON p.id = x.prioridade_id
               LEFT JOIN usuarios u ON u.id = x.criado_por
              WHERE x.processo_administrativo_id = :p
              ORDER BY p.nivel DESC, p.nome'
        );
        $stmt->execute([':p' => $processoId]);
        return $stmt->fetchAll();
    }

    public function atribuicaoExiste(int $processoId, int $prioridadeId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM processo_administrativo_prioridades WHERE processo_administrativo_id = :p AND prioridade_id = :pr'
        );
        $stmt->execute([':p' => $processoId, ':pr' => $prioridadeId]);
        return (bool)$stmt->fetchColumn();
    }

    public function atribuir(int $processoId, int $prioridadeId, ?string $observacao, ?int $autorId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO processo_administrativo_prioridades (processo_administrativo_id, prioridade_id, observacao, criado_por)
             VALUES (:p, :pr, :obs, :autor)'
        );
        $stmt->execute([':p' => $processoId, ':pr' => $prioridadeId, ':obs' => $observacao, ':autor' => $autorId]);
        return (int)$this->db->lastInsertId();
    }

    public function removerAtribuicao(int $processoId, int $prioridadeId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM processo_administrativo_prioridades WHERE processo_administrativo_id = :p AND prioridade_id = :pr'
        );
        $stmt->execute([':p' => $processoId, ':pr' => $prioridadeId]);
        return $stmt->rowCount() > 0;
    }
}
