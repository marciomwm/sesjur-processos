<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginacao;
use PDO;

/**
 * Anotacoes (notas livres internas) dos processos administrativos.
 */
final class AnotacaoRepository
{
    private const SELECT_BASE =
        'SELECT n.*, pa.protocolo, pa.objeto AS processo_objeto, u.nome AS criado_por_nome
           FROM processo_administrativo_anotacoes n
           JOIN processos_administrativos pa ON pa.id = n.processo_administrativo_id
           LEFT JOIN usuarios u ON u.id = n.criado_por';

    /** Colunas ordenaveis na listagem (chave vinda da URL => expressao SQL). */
    private const CAMPOS_ORDENACAO = [
        'created_at' => 'n.created_at',
        'updated_at' => 'n.updated_at',
        'protocolo'  => 'pa.protocolo',
        'autor'      => 'u.nome',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT_BASE . ' WHERE n.id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function inserir(array $dados): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO processo_administrativo_anotacoes (processo_administrativo_id, texto, criado_por)
             VALUES (:processo, :texto, :criado_por)'
        );
        $stmt->execute([
            ':processo'   => $dados['processo_administrativo_id'],
            ':texto'      => $dados['texto'],
            ':criado_por' => $dados['criado_por'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $stmt = $this->db->prepare('UPDATE processo_administrativo_anotacoes SET texto = :texto WHERE id = :id');
        $stmt->execute([':texto' => $dados['texto'], ':id' => $id]);
    }

    public function excluir(int $id): void
    {
        $this->db->prepare('DELETE FROM processo_administrativo_anotacoes WHERE id = :id')->execute([':id' => $id]);
    }

    /** Anotacoes de um processo, da mais recente para a mais antiga. */
    public function listarPorProcesso(int $processoId): array
    {
        $stmt = $this->db->prepare(
            self::SELECT_BASE . ' WHERE n.processo_administrativo_id = :p ORDER BY n.created_at DESC, n.id DESC'
        );
        $stmt->execute([':p' => $processoId]);
        return $stmt->fetchAll();
    }

    /**
     * Listagem geral com filtros, ordenacao e paginacao.
     * @param array{busca?:string,processo_id?:int|null,criado_por?:int|null,ordenar?:string,direcao?:string} $filtros
     */
    public function listar(array $filtros, int $pagina, int $porPagina): Paginacao
    {
        $condicoes = [];
        $params = [];

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $like = '%' . addcslashes($busca, '%_\\') . '%';
            $condicoes[] = '(n.texto LIKE :b1 OR pa.protocolo LIKE :b2)';
            $params[':b1'] = $params[':b2'] = $like;
        }
        if (!empty($filtros['processo_id'])) {
            $condicoes[] = 'n.processo_administrativo_id = :processo';
            $params[':processo'] = (int)$filtros['processo_id'];
        }
        if (!empty($filtros['criado_por'])) {
            $condicoes[] = 'n.criado_por = :criado_por';
            $params[':criado_por'] = (int)$filtros['criado_por'];
        }

        $where = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);
        $ordenar = self::CAMPOS_ORDENACAO[$filtros['ordenar'] ?? ''] ?? 'n.created_at';
        $direcao = strtoupper((string)($filtros['direcao'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM processo_administrativo_anotacoes n
               JOIN processos_administrativos pa ON pa.id = n.processo_administrativo_id' . $where
        );
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            self::SELECT_BASE . $where . "
             ORDER BY {$ordenar} {$direcao}, n.id {$direcao}
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
}
