<?php
declare(strict_types=1);

namespace App\Repositories;

/**
 * Categorias de recurso — as "Areas" da Central de Recursos (N:N com o item).
 */
final class CategoriaRecursoRepository extends TabelaApoioRepository
{
    protected const TABELA = 'categorias_recurso';
    protected const TABELA_VINCULO = 'recurso_categorias';
    protected const COLUNA_USUARIO = 'categoria_id';
    protected const CONDICAO_VINCULO_ATIVO = 'u.id IS NOT NULL';
}
