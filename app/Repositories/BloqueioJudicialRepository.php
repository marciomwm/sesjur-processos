<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Lancamentos de Bloqueio e Desbloqueio de valores dos Processos Judiciais.
 * O Saldo retido nao e gravado: e sempre a soma dos lancamentos.
 */
final class BloqueioJudicialRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM bloqueios_judiciais WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Lancamentos do processo, do mais antigo ao mais recente (ordem do saldo). */
    public function listarPorProcesso(int $processoId): array
    {
        $stmt = $this->db->prepare(
            self::SELECT . ' WHERE b.processo_judicial_id = :id ORDER BY ' . self::ORDEM_CRONOLOGICA
        );
        $stmt->execute([':id' => $processoId]);
        return $stmt->fetchAll();
    }

    /** Todos os lancamentos (painel), em ordem cronologica. */
    public function listarTodos(): array
    {
        return $this->db->query(self::SELECT . ' ORDER BY ' . self::ORDEM_CRONOLOGICA)->fetchAll();
    }

    public function contarDoProcesso(int $processoId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM bloqueios_judiciais WHERE processo_judicial_id = :id');
        $stmt->execute([':id' => $processoId]);
        return (int)$stmt->fetchColumn();
    }

    public function inserir(int $processoId, array $dados, ?int $usuarioId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO bloqueios_judiciais
                (processo_judicial_id, tipo, data_lancamento, valor, identificador, observacao, criado_por)
             VALUES (:processo, :tipo, :data, :valor, :identificador, :observacao, :usuario)'
        );
        $stmt->execute([':processo' => $processoId, ':usuario' => $usuarioId] + self::parametros($dados));
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados, ?int $usuarioId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE bloqueios_judiciais
                SET tipo = :tipo, data_lancamento = :data, valor = :valor, identificador = :identificador,
                    observacao = :observacao, atualizado_por = :usuario
              WHERE id = :id'
        );
        $stmt->execute([':id' => $id, ':usuario' => $usuarioId] + self::parametros($dados));
    }

    public function excluir(int $id): void
    {
        $this->db->prepare('DELETE FROM bloqueios_judiciais WHERE id = :id')->execute([':id' => $id]);
    }

    /** No mesmo dia, o bloqueio vem antes do desbloqueio (o valor so sai depois de entrar). */
    private const ORDEM_CRONOLOGICA = "b.data_lancamento, b.tipo = 'DESBLOQUEIO', b.id";

    private const SELECT =
        'SELECT b.*, pj.numero_cnj, u.nome AS criado_por_nome
           FROM bloqueios_judiciais b
           JOIN processos_judiciais pj ON pj.id = b.processo_judicial_id
           LEFT JOIN usuarios u ON u.id = b.criado_por';

    private static function parametros(array $dados): array
    {
        return [
            ':tipo'          => $dados['tipo'],
            ':data'          => $dados['data_lancamento'],
            ':valor'         => $dados['valor'],
            ':identificador' => $dados['identificador'],
            ':observacao'    => $dados['observacao'],
        ];
    }
}
