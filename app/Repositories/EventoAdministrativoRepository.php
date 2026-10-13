<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Acesso a dados de processo_administrativo_eventos.
 * As movimentacoes sao um espelho do SIGADOC: a cada extracao confirmada a
 * lista e substituida integralmente, preservando a ordem da fonte.
 */
final class EventoAdministrativoRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    /** Movimentacoes do processo, da mais recente para a mais antiga. */
    public function listarPorProcesso(int $processoAdministrativoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM processo_administrativo_eventos
              WHERE processo_administrativo_id = :id
              ORDER BY ordem, id'
        );
        $stmt->execute([':id' => $processoAdministrativoId]);
        return $stmt->fetchAll();
    }

    public function contarPorProcesso(int $processoAdministrativoId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM processo_administrativo_eventos WHERE processo_administrativo_id = :id');
        $stmt->execute([':id' => $processoAdministrativoId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Substitui todas as movimentacoes do processo. Deve ser chamado dentro
     * de uma transacao (ver ProcessoAdministrativoService).
     *
     * @param list<array<string, ?string>> $eventos ja normalizados (Normalizador::eventos)
     */
    public function substituir(int $processoAdministrativoId, array $eventos): void
    {
        $stmt = $this->db->prepare('DELETE FROM processo_administrativo_eventos WHERE processo_administrativo_id = :id');
        $stmt->execute([':id' => $processoAdministrativoId]);

        if ($eventos === []) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO processo_administrativo_eventos
                (processo_administrativo_id, data_evento, tipo, tipo_descricao, lotacao, responsavel,
                 atendente_lotacao, atendente, documento, descricao, ordem)
             VALUES (:pid, :data_evento, :tipo, :tipo_descricao, :lotacao, :responsavel,
                     :atendente_lotacao, :atendente, :documento, :descricao, :ordem)'
        );
        foreach (array_values($eventos) as $ordem => $e) {
            $stmt->execute([
                ':pid'               => $processoAdministrativoId,
                ':data_evento'       => $e['data_evento'] ?? null,
                ':tipo'              => $e['tipo'] ?? 'OUTRO',
                ':tipo_descricao'    => $e['tipo_descricao'] ?? null,
                ':lotacao'           => $e['lotacao'] ?? null,
                ':responsavel'       => $e['responsavel'] ?? null,
                ':atendente_lotacao' => $e['atendente_lotacao'] ?? null,
                ':atendente'         => $e['atendente'] ?? null,
                ':documento'         => $e['documento'] ?? null,
                ':descricao'         => $e['descricao'] ?? null,
                ':ordem'             => $ordem,
            ]);
        }
    }
}
