<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\CredencialRepository;
use App\Repositories\UsuarioRepository;
use App\Validators\SenhaValidator;
use App\Validators\UsuarioValidator;

/**
 * Login e senhas. Regras:
 *  - mensagem unica para qualquer falha (nao revela se o e-mail existe,
 *    se o usuario esta inativo, sem senha ou bloqueado);
 *  - bloqueio temporario apos N tentativas falhas;
 *  - senha definida pelo administrador e provisoria: exige troca no login;
 *  - LOGIN_REALIZADO / LOGIN_FALHOU / SENHA_* gravados na auditoria.
 */
final class AuthService
{
    public const MSG_FALHA = 'E-mail ou senha incorretos. Após várias tentativas seguidas, o acesso fica bloqueado por alguns minutos.';

    private const ENTIDADE = 'usuarios';

    /** Hash de uma senha aleatoria: equaliza o tempo de resposta quando o e-mail nao existe. */
    private const HASH_FICTICIO = '$2y$10$ekU3sDu7MQzVteG8/53lseUhcXx4CPzRpXdQgtXLEtg2QO.8JWzLm';

    public function __construct(
        private readonly CredencialRepository $credenciais = new CredencialRepository(),
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    /**
     * Confere e-mail e senha. Nao grava a sessao (ver Auth::iniciarSessao).
     * @return array{id:int,exigir_troca:bool}
     * @throws ValidationException
     */
    public function autenticar(mixed $email, mixed $senha): array
    {
        [$email] = UsuarioValidator::email($email);
        $senha = is_string($senha) ? $senha : '';

        $registro = ($email !== null && $senha !== '') ? $this->credenciais->buscarParaLogin($email) : null;

        if ($registro === null || $registro['senha_hash'] === null) {
            password_verify($senha, self::HASH_FICTICIO);
            $this->auditarFalha($registro !== null ? (int)$registro['id'] : null, $email, 'sem_acesso');
            throw new ValidationException([], self::MSG_FALHA);
        }

        $id = (int)$registro['id'];

        // Comparado no banco: o relogio de referencia e o mesmo do DATE_ADD do bloqueio.
        if ((int)$registro['bloqueado'] === 1) {
            $this->auditarFalha($id, $email, 'bloqueado');
            throw new ValidationException([], self::MSG_FALHA);
        }

        if (!password_verify($senha, $registro['senha_hash'])) {
            $this->credenciais->registrarFalha(
                $id,
                max(1, (int)config('auth.max_tentativas', 5)),
                max(1, (int)config('auth.bloqueio_minutos', 15))
            );
            $this->auditarFalha($id, $email, 'senha_incorreta');
            throw new ValidationException([], self::MSG_FALHA);
        }

        // Senha correta, mas usuario inativo: tambem nao entra.
        if ((int)$registro['ativo'] !== 1) {
            $this->auditarFalha($id, $email, 'inativo');
            throw new ValidationException([], self::MSG_FALHA);
        }

        if (password_needs_rehash($registro['senha_hash'], PASSWORD_DEFAULT)) {
            $this->credenciais->atualizarHash($id, password_hash($senha, PASSWORD_DEFAULT));
        }
        $this->credenciais->registrarSucesso($id);
        $this->auditoria->registrar(AuditoriaService::LOGIN_REALIZADO, self::ENTIDADE, $id, null, null, $id);

        return ['id' => $id, 'exigir_troca' => (int)$registro['exigir_troca_senha'] === 1];
    }

    /** Saida do sistema (auditada). */
    public function sair(): void
    {
        $id = usuario_logado_id();
        if ($id !== null) {
            $this->auditoria->registrar(AuditoriaService::LOGOUT, self::ENTIDADE, $id);
        }
        Auth::encerrarSessao();
    }

    /**
     * Administrador define uma senha provisoria para outro usuario (ou
     * desbloqueia o acesso). O usuario tera de troca-la no primeiro login.
     * @throws ValidationException
     */
    public function definirSenhaProvisoria(int $usuarioId, mixed $senha, mixed $confirmacao): void
    {
        Auth::exigirPerfil(Perfil::ADMINISTRADOR);

        $erro = SenhaValidator::validar($senha, $confirmacao);
        if ($erro !== null) {
            throw new ValidationException(['senha' => $erro]);
        }

        Database::transacao(function () use ($usuarioId, $senha): void {
            if ($this->usuarios->buscarParaAtualizar($usuarioId) === null) {
                throw new ValidationException([], 'Usuário não encontrado.');
            }
            $this->credenciais->definirSenha($usuarioId, password_hash($senha, PASSWORD_DEFAULT), true);
            $this->auditoria->registrar(AuditoriaService::SENHA_DEFINIDA, self::ENTIDADE, $usuarioId);
        });
    }

    /**
     * O proprio usuario troca a senha, informando a atual.
     * @throws ValidationException
     */
    public function alterarPropriaSenha(int $usuarioId, mixed $atual, mixed $nova, mixed $confirmacao): void
    {
        $credencial = $this->credenciais->buscar($usuarioId);
        if ($credencial === null || !is_string($atual) || !password_verify($atual, $credencial['senha_hash'])) {
            throw new ValidationException(['senha_atual' => 'Senha atual incorreta.']);
        }

        $erro = SenhaValidator::validar($nova, $confirmacao);
        if ($erro === null && hash_equals($atual, (string)$nova)) {
            $erro = 'A nova senha deve ser diferente da atual.';
        }
        if ($erro !== null) {
            throw new ValidationException(['senha' => $erro]);
        }

        Database::transacao(function () use ($usuarioId, $nova): void {
            $this->credenciais->definirSenha($usuarioId, password_hash($nova, PASSWORD_DEFAULT), false);
            $this->auditoria->registrar(AuditoriaService::SENHA_ALTERADA, self::ENTIDADE, $usuarioId);
        });
    }

    private function auditarFalha(?int $usuarioId, ?string $email, string $motivo): void
    {
        $this->auditoria->registrar(
            AuditoriaService::LOGIN_FALHOU,
            self::ENTIDADE,
            $usuarioId,
            null,
            ['email' => $email !== null ? mb_substr($email, 0, 254) : null, 'motivo' => $motivo]
        );
    }
}
