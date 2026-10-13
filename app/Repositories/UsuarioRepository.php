<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginacao;
use App\Core\Perfil;
use PDO;

/**
 * Acesso a dados de usuarios. Nao ha metodo de exclusao: usuarios sao
 * apenas inativados, para preservar autoria e historico.
 * Nao e final para permitir dubles nos testes (ex.: simular concorrencia).
 */
class UsuarioRepository
{
    /** Colunas gravaveis pelo cadastro/edicao. */
    public const CAMPOS = ['nome', 'email', 'matricula', 'cargo_id', 'lotacao_id', 'perfil', 'data_nascimento'];

    /** Filtro de status da listagem. */
    public const STATUS = ['ativos' => 'Ativos', 'inativos' => 'Inativos', 'todos' => 'Todos'];

    private const SELECT_COMPLETO =
        'SELECT u.*, c.nome AS cargo, l.nome AS lotacao
           FROM usuarios u
           JOIN cargos c   ON c.id = u.cargo_id
           JOIN lotacoes l ON l.id = u.lotacao_id';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    /** Usuario com os nomes de cargo e lotacao. */
    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT_COMPLETO . ' WHERE u.id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Linha crua da tabela, com bloqueio para atualizacao (usar em transacao). */
    public function buscarParaAtualizar(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM usuarios WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function emailEmUso(string $email, ?int $ignorarId = null): bool
    {
        return $this->valorEmUso('email', $email, $ignorarId);
    }

    public function matriculaEmUso(string $matricula, ?int $ignorarId = null): bool
    {
        return $this->valorEmUso('matricula', $matricula, $ignorarId);
    }

    /** @param 'email'|'matricula' $coluna (fixa no codigo, nunca vinda do usuario) */
    private function valorEmUso(string $coluna, string $valor, ?int $ignorarId): bool
    {
        $sql = "SELECT 1 FROM usuarios WHERE {$coluna} = :valor";
        $params = [':valor' => $valor];
        if ($ignorarId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $ignorarId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    /** @param array $dados CAMPOS e agente_publico_id (agente titular da conta) */
    public function inserir(array $dados): int
    {
        $params = [':agente' => $dados['agente_publico_id'] ?? null];
        foreach (self::CAMPOS as $campo) {
            $params[':' . $campo] = $dados[$campo] ?? null;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO usuarios (agente_publico_id, nome, email, matricula, cargo_id, lotacao_id, perfil, data_nascimento)
             VALUES (:agente, :nome, :email, :matricula, :cargo_id, :lotacao_id, :perfil, :data_nascimento)'
        );
        $stmt->execute($params);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $params = [':id' => $id];
        foreach (self::CAMPOS as $campo) {
            $params[':' . $campo] = $dados[$campo] ?? null;
        }
        $stmt = $this->db->prepare(
            'UPDATE usuarios
                SET nome = :nome, email = :email, matricula = :matricula,
                    cargo_id = :cargo_id, lotacao_id = :lotacao_id, perfil = :perfil,
                    data_nascimento = :data_nascimento
              WHERE id = :id'
        );
        $stmt->execute($params);
    }

    /**
     * Copia do Agente Publico os dados pessoais e funcionais da conta
     * (nome, matricula, cargo, unidade e nascimento).
     */
    public function sincronizarDoAgente(int $id, array $dados): void
    {
        $stmt = $this->db->prepare(
            'UPDATE usuarios
                SET nome = :nome, matricula = :matricula, cargo_id = :cargo_id,
                    lotacao_id = :lotacao_id, data_nascimento = :data_nascimento
              WHERE id = :id'
        );
        $stmt->execute([
            ':nome'            => $dados['nome'],
            ':matricula'       => $dados['matricula'],
            ':cargo_id'        => $dados['cargo_id'],
            ':lotacao_id'      => $dados['lotacao_id'],
            ':data_nascimento' => $dados['data_nascimento'],
            ':id'              => $id,
        ]);
    }

    /** Ativa ou inativa (exclusao logica), registrando o momento da inativacao. */
    public function definirAtivo(int $id, bool $ativo): void
    {
        $stmt = $this->db->prepare(
            'UPDATE usuarios
                SET ativo = :ativo,
                    inativado_em = CASE WHEN :inativar = 1 THEN CURRENT_TIMESTAMP ELSE NULL END
              WHERE id = :id'
        );
        $stmt->bindValue(':ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':inativar', $ativo ? 0 : 1, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function listar(array $filtros, int $pagina, int $porPagina): Paginacao
    {
        [$where, $params] = $this->montarFiltros($filtros);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM usuarios u {$where}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $offset = max(0, ($pagina - 1) * $porPagina);
        $stmt = $this->db->prepare(
            self::SELECT_COMPLETO . " {$where} ORDER BY u.nome, u.id LIMIT :limite OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
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
            // % e _ digitados pelo usuario sao literais, nao curingas.
            $termo = '%' . addcslashes($busca, '\\%_') . '%';
            $condicoes[] = '(u.nome LIKE :busca_nome OR u.matricula LIKE :busca_matricula OR u.email LIKE :busca_email)';
            $params[':busca_nome'] = $termo;
            $params[':busca_matricula'] = $termo;
            $params[':busca_email'] = $termo;
        }

        foreach (['cargo_id', 'lotacao_id'] as $campo) {
            $id = $filtros[$campo] ?? null;
            if (is_int($id) && $id > 0) {
                $condicoes[] = "u.{$campo} = :{$campo}";
                $params[':' . $campo] = $id;
            }
        }

        $perfil = $filtros['perfil'] ?? '';
        if (Perfil::valido($perfil)) {
            $condicoes[] = 'u.perfil = :perfil';
            $params[':perfil'] = $perfil;
        }

        $status = $filtros['status'] ?? 'ativos';
        if ($status === 'ativos') {
            $condicoes[] = 'u.ativo = 1';
        } elseif ($status === 'inativos') {
            $condicoes[] = 'u.ativo = 0';
        }

        return [$condicoes === [] ? '' : 'WHERE ' . implode(' AND ', $condicoes), $params];
    }

    public function contar(bool $somenteAtivos = false): int
    {
        $sql = 'SELECT COUNT(*) FROM usuarios' . ($somenteAtivos ? ' WHERE ativo = 1' : '');
        return (int)$this->db->query($sql)->fetchColumn();
    }

    /** Administradores ativos, exceto o informado (bloqueia linhas: usar em transacao). */
    public function outrosAdministradoresAtivos(int $exceto): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM (
                SELECT id FROM usuarios
                 WHERE perfil = 'ADMINISTRADOR' AND ativo = 1 AND id <> :id
                 FOR UPDATE
             ) t"
        );
        $stmt->execute([':id' => $exceto]);
        return (int)$stmt->fetchColumn();
    }

    /** Usuarios ativos para seletores (designacao), por nome. */
    public function opcoesAtivos(): array
    {
        return $this->db->query(
            "SELECT u.id, u.nome, u.perfil, l.nome AS lotacao
               FROM usuarios u
               JOIN lotacoes l ON l.id = u.lotacao_id
              WHERE u.ativo = 1
              ORDER BY u.nome, u.id"
        )->fetchAll();
    }
}
