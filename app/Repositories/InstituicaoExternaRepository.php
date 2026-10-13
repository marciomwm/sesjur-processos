<?php
declare(strict_types=1);

namespace App\Repositories;

/**
 * Instituicoes externas ao Poder Executivo estadual (Ministerio Publico,
 * Defensoria, Tribunal de Contas...) de onde vem os processos
 * extrajudiciais. Tabela de apoio: inativada, nunca excluida.
 */
final class InstituicaoExternaRepository extends TabelaApoioRepository
{
    protected const TABELA = 'instituicoes_externas';
    protected const TABELA_VINCULO = 'orgaos_externos';
    protected const COLUNA_USUARIO = 'instituicao_externa_id';
    protected const CAMPOS_EXTRAS = ['sigla'];
}
