<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AnotacaoRepository;
use App\Validators\Normalizador;

/**
 * Anotacoes dos processos administrativos: notas livres internas da equipe
 * (nao confundir com o evento "Anotacao" importado do SIGADOC).
 *
 * Qualquer usuario cria uma anotacao; alterar ou excluir cabe ao
 * administrador, ao gestor ou a quem a criou.
 */
final class AnotacaoService
{
    public const TEXTO_MAX = 10000;

    public const MSG_SEM_PERMISSAO = 'Somente administradores, gestores ou quem criou a anotação podem alterá-la.';

    public function __construct(
        private readonly AnotacaoRepository $repo = new AnotacaoRepository(),
        private readonly ProcessoAdministrativoService $processoServico = new ProcessoAdministrativoService(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    /** O usuario autenticado pode alterar a anotacao (administrador, gestor ou autor). */
    public static function podeAlterar(array $anotacao): bool
    {
        if (Auth::autorizado(Perfil::ADMINISTRADOR, Perfil::GESTOR)) {
            return true;
        }
        $eu = usuario_logado_id();
        return $eu !== null && (int)($anotacao['criado_por'] ?? 0) === $eu;
    }

    /**
     * @param array $entrada processo_id ou protocolo, texto
     * @return int id da anotacao criada
     * @throws ValidationException
     */
    public function criar(array $entrada): int
    {
        $processoId = $this->processoServico->resolverId($entrada);
        $dados = $this->validar($entrada);

        return Database::transacao(function () use ($processoId, $dados): int {
            $dados['processo_administrativo_id'] = $processoId;
            $dados['criado_por'] = usuario_logado_id();

            $id = $this->repo->inserir($dados);
            $this->auditoria->registrar(
                AuditoriaService::ANOTACAO_CRIADA,
                'processos_administrativos',
                $processoId,
                null,
                ['anotacao_id' => $id, 'texto' => $dados['texto']]
            );
            return $id;
        });
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function atualizar(int $id, array $entrada): void
    {
        $atual = $this->exigirAlteravel($id);
        $dados = $this->validar($entrada);
        if ($dados['texto'] === $atual['texto']) {
            return;
        }

        Database::transacao(function () use ($id, $atual, $dados): void {
            $this->repo->atualizar($id, $dados);
            $this->auditoria->registrar(
                AuditoriaService::ANOTACAO_EDITADA,
                'processos_administrativos',
                (int)$atual['processo_administrativo_id'],
                ['anotacao_id' => $id, 'texto' => $atual['texto']],
                ['anotacao_id' => $id, 'texto' => $dados['texto']]
            );
        });
    }

    /**
     * @return int id do processo da anotacao excluida
     * @throws AcessoNegadoException
     * @throws ValidationException
     */
    public function excluir(int $id): int
    {
        $atual = $this->exigirAlteravel($id);
        $processoId = (int)$atual['processo_administrativo_id'];

        Database::transacao(function () use ($id, $atual, $processoId): void {
            $this->repo->excluir($id);
            $this->auditoria->registrar(
                AuditoriaService::ANOTACAO_EXCLUIDA,
                'processos_administrativos',
                $processoId,
                ['anotacao_id' => $id, 'texto' => $atual['texto']],
                null
            );
        });
        return $processoId;
    }

    /**
     * Valida e normaliza o texto, preservando as quebras de linha.
     * @throws ValidationException
     */
    public function validar(array $entrada): array
    {
        $texto = self::normalizarTexto($entrada['texto'] ?? null);
        if ($texto === null) {
            throw new ValidationException(['texto' => 'Escreva a anotação.']);
        }
        if (mb_strlen($texto) > self::TEXTO_MAX) {
            throw new ValidationException(['texto' => 'A anotação deve ter no máximo ' . self::TEXTO_MAX . ' caracteres.']);
        }
        return ['texto' => $texto];
    }

    /** Texto de varias linhas, com as quebras preservadas. */
    public static function normalizarTexto(mixed $valor): ?string
    {
        return Normalizador::textoLongo($valor);
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    private function exigirAlteravel(int $id): array
    {
        $anotacao = $this->repo->buscarPorId($id);
        if ($anotacao === null) {
            throw new ValidationException([], 'Anotação não encontrada.');
        }
        if (!self::podeAlterar($anotacao)) {
            throw new AcessoNegadoException(self::MSG_SEM_PERMISSAO);
        }
        return $anotacao;
    }
}
