<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Orgaos/unidades internas das instituicoes externas (ex.: 1a Promotoria de
 * Justica de Cuiaba, do MP-MT). O nome e unico dentro da instituicao.
 */
final class OrgaoExternoRepository extends TabelaApoioRepository
{
    protected const TABELA = 'orgaos_externos';
    protected const TABELA_VINCULO = 'processos_extrajudiciais';
    protected const COLUNA_USUARIO = 'orgao_externo_id';
    protected const CAMPOS_EXTRAS = ['instituicao_externa_id'];
    protected const COLUNA_PAI = 'instituicao_externa_id';
    protected const TABELA_PAI = 'instituicoes_externas';
    // Processos nao tem status ativo/inativo: todos contam.
    protected const CONDICAO_VINCULO_ATIVO = 'u.id IS NOT NULL';

    /**
     * Orgaos ativos (e o ja gravado) com a instituicao, para os seletores
     * que filtram os orgaos pela instituicao escolhida.
     */
    public function opcoesComInstituicao(?int $incluirId = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nome, ativo, instituicao_externa_id
               FROM orgaos_externos
              WHERE ativo = 1 OR id = :incluir
              ORDER BY nome'
        );
        $stmt->bindValue(':incluir', $incluirId ?? 0, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Todos os orgaos com a sigla da instituicao (filtros). */
    public function todosComInstituicao(): array
    {
        return $this->db->query(
            'SELECT o.id, o.nome, o.ativo, o.instituicao_externa_id, i.sigla AS instituicao_sigla
               FROM orgaos_externos o JOIN instituicoes_externas i ON i.id = o.instituicao_externa_id
              ORDER BY i.nome, o.nome'
        )->fetchAll();
    }

    public function buscarPorNome(int $instituicaoId, string $nome): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orgaos_externos WHERE instituicao_externa_id = :i AND nome = :n');
        $stmt->execute([':i' => $instituicaoId, ':n' => $nome]);
        return $stmt->fetch() ?: null;
    }
}
