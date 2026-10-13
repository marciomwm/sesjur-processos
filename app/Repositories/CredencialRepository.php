<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Credenciais de acesso (usuario_credenciais). O hash da senha so e lido
 * por aqui, nunca pelas consultas de cadastro.
 */
final class CredencialRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    /** Usuario + credencial para o login, pelo e-mail (ja normalizado). */
    public function buscarParaLogin(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.nome, u.email, u.ativo, u.perfil,
                    c.senha_hash, c.exigir_troca_senha, c.tentativas_login,
                    (c.bloqueado_ate IS NOT NULL AND c.bloqueado_ate > CURRENT_TIMESTAMP) AS bloqueado
               FROM usuarios u
               LEFT JOIN usuario_credenciais c ON c.usuario_id = u.id
              WHERE u.email = :email
              LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        return $stmt->fetch() ?: null;
    }

    public function buscar(int $usuarioId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM usuario_credenciais WHERE usuario_id = :id');
        $stmt->execute([':id' => $usuarioId]);
        return $stmt->fetch() ?: null;
    }

    /** Situacao do acesso para exibicao (sem o hash). */
    public function situacao(int $usuarioId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT exigir_troca_senha, tentativas_login, bloqueado_ate, ultimo_login, senha_alterada_em,
                    (bloqueado_ate IS NOT NULL AND bloqueado_ate > CURRENT_TIMESTAMP) AS bloqueado
               FROM usuario_credenciais WHERE usuario_id = :id'
        );
        $stmt->execute([':id' => $usuarioId]);
        return $stmt->fetch() ?: null;
    }

    /** Grava (ou substitui) a senha, zerando tentativas e bloqueio. */
    public function definirSenha(int $usuarioId, string $hash, bool $exigirTroca): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO usuario_credenciais (usuario_id, senha_hash, exigir_troca_senha, senha_alterada_em)
             VALUES (:id, :hash, :troca, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                senha_hash = VALUES(senha_hash),
                exigir_troca_senha = VALUES(exigir_troca_senha),
                senha_alterada_em = CURRENT_TIMESTAMP,
                tentativas_login = 0,
                bloqueado_ate = NULL'
        );
        $stmt->bindValue(':id', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue(':hash', $hash);
        $stmt->bindValue(':troca', $exigirTroca ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Atualiza apenas o hash (rehash transparente no login). */
    public function atualizarHash(int $usuarioId, string $hash): void
    {
        $stmt = $this->db->prepare('UPDATE usuario_credenciais SET senha_hash = :hash WHERE usuario_id = :id');
        $stmt->execute([':hash' => $hash, ':id' => $usuarioId]);
    }

    public function registrarSucesso(int $usuarioId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE usuario_credenciais
                SET tentativas_login = 0, bloqueado_ate = NULL, ultimo_login = CURRENT_TIMESTAMP
              WHERE usuario_id = :id'
        );
        $stmt->execute([':id' => $usuarioId]);
    }

    /**
     * Conta uma tentativa falha; ao atingir o limite, bloqueia por alguns
     * minutos e recomeca a contagem.
     */
    public function registrarFalha(int $usuarioId, int $maxTentativas, int $bloqueioMinutos): void
    {
        $stmt = $this->db->prepare(
            'UPDATE usuario_credenciais
                SET bloqueado_ate = CASE WHEN tentativas_login + 1 >= :max1
                                         THEN DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :minutos MINUTE)
                                         ELSE bloqueado_ate END,
                    tentativas_login = CASE WHEN tentativas_login + 1 >= :max2 THEN 0 ELSE tentativas_login + 1 END
              WHERE usuario_id = :id'
        );
        $stmt->bindValue(':max1', $maxTentativas, PDO::PARAM_INT);
        $stmt->bindValue(':max2', $maxTentativas, PDO::PARAM_INT);
        $stmt->bindValue(':minutos', $bloqueioMinutos, PDO::PARAM_INT);
        $stmt->bindValue(':id', $usuarioId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Ha ao menos um usuario com senha definida (para orientar a primeira instalacao). */
    public function existeAlguma(): bool
    {
        return (bool)$this->db->query('SELECT 1 FROM usuario_credenciais LIMIT 1')->fetchColumn();
    }
}
