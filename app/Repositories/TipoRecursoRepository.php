<?php
declare(strict_types=1);

namespace App\Repositories;

/**
 * Tipos de recurso — as "Pastas" da Central de Recursos (1:1 com o item).
 */
final class TipoRecursoRepository extends TabelaApoioRepository
{
    protected const TABELA = 'tipos_recurso';
    protected const TABELA_VINCULO = 'recursos';
    protected const COLUNA_USUARIO = 'tipo_id';
    /** Recursos nao tem `ativo`: todo recurso vinculado conta. */
    protected const CONDICAO_VINCULO_ATIVO = 'u.id IS NOT NULL';
}
