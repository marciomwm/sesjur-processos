<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Clientes da API de Integracao (api_clientes) e registro das chamadas
 * (api_requisicoes). O hash do token so e lido por aqui.
 */
final class ApiClienteRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function inserir(array $dados): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO api_clientes
                (nome, responsavel_usuario_id, token_prefixo, token_hash, escopos, ips_permitidos, expira_em, observacao)
             VALUES (:nome, :responsavel, :prefixo, :hash, :escopos, :ips, :expira, :obs)'
        );
        $stmt->execute([
            ':nome'        => $dados['nome'],
            ':responsavel' => $dados['responsavel_usuario_id'],
            ':prefixo'     => $dados['token_prefixo'],
            ':hash'        => $dados['token_hash'],
            ':escopos'     => json_encode(array_values($dados['escopos'])),
            ':ips'         => $dados['ips_permitidos'],
            ':expira'      => $dados['expira_em'],
            ':obs'         => $dados['observacao'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** Cliente pelo prefixo publico do token, com o hash para conferencia. */
    public function buscarPorPrefixo(string $prefixo): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, u.nome AS responsavel_nome
               FROM api_clientes c
               LEFT JOIN usuarios u ON u.id = c.responsavel_usuario_id
              WHERE c.token_prefixo = :prefixo'
        );
        $stmt->execute([':prefixo' => $prefixo]);
        return $stmt->fetch() ?: null;
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM api_clientes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Todos os clientes, sem o hash do token. */
    public function listar(): array
    {
        return $this->db->query(
            'SELECT c.id, c.nome, c.token_prefixo, c.escopos, c.ips_permitidos, c.ativo, c.expira_em,
                    c.ultimo_uso_em, c.ultimo_ip, c.revogado_em, c.created_at, u.nome AS responsavel_nome
               FROM api_clientes c
               LEFT JOIN usuarios u ON u.id = c.responsavel_usuario_id
              ORDER BY c.id'
        )->fetchAll();
    }

    public function revogar(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE api_clientes SET ativo = 0, revogado_em = CURRENT_TIMESTAMP WHERE id = :id AND ativo = 1'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /** Troca o token (o anterior deixa de valer na hora) e reativa o cliente. */
    public function trocarToken(int $id, string $prefixo, string $hash): void
    {
        $stmt = $this->db->prepare(
            'UPDATE api_clientes
                SET token_prefixo = :prefixo, token_hash = :hash, ativo = 1, revogado_em = NULL
              WHERE id = :id'
        );
        $stmt->execute([':prefixo' => $prefixo, ':hash' => $hash, ':id' => $id]);
    }

    public function registrarUso(int $id, ?string $ip): void
    {
        $stmt = $this->db->prepare(
            'UPDATE api_clientes SET ultimo_uso_em = CURRENT_TIMESTAMP, ultimo_ip = :ip WHERE id = :id'
        );
        $stmt->execute([':ip' => $ip, ':id' => $id]);
    }

    /** Chamadas do cliente nos ultimos $segundos (limite de requisicoes). */
    public function contarRecentes(int $clienteId, int $segundos): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM api_requisicoes
              WHERE cliente_id = :id AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL :seg SECOND)'
        );
        $stmt->bindValue(':id', $clienteId, PDO::PARAM_INT);
        $stmt->bindValue(':seg', $segundos, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /** Falhas de autenticacao do IP nos ultimos $segundos (protege contra tentativa de adivinhar tokens). */
    public function contarFalhasDoIp(string $ip, int $segundos): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM api_requisicoes
              WHERE ip = :ip AND status_http = 401 AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL :seg SECOND)'
        );
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':seg', $segundos, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function registrarRequisicao(array $r): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO api_requisicoes (cliente_id, metodo, recurso, parametros, status_http, ip, user_agent, duracao_ms)
             VALUES (:cliente, :metodo, :recurso, :parametros, :status, :ip, :ua, :duracao)'
        );
        $stmt->execute([
            ':cliente'    => $r['cliente_id'],
            ':metodo'     => mb_substr($r['metodo'], 0, 10),
            ':recurso'    => mb_substr($r['recurso'], 0, 60),
            ':parametros' => $r['parametros'] !== null ? mb_substr($r['parametros'], 0, 500) : null,
            ':status'     => $r['status_http'],
            ':ip'         => $r['ip'],
            ':ua'         => $r['user_agent'] !== null ? mb_substr($r['user_agent'], 0, 255) : null,
            ':duracao'    => $r['duracao_ms'],
        ]);
    }
}
