<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Trilha de auditoria. Somente insercao e leitura: registros de auditoria
 * nunca sao alterados nem excluidos pela aplicacao.
 */
final class AuditoriaRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function inserir(array $registro): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO auditoria
                (usuario_id, acao, entidade, entidade_id, dados_anteriores, dados_novos, ip, user_agent)
             VALUES
                (:usuario_id, :acao, :entidade, :entidade_id, :dados_anteriores, :dados_novos, :ip, :user_agent)'
        );
        $stmt->execute([
            ':usuario_id'       => $registro['usuario_id'] ?? null,
            ':acao'             => $registro['acao'],
            ':entidade'         => $registro['entidade'],
            ':entidade_id'      => $registro['entidade_id'] ?? null,
            ':dados_anteriores' => $registro['dados_anteriores'] ?? null,
            ':dados_novos'      => $registro['dados_novos'] ?? null,
            ':ip'               => $registro['ip'] ?? null,
            ':user_agent'       => $registro['user_agent'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Historico de um registro, do mais recente ao mais antigo.
     * @param list<string> $somenteAcoes restringe as acoes (vazio = todas)
     */
    public function listarPorEntidade(string $entidade, int $entidadeId, int $limite = 50, array $somenteAcoes = []): array
    {
        $filtroAcoes = '';
        foreach (array_values($somenteAcoes) as $i => $acao) {
            $filtroAcoes .= ($i === 0 ? '' : ', ') . ':acao' . $i;
        }
        $stmt = $this->db->prepare(
            'SELECT a.*, u.nome AS autor_nome
               FROM auditoria a
               LEFT JOIN usuarios u ON u.id = a.usuario_id
              WHERE a.entidade = :entidade AND a.entidade_id = :entidade_id'
              . ($filtroAcoes !== '' ? " AND a.acao IN ({$filtroAcoes})" : '') . '
              ORDER BY a.id DESC
              LIMIT :limite'
        );
        foreach (array_values($somenteAcoes) as $i => $acao) {
            $stmt->bindValue(':acao' . $i, $acao);
        }
        $stmt->bindValue(':entidade', $entidade);
        $stmt->bindValue(':entidade_id', $entidadeId, PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Eventos de uma entidade sem registro proprio (ex.: configuracoes), do mais recente ao mais antigo. */
    public function listarPorEntidadeSemId(string $entidade, int $limite = 30): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*, u.nome AS autor_nome
               FROM auditoria a
               LEFT JOIN usuarios u ON u.id = a.usuario_id
              WHERE a.entidade = :entidade AND a.entidade_id IS NULL
              ORDER BY a.id DESC
              LIMIT :limite'
        );
        $stmt->bindValue(':entidade', $entidade);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
