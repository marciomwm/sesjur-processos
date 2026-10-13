<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Acesso a dados de partes_processuais.
 */
final class ParteProcessualRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function listarPorProcesso(int $processoJudicialId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM partes_processuais
              WHERE processo_judicial_id = :id
              ORDER BY FIELD(polo, "ATIVO","PASSIVO","TERCEIRO","OUTRO"), ordem, id'
        );
        $stmt->execute([':id' => $processoJudicialId]);
        return $stmt->fetchAll();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM partes_processuais WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function inserir(int $processoJudicialId, array $parte, int $ordem = 0): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO partes_processuais
                (processo_judicial_id, nome, documento, polo, tipo, representacao, observacoes, ordem)
             VALUES (:pid, :nome, :documento, :polo, :tipo, :representacao, :observacoes, :ordem)'
        );
        $stmt->execute([
            ':pid'           => $processoJudicialId,
            ':nome'          => $parte['nome'],
            ':documento'     => $parte['documento'] ?? null,
            ':polo'          => $parte['polo'] ?? 'OUTRO',
            ':tipo'          => $parte['tipo'] ?? null,
            ':representacao' => $parte['representacao'] ?? null,
            ':observacoes'   => $parte['observacoes'] ?? null,
            ':ordem'         => $ordem,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $parte, int $ordem = 0): void
    {
        $stmt = $this->db->prepare(
            'UPDATE partes_processuais SET
                nome = :nome, documento = :documento, polo = :polo, tipo = :tipo,
                representacao = :representacao, observacoes = :observacoes, ordem = :ordem
             WHERE id = :id'
        );
        $stmt->execute([
            ':nome'          => $parte['nome'],
            ':documento'     => $parte['documento'] ?? null,
            ':polo'          => $parte['polo'] ?? 'OUTRO',
            ':tipo'          => $parte['tipo'] ?? null,
            ':representacao' => $parte['representacao'] ?? null,
            ':observacoes'   => $parte['observacoes'] ?? null,
            ':ordem'         => $ordem,
            ':id'            => $id,
        ]);
    }

    public function excluir(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM partes_processuais WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Remove partes do processo que nao estejam na lista de IDs mantidos. */
    public function excluirAusentes(int $processoJudicialId, array $idsMantidos): void
    {
        if ($idsMantidos === []) {
            $stmt = $this->db->prepare('DELETE FROM partes_processuais WHERE processo_judicial_id = :pid');
            $stmt->execute([':pid' => $processoJudicialId]);
            return;
        }
        $marcadores = implode(',', array_fill(0, count($idsMantidos), '?'));
        $sql = "DELETE FROM partes_processuais WHERE processo_judicial_id = ? AND id NOT IN ({$marcadores})";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$processoJudicialId], $idsMantidos));
    }

    public function contarPorProcesso(int $processoJudicialId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM partes_processuais WHERE processo_judicial_id = :id');
        $stmt->execute([':id' => $processoJudicialId]);
        return (int)$stmt->fetchColumn();
    }
}
