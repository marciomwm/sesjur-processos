<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Dados do Pagamento do processo administrativo com o procedimento
 * "Pagamento Indenizatorio": um registro por processo.
 */
final class PagamentoIndenizatorioRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    /** Registro com os nomes do Demandante e do Credor. */
    public function doProcesso(int $processoId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, l.nome AS demandante_nome, l.sigla AS demandante_sigla, l.ativo AS demandante_ativo,
                    e.nome AS credor_nome, e.documento AS credor_documento, e.tipo_pessoa AS credor_tipo_pessoa,
                    uc.nome AS criado_por_nome, ua.nome AS atualizado_por_nome
               FROM processo_administrativo_pagamentos p
               JOIN lotacoes l ON l.id = p.demandante_lotacao_id
               JOIN entregadores e ON e.id = p.entregador_id
               LEFT JOIN usuarios uc ON uc.id = p.criado_por
               LEFT JOIN usuarios ua ON ua.id = p.atualizado_por
              WHERE p.processo_administrativo_id = :id'
        );
        $stmt->execute([':id' => $processoId]);
        return $stmt->fetch() ?: null;
    }

    /** Linha crua com bloqueio para atualizacao (usar em transacao). */
    public function buscarParaAtualizar(int $processoId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM processo_administrativo_pagamentos WHERE processo_administrativo_id = :id FOR UPDATE');
        $stmt->execute([':id' => $processoId]);
        return $stmt->fetch() ?: null;
    }

    public function inserir(int $processoId, array $dados, ?int $autorId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO processo_administrativo_pagamentos
                    (processo_administrativo_id, demandante_lotacao_id, entregador_id, objeto, data_execucao,
                     competencia, valor, criado_por, atualizado_por)
             VALUES (:processo, :demandante, :entregador, :objeto, :data_execucao, :competencia, :valor, :autor, :autor2)'
        );
        $stmt->execute([':processo' => $processoId, ':autor' => $autorId, ':autor2' => $autorId] + self::parametros($dados));
    }

    public function atualizar(int $processoId, array $dados, ?int $autorId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE processo_administrativo_pagamentos
                SET demandante_lotacao_id = :demandante, entregador_id = :entregador, objeto = :objeto,
                    data_execucao = :data_execucao, competencia = :competencia, valor = :valor, atualizado_por = :autor
              WHERE processo_administrativo_id = :processo'
        );
        $stmt->execute([':processo' => $processoId, ':autor' => $autorId] + self::parametros($dados));
    }

    private static function parametros(array $dados): array
    {
        return [
            ':demandante'    => $dados['demandante_lotacao_id'],
            ':entregador'    => $dados['entregador_id'],
            ':objeto'        => $dados['objeto'],
            ':data_execucao' => $dados['data_execucao'],
            ':competencia'   => $dados['competencia'],
            ':valor'         => $dados['valor'],
        ];
    }
}
