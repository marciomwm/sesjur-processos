<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Base das tabelas de apoio do cadastro de usuarios (cargos, lotacoes).
 * Itens nunca sao excluidos: sao inativados, preservando os vinculos.
 * O nome da tabela vem de constante da subclasse, nunca do usuario.
 */
abstract class TabelaApoioRepository
{
    protected const TABELA = '';
    /** Tabela que referencia o item (usuarios, lotacoes) e a coluna da FK nela. */
    protected const TABELA_VINCULO = 'usuarios';
    protected const COLUNA_USUARIO = '';
    /** Colunas gravaveis alem de `nome`. */
    protected const CAMPOS_EXTRAS = [];
    /**
     * Item subordinado a outra tabela de apoio (ex.: orgao externo de uma
     * instituicao externa): coluna da FK e tabela do pai. O nome passa a ser
     * unico dentro do pai. A coluna deve constar tambem em CAMPOS_EXTRAS.
     */
    protected const COLUNA_PAI = '';
    protected const TABELA_PAI = '';
    /** Como contar os vinculados ativos (a tabela vinculada pode nao ter `ativo`). */
    protected const CONDICAO_VINCULO_ATIVO = 'u.ativo = 1';

    protected PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . static::TABELA . ' WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function buscarParaAtualizar(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . static::TABELA . ' WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Opcoes para o formulario de usuario: itens ativos e, na edicao, tambem
     * o item ja gravado no usuario (mesmo que tenha sido inativado depois).
     */
    public function opcoes(?int $incluirId = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nome, ativo' . $this->colunaSigla() . ' FROM ' . static::TABELA . '
              WHERE ativo = 1 OR id = :incluir
              ORDER BY nome'
        );
        $stmt->bindValue(':incluir', $incluirId ?? 0, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Todos os itens, inclusive inativos (filtros da listagem de usuarios). */
    public function todos(): array
    {
        return $this->db->query('SELECT id, nome, ativo' . $this->colunaSigla() . ' FROM ' . static::TABELA . ' ORDER BY nome')->fetchAll();
    }

    /** A sigla entra nas opcoes quando a tabela a tem. */
    private function colunaSigla(): string
    {
        return in_array('sigla', static::CAMPOS_EXTRAS, true) ? ', sigla' : '';
    }

    /**
     * Listagem de manutencao, com a quantidade de usuarios vinculados.
     * @param array{busca?:string,status?:string,pai_id?:int|null} $filtros
     */
    public function listar(array $filtros): array
    {
        $condicoes = [];
        $params = [];

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $termo = '%' . addcslashes($busca, '\\%_') . '%';
            $colunasBusca = array_diff(array_merge(['nome'], static::CAMPOS_EXTRAS), [static::COLUNA_PAI]);
            $partes = [];
            foreach ($colunasBusca as $coluna) {
                $partes[] = "t.{$coluna} LIKE :busca_{$coluna}";
                $params[':busca_' . $coluna] = $termo;
            }
            $condicoes[] = '(' . implode(' OR ', $partes) . ')';
        }

        if (static::COLUNA_PAI !== '' && !empty($filtros['pai_id'])) {
            $condicoes[] = 't.' . static::COLUNA_PAI . ' = :pai';
            $params[':pai'] = (int)$filtros['pai_id'];
        }

        $status = $filtros['status'] ?? 'todos';
        if ($status === 'ativos') {
            $condicoes[] = 't.ativo = 1';
        } elseif ($status === 'inativos') {
            $condicoes[] = 't.ativo = 0';
        }

        $where = $condicoes === [] ? '' : 'WHERE ' . implode(' AND ', $condicoes);
        $pai = static::COLUNA_PAI !== '';
        $stmt = $this->db->prepare(
            'SELECT t.*,' . ($pai ? ' p.nome AS pai_nome, p.sigla AS pai_sigla,' : '') . '
                    COUNT(u.id) AS total_usuarios,
                    COALESCE(SUM(' . static::CONDICAO_VINCULO_ATIVO . '), 0) AS usuarios_ativos
               FROM ' . static::TABELA . ' t' . ($pai ? '
               JOIN ' . static::TABELA_PAI . ' p ON p.id = t.' . static::COLUNA_PAI : '') . '
               LEFT JOIN ' . static::TABELA_VINCULO . ' u ON u.' . static::COLUNA_USUARIO . ' = t.id
              ' . $where . '
              GROUP BY t.id
              ORDER BY ' . ($pai ? 'p.nome, ' : '') . 't.nome'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @param int|null $paiId com COLUNA_PAI, o nome so conflita dentro do mesmo pai */
    public function valorEmUso(string $coluna, string $valor, ?int $ignorarId = null, ?int $paiId = null): bool
    {
        if (!in_array($coluna, array_merge(['nome'], static::CAMPOS_EXTRAS), true)) {
            throw new \InvalidArgumentException('Coluna não permitida.');
        }
        $sql = 'SELECT 1 FROM ' . static::TABELA . " WHERE {$coluna} = :valor";
        $params = [':valor' => $valor];
        if ($coluna === 'nome' && static::COLUNA_PAI !== '') {
            $sql .= ' AND ' . static::COLUNA_PAI . ' <=> :pai';
            $params[':pai'] = $paiId;
        }
        if ($ignorarId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $ignorarId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    public function inserir(array $dados): int
    {
        $colunas = array_merge(['nome'], static::CAMPOS_EXTRAS);
        $params = [];
        foreach ($colunas as $coluna) {
            $params[':' . $coluna] = $dados[$coluna] ?? null;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO ' . static::TABELA . ' (' . implode(', ', $colunas) . ')
             VALUES (:' . implode(', :', $colunas) . ')'
        );
        $stmt->execute($params);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $sets = [];
        $params = [':id' => $id];
        foreach (array_merge(['nome'], static::CAMPOS_EXTRAS) as $coluna) {
            $sets[] = "{$coluna} = :{$coluna}";
            $params[':' . $coluna] = $dados[$coluna] ?? null;
        }
        $stmt = $this->db->prepare('UPDATE ' . static::TABELA . ' SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public function definirAtivo(int $id, bool $ativo): void
    {
        $stmt = $this->db->prepare('UPDATE ' . static::TABELA . ' SET ativo = :ativo WHERE id = :id');
        $stmt->bindValue(':ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Quantidade de registros ativos (usuarios ou unidades) vinculados ao item. */
    public function usuariosAtivos(int $id): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . static::TABELA_VINCULO . ' u WHERE u.' . static::COLUNA_USUARIO . ' = :id AND ' . static::CONDICAO_VINCULO_ATIVO
        );
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn();
    }
}
