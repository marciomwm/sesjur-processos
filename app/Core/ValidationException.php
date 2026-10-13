<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Erro de validacao de dominio. Carrega os erros por campo.
 */
class ValidationException extends RuntimeException
{
    /** @param array<string,string> $erros */
    public function __construct(private array $erros, string $mensagem = 'Dados inválidos.')
    {
        parent::__construct($mensagem);
    }

    /** @return array<string,string> */
    public function erros(): array
    {
        return $this->erros;
    }
}
