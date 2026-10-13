<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Produtos de saude (medicamentos, procedimentos, exames...) dos processos
 * administrativos, com a classificacao SIGTAP quando informada.
 */
final class ProdutoSaudeRepository
{
    private const SELECT_BASE =
        'SELECT ps.*, pa.protocolo,
                s.codigo AS sigtap_codigo, s.nome AS sigtap_nome, s.grupo_nome AS sigtap_grupo_nome,
                s.subgrupo_nome AS sigtap_subgrupo_nome, s.forma_organizacao_nome AS sigtap_forma_organizacao_nome,
                s.origem AS sigtap_origem,
                uc.nome AS criado_por_nome, ua.nome AS atualizado_por_nome
           FROM processo_administrativo_produtos_saude ps
           JOIN processos_administrativos pa ON pa.id = ps.processo_administrativo_id
           LEFT JOIN sigtap_procedimentos s ON s.id = ps.sigtap_procedimento_id
           LEFT JOIN usuarios uc ON uc.id = ps.criado_por
           LEFT JOIN usuarios ua ON ua.id = ps.atualizado_por';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT_BASE . ' WHERE ps.id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Produtos do processo, agrupados por categoria na ordem de cadastro. */
    public function listarPorProcesso(int $processoId): array
    {
        $stmt = $this->db->prepare(self::SELECT_BASE . ' WHERE ps.processo_administrativo_id = :p ORDER BY ps.categoria, ps.id');
        $stmt->execute([':p' => $processoId]);
        return $stmt->fetchAll();
    }

    /** O mesmo item (categoria + descricao, sem diferenciar maiusculas) ja existe no processo. */
    public function itemExiste(int $processoId, string $categoria, string $descricao, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT 1 FROM processo_administrativo_produtos_saude
                 WHERE processo_administrativo_id = :p AND categoria = :c AND descricao = :d';
        $params = [':p' => $processoId, ':c' => $categoria, ':d' => $descricao];
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
            'INSERT INTO processo_administrativo_produtos_saude
                (processo_administrativo_id, categoria, descricao, sigtap_procedimento_id, criado_por, atualizado_por)
             VALUES (:p, :categoria, :descricao, :sigtap, :autor, :autor2)'
        );
        $stmt->execute([
            ':p'         => $dados['processo_administrativo_id'],
            ':categoria' => $dados['categoria'],
            ':descricao' => $dados['descricao'],
            ':sigtap'    => $dados['sigtap_procedimento_id'],
            ':autor'     => $autorId,
            ':autor2'    => $autorId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados, ?int $autorId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE processo_administrativo_produtos_saude
                SET categoria = :categoria, descricao = :descricao, sigtap_procedimento_id = :sigtap, atualizado_por = :autor
              WHERE id = :id'
        );
        $stmt->execute([
            ':categoria' => $dados['categoria'],
            ':descricao' => $dados['descricao'],
            ':sigtap'    => $dados['sigtap_procedimento_id'],
            ':autor'     => $autorId,
            ':id'        => $id,
        ]);
    }

    public function excluir(int $id): void
    {
        $this->db->prepare('DELETE FROM processo_administrativo_produtos_saude WHERE id = :id')->execute([':id' => $id]);
    }
}
