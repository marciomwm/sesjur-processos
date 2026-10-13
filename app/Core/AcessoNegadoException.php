<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Operacao nao permitida para o perfil do usuario autenticado.
 * Quando nao tratada, o bootstrap responde 403 com a mensagem.
 */
final class AcessoNegadoException extends RuntimeException
{
    public function __construct(string $mensagem = 'Você não tem permissão para realizar esta operação.')
    {
        parent::__construct($mensagem);
    }
}
