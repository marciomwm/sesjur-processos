<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Acesso a dados da tabela associativa processo_administrativo_judicial.
 * A remocao de um vinculo nunca remove os processos envolvidos.
 */
final class VinculoRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    /** Processos judiciais vinculados a um processo administrativo. */
    public function judiciaisDoAdministrativo(int $administrativoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT v.id AS vinculo_id, v.data_vinculo, v.observacao,
                    pj.id, pj.numero_cnj, pj.tribunal, pj.orgao_julgador,
                    pj.classe_judicial, pj.situacao
               FROM processo_administrativo_judicial v
               JOIN processos_judiciais pj ON pj.id = v.processo_judicial_id
              WHERE v.processo_administrativo_id = :id
              ORDER BY pj.numero_cnj'
        );
        $stmt->execute([':id' => $administrativoId]);
        return $stmt->fetchAll();
    }

    /** Processos administrativos vinculados a um processo judicial. */
    public function administrativosDoJudicial(int $judicialId): array
    {
        $stmt = $this->db->prepare(
            'SELECT v.id AS vinculo_id, v.data_vinculo, v.observacao,
                    pa.id, pa.protocolo, pa.data_abertura, pa.objeto
               FROM processo_administrativo_judicial v
               JOIN processos_administrativos pa ON pa.id = v.processo_administrativo_id
              WHERE v.processo_judicial_id = :id
              ORDER BY pa.protocolo'
        );
        $stmt->execute([':id' => $judicialId]);
        return $stmt->fetchAll();
    }

    public function existe(int $administrativoId, int $judicialId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM processo_administrativo_judicial
              WHERE processo_administrativo_id = :a AND processo_judicial_id = :j LIMIT 1'
        );
        $stmt->execute([':a' => $administrativoId, ':j' => $judicialId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Cria o vinculo; ignora silenciosamente quando ja existe (UNIQUE). */
    public function criar(int $administrativoId, int $judicialId, ?string $observacao = null, ?string $dataVinculo = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO processo_administrativo_judicial
                (processo_administrativo_id, processo_judicial_id, data_vinculo, observacao)
             VALUES (:a, :j, :d, :o)'
        );
        $stmt->execute([
            ':a' => $administrativoId,
            ':j' => $judicialId,
            ':d' => $dataVinculo ?: date('Y-m-d'),
            ':o' => $observacao,
        ]);
        $id = (int)$this->db->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        $stmt = $this->db->prepare(
            'SELECT id FROM processo_administrativo_judicial
              WHERE processo_administrativo_id = :a AND processo_judicial_id = :j'
        );
        $stmt->execute([':a' => $administrativoId, ':j' => $judicialId]);
        return (int)$stmt->fetchColumn();
    }

    public function remover(int $administrativoId, int $judicialId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM processo_administrativo_judicial
              WHERE processo_administrativo_id = :a AND processo_judicial_id = :j'
        );
        $stmt->execute([':a' => $administrativoId, ':j' => $judicialId]);
        return $stmt->rowCount() > 0;
    }

    public function removerPorId(int $vinculoId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM processo_administrativo_judicial WHERE id = :id');
        $stmt->execute([':id' => $vinculoId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Sincroniza os vinculos de um processo administrativo com a lista informada.
     * @param int[] $judiciaisIds
     */
    public function sincronizarPorAdministrativo(int $administrativoId, array $judiciaisIds): void
    {
        $judiciaisIds = array_values(array_unique(array_filter(array_map('intval', $judiciaisIds))));

        if ($judiciaisIds === []) {
            $stmt = $this->db->prepare(
                'DELETE FROM processo_administrativo_judicial WHERE processo_administrativo_id = :a'
            );
            $stmt->execute([':a' => $administrativoId]);
            return;
        }

        $marcadores = implode(',', array_fill(0, count($judiciaisIds), '?'));
        $stmt = $this->db->prepare(
            "DELETE FROM processo_administrativo_judicial
              WHERE processo_administrativo_id = ? AND processo_judicial_id NOT IN ({$marcadores})"
        );
        $stmt->execute(array_merge([$administrativoId], $judiciaisIds));

        foreach ($judiciaisIds as $jid) {
            $this->criar($administrativoId, $jid);
        }
    }

    /**
     * Sincroniza os vinculos de um processo judicial com a lista informada.
     * @param int[] $administrativosIds
     */
    public function sincronizarPorJudicial(int $judicialId, array $administrativosIds): void
    {
        $administrativosIds = array_values(array_unique(array_filter(array_map('intval', $administrativosIds))));

        if ($administrativosIds === []) {
            $stmt = $this->db->prepare(
                'DELETE FROM processo_administrativo_judicial WHERE processo_judicial_id = :j'
            );
            $stmt->execute([':j' => $judicialId]);
            return;
        }

        $marcadores = implode(',', array_fill(0, count($administrativosIds), '?'));
        $stmt = $this->db->prepare(
            "DELETE FROM processo_administrativo_judicial
              WHERE processo_judicial_id = ? AND processo_administrativo_id NOT IN ({$marcadores})"
        );
        $stmt->execute(array_merge([$judicialId], $administrativosIds));

        foreach ($administrativosIds as $aid) {
            $this->criar($aid, $judicialId);
        }
    }

    public function contar(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM processo_administrativo_judicial')->fetchColumn();
    }
}
