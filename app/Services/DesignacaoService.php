<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\DesignacaoRepository;
use App\Repositories\UsuarioRepository;
use App\Validators\Normalizador;
use App\Validators\UsuarioValidator;
use PDOException;

/**
 * Designacao de processos a usuarios para acompanhamento.
 * Somente administradores e gestores designam ou removem designacoes;
 * a regra e aplicada aqui, e nao apenas escondendo botoes na tela.
 */
final class DesignacaoService
{
    public const OBSERVACAO_MAX = 255;
    public const MSG_SEM_PERMISSAO = 'Somente administradores e gestores podem alterar a designação de processos.';
    public const MSG_DUPLICADA     = 'Este usuário já está designado para o processo.';

    public function __construct(
        private readonly DesignacaoRepository $repo = new DesignacaoRepository(),
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function designar(string $tipo, int $processoId, mixed $usuarioId, mixed $observacao = null): int
    {
        $this->exigirPermissao();
        $meta = DesignacaoRepository::meta($tipo);

        $usuarioId = UsuarioValidator::id($usuarioId);
        if ($usuarioId === null) {
            throw new ValidationException(['usuario_id' => 'Selecione o usuário.']);
        }
        $observacao = Normalizador::texto(is_string($observacao) ? $observacao : null);
        if ($observacao !== null && mb_strlen($observacao) > self::OBSERVACAO_MAX) {
            throw new ValidationException(['observacao' => 'A observação deve ter no máximo ' . self::OBSERVACAO_MAX . ' caracteres.']);
        }

        try {
            return Database::transacao(function () use ($tipo, $meta, $processoId, $usuarioId, $observacao): int {
                if (!$this->repo->processoExiste($tipo, $processoId)) {
                    throw new ValidationException([], 'Processo não encontrado.');
                }
                $usuario = $this->usuarios->buscarPorId($usuarioId);
                if ($usuario === null || (int)$usuario['ativo'] !== 1) {
                    throw new ValidationException(['usuario_id' => 'Selecione um usuário ativo.']);
                }
                if ($this->repo->existe($tipo, $processoId, $usuarioId)) {
                    throw new ValidationException(['usuario_id' => self::MSG_DUPLICADA]);
                }

                $id = $this->repo->inserir($tipo, $processoId, $usuarioId, usuario_logado_id(), $observacao);
                $this->auditoria->registrar(
                    AuditoriaService::DESIGNACAO_CRIADA,
                    $meta['processos'],
                    $processoId,
                    null,
                    ['usuario_id' => $usuarioId, 'usuario' => $usuario['nome'], 'observacao' => $observacao]
                );
                return $id;
            });
        } catch (PDOException $e) {
            // Duas designacoes simultaneas: a UNIQUE do banco e a garantia final.
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new ValidationException(['usuario_id' => self::MSG_DUPLICADA]);
            }
            throw $e;
        }
    }

    /**
     * Remove uma designacao do processo informado. Retorna false se ela ja
     * nao existia.
     * @throws AcessoNegadoException
     */
    public function remover(string $tipo, int $processoId, int $designacaoId): bool
    {
        $this->exigirPermissao();
        $meta = DesignacaoRepository::meta($tipo);

        return Database::transacao(function () use ($tipo, $meta, $processoId, $designacaoId): bool {
            $designacao = $this->repo->buscarParaRemover($tipo, $designacaoId);
            if ($designacao === null || (int)$designacao['processo_id'] !== $processoId) {
                return false;
            }
            $this->repo->remover($tipo, $designacaoId);
            $this->auditoria->registrar(
                AuditoriaService::DESIGNACAO_REMOVIDA,
                $meta['processos'],
                $processoId,
                ['usuario_id' => (int)$designacao['usuario_id'], 'usuario' => $designacao['nome'], 'observacao' => $designacao['observacao']],
                null
            );
            return true;
        });
    }

    /** @throws AcessoNegadoException */
    private function exigirPermissao(): void
    {
        if (!Auth::autorizado(...Perfil::DESIGNAM)) {
            throw new AcessoNegadoException(self::MSG_SEM_PERMISSAO);
        }
    }
}
