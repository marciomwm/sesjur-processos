<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginacao;
use PDO;

/**
 * Entregadores: pessoas fisicas e juridicas que entregam o objeto (prestam
 * servicos ou fornecem bens) a Administracao. Sao o Credor nos Dados do
 * Pagamento do processo administrativo.
 */
final class EntregadorRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM entregadores WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Linha com bloqueio para atualizacao (usar em transacao). */
    public function buscarParaAtualizar(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM entregadores WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function documentoEmUso(string $documento, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT 1 FROM entregadores WHERE documento = :documento';
        $params = [':documento' => $documento];
        if ($ignorarId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $ignorarId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    public function inserir(array $dados, ?int $autorId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO entregadores (tipo_pessoa, documento, nome, criado_por, atualizado_por)
             VALUES (:tipo, :documento, :nome, :autor, :autor2)'
        );
        $stmt->execute([
            ':tipo'      => $dados['tipo_pessoa'],
            ':documento' => $dados['documento'],
            ':nome'      => $dados['nome'],
            ':autor'     => $autorId,
            ':autor2'    => $autorId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados, ?int $autorId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE entregadores SET tipo_pessoa = :tipo, documento = :documento, nome = :nome, atualizado_por = :autor
              WHERE id = :id'
        );
        $stmt->execute([
            ':tipo'      => $dados['tipo_pessoa'],
            ':documento' => $dados['documento'],
            ':nome'      => $dados['nome'],
            ':autor'     => $autorId,
            ':id'        => $id,
        ]);
    }

    /**
     * Listagem em ordem alfabetica, com a quantidade de pagamentos em que o
     * entregador e o credor.
     * @param array{busca?:string,tipo_pessoa?:string} $filtros
     */
    public function listar(array $filtros, int $pagina, int $porPagina): Paginacao
    {
        $condicoes = [];
        $params = [];

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $condicoes[] = '(e.nome LIKE :busca OR e.documento LIKE :documento)';
            $params[':busca'] = '%' . addcslashes($busca, '%_\\') . '%';
            $documento = preg_replace('/[^0-9A-Za-z]/', '', $busca) ?? '';
            $params[':documento'] = $documento !== '' ? '%' . strtoupper($documento) . '%' : '';
        }
        if (in_array($filtros['tipo_pessoa'] ?? '', ['FISICA', 'JURIDICA'], true)) {
            $condicoes[] = 'e.tipo_pessoa = :tipo';
            $params[':tipo'] = $filtros['tipo_pessoa'];
        }
        $where = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM entregadores e' . $where);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM processo_administrativo_pagamentos p WHERE p.entregador_id = e.id) AS total_pagamentos
               FROM entregadores e' . $where . '
              ORDER BY e.nome, e.id
              LIMIT :limite OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, ($pagina - 1) * $porPagina), PDO::PARAM_INT);
        $stmt->execute();

        return new Paginacao($stmt->fetchAll(), $total, $pagina, $porPagina);
    }

    /** Todos, em ordem alfabetica, para a escolha do Credor. */
    public function opcoes(): array
    {
        return $this->db->query('SELECT id, tipo_pessoa, documento, nome FROM entregadores ORDER BY nome, id')->fetchAll();
    }

    public function totalPagamentos(int $id): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM processo_administrativo_pagamentos WHERE entregador_id = :id');
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn();
    }
}
