<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Acesso a dados de assuntos_processuais (assunto principal + secundarios).
 */
final class AssuntoProcessualRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function listarPorProcesso(int $processoJudicialId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM assuntos_processuais
              WHERE processo_judicial_id = :id
              ORDER BY principal DESC, ordem, id'
        );
        $stmt->execute([':id' => $processoJudicialId]);
        return $stmt->fetchAll();
    }

    public function inserir(int $processoJudicialId, string $descricao, bool $principal, int $ordem = 0, ?string $codigo = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO assuntos_processuais (processo_judicial_id, descricao, codigo, principal, ordem)
             VALUES (:pid, :descricao, :codigo, :principal, :ordem)'
        );
        $stmt->execute([
            ':pid'       => $processoJudicialId,
            ':descricao' => $descricao,
            ':codigo'    => $codigo,
            ':principal' => $principal ? 1 : 0,
            ':ordem'     => $ordem,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** Substitui integralmente os assuntos do processo (usado dentro de transacao). */
    public function substituir(int $processoJudicialId, array $assuntos): void
    {
        $stmt = $this->db->prepare('DELETE FROM assuntos_processuais WHERE processo_judicial_id = :pid');
        $stmt->execute([':pid' => $processoJudicialId]);

        $ordem = 0;
        foreach ($assuntos as $assunto) {
            $descricao = trim((string)($assunto['descricao'] ?? ''));
            if ($descricao === '') {
                continue;
            }
            $this->inserir(
                $processoJudicialId,
                $descricao,
                (bool)($assunto['principal'] ?? false),
                $ordem++,
                $assunto['codigo'] ?? null
            );
        }
    }

    /** Codigo CNJ do assunto principal, quando a fonte o exibiu. */
    public function codigoPrincipal(int $processoJudicialId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT codigo FROM assuntos_processuais
              WHERE processo_judicial_id = :id AND principal = 1
              ORDER BY ordem, id LIMIT 1'
        );
        $stmt->execute([':id' => $processoJudicialId]);
        $codigo = $stmt->fetchColumn();
        return $codigo === false || $codigo === null ? null : (string)$codigo;
    }

    /**
     * Assuntos secundarios. Quando ha codigo CNJ, ele volta entre parenteses,
     * no mesmo formato exibido pelo PJe — assim o textarea do formulario faz o
     * round-trip sem perder o codigo ao salvar.
     *
     * @return list<string>
     */
    public function secundarios(int $processoJudicialId): array
    {
        $stmt = $this->db->prepare(
            'SELECT descricao, codigo FROM assuntos_processuais
              WHERE processo_judicial_id = :id AND principal = 0
              ORDER BY ordem, id'
        );
        $stmt->execute([':id' => $processoJudicialId]);

        return array_map(
            static fn(array $a): string => $a['codigo'] !== null && $a['codigo'] !== ''
                ? $a['descricao'] . ' (' . $a['codigo'] . ')'
                : $a['descricao'],
            $stmt->fetchAll()
        );
    }
}
