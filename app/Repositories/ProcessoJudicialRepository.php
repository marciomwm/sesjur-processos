<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginacao;
use PDO;

/**
 * Acesso a dados de processos_judiciais.
 */
final class ProcessoJudicialRepository
{
    private const CAMPOS_ORDENACAO = [
        'numero_cnj'        => 'pj.numero_cnj',
        'tribunal'          => 'pj.tribunal',
        'data_distribuicao' => 'pj.data_distribuicao',
        'data_autuacao'     => 'pj.data_autuacao',
        'created_at'        => 'pj.created_at',
    ];

    /** Colunas gravaveis da entidade. */
    public const CAMPOS = [
        'numero_cnj', 'tribunal', 'orgao_julgador', 'cargo_judicial', 'competencia',
        'jurisdicao', 'classe_judicial', 'classe_codigo', 'classe_abreviada',
        'assunto_principal', 'resumo_partes', 'data_autuacao', 'data_distribuicao', 'valor_causa',
        'situacao', 'segredo_justica', 'justica_gratuita', 'tutela_liminar', 'prioridade',
        'prioridade_descricao',
        'resumo', 'observacoes', 'origem', 'url_origem',
        'extrator_versao', 'data_extracao', 'data_ultima_atualizacao', 'historico_completo',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM processos_judiciais WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function buscarPorCnj(string $cnj): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM processos_judiciais WHERE numero_cnj = :n');
        $stmt->execute([':n' => $cnj]);
        return $stmt->fetch() ?: null;
    }

    public function cnjEmUso(string $cnj, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT id FROM processos_judiciais WHERE numero_cnj = :n';
        $params = [':n' => $cnj];
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
        $colunas = [];
        $marcadores = [];
        $params = [];
        foreach (self::CAMPOS as $campo) {
            $colunas[] = $campo;
            $marcadores[] = ':' . $campo;
            $params[':' . $campo] = $dados[$campo] ?? null;
        }
        $sql = 'INSERT INTO processos_judiciais (' . implode(', ', $colunas) . ')
                VALUES (' . implode(', ', $marcadores) . ')';
        $this->db->prepare($sql)->execute($params);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $sets = [];
        $params = [':id' => $id];
        foreach (self::CAMPOS as $campo) {
            $sets[] = "{$campo} = :{$campo}";
            $params[':' . $campo] = $dados[$campo] ?? null;
        }
        $sql = 'UPDATE processos_judiciais SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->db->prepare($sql)->execute($params);
    }

    public function excluir(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM processos_judiciais WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function listar(array $filtros, int $pagina, int $porPagina): Paginacao
    {
        [$where, $params] = $this->montarFiltros($filtros);

        $ordenar = self::CAMPOS_ORDENACAO[$filtros['ordenar'] ?? ''] ?? 'pj.created_at';
        $direcao = strtoupper($filtros['direcao'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM processos_judiciais pj {$where}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $offset = max(0, ($pagina - 1) * $porPagina);
        $sql = "SELECT pj.*,
                       (SELECT COUNT(*) FROM processo_administrativo_judicial v
                         WHERE v.processo_judicial_id = pj.id) AS total_vinculos
                FROM processos_judiciais pj
                {$where}
                ORDER BY {$ordenar} {$direcao}, pj.id DESC
                LIMIT :limite OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return new Paginacao($stmt->fetchAll(), $total, $pagina, $porPagina);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function montarFiltros(array $filtros): array
    {
        $condicoes = [];
        $params = [];

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            // Placeholders distintos: prepared statements nativos nao permitem
            // reaproveitar o mesmo nome em varios pontos da consulta.
            $condicoes[] = '(pj.numero_cnj LIKE :busca_cnj OR pj.resumo LIKE :busca_resumo
                             OR pj.classe_judicial LIKE :busca_classe OR pj.assunto_principal LIKE :busca_assunto)';
            foreach (['busca_cnj', 'busca_resumo', 'busca_classe', 'busca_assunto'] as $marcador) {
                $params[':' . $marcador] = '%' . $busca . '%';
            }
        }

        foreach (['tribunal', 'classe_judicial', 'situacao'] as $campo) {
            $valor = trim((string)($filtros[$campo] ?? ''));
            if ($valor !== '') {
                $condicoes[] = "pj.{$campo} LIKE :{$campo}";
                $params[':' . $campo] = '%' . $valor . '%';
            }
        }

        $de = trim((string)($filtros['de'] ?? ''));
        if ($de !== '') {
            $condicoes[] = 'pj.data_distribuicao >= :de';
            $params[':de'] = $de;
        }
        $ate = trim((string)($filtros['ate'] ?? ''));
        if ($ate !== '') {
            $condicoes[] = 'pj.data_distribuicao <= :ate';
            $params[':ate'] = $ate;
        }

        return [$condicoes === [] ? '' : 'WHERE ' . implode(' AND ', $condicoes), $params];
    }

    public function pesquisar(string $termo, int $limite = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, numero_cnj, tribunal, orgao_julgador, classe_judicial, situacao
               FROM processos_judiciais
              WHERE numero_cnj LIKE :termo_cnj OR classe_judicial LIKE :termo_classe OR resumo LIKE :termo_resumo
              ORDER BY numero_cnj
              LIMIT :limite'
        );
        $stmt->bindValue(':termo_cnj', '%' . $termo . '%');
        $stmt->bindValue(':termo_classe', '%' . $termo . '%');
        $stmt->bindValue(':termo_resumo', '%' . $termo . '%');
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function contar(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM processos_judiciais')->fetchColumn();
    }

    public function recentes(int $limite = 5): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, numero_cnj, tribunal, created_at
               FROM processos_judiciais
              ORDER BY created_at DESC, id DESC
              LIMIT :limite'
        );
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Valores distintos para os filtros da listagem. */
    public function valoresDistintos(string $coluna): array
    {
        $permitidas = ['tribunal', 'situacao', 'classe_judicial'];
        if (!in_array($coluna, $permitidas, true)) {
            return [];
        }
        $sql = "SELECT DISTINCT {$coluna} AS v FROM processos_judiciais
                 WHERE {$coluna} IS NOT NULL AND {$coluna} <> '' ORDER BY v LIMIT 200";
        return array_column($this->db->query($sql)->fetchAll(), 'v');
    }
}
