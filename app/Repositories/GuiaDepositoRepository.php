<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/** Guias de Deposito Judicial emitidas nos Processos Judiciais. */
final class GuiaDepositoRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM guias_deposito_judicial WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Guias do processo, da emissao mais recente para a mais antiga. */
    public function listarPorProcesso(int $processoId): array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE g.processo_judicial_id = :id ORDER BY ' . self::ORDEM);
        $stmt->execute([':id' => $processoId]);
        return $stmt->fetchAll();
    }

    /** Todas as guias (painel). */
    public function listarTodas(): array
    {
        return $this->db->query(self::SELECT . ' ORDER BY ' . self::ORDEM)->fetchAll();
    }

    public function contarDoProcesso(int $processoId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM guias_deposito_judicial WHERE processo_judicial_id = :id');
        $stmt->execute([':id' => $processoId]);
        return (int)$stmt->fetchColumn();
    }

    public function inserir(int $processoId, array $dados, ?int $usuarioId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO guias_deposito_judicial
                (processo_judicial_id, numero, data_emissao, vencimento, valor, finalidade, status, data_pagamento, observacao, criado_por)
             VALUES (:processo, :numero, :emissao, :vencimento, :valor, :finalidade, :status, :pagamento, :observacao, :usuario)'
        );
        $stmt->execute([':processo' => $processoId, ':usuario' => $usuarioId] + self::parametros($dados));
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados, ?int $usuarioId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE guias_deposito_judicial
                SET numero = :numero, data_emissao = :emissao, vencimento = :vencimento, valor = :valor,
                    finalidade = :finalidade, status = :status, data_pagamento = :pagamento,
                    observacao = :observacao, atualizado_por = :usuario
              WHERE id = :id'
        );
        $stmt->execute([':id' => $id, ':usuario' => $usuarioId] + self::parametros($dados));
    }

    public function excluir(int $id): void
    {
        $this->db->prepare('DELETE FROM guias_deposito_judicial WHERE id = :id')->execute([':id' => $id]);
    }

    private const ORDEM = 'g.data_emissao DESC, g.id DESC';

    private const SELECT =
        'SELECT g.*, pj.numero_cnj, u.nome AS criado_por_nome
           FROM guias_deposito_judicial g
           JOIN processos_judiciais pj ON pj.id = g.processo_judicial_id
           LEFT JOIN usuarios u ON u.id = g.criado_por';

    private static function parametros(array $dados): array
    {
        return [
            ':numero'     => $dados['numero'],
            ':emissao'    => $dados['data_emissao'],
            ':vencimento' => $dados['vencimento'],
            ':valor'      => $dados['valor'],
            ':finalidade' => $dados['finalidade'],
            ':status'     => $dados['status'],
            ':pagamento'  => $dados['data_pagamento'],
            ':observacao' => $dados['observacao'],
        ];
    }
}
