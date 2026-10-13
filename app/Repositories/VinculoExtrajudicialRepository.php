<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Tabela associativa processo_administrativo_extrajudicial (N:N), espelho
 * de VinculoRepository. A remocao de um vinculo nunca remove os processos.
 */
final class VinculoExtrajudicialRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    /** Processos extrajudiciais vinculados a um processo administrativo. */
    public function extrajudiciaisDoAdministrativo(int $administrativoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT v.id AS vinculo_id, v.data_vinculo, v.observacao,
                    pe.id, pe.protocolo, pe.data_expediente, pe.assunto, pe.situacao,
                    i.nome AS instituicao_nome, i.sigla AS instituicao_sigla, o.nome AS orgao_nome
               FROM processo_administrativo_extrajudicial v
               JOIN processos_extrajudiciais pe ON pe.id = v.processo_extrajudicial_id
               JOIN instituicoes_externas i ON i.id = pe.instituicao_externa_id
               LEFT JOIN orgaos_externos o ON o.id = pe.orgao_externo_id
              WHERE v.processo_administrativo_id = :id
              ORDER BY pe.data_expediente DESC, pe.id DESC'
        );
        $stmt->execute([':id' => $administrativoId]);
        return $stmt->fetchAll();
    }

    /** Processos administrativos vinculados a um processo extrajudicial. */
    public function administrativosDoExtrajudicial(int $extrajudicialId): array
    {
        $stmt = $this->db->prepare(
            'SELECT v.id AS vinculo_id, v.data_vinculo, v.observacao,
                    pa.id, pa.protocolo, pa.data_abertura, pa.objeto
               FROM processo_administrativo_extrajudicial v
               JOIN processos_administrativos pa ON pa.id = v.processo_administrativo_id
              WHERE v.processo_extrajudicial_id = :id
              ORDER BY pa.protocolo'
        );
        $stmt->execute([':id' => $extrajudicialId]);
        return $stmt->fetchAll();
    }

    public function existe(int $administrativoId, int $extrajudicialId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM processo_administrativo_extrajudicial
              WHERE processo_administrativo_id = :a AND processo_extrajudicial_id = :e'
        );
        $stmt->execute([':a' => $administrativoId, ':e' => $extrajudicialId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Cria o vinculo; devolve false quando ja existia (UNIQUE). */
    public function criar(int $administrativoId, int $extrajudicialId, ?int $autorId, ?string $observacao = null): bool
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO processo_administrativo_extrajudicial
                (processo_administrativo_id, processo_extrajudicial_id, data_vinculo, observacao, criado_por)
             VALUES (:a, :e, :d, :o, :autor)'
        );
        $stmt->execute([
            ':a' => $administrativoId, ':e' => $extrajudicialId, ':d' => date('Y-m-d'),
            ':o' => $observacao, ':autor' => $autorId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function remover(int $administrativoId, int $extrajudicialId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM processo_administrativo_extrajudicial
              WHERE processo_administrativo_id = :a AND processo_extrajudicial_id = :e'
        );
        $stmt->execute([':a' => $administrativoId, ':e' => $extrajudicialId]);
        return $stmt->rowCount() > 0;
    }

    /** Ids dos extrajudiciais de um administrativo. @return int[] */
    public function idsExtrajudiciais(int $administrativoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT processo_extrajudicial_id FROM processo_administrativo_extrajudicial WHERE processo_administrativo_id = :a'
        );
        $stmt->execute([':a' => $administrativoId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Ids dos administrativos de um extrajudicial. @return int[] */
    public function idsAdministrativos(int $extrajudicialId): array
    {
        $stmt = $this->db->prepare(
            'SELECT processo_administrativo_id FROM processo_administrativo_extrajudicial WHERE processo_extrajudicial_id = :e'
        );
        $stmt->execute([':e' => $extrajudicialId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
