<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginacao;
use PDO;

/**
 * Catalogo de Procedimentos do processo administrativo
 * (processos_administrativos.procedimento_id). Itens nunca sao excluidos:
 * sao inativados, preservando a classificacao dos processos.
 */
final class ProcedimentoRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM procedimentos WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Linha com bloqueio para atualizacao (usar em transacao). */
    public function buscarParaAtualizar(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM procedimentos WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** A collation (utf8mb4_unicode_ci) ja compara sem caixa e sem acentos. */
    public function nomeEmUso(string $nome, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT 1 FROM procedimentos WHERE nome = :nome';
        $params = [':nome' => $nome];
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
            'INSERT INTO procedimentos (nome, descricao, criado_por, atualizado_por)
             VALUES (:nome, :descricao, :autor, :autor2)'
        );
        $stmt->execute([
            ':nome'      => $dados['nome'],
            ':descricao' => $dados['descricao'],
            ':autor'     => $autorId,
            ':autor2'    => $autorId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados, ?int $autorId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE procedimentos SET nome = :nome, descricao = :descricao, atualizado_por = :autor WHERE id = :id'
        );
        $stmt->execute([
            ':nome'      => $dados['nome'],
            ':descricao' => $dados['descricao'],
            ':autor'     => $autorId,
            ':id'        => $id,
        ]);
    }

    public function definirAtivo(int $id, bool $ativo, ?int $autorId): void
    {
        $stmt = $this->db->prepare('UPDATE procedimentos SET ativo = :ativo, atualizado_por = :autor WHERE id = :id');
        $stmt->bindValue(':ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':autor', $autorId, $autorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Listagem de manutencao, em ordem alfabetica, com a quantidade de
     * processos que usam cada item.
     * @param array{busca?:string,status?:string} $filtros
     */
    public function listar(array $filtros, int $pagina, int $porPagina): Paginacao
    {
        $condicoes = [];
        $params = [];

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $condicoes[] = 'p.nome LIKE :busca';
            $params[':busca'] = '%' . addcslashes($busca, '%_\\') . '%';
        }
        $status = $filtros['status'] ?? 'todos';
        if ($status === 'ativos') {
            $condicoes[] = 'p.ativo = 1';
        } elseif ($status === 'inativos') {
            $condicoes[] = 'p.ativo = 0';
        }
        $where = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);
        $direcao = ($filtros['direcao'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM procedimentos p' . $where);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM processos_administrativos pa WHERE pa.procedimento_id = p.id) AS total_processos
               FROM procedimentos p' . $where . "
              ORDER BY p.nome {$direcao}, p.id
              LIMIT :limite OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, ($pagina - 1) * $porPagina), PDO::PARAM_INT);
        $stmt->execute();

        return new Paginacao($stmt->fetchAll(), $total, $pagina, $porPagina);
    }

    /** Itens ativos, em ordem alfabetica, para a escolha no processo. */
    public function opcoesAtivas(): array
    {
        return $this->db->query(
            'SELECT id, nome, descricao FROM procedimentos WHERE ativo = 1 ORDER BY nome'
        )->fetchAll();
    }

    /** Todos os itens (ativos e inativos), para filtros e relatorios historicos. */
    public function todos(): array
    {
        return $this->db->query('SELECT id, nome, ativo FROM procedimentos ORDER BY nome')->fetchAll();
    }

    public function existeAtivo(): bool
    {
        return (bool)$this->db->query('SELECT 1 FROM procedimentos WHERE ativo = 1 LIMIT 1')->fetchColumn();
    }

    public function totalProcessos(int $procedimentoId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM processos_administrativos WHERE procedimento_id = :id');
        $stmt->execute([':id' => $procedimentoId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Valores de texto livre (legado) de processos ainda sem item do
     * catalogo, exatamente como gravados (cada grafia em uma linha), com a
     * quantidade de processos. Base do relatorio de conciliacao.
     * @return list<array{procedimento:string,total:int}>
     */
    public function textosLegadosSemVinculo(): array
    {
        return $this->db->query(
            "SELECT procedimento COLLATE utf8mb4_bin AS procedimento, COUNT(*) AS total
               FROM processos_administrativos
              WHERE procedimento_id IS NULL AND procedimento IS NOT NULL AND TRIM(procedimento) <> ''
              GROUP BY procedimento COLLATE utf8mb4_bin
              ORDER BY procedimento COLLATE utf8mb4_bin"
        )->fetchAll();
    }

    /**
     * Item do catalogo cujo nome corresponde ao texto (ja com espacos
     * normalizados), sem distincao de caixa e acentos. O nome e unico nessa
     * comparacao, entao a correspondencia nunca e ambigua.
     */
    public function buscarPorNome(string $nome): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM procedimentos WHERE nome = :nome');
        $stmt->execute([':nome' => $nome]);
        return $stmt->fetch() ?: null;
    }

    /** Vincula ao item os processos sem classificacao cujo texto legado e exatamente o informado. */
    public function vincularTextoLegado(string $textoLegado, int $procedimentoId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE processos_administrativos SET procedimento_id = :id
              WHERE procedimento_id IS NULL AND procedimento COLLATE utf8mb4_bin = :texto'
        );
        $stmt->execute([':id' => $procedimentoId, ':texto' => $textoLegado]);
        return $stmt->rowCount();
    }
}
