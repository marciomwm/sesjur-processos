<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Unidades Administrativas (tabela historica `lotacoes`): lotacao dos
 * usuarios, destinatario das solicitacoes e estrutura organizacional das
 * instituicoes, com o lotacionograma em `lotacao_servidores`.
 */
final class LotacaoRepository extends TabelaApoioRepository
{
    protected const TABELA = 'lotacoes';
    protected const COLUNA_USUARIO = 'lotacao_id';
    protected const CAMPOS_EXTRAS = [
        'sigla', 'instituicao_id', 'nivel_id', 'unidade_superior_id',
        'gestor_nome', 'gestor_matricula', 'competencias',
    ];

    /** Rotulo de exibicao: nome e, se houver, a sigla da instituicao. */
    private const ROTULO = "CONCAT(t.nome, IF(i.sigla IS NULL, '', CONCAT(' (', i.sigla, ')')))";

    private const SELECT_DETALHADO =
        'SELECT t.*, i.nome AS instituicao_nome, i.sigla AS instituicao_sigla,
                n.nome AS nivel_nome, s.nome AS superior_nome, s.sigla AS superior_sigla
           FROM lotacoes t
           LEFT JOIN instituicoes i ON i.id = t.instituicao_id
           LEFT JOIN niveis_organizacionais n ON n.id = t.nivel_id
           LEFT JOIN lotacoes s ON s.id = t.unidade_superior_id';

