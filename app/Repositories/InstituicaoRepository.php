<?php
declare(strict_types=1);

namespace App\Repositories;

/**
 * Instituicoes (SES-MT, PGE-MT...) a que pertencem as unidades
 * administrativas.
 */
final class InstituicaoRepository extends TabelaApoioRepository
{
    protected const TABELA = 'instituicoes';
    protected const TABELA_VINCULO = 'lotacoes';
    protected const COLUNA_USUARIO = 'instituicao_id';
    protected const CAMPOS_EXTRAS = ['sigla'];
}
