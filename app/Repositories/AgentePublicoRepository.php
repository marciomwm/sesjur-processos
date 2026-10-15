<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginacao;
use PDO;

/**
 * Agentes Publicos: pessoas com vinculo funcional ou profissional com a
 * Administracao. O orgao/entidade vem da instituicao da unidade de lotacao
 * (Orgao > Unidade > Agente > Usuario). Nao ha exclusao: o desligamento e
 * a situacao DESLIGADO.
 */
final class AgentePublicoRepository
{
    /** Colunas gravaveis pelo cadastro/edicao. */
    public const CAMPOS = [
        'nome', 'nome_social', 'cpf', 'data_nascimento', 'email_institucional', 'email_alternativo', 'telefone',
        'matricula', 'tipo_vinculo', 'cargo_id', 'funcao', 'lotacao_id', 'data_ingresso', 'data_desligamento',
        'situacao', 'observacoes',
    ];

    private const SELECT_COMPLETO =
        'SELECT a.*, c.nome AS cargo, l.nome AS lotacao, l.sigla AS lotacao_sigla, l.ativo AS lotacao_ativa,
                i.id AS instituicao_id, i.nome AS instituicao_nome, i.sigla AS instituicao_sigla,
                u.id AS usuario_id, u.perfil AS usuario_perfil, u.ativo AS usuario_ativo, u.email AS usuario_email,
                uc.nome AS criado_por_nome, ua.nome AS atualizado_por_nome
           FROM agentes_publicos a
           JOIN lotacoes l ON l.id = a.lotacao_id
           LEFT JOIN instituicoes i ON i.id = l.instituicao_id
           LEFT JOIN cargos c ON c.id = a.cargo_id
           LEFT JOIN usuarios u ON u.agente_publico_id = a.id
           LEFT JOIN usuarios uc ON uc.id = a.criado_por
           LEFT JOIN usuarios ua ON ua.id = a.atualizado_por';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT_COMPLETO . ' WHERE a.id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Linha crua, com bloqueio para atualizacao (usar em transacao). */
    public function buscarParaAtualizar(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM agentes_publicos WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Agente ainda sem usuario com a matricula informada. */
    public function semUsuarioPorMatricula(string $matricula): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT a.* FROM agentes_publicos a
              WHERE a.matricula = :m AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.agente_publico_id = a.id)'
        );
        $stmt->execute([':m' => $matricula]);
        return $stmt->fetch() ?: null;
    }

    /** @param 'cpf'|'matricula'|'email_institucional' $coluna (fixa no codigo) */
    public function valorEmUso(string $coluna, string $valor, ?int $ignorarId = null): bool
    {
        if (!in_array($coluna, ['cpf', 'matricula', 'email_institucional'], true)) {
            throw new \InvalidArgumentException('Coluna não permitida.');
        }
        $sql = "SELECT 1 FROM agentes_publicos WHERE {$coluna} = :valor";
        $params = [':valor' => $valor];
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
        $params = [];
        foreach (self::CAMPOS as $campo) {
            $params[':' . $campo] = $dados[$campo] ?? null;
        }
        $params[':criado_por'] = $params[':atualizado_por'] = $autorId;
        $stmt = $this->db->prepare(
            'INSERT INTO agentes_publicos (' . implode(', ', $colunas) . ') VALUES (:' . implode(', :', $colunas) . ')'
        );
        $stmt->execute($params);
        return (int)$this->db->lastInsertId();
    }

    /** Atualiza apenas as colunas presentes em $dados. */
    public function atualizar(int $id, array $dados, ?int $autorId): void
    {
        $sets = [];
        $params = [':id' => $id, ':autor' => $autorId];
        foreach (self::CAMPOS as $campo) {
            if (array_key_exists($campo, $dados)) {
                $sets[] = "{$campo} = :{$campo}";
                $params[':' . $campo] = $dados[$campo];
            }
        }
        if ($sets === []) {
            return;
        }
        $stmt = $this->db->prepare('UPDATE agentes_publicos SET ' . implode(', ', $sets) . ', atualizado_por = :autor WHERE id = :id');
        $stmt->execute($params);
    }

    /**
     * Listagem com filtros e paginacao.
     * @param array{busca?:string,matricula?:string,instituicao_id?:int|null,lotacao_id?:int|null,cargo_id?:int|null,tipo_vinculo?:string,situacao?:string,acesso?:string} $filtros
     */
    public function listar(array $filtros, int $pagina, int $porPagina): Paginacao
    {
        $condicoes = [];
        $params = [];

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $like = '%' . addcslashes($busca, '%_\\') . '%';
            $condicoes[] = '(a.nome LIKE :b1 OR a.nome_social LIKE :b2 OR a.email_institucional LIKE :b3 OR a.cpf LIKE :b4)';
            $params[':b1'] = $params[':b2'] = $params[':b3'] = $like;
            $digitos = preg_replace('/\D+/', '', $busca) ?? '';
            $params[':b4'] = $digitos !== '' ? '%' . $digitos . '%' : '-';
        }
        $matricula = trim((string)($filtros['matricula'] ?? ''));
        if ($matricula !== '') {
            $condicoes[] = 'a.matricula LIKE :matricula';
            $params[':matricula'] = addcslashes(mb_strtoupper($matricula), '%_\\') . '%';
        }
        foreach (['lotacao_id' => 'a.lotacao_id', 'cargo_id' => 'a.cargo_id', 'instituicao_id' => 'l.instituicao_id'] as $chave => $coluna) {
            if (!empty($filtros[$chave])) {
                $condicoes[] = "{$coluna} = :{$chave}";
                $params[':' . $chave] = (int)$filtros[$chave];
            }
        }
        foreach (['tipo_vinculo', 'situacao'] as $chave) {
            if (!empty($filtros[$chave])) {
                $condicoes[] = "a.{$chave} = :{$chave}";
                $params[':' . $chave] = $filtros[$chave];
            }
        }
        if (($filtros['acesso'] ?? '') === 'com') {
            $condicoes[] = 'u.id IS NOT NULL';
        } elseif (($filtros['acesso'] ?? '') === 'sem') {
            $condicoes[] = 'u.id IS NULL';
        }
        $where = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM agentes_publicos a
               JOIN lotacoes l ON l.id = a.lotacao_id
               LEFT JOIN usuarios u ON u.agente_publico_id = a.id' . $where
        );
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(self::SELECT_COMPLETO . $where . ' ORDER BY a.nome, a.id LIMIT :limite OFFSET :offset');
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, ($pagina - 1) * $porPagina), PDO::PARAM_INT);
        $stmt->execute();

        return new Paginacao($stmt->fetchAll(), $total, $pagina, $porPagina);
    }

    /**
     * Autocomplete por nome, nome social ou matricula: so os dados de
     * identificacao funcional (nada de CPF, e-mail ou telefone).
     */
    public function pesquisar(string $termo, int $limite = 10): array
    {
        $like = '%' . addcslashes($termo, '%_\\') . '%';
        $stmt = $this->db->prepare(
            'SELECT a.id, a.nome, a.nome_social, a.matricula, a.situacao, c.nome AS cargo, l.nome AS lotacao
               FROM agentes_publicos a
               JOIN lotacoes l ON l.id = a.lotacao_id
               LEFT JOIN cargos c ON c.id = a.cargo_id
              WHERE a.nome LIKE :b1 OR a.nome_social LIKE :b2 OR a.matricula LIKE :b3
              ORDER BY a.situacao = \'DESLIGADO\', a.nome, a.id
              LIMIT :limite'
        );
        $stmt->bindValue(':b1', $like);
        $stmt->bindValue(':b2', $like);
        $stmt->bindValue(':b3', addcslashes(mb_strtoupper($termo), '%_\\') . '%');
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Agentes que ainda podem receber usuario (sem conta e nao desligados). */
    public function semUsuario(): array
    {
        return $this->db->query(
            "SELECT a.id, a.nome, a.matricula, a.email_institucional, l.nome AS lotacao
               FROM agentes_publicos a
               JOIN lotacoes l ON l.id = a.lotacao_id
              WHERE a.situacao <> 'DESLIGADO'
                AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.agente_publico_id = a.id)
              ORDER BY a.nome, a.id"
        )->fetchAll();
    }
}
