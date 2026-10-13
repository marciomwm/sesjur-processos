<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\ValidationException;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Repositories\VinculoRepository;

/**
 * Criacao e remocao do relacionamento N:N entre processos.
 * A remocao do vinculo NUNCA exclui os processos envolvidos.
 */
final class VinculoService
{
    public function __construct(
        private readonly VinculoRepository $repo = new VinculoRepository(),
        private readonly ProcessoAdministrativoRepository $administrativos = new ProcessoAdministrativoRepository(),
        private readonly ProcessoJudicialRepository $judiciais = new ProcessoJudicialRepository()
    ) {
    }

    /** @return array{criado:bool, vinculo_id:int} */
    public function vincular(int $administrativoId, int $judicialId, ?string $observacao = null): array
    {
        $this->garantirExistencia($administrativoId, $judicialId);

        if ($this->repo->existe($administrativoId, $judicialId)) {
            return ['criado' => false, 'vinculo_id' => 0];
        }

        $id = Database::transacao(
            fn(): int => $this->repo->criar($administrativoId, $judicialId, $observacao)
        );

        return ['criado' => true, 'vinculo_id' => $id];
    }

    public function desvincular(int $administrativoId, int $judicialId): bool
    {
        $this->garantirExistencia($administrativoId, $judicialId);
        return Database::transacao(
            fn(): bool => $this->repo->remover($administrativoId, $judicialId)
        );
    }

    public function judiciaisDe(int $administrativoId): array
    {
        return $this->repo->judiciaisDoAdministrativo($administrativoId);
    }

    public function administrativosDe(int $judicialId): array
    {
        return $this->repo->administrativosDoJudicial($judicialId);
    }

    private function garantirExistencia(int $administrativoId, int $judicialId): void
    {
        $erros = [];
        if ($this->administrativos->buscarPorId($administrativoId) === null) {
            $erros['processo_administrativo_id'] = 'Processo administrativo não encontrado.';
        }
        if ($this->judiciais->buscarPorId($judicialId) === null) {
            $erros['processo_judicial_id'] = 'Processo judicial não encontrado.';
        }
        if ($erros !== []) {
            throw new ValidationException($erros, 'Não foi possível criar o vínculo.');
        }
    }
}
