<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Procedimentos da Tabela SIGTAP. Hoje cadastrados manualmente a partir dos
 * produtos de saude; a mesma tabela recebera a importacao oficial
 * (origem SIGTAP, com a competencia).
 */
final class SigtapRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sigtap_procedimentos WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function buscarPorCodigo(string $codigo): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sigtap_procedimentos WHERE codigo = :c');
        $stmt->execute([':c' => $codigo]);
        return $stmt->fetch() ?: null;
    }

    public function inserir(array $dados, ?int $autorId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO sigtap_procedimentos
                (codigo, nome, grupo_codigo, grupo_nome, subgrupo_codigo, subgrupo_nome,
                 forma_organizacao_codigo, forma_organizacao_nome, origem, criado_por, atualizado_por)
             VALUES (:codigo, :nome, :gc, :gn, :sc, :sn, :fc, :fn, :origem, :autor, :autor2)'
        );
        $stmt->execute([
            ':codigo' => $dados['codigo'],
            ':nome'   => $dados['nome'],
            ':gc'     => substr($dados['codigo'], 0, 2),
            ':gn'     => $dados['grupo_nome'] ?? null,
            ':sc'     => substr($dados['codigo'], 2, 2),
            ':sn'     => $dados['subgrupo_nome'] ?? null,
            ':fc'     => substr($dados['codigo'], 4, 2),
            ':fn'     => $dados['forma_organizacao_nome'] ?? null,
            ':origem' => 'MANUAL',
            ':autor'  => $autorId,
            ':autor2' => $autorId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Completa, num procedimento manual, os nomes da estrutura ainda vazios
     * (nunca sobrescreve o que ja foi informado nem os importados).
     */
    public function completarEstrutura(int $id, array $nomes, ?int $autorId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE sigtap_procedimentos
                SET grupo_nome = COALESCE(grupo_nome, :gn),
                    subgrupo_nome = COALESCE(subgrupo_nome, :sn),
                    forma_organizacao_nome = COALESCE(forma_organizacao_nome, :fn),
                    atualizado_por = :autor
              WHERE id = :id AND origem = 'MANUAL'
                AND ((grupo_nome IS NULL AND :gn2 IS NOT NULL)
                  OR (subgrupo_nome IS NULL AND :sn2 IS NOT NULL)
                  OR (forma_organizacao_nome IS NULL AND :fn2 IS NOT NULL))"
        );
        $stmt->execute([
            ':gn' => $nomes['grupo_nome'] ?? null, ':gn2' => $nomes['grupo_nome'] ?? null,
            ':sn' => $nomes['subgrupo_nome'] ?? null, ':sn2' => $nomes['subgrupo_nome'] ?? null,
            ':fn' => $nomes['forma_organizacao_nome'] ?? null, ':fn2' => $nomes['forma_organizacao_nome'] ?? null,
            ':autor' => $autorId,
            ':id' => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Sugestoes por codigo (com ou sem mascara) ou pela descricao. */
    public function pesquisar(string $termo, int $limite = 10): array
    {
        $digitos = preg_replace('/\D+/', '', $termo) ?? '';
        $like = '%' . addcslashes(trim($termo), '%_\\') . '%';
        $stmt = $this->db->prepare(
            'SELECT id, codigo, nome, grupo_nome, subgrupo_nome, forma_organizacao_nome
               FROM sigtap_procedimentos
              WHERE ativo = 1 AND (nome LIKE :like' . ($digitos !== '' ? ' OR codigo LIKE :codigo' : '') . ')
              ORDER BY nome
              LIMIT :limite'
        );
        $stmt->bindValue(':like', $like);
        if ($digitos !== '') {
            $stmt->bindValue(':codigo', $digitos . '%');
        }
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
