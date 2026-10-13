<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Perfis de acesso (coluna usuarios.perfil) e o que cada um pode fazer.
 *
 *   Administrador - tudo, inclusive usuarios, perfis, senhas e configuracoes;
 *   Gestor        - processos e designacao de processos;
 *   Usuario       - processos; ve as designacoes, mas nao as altera.
 *
 * Em qualquer perfil, o usuario pode editar o proprio cadastro, exceto
 * perfil e status, que somente o administrador altera.
 */
final class Perfil
{
    public const ADMINISTRADOR = 'ADMINISTRADOR';
    public const GESTOR        = 'GESTOR';
    public const USUARIO       = 'USUARIO';

    public const ROTULOS = [
        self::ADMINISTRADOR => 'Administrador',
        self::GESTOR        => 'Gestor',
        self::USUARIO       => 'Usuário',
    ];

    public const DESCRICOES = [
        self::ADMINISTRADOR => 'Acesso total, inclusive usuários, perfis e configurações.',
        self::GESTOR        => 'Processos e designação de processos aos usuários.',
        self::USUARIO       => 'Acompanha processos; não altera designações.',
    ];

    /** Perfis que podem designar processos. */
    public const DESIGNAM = [self::ADMINISTRADOR, self::GESTOR];

    public static function valido(mixed $perfil): bool
    {
        return is_string($perfil) && isset(self::ROTULOS[$perfil]);
    }

    public static function rotulo(?string $perfil): string
    {
        return self::ROTULOS[$perfil ?? ''] ?? '—';
    }

    /** Classe do badge de exibicao. */
    public static function classeBadge(?string $perfil): string
    {
        return match ($perfil) {
            self::ADMINISTRADOR => 'badge--vermelho',
            self::GESTOR        => 'badge--ambar',
            default             => 'badge--neutro',
        };
    }

    public static function podeDesignar(?string $perfil): bool
    {
        return in_array($perfil, self::DESIGNAM, true);
    }
}