    public function buscarDetalhado(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT_DETALHADO . ' WHERE t.id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Opcoes de selecao (usuario, solicitacao): ativas e a ja gravada, com rotulo. */
    public function opcoes(?int $incluirId = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.nome, t.ativo, t.instituicao_id, ' . self::ROTULO . ' AS rotulo
               FROM lotacoes t LEFT JOIN instituicoes i ON i.id = t.instituicao_id
              WHERE t.ativo = 1 OR t.id = :incluir
              ORDER BY i.sigla IS NULL, i.sigla, t.nome'
        );
        $stmt->bindValue(':incluir', $incluirId ?? 0, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Unidades de uma instituicao (pela sigla, ex.: SES-MT) para selecao:
     * ativas e a ja gravada, em ordem alfabetica.
     */
    public function opcoesDaInstituicao(string $sigla, ?int $incluirId = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.nome, t.sigla, t.ativo
               FROM lotacoes t JOIN instituicoes i ON i.id = t.instituicao_id
              WHERE i.sigla = :sigla AND (t.ativo = 1 OR t.id = :incluir)
              ORDER BY t.nome'
        );
        $stmt->bindValue(':sigla', $sigla);
        $stmt->bindValue(':incluir', $incluirId ?? 0, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Todas as unidades, inclusive inativas (filtros), com rotulo. */
    public function todos(): array
    {
        return $this->db->query(
            'SELECT t.id, t.nome, t.ativo, t.instituicao_id, ' . self::ROTULO . ' AS rotulo
               FROM lotacoes t LEFT JOIN instituicoes i ON i.id = t.instituicao_id
              ORDER BY i.sigla IS NULL, i.sigla, t.nome'
        )->fetchAll();
    }

    /**
     * Estrutura minima de todas as unidades, para montar a hierarquia
     * (caminho, subordinadas, ciclos).
     * @return array<int, array{id:int, unidade_superior_id:?int, nome:string, sigla:?string, instituicao_id:?int, ativo:int}>
     */
    public function estrutura(): array
    {
        $mapa = [];
        foreach ($this->db->query('SELECT id, unidade_superior_id, nome, sigla, instituicao_id, ativo FROM lotacoes') as $r) {
            $mapa[(int)$r['id']] = [
                'id'                  => (int)$r['id'],
                'unidade_superior_id' => $r['unidade_superior_id'] !== null ? (int)$r['unidade_superior_id'] : null,
                'nome'                => $r['nome'],
                'sigla'               => $r['sigla'],
                'instituicao_id'      => $r['instituicao_id'] !== null ? (int)$r['instituicao_id'] : null,
                'ativo'               => (int)$r['ativo'],
            ];
        }
        return $mapa;
    }

    /**
     * Listagem das unidades com os nomes relacionados e as contagens.
     * @param array{busca?:string,instituicao_id?:int|null,nivel_id?:int|null,status?:string} $filtros
     */
    public function listarUnidades(array $filtros): array
    {
        $condicoes = [];
        $params = [];

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $like = '%' . addcslashes($busca, '\\%_') . '%';
            $condicoes[] = '(t.nome LIKE :b1 OR t.sigla LIKE :b2 OR t.gestor_nome LIKE :b3 OR t.competencias LIKE :b4)';
            $params[':b1'] = $params[':b2'] = $params[':b3'] = $params[':b4'] = $like;
        }
        if (!empty($filtros['instituicao_id'])) {
            $condicoes[] = 't.instituicao_id = :instituicao';
            $params[':instituicao'] = (int)$filtros['instituicao_id'];
        }
        if (!empty($filtros['nivel_id'])) {
            $condicoes[] = 't.nivel_id = :nivel';
            $params[':nivel'] = (int)$filtros['nivel_id'];
        }
        $status = $filtros['status'] ?? 'todos';
        if ($status === 'ativos') {
            $condicoes[] = 't.ativo = 1';
        } elseif ($status === 'inativos') {
            $condicoes[] = 't.ativo = 0';
        }

        $where = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);
        $stmt = $this->db->prepare(
            'SELECT t.*, i.nome AS instituicao_nome, i.sigla AS instituicao_sigla,
                    n.nome AS nivel_nome, s.nome AS superior_nome,
                    (SELECT COUNT(*) FROM lotacao_servidores ls WHERE ls.lotacao_id = t.id) AS total_servidores,
                    (SELECT COUNT(*) FROM usuarios u WHERE u.lotacao_id = t.id AND u.ativo = 1) AS usuarios_ativos
               FROM lotacoes t
               LEFT JOIN instituicoes i ON i.id = t.instituicao_id
               LEFT JOIN niveis_organizacionais n ON n.id = t.nivel_id
               LEFT JOIN lotacoes s ON s.id = t.unidade_superior_id' . $where . '
              ORDER BY t.nome'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Nome ja usado por outra unidade da mesma instituicao. */
    public function nomeEmUso(?int $instituicaoId, string $nome, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT 1 FROM lotacoes WHERE nome = :nome AND instituicao_id <=> :instituicao';
        $params = [':nome' => $nome, ':instituicao' => $instituicaoId];
        if ($ignorarId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $ignorarId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    /** Lotacionograma da unidade, na ordem digitada. */
    public function servidores(int $lotacaoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT nome, matricula, cargo FROM lotacao_servidores WHERE lotacao_id = :id ORDER BY ordem, id'
        );
        $stmt->execute([':id' => $lotacaoId]);
        return $stmt->fetchAll();
    }

    /** Substitui o lotacionograma inteiro (chamar dentro de transacao). */
    public function substituirServidores(int $lotacaoId, array $servidores): void
    {
        $this->db->prepare('DELETE FROM lotacao_servidores WHERE lotacao_id = :id')->execute([':id' => $lotacaoId]);
        $stmt = $this->db->prepare(
            'INSERT INTO lotacao_servidores (lotacao_id, ordem, nome, matricula, cargo)
             VALUES (:lotacao, :ordem, :nome, :matricula, :cargo)'
        );
        foreach (array_values($servidores) as $i => $s) {
            $stmt->execute([
                ':lotacao'   => $lotacaoId,
                ':ordem'     => $i,
                ':nome'      => $s['nome'],
                ':matricula' => $s['matricula'],
                ':cargo'     => $s['cargo'],
            ]);
        }
    }

    /** Unidades imediatamente abaixo desta. */
    public function subordinadas(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.nome, t.sigla, t.ativo, n.nome AS nivel_nome, t.gestor_nome
               FROM lotacoes t LEFT JOIN niveis_organizacionais n ON n.id = t.nivel_id
              WHERE t.unidade_superior_id = :id
              ORDER BY t.nome'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll();
    }
}
