<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Recursos da Central de Recursos: links para sites, sistemas, documentos
 * e paineis, com uma Pasta (tipo) e nenhuma, uma ou varias Areas (categorias).
 */
final class RecursoRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM recursos WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Todos os recursos (do mais recente ao mais antigo), com o nome do
     * autor e os ids das categorias. O volume e de dezenas de itens: a
     * pagina filtra e agrupa no navegador.
     */
    public function listar(): array
    {
        $itens = $this->db->query(
            'SELECT r.*, u.nome AS criado_por_nome, u.email AS criado_por_email
               FROM recursos r
               LEFT JOIN usuarios u ON u.id = r.criado_por
              ORDER BY r.created_at DESC, r.id DESC'
        )->fetchAll();

        $categorias = [];
        foreach ($this->db->query('SELECT recurso_id, categoria_id FROM recurso_categorias ORDER BY id') as $linha) {
            $categorias[(int)$linha['recurso_id']][] = (int)$linha['categoria_id'];
        }
        foreach ($itens as &$item) {
            $item['categorias'] = $categorias[(int)$item['id']] ?? [];
        }
        return $itens;
    }

    /** @return list<int> */
    public function categorias(int $recursoId): array
    {
        $stmt = $this->db->prepare('SELECT categoria_id FROM recurso_categorias WHERE recurso_id = :id ORDER BY categoria_id');
        $stmt->execute([':id' => $recursoId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function inserir(array $dados, ?int $usuarioId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO recursos (titulo, descricao, url, tipo_id, criado_por)
             VALUES (:titulo, :descricao, :url, :tipo, :usuario)'
        );
        $stmt->execute([
            ':titulo'    => $dados['titulo'],
            ':descricao' => $dados['descricao'],
            ':url'       => $dados['url'],
            ':tipo'      => $dados['tipo_id'],
            ':usuario'   => $usuarioId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados, ?int $usuarioId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE recursos
                SET titulo = :titulo, descricao = :descricao, url = :url, tipo_id = :tipo, atualizado_por = :usuario
              WHERE id = :id'
        );
        $stmt->execute([
            ':titulo'    => $dados['titulo'],
            ':descricao' => $dados['descricao'],
            ':url'       => $dados['url'],
            ':tipo'      => $dados['tipo_id'],
            ':usuario'   => $usuarioId,
            ':id'        => $id,
        ]);
    }

    /** Substitui as categorias do recurso (chamar dentro de transacao). */
    public function substituirCategorias(int $recursoId, array $categoriaIds): void
    {
        $this->db->prepare('DELETE FROM recurso_categorias WHERE recurso_id = :id')->execute([':id' => $recursoId]);
        $stmt = $this->db->prepare('INSERT INTO recurso_categorias (recurso_id, categoria_id) VALUES (:recurso, :categoria)');
        foreach ($categoriaIds as $categoriaId) {
            $stmt->execute([':recurso' => $recursoId, ':categoria' => $categoriaId]);
        }
    }

    public function excluir(int $id): void
    {
        $this->db->prepare('DELETE FROM recursos WHERE id = :id')->execute([':id' => $id]);
    }
}
