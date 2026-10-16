<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Modelos do Gerador de Expedientes e as preferencias (favorito, uso) de
 * cada usuario sobre eles.
 */
final class ModeloExpedienteRepository
{
    /** Modelo com autor e as preferencias do usuario :eu. */
    private const SELECT_BASE =
        'SELECT m.*, u.nome AS criado_por_nome,
                COALESCE(p.favorito, 0) AS favorito, COALESCE(p.usos, 0) AS usos, p.ultimo_uso_em
           FROM expediente_modelos m
           LEFT JOIN usuarios u ON u.id = m.criado_por
           LEFT JOIN expediente_modelo_usuarios p ON p.modelo_id = m.id AND p.usuario_id = :eu';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id, int $usuarioId = 0): ?array
    {
        $stmt = $this->db->prepare(self::SELECT_BASE . ' WHERE m.id = :id');
        $stmt->execute([':id' => $id, ':eu' => $usuarioId]);
        return $stmt->fetch() ?: null;
    }

    /** Os modelos que o usuario ve: os proprios e os compartilhados. */
    public function visiveisPara(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            self::SELECT_BASE . " WHERE m.criado_por = :dono OR m.visibilidade = 'COMPARTILHADO'
                                  ORDER BY m.nome, m.id"
        );
        $stmt->execute([':eu' => $usuarioId, ':dono' => $usuarioId]);
        return $stmt->fetchAll();
    }

    /**
     * Ha outro modelo com o mesmo nome entre os que o usuario ve (os dele e
     * os compartilhados) ou, se o modelo for compartilhado, entre todos os
     * compartilhados.
     */
    public function nomeEmUso(string $nome, int $usuarioId, ?int $ignorarId = null): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM expediente_modelos
              WHERE nome = :nome AND id <> :ignorar
                AND (criado_por = :dono OR visibilidade = 'COMPARTILHADO')
              LIMIT 1"
        );
        $stmt->execute([':nome' => $nome, ':ignorar' => $ignorarId ?? 0, ':dono' => $usuarioId]);
        return (bool)$stmt->fetchColumn();
    }

    public function inserir(array $dados): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO expediente_modelos (nome, tipo, descricao, conteudo, visibilidade, criado_por)
             VALUES (:nome, :tipo, :descricao, :conteudo, :visibilidade, :criado_por)'
        );
        $stmt->execute([
            ':nome'         => $dados['nome'],
            ':tipo'         => $dados['tipo'],
            ':descricao'    => $dados['descricao'],
            ':conteudo'     => $dados['conteudo'],
            ':visibilidade' => $dados['visibilidade'],
            ':criado_por'   => $dados['criado_por'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $stmt = $this->db->prepare(
            'UPDATE expediente_modelos
                SET nome = :nome, tipo = :tipo, descricao = :descricao, conteudo = :conteudo,
                    visibilidade = :visibilidade, atualizado_por = :atualizado_por
              WHERE id = :id'
        );
        $stmt->execute([
            ':nome'           => $dados['nome'],
            ':tipo'           => $dados['tipo'],
            ':descricao'      => $dados['descricao'],
            ':conteudo'       => $dados['conteudo'],
            ':visibilidade'   => $dados['visibilidade'],
            ':atualizado_por' => $dados['atualizado_por'],
            ':id'             => $id,
        ]);
    }

    public function excluir(int $id): void
    {
        $this->db->prepare('DELETE FROM expediente_modelos WHERE id = :id')->execute([':id' => $id]);
    }

    public function marcarFavorito(int $modeloId, int $usuarioId, bool $favorito): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO expediente_modelo_usuarios (modelo_id, usuario_id, favorito)
             VALUES (:modelo, :usuario, :favorito)
             ON DUPLICATE KEY UPDATE favorito = VALUES(favorito)'
        );
        $stmt->execute([':modelo' => $modeloId, ':usuario' => $usuarioId, ':favorito' => $favorito ? 1 : 0]);
    }

    public function registrarUso(int $modeloId, int $usuarioId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO expediente_modelo_usuarios (modelo_id, usuario_id, usos, ultimo_uso_em)
             VALUES (:modelo, :usuario, 1, NOW())
             ON DUPLICATE KEY UPDATE usos = usos + 1, ultimo_uso_em = NOW()'
        );
        $stmt->execute([':modelo' => $modeloId, ':usuario' => $usuarioId]);
    }
}
