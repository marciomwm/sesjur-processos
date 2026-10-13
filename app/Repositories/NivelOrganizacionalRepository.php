<?php
declare(strict_types=1);

namespace App\Repositories;

/**
 * Niveis da estrutura organizacional (ex.: Nivel I - Decisao Colegiada),
 * comuns a todas as instituicoes.
 */
final class NivelOrganizacionalRepository extends TabelaApoioRepository
{
    protected const TABELA = 'niveis_organizacionais';
    protected const TABELA_VINCULO = 'lotacoes';
    protected const COLUNA_USUARIO = 'nivel_id';

    /** Nivel pelo nome, sem diferenciar maiusculas e acentos (collation da coluna). */
    public function buscarPorNome(string $nome): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM niveis_organizacionais WHERE nome = :nome');
        $stmt->execute([':nome' => $nome]);
        return $stmt->fetch() ?: null;
    }
}
