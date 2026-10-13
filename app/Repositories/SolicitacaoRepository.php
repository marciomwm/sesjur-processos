<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginacao;
use PDO;

/**
 * Solicitacoes dos processos administrativos (pedidos a outras unidades).
 */
final class SolicitacaoRepository
{
    private const SELECT_BASE =
        'SELECT s.*, pa.protocolo, pa.objeto AS processo_objeto, u.nome AS criado_por_nome,
                l.nome AS destinatario_nome, l.sigla AS destinatario_sigla
           FROM processo_administrativo_solicitacoes s
           JOIN processos_administrativos pa ON pa.id = s.processo_administrativo_id
           JOIN lotacoes l ON l.id = s.destinatario_lotacao_id
           LEFT JOIN usuarios u ON u.id = s.criado_por';

    /** Colunas ordenaveis na listagem (chave vinda da URL => expressao SQL). */
    private const CAMPOS_ORDENACAO = [
        'data_solicitacao' => 's.data_solicitacao',
        'prazo'            => 's.prazo',
        'protocolo'        => 'pa.protocolo',
        'destinatario'     => 'l.nome',
        'status'           => 's.status',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT_BASE . ' WHERE s.id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function inserir(array $dados): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO processo_administrativo_solicitacoes
                (processo_administrativo_id, data_solicitacao, expediente, destinatario_lotacao_id,
                 assunto, prazo, status, criado_por)
             VALUES (:processo, :data_solicitacao, :expediente, :destinatario, :assunto, :prazo, :status, :criado_por)'
        );
        $stmt->execute([
            ':processo'         => $dados['processo_administrativo_id'],
            ':data_solicitacao' => $dados['data_solicitacao'],
            ':expediente'       => $dados['expediente'],
            ':destinatario'     => $dados['destinatario_lotacao_id'],
            ':assunto'          => $dados['assunto'],
            ':prazo'            => $dados['prazo'],
            ':status'           => $dados['status'],
            ':criado_por'       => $dados['criado_por'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $stmt = $this->db->prepare(
            'UPDATE processo_administrativo_solicitacoes SET
                data_solicitacao = :data_solicitacao, expediente = :expediente,
                destinatario_lotacao_id = :destinatario, assunto = :assunto,
                prazo = :prazo, status = :status
              WHERE id = :id'
        );
        $stmt->execute([
            ':data_solicitacao' => $dados['data_solicitacao'],
            ':expediente'       => $dados['expediente'],
            ':destinatario'     => $dados['destinatario_lotacao_id'],
            ':assunto'          => $dados['assunto'],
            ':prazo'            => $dados['prazo'],
            ':status'           => $dados['status'],
            ':id'               => $id,
        ]);
    }

    public function excluir(int $id): void
    {
        $this->db->prepare('DELETE FROM processo_administrativo_solicitacoes WHERE id = :id')->execute([':id' => $id]);
    }

    /** Solicitacoes de um processo: aguardando primeiro, por prazo; depois as demais, da mais recente. */
    public function listarPorProcesso(int $processoId): array
    {
        $stmt = $this->db->prepare(
            self::SELECT_BASE . "
             WHERE s.processo_administrativo_id = :p
             ORDER BY s.status <> 'AGUARDANDO', s.prazo IS NULL, s.prazo, s.data_solicitacao DESC, s.id DESC"
        );
        $stmt->execute([':p' => $processoId]);
        return $stmt->fetchAll();
    }

    /**
     * Listagem geral com filtros, ordenacao e paginacao.
     * @param array{busca?:string,status?:string,destinatario?:int|null,vencidas?:bool,processo_id?:int|null,criado_por?:int|null,ordenar?:string,direcao?:string} $filtros
     */
    public function listar(array $filtros, int $pagina, int $porPagina): Paginacao
    {
        $condicoes = [];
        $params = [];

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            // Um marcador por coluna: prepared statements nativos nao repetem nomes.
            $like = '%' . addcslashes($busca, '%_\\') . '%';
            $condicoes[] = '(s.assunto LIKE :b1 OR s.expediente LIKE :b2 OR pa.protocolo LIKE :b3)';
            $params[':b1'] = $params[':b2'] = $params[':b3'] = $like;
        }

        $status = (string)($filtros['status'] ?? '');
        if (in_array($status, ['AGUARDANDO', 'ATENDIDO', 'ENCERRADO'], true)) {
            $condicoes[] = 's.status = :status';
            $params[':status'] = $status;
        }
        if (!empty($filtros['destinatario'])) {
            $condicoes[] = 's.destinatario_lotacao_id = :destinatario';
            $params[':destinatario'] = (int)$filtros['destinatario'];
        }
        if (!empty($filtros['vencidas'])) {
            $condicoes[] = "s.status = 'AGUARDANDO' AND s.prazo < :hoje";
            $params[':hoje'] = date('Y-m-d');
        }
        if (!empty($filtros['processo_id'])) {
            $condicoes[] = 's.processo_administrativo_id = :processo';
            $params[':processo'] = (int)$filtros['processo_id'];
        }
        if (!empty($filtros['criado_por'])) {
            $condicoes[] = 's.criado_por = :criado_por';
            $params[':criado_por'] = (int)$filtros['criado_por'];
        }

        $where = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);
        $ordenar = self::CAMPOS_ORDENACAO[$filtros['ordenar'] ?? ''] ?? 's.data_solicitacao';
        $direcao = strtoupper((string)($filtros['direcao'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM processo_administrativo_solicitacoes s
               JOIN processos_administrativos pa ON pa.id = s.processo_administrativo_id' . $where
        );
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            self::SELECT_BASE . $where . "
             ORDER BY {$ordenar} IS NULL, {$ordenar} {$direcao}, s.id DESC
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

    /** Solicitacoes aguardando resposta com o prazo expirado. */
    public function contarVencidas(): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM processo_administrativo_solicitacoes
              WHERE status = 'AGUARDANDO' AND prazo < :hoje"
        );
        $stmt->execute([':hoje' => date('Y-m-d')]);
        return (int)$stmt->fetchColumn();
    }

    public function contarAguardando(): int
    {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM processo_administrativo_solicitacoes WHERE status = 'AGUARDANDO'"
        )->fetchColumn();
    }
}
