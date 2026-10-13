<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\UsuarioRepository;

/**
 * Sessao do usuario autenticado e checagem de perfil.
 *
 * O perfil e o status sao relidos do banco a cada requisicao: inativar um
 * usuario ou trocar o perfil dele vale imediatamente, sem novo login.
 *
 * Contexto de sistema: sem usuario na sessao, em linha de comando (scripts
 * de manutencao e testes), as operacoes sao permitidas. Na web isso nunca
 * ocorre, pois o bootstrap exige login antes de qualquer pagina.
 */
final class Auth
{
    /** Paginas acessiveis enquanto a troca de senha obrigatoria estiver pendente. */
    private const PAGINAS_TROCA_PENDENTE = ['alterar-senha.php', 'logout.php'];

    private static ?array $usuario = null;
    private static ?int $usuarioCarregado = null;

    /** Usuario autenticado (com cargo, lotacao e perfil) ou null. */
    public static function usuario(): ?array
    {
        $id = usuario_logado_id();
        if ($id === null) {
            return null;
        }
        if (self::$usuarioCarregado !== $id) {
            self::$usuario = (new UsuarioRepository())->buscarPorId($id);
            self::$usuarioCarregado = $id;
        }
        return self::$usuario;
    }

    public static function perfil(): ?string
    {
        return self::usuario()['perfil'] ?? null;
    }

    /** O usuario autenticado tem um dos perfis informados (ou e contexto de sistema). */
    public static function autorizado(string ...$perfis): bool
    {
        $usuario = self::usuario();
        if ($usuario === null) {
            return PHP_SAPI === 'cli' && usuario_logado_id() === null;
        }
        return in_array($usuario['perfil'], $perfis, true);
    }

    public static function ehAdministrador(): bool
    {
        return self::autorizado(Perfil::ADMINISTRADOR);
    }

    public static function podeDesignar(): bool
    {
        return self::autorizado(...Perfil::DESIGNAM);
    }

    /** @throws AcessoNegadoException */
    public static function exigirPerfil(string ...$perfis): void
    {
        if (!self::autorizado(...$perfis)) {
            throw new AcessoNegadoException();
        }
    }

    /** Descarta o usuario carregado (apos alterar o proprio cadastro, nos testes etc.). */
    public static function esquecer(): void
    {
        self::$usuario = null;
        self::$usuarioCarregado = null;
    }

    /** Registra o login na sessao, trocando o id de sessao (evita fixacao). */
    public static function iniciarSessao(int $usuarioId, bool $exigirTrocaSenha): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['auth'] = [
            'usuario_id'    => $usuarioId,
            'ultimo_acesso' => time(),
            'exigir_troca'  => $exigirTrocaSenha,
        ];
        unset($_SESSION['_csrf_token']);
        self::esquecer();
    }

    public static function trocaSenhaPendente(): bool
    {
        return (bool)($_SESSION['auth']['exigir_troca'] ?? false);
    }

    public static function concluirTrocaSenha(): void
    {
        if (isset($_SESSION['auth'])) {
            $_SESSION['auth']['exigir_troca'] = false;
        }
    }

    public static function encerrarSessao(): void
    {
        unset($_SESSION['auth']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf_token']);
        self::esquecer();
    }

    /**
     * Chamado pelo bootstrap em toda requisicao web que nao seja publica.
     * Sem sessao valida: redireciona ao login (ou 401 nos endpoints JSON).
     */
    public static function exigirLogin(): void
    {
        $motivo = null;

        if (usuario_logado_id() === null) {
            $motivo = '';
        } else {
            $limite = max(5, (int)config('auth.inatividade_minutos', 120)) * 60;
            $ultimoAcesso = (int)($_SESSION['auth']['ultimo_acesso'] ?? 0);
            $usuario = self::usuario();

            if (time() - $ultimoAcesso > $limite) {
                $motivo = 'Sua sessão expirou por inatividade. Entre novamente.';
            } elseif ($usuario === null || (int)$usuario['ativo'] !== 1) {
                $motivo = 'Seu acesso não está mais ativo. Procure um administrador.';
            }
        }

        if ($motivo !== null) {
            if ($motivo !== '') {
                self::encerrarSessao();
            }
            if (defined('GJ_API')) {
                responder_json([
                    'success' => false,
                    'error'   => ['code' => 'NAO_AUTENTICADO', 'message' => 'Sessão expirada. Entre novamente no sistema.'],
                ], 401);
            }
            if ($motivo !== '') {
                flash('aviso', $motivo);
            }
            redirecionar('login.php' . self::parametroRetorno());
        }

        $_SESSION['auth']['ultimo_acesso'] = time();

        if (self::trocaSenhaPendente() && !defined('GJ_API')
            && !in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), self::PAGINAS_TROCA_PENDENTE, true)) {
            redirecionar('alterar-senha.php');
        }
    }

    /**
     * Caminho interno seguro para voltar apos o login (sem redirecionamento
     * aberto): apenas "pagina.php" com query simples.
     */
    public static function retornoSeguro(mixed $retorno): string
    {
        $retorno = is_string($retorno) ? $retorno : '';
        return preg_match('#^[a-z0-9\-]+\.php(\?[\w\-=&%.+]*)?$#i', $retorno) === 1 ? $retorno : 'index.php';
    }

    private static function parametroRetorno(): string
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return '';
        }
        $pagina = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $query  = (string)($_SERVER['QUERY_STRING'] ?? '');
        $retorno = $pagina . ($query !== '' ? '?' . $query : '');
        return self::retornoSeguro($retorno) === $retorno && $pagina !== 'index.php'
            ? '?retorno=' . rawurlencode($retorno)
            : '';
    }
}
