<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginacao;
use PDO;

/**
 * Processos extrajudiciais (procedimentos de instituicoes externas: MP,
 * Defensoria, Tribunal de Contas...).
 */
final class ProcessoExtrajudicialRepository
{
    private const SELECT_BASE =
        'SELECT pe.*, i.nome AS instituicao_nome, i.sigla AS instituicao_sigla, o.nome AS orgao_nome,
                uc.nome AS criado_por_nome, ua.nome AS atualizado_por_nome
           FROM processos_extrajudiciais pe
           JOIN instituicoes_externas i ON i.id = pe.instituicao_externa_id
           LEFT JOIN orgaos_externos o ON o.id = pe.orgao_externo_id
           LEFT JOIN usuarios uc ON uc.id = pe.criado_por
           LEFT JOIN usuarios ua ON ua.id = pe.atualizado_por';

    /** Colunas gravaveis. */
    public const CAMPOS = ['protocolo', 'data_expediente', 'instituicao_externa_id', 'orgao_externo_id',
                           'assunto', 'descricao', 'situacao', 'observacoes'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT_BASE . ' WHERE pe.id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function protocoloEmUso(int $instituicaoId, string $protocolo, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT 1 FROM processos_extrajudiciais WHERE instituicao_externa_id = :i AND protocolo = :p';
        $params = [':i' => $instituicaoId, ':p' => $protocolo];
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
        $colunas = array_merge(self::CAMPOS, ['criado_por', 'atualizado_por']);
        $stmt = $this->db->prepare(
            'INSERT INTO processos_extrajudiciais (' . implode(', ', $colunas) . ')
             VALUES (:' . implode(', :', $colunas) . ')'
        );
        $params = [];
        foreach (self::CAMPOS as $campo) {
            $params[':' . $campo] = $dados[$campo] ?? null;
        }
        $params[':criado_por'] = $params[':atualizado_por'] = $autorId;
        $stmt->execute($params);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados, ?int $autorId): void
    {
        $sets = [];
        $params = [':id' => $id, ':autor' => $autorId];
        foreach (self::CAMPOS as $campo) {
            $sets[] = "{$campo} = :{$campo}";
            $params[':' . $campo] = $dados[$campo] ?? null;
        }
        $stmt = $this->db->prepare(
            'UPDATE processos_extrajudiciais SET ' . implode(', ', $sets) . ', atualizado_por = :autor WHERE id = :id'
        );
        $stmt->execute($params);
    }

    public function excluir(int $id): void
    {
        $this->db->prepare('DELETE FROM processos_extrajudiciais WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Listagem com filtros e paginacao, com a quantidade de processos
     * administrativos vinculados.
     * @param array{busca?:string,instituicao_id?:int|null,orgao_id?:int|null,data_de?:?string,data_ate?:?string,situacao?:string} $filtros
     */
    public function listar(array $filtros, int $pagina, int $porPagina): Paginacao
    {
        $condicoes = [];
        $params = [];

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $like = '%' . addcslashes($busca, '%_\\') . '%';
            $condicoes[] = '(pe.protocolo LIKE :b1 OR pe.assunto LIKE :b2 OR pe.descricao LIKE :b3)';
            $params[':b1'] = $params[':b2'] = $params[':b3'] = $like;
        }
        if (!empty($filtros['instituicao_id'])) {
            $condicoes[] = 'pe.instituicao_externa_id = :instituicao';
            $params[':instituicao'] = (int)$filtros['instituicao_id'];
        }
        if (!empty($filtros['orgao_id'])) {
            $condicoes[] = 'pe.orgao_externo_id = :orgao';
            $params[':orgao'] = (int)$filtros['orgao_id'];
        }
        if (!empty($filtros['data_de'])) {
            $condicoes[] = 'pe.data_expediente >= :de';
            $params[':de'] = $filtros['data_de'];
        }
        if (!empty($filtros['data_ate'])) {
            $condicoes[] = 'pe.data_expediente <= :ate';
            $params[':ate'] = $filtros['data_ate'];
        }
        if (!empty($filtros['situacao'])) {
            $condicoes[] = 'pe.situacao = :situacao';
            $params[':situacao'] = $filtros['situacao'];
        }
        $where = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM processos_extrajudiciais pe' . $where);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT pe.*, i.nome AS instituicao_nome, i.sigla AS instituicao_sigla, o.nome AS orgao_nome,
                    (SELECT COUNT(*) FROM processo_administrativo_extrajudicial v WHERE v.processo_extrajudicial_id = pe.id) AS total_vinculos
               FROM processos_extrajudiciais pe
               JOIN instituicoes_externas i ON i.id = pe.instituicao_externa_id
               LEFT JOIN orgaos_externos o ON o.id = pe.orgao_externo_id' . $where . '
              ORDER BY pe.data_expediente DESC, pe.id DESC
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

    /** Sugestoes para o widget de vinculos (protocolo, assunto ou instituicao). */
    public function pesquisar(string $termo, int $limite = 10): array
    {
        $like = '%' . addcslashes($termo, '%_\\') . '%';
        $stmt = $this->db->prepare(
            'SELECT pe.id, pe.protocolo, pe.assunto, pe.data_expediente, i.sigla AS instituicao_sigla,
                    i.nome AS instituicao_nome, o.nome AS orgao_nome
               FROM processos_extrajudiciais pe
               JOIN instituicoes_externas i ON i.id = pe.instituicao_externa_id
               LEFT JOIN orgaos_externos o ON o.id = pe.orgao_externo_id
              WHERE pe.protocolo LIKE :b1 OR pe.assunto LIKE :b2 OR i.sigla LIKE :b3
              ORDER BY pe.data_expediente DESC
              LIMIT :limite'
        );
        $stmt->bindValue(':b1', $like);
        $stmt->bindValue(':b2', $like);
        $stmt->bindValue(':b3', $like);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
