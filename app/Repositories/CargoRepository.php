<?php
declare(strict_types=1);

namespace App\Repositories;

/**
 * Acesso a dados de cargos (tabela de apoio do cadastro de usuarios).
 */
final class CargoRepository extends TabelaApoioRepository
{
    protected const TABELA = 'cargos';
    protected const COLUNA_USUARIO = 'cargo_id';
}
