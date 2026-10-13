<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Acesso a dados de processo_judicial_movimentacoes.
 * A cronologia e um espelho do PJe: a cada extracao confirmada a lista e
 * substituida integralmente, preservando a ordem da fonte.
 */
final class MovimentacaoJudicialRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    /** Movimentacoes do processo, da mais recente para a mais antiga. */
    public function listarPorProcesso(int $processoJudicialId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM processo_judicial_movimentacoes
              WHERE processo_judicial_id = :id
              ORDER BY ordem, id'
        );
        $stmt->execute([':id' => $processoJudicialId]);
        return $stmt->fetchAll();
    }

    public function contarPorProcesso(int $processoJudicialId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM processo_judicial_movimentacoes WHERE processo_judicial_id = :id');
        $stmt->execute([':id' => $processoJudicialId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Substitui toda a cronologia do processo. Deve ser chamado dentro de uma
     * transacao (ver ProcessoJudicialService).
     *
     * @param list<array<string, mixed>> $movimentacoes ja normalizadas
     *        (Normalizador::movimentacoes)
     * @return list<int> ids inseridos, na ordem recebida (indice = ordem)
     */
    public function substituir(int $processoJudicialId, array $movimentacoes): array
    {
        $stmt = $this->db->prepare('DELETE FROM processo_judicial_movimentacoes WHERE processo_judicial_id = :id');
        $stmt->execute([':id' => $processoJudicialId]);

        if ($movimentacoes === []) {
            return [];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO processo_judicial_movimentacoes
                (processo_judicial_id, data_movimentacao, hora, tipo, descricao,
                 documento_id, documento_tipo, natureza, origem, ordem)
             VALUES (:pid, :data_movimentacao, :hora, :tipo, :descricao,
                     :documento_id, :documento_tipo, :natureza, :origem, :ordem)'
        );
        $ids = [];
        foreach (array_values($movimentacoes) as $ordem => $m) {
            $stmt->execute([
                ':pid'               => $processoJudicialId,
                ':data_movimentacao' => $m['data_movimentacao'] ?? null,
                ':hora'              => $m['hora'] ?? null,
                ':tipo'              => $m['tipo'] ?? 'MOVIMENTO',
                ':descricao'         => $m['descricao'] ?? null,
                ':documento_id'      => $m['documento_id'] ?? null,
                ':documento_tipo'    => $m['documento_tipo'] ?? null,
                ':natureza'          => $m['natureza'] ?? null,
                ':origem'            => $m['origem'] ?? null,
                ':ordem'             => $ordem,
            ]);
            $ids[] = (int)$this->db->lastInsertId();
        }
        return $ids;
    }
}
