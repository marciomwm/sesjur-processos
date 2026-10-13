<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Estrutura simples de paginacao.
 */
final class Paginacao
{
    public int $totalPaginas;

    public function __construct(
        public readonly array $itens,
        public readonly int $total,
        public readonly int $pagina,
        public readonly int $porPagina
    ) {
        $this->totalPaginas = max(1, (int)ceil($total / max(1, $porPagina)));
    }

    public function vazio(): bool
    {
        return $this->itens === [];
    }

    /** Gera a URL de uma pagina preservando os filtros atuais. */
    public function urlPagina(int $pagina, array $query): string
    {
        $query['pagina'] = $pagina;
        return '?' . http_build_query($query);
    }
}
