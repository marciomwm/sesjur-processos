<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginacao;
use PDO;

/**
 * Acesso a dados de processos_administrativos.
 * Todas as consultas usam prepared statements.
 */
final class ProcessoAdministrativoRepository
{
    private const CAMPOS_ORDENACAO = [
        'protocolo'     => 'pa.protocolo',
        'data_abertura' => 'pa.data_abertura',
        // Sem prazo por ultimo; a direcao escolhida vale para o instante.
        'prazo_vigente' => "pa.prazo_vigente IS NULL, TIMESTAMP(pa.prazo_vigente, COALESCE(pa.prazo_vigente_hora, '23:59:59'))",
        'created_at'    => 'pa.created_at',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    /** Registro com o nome e o status do procedimento do catalogo (procedimento_nome, procedimento_ativo). */
    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT pa.*, pr.nome AS procedimento_nome, pr.ativo AS procedimento_ativo
               FROM processos_administrativos pa
               LEFT JOIN procedimentos pr ON pr.id = pa.procedimento_id
              WHERE pa.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $linha = $stmt->fetch();
        return $linha ?: null;
    }

    public function buscarPorProtocolo(string $protocolo): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM processos_administrativos WHERE protocolo = :p');
        $stmt->execute([':p' => $protocolo]);
        $linha = $stmt->fetch();
        return $linha ?: null;
    }

    public function protocoloEmUso(string $protocolo, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT id FROM processos_administrativos WHERE protocolo = :p';
        $params = [':p' => $protocolo];
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
        $sql = 'INSERT INTO processos_administrativos
                (protocolo, data_abertura, objeto, procedimento, procedimento_id, origem, url_origem, extrator_versao, data_extracao,
                 situacao, situacao_descricao, responsavel_nome, responsavel_matricula,
                 responsavel_lotacao, responsavel_lotacao_nome, prazo, prazo_hora)
                VALUES (:protocolo, :data_abertura, :objeto, :procedimento, :procedimento_id, :origem, :url_origem, :extrator_versao, :data_extracao,
                 :situacao, :situacao_descricao, :responsavel_nome, :responsavel_matricula,
                 :responsavel_lotacao, :responsavel_lotacao_nome, :prazo, :prazo_hora)';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->parametros($dados));
        return (int)$this->db->lastInsertId();
    }

    /** @return array<string, mixed> parametros comuns a INSERT e UPDATE */
    private function parametros(array $dados): array
    {
        return [
            ':protocolo'                => $dados['protocolo'],
            ':data_abertura'            => $dados['data_abertura'],
            ':objeto'                   => $dados['objeto'],
            ':procedimento'             => $dados['procedimento'] ?? null,
            ':procedimento_id'          => $dados['procedimento_id'] ?? null,
            ':origem'                   => $dados['origem'] ?? 'MANUAL',
            ':url_origem'               => $dados['url_origem'] ?? null,
            ':extrator_versao'          => $dados['extrator_versao'] ?? null,
            ':data_extracao'            => $dados['data_extracao'] ?? null,
            ':situacao'                 => $dados['situacao'] ?? null,
            ':situacao_descricao'       => $dados['situacao_descricao'] ?? null,
            ':responsavel_nome'         => $dados['responsavel_nome'] ?? null,
            ':responsavel_matricula'    => $dados['responsavel_matricula'] ?? null,
            ':responsavel_lotacao'      => $dados['responsavel_lotacao'] ?? null,
            ':responsavel_lotacao_nome' => $dados['responsavel_lotacao_nome'] ?? null,
            ':prazo'                    => $dados['prazo'] ?? null,
            ':prazo_hora'               => $dados['prazo_hora'] ?? null,
        ];
    }

    public function atualizar(int $id, array $dados): void
    {
        $sql = 'UPDATE processos_administrativos SET
                    protocolo = :protocolo,
                    data_abertura = :data_abertura,
                    objeto = :objeto,
                    procedimento = :procedimento,
                    procedimento_id = :procedimento_id,
                    origem = :origem,
                    url_origem = :url_origem,
                    extrator_versao = :extrator_versao,
                    data_extracao = :data_extracao,
                    situacao = :situacao,
                    situacao_descricao = :situacao_descricao,
                    responsavel_nome = :responsavel_nome,
                    responsavel_matricula = :responsavel_matricula,
                    responsavel_lotacao = :responsavel_lotacao,
                    responsavel_lotacao_nome = :responsavel_lotacao_nome,
                    prazo = :prazo,
                    prazo_hora = :prazo_hora
                WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->parametros($dados) + [':id' => $id]);
    }

    public function excluir(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM processos_administrativos WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Grava os campos calculados a partir das acoes (ver ProcessoAdministrativoService). */
    public function gravarSituacaoDasAcoes(int $id, ?string $prazoVigente, ?string $prazoVigenteHora, bool $ativo): void
    {
        $stmt = $this->db->prepare(
            'UPDATE processos_administrativos
                SET prazo_vigente = :prazo, prazo_vigente_hora = :hora, ativo = :ativo
              WHERE id = :id'
        );
        $stmt->execute([':prazo' => $prazoVigente, ':hora' => $prazoVigenteHora, ':ativo' => $ativo ? 1 : 0, ':id' => $id]);
    }

    /** Linha do processo bloqueada ate o fim da transacao (serializa o recalculo). */
    public function bloquear(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, prazo, prazo_hora FROM processos_administrativos WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Listagem com filtros, ordenacao e paginacao.
     * @param array{busca?:string,protocolo?:string,objeto?:string,de?:string,ate?:string,ordenar?:string,direcao?:string} $filtros
     */
    public function listar(array $filtros, int $pagina, int $porPagina): Paginacao
    {
        [$where, $params] = $this->montarFiltros($filtros);

        $ordenar = self::CAMPOS_ORDENACAO[$filtros['ordenar'] ?? ''] ?? 'pa.created_at';
        $direcao = strtoupper($filtros['direcao'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $sqlTotal = "SELECT COUNT(*) FROM processos_administrativos pa {$where}";
        $stmt = $this->db->prepare($sqlTotal);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $offset = max(0, ($pagina - 1) * $porPagina);
        $sql = "SELECT pa.*,
                       (SELECT pr.nome FROM procedimentos pr WHERE pr.id = pa.procedimento_id) AS procedimento_nome,
                       (SELECT COUNT(*) FROM processo_administrativo_judicial v
                         WHERE v.processo_administrativo_id = pa.id) AS total_vinculos,
                       (SELECT COUNT(*) FROM processo_administrativo_acoes ac
                         WHERE ac.processo_administrativo_id = pa.id
                           AND ac.status IN ('PENDENTE', 'EM_ANDAMENTO')) AS acoes_abertas,
                       (SELECT GROUP_CONCAT(u.nome ORDER BY u.nome SEPARATOR ', ')
                          FROM processo_administrativo_designacoes d
                          JOIN usuarios u ON u.id = d.usuario_id
                         WHERE d.processo_administrativo_id = pa.id) AS designados
                FROM processos_administrativos pa
                {$where}
                ORDER BY {$ordenar} {$direcao}, pa.id DESC
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
            // Placeholders distintos: com prepared statements nativos (sem
            // emulacao) um mesmo nome nao pode ser reaproveitado.
            $condicoes[] = '(pa.protocolo LIKE :busca_protocolo OR pa.objeto LIKE :busca_objeto OR pa.procedimento LIKE :busca_procedimento
                             OR EXISTS (SELECT 1 FROM procedimentos prb WHERE prb.id = pa.procedimento_id AND prb.nome LIKE :busca_catalogo))';
            $params[':busca_protocolo'] = '%' . $busca . '%';
            $params[':busca_objeto']    = '%' . $busca . '%';
            $params[':busca_procedimento'] = '%' . $busca . '%';
            $params[':busca_catalogo'] = '%' . $busca . '%';
        }

        // Procedimento do catalogo (ativo ou inativo: vale para o historico).
        $procedimentoId = $filtros['procedimento_id'] ?? null;
        if (is_int($procedimentoId) && $procedimentoId > 0) {
            $condicoes[] = 'pa.procedimento_id = :procedimento_id';
            $params[':procedimento_id'] = $procedimentoId;
        } elseif ($procedimentoId === 'nenhum') {
            $condicoes[] = 'pa.procedimento_id IS NULL';
        }

        $protocolo = trim((string)($filtros['protocolo'] ?? ''));
        if ($protocolo !== '') {
            $condicoes[] = 'pa.protocolo LIKE :protocolo';
            $params[':protocolo'] = '%' . $protocolo . '%';
        }

        $objeto = trim((string)($filtros['objeto'] ?? ''));
        if ($objeto !== '') {
            $condicoes[] = 'pa.objeto LIKE :objeto';
            $params[':objeto'] = '%' . $objeto . '%';
        }

        $procedimento = trim((string)($filtros['procedimento'] ?? ''));
        if ($procedimento !== '') {
            $condicoes[] = 'pa.procedimento = :procedimento';
            $params[':procedimento'] = $procedimento;
        }

        $de = trim((string)($filtros['de'] ?? ''));
        if ($de !== '') {
            $condicoes[] = 'pa.data_abertura >= :de';
            $params[':de'] = $de;
        }

        $ate = trim((string)($filtros['ate'] ?? ''));
        if ($ate !== '') {
            $condicoes[] = 'pa.data_abertura <= :ate';
            $params[':ate'] = $ate;
        }

        $atividade = $filtros['atividade'] ?? '';
        if ($atividade === 'ativos' || $atividade === 'inativos') {
            $condicoes[] = 'pa.ativo = :ativo';
            $params[':ativo'] = $atividade === 'ativos' ? 1 : 0;
        }

        // Prazo vigente: vencidos ou a vencer nos proximos 7 dias.
        $momento = "TIMESTAMP(pa.prazo_vigente, COALESCE(pa.prazo_vigente_hora, '23:59:59'))";
        if (($filtros['prazo'] ?? '') === 'vencidos') {
            $condicoes[] = "{$momento} < :agora";
            $params[':agora'] = date('Y-m-d H:i:s');
        } elseif (($filtros['prazo'] ?? '') === 'semana') {
            $condicoes[] = "{$momento} >= :agora AND pa.prazo_vigente <= :limite_semana";
            $params[':agora'] = date('Y-m-d H:i:s');
            $params[':limite_semana'] = date('Y-m-d', strtotime('+7 days'));
        }

        // Designacao: id do usuario designado ou 'nenhum' (sem designacao).
        $designado = $filtros['designado'] ?? null;
        if (is_int($designado) && $designado > 0) {
            $condicoes[] = 'EXISTS (SELECT 1 FROM processo_administrativo_designacoes d
                                     WHERE d.processo_administrativo_id = pa.id AND d.usuario_id = :designado)';
            $params[':designado'] = $designado;
        } elseif ($designado === 'nenhum') {
            $condicoes[] = 'NOT EXISTS (SELECT 1 FROM processo_administrativo_designacoes d
                                         WHERE d.processo_administrativo_id = pa.id)';
        }

        return [$condicoes === [] ? '' : 'WHERE ' . implode(' AND ', $condicoes), $params];
    }

    /** Autocomplete por protocolo/objeto. */
    public function pesquisar(string $termo, int $limite = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, protocolo, data_abertura, objeto
               FROM processos_administrativos
              WHERE protocolo LIKE :termo_protocolo OR objeto LIKE :termo_objeto
              ORDER BY protocolo
              LIMIT :limite'
        );
        $stmt->bindValue(':termo_protocolo', '%' . $termo . '%');
        $stmt->bindValue(':termo_objeto', '%' . $termo . '%');
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }


    public function contar(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM processos_administrativos')->fetchColumn();
    }

    public function recentes(int $limite = 5): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, protocolo, data_abertura, objeto, created_at
               FROM processos_administrativos
              ORDER BY created_at DESC, id DESC
              LIMIT :limite'
        );
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
