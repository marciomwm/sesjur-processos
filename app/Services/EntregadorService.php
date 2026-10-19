<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\ValidationException;
use App\Repositories\EntregadorRepository;
use App\Validators\CnpjValidator;
use App\Validators\CpfValidator;
use App\Validators\Normalizador;
use PDOException;

/**
 * Entregadores: pessoas fisicas (CPF) e juridicas (CNPJ) que entregam o
 * objeto a Administracao e, por isso, sao credoras no Pagamento
 * Indenizatorio. O tipo de pessoa vem do proprio documento. Qualquer
 * usuario cadastra e edita (o cadastro e feito durante o registro do
 * pagamento); toda alteracao fica na auditoria.
 */
final class EntregadorService
{
    public const TIPOS = ['FISICA' => 'Pessoa física', 'JURIDICA' => 'Pessoa jurídica'];

    public const NOME_MIN = 2;
    public const NOME_MAX = 200;

    public const MSG_DOCUMENTO_DUPLICADO = 'Já existe um entregador cadastrado com este CPF/CNPJ.';

    private const ENTIDADE = 'entregadores';

    public function __construct(
        private readonly EntregadorRepository $repo = new EntregadorRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    /** CPF ou CNPJ gravado, com a mascara do tipo. */
    public static function formatarDocumento(?string $documento): string
    {
        return strlen((string)$documento) === 11 ? CpfValidator::formatar($documento) : CnpjValidator::formatar($documento);
    }

    /** Rotulo de escolha: "Nome — CPF/CNPJ". */
    public static function rotulo(array $entregador): string
    {
        return $entregador['nome'] . ' — ' . self::formatarDocumento($entregador['documento']);
    }

    /**
     * @return int id do entregador criado
     * @throws ValidationException
     */
    public function criar(array $entrada): int
    {
        return $this->comTraducaoDeDuplicidade(fn(): int => Database::transacao(function () use ($entrada): int {
            $dados = $this->validar($entrada, null);
            $id = $this->repo->inserir($dados, usuario_logado_id());
            $this->auditoria->registrar('ENTREGADOR_CRIADO', self::ENTIDADE, $id, null, $dados);
            return $id;
        }));
    }

    /** @throws ValidationException */
    public function atualizar(int $id, array $entrada): void
    {
        $this->comTraducaoDeDuplicidade(fn() => Database::transacao(function () use ($id, $entrada): void {
            $atual = $this->repo->buscarParaAtualizar($id);
            if ($atual === null) {
                throw new ValidationException([], 'Entregador não encontrado.');
            }
            $dados = $this->validar($entrada, $id);
            [$antes, $depois] = AuditoriaService::diferencas($atual, $dados);
            if ($depois !== []) {
                $this->repo->atualizar($id, $dados, usuario_logado_id());
                $this->auditoria->registrar('ENTREGADOR_EDITADO', self::ENTIDADE, $id, $antes, $depois);
            }
        }));
    }

    /**
     * @return array{tipo_pessoa:string,documento:string,nome:string}
     * @throws ValidationException
     */
    public function validar(array $entrada, ?int $ignorarId): array
    {
        $erros = [];

        $documento = CnpjValidator::normalizar($entrada['documento'] ?? null);
        $tipo = null;
        if ($documento === null) {
            $erros['documento'] = 'Informe o CPF ou o CNPJ.';
        } elseif (strlen($documento) === 11 && ctype_digit($documento)) {
            $tipo = 'FISICA';
            if (!CpfValidator::valido($documento)) {
                $erros['documento'] = 'CPF inválido: confira os dígitos.';
            }
        } elseif (strlen($documento) === 14) {
            $tipo = 'JURIDICA';
            if (!CnpjValidator::valido($documento)) {
                $erros['documento'] = 'CNPJ inválido: confira os caracteres e os dígitos verificadores.';
            }
        } else {
            $erros['documento'] = 'Informe um CPF (11 dígitos) ou um CNPJ (14 caracteres).';
        }
        if (!isset($erros['documento']) && $this->repo->documentoEmUso((string)$documento, $ignorarId)) {
            $erros['documento'] = self::MSG_DOCUMENTO_DUPLICADO;
        }

        $nome = Normalizador::texto(is_string($entrada['nome'] ?? null) ? $entrada['nome'] : null);
        if ($nome === null) {
            $erros['nome'] = 'Informe o nome ou a razão social.';
        } elseif (mb_strlen($nome) < self::NOME_MIN) {
            $erros['nome'] = 'O nome deve ter pelo menos ' . self::NOME_MIN . ' caracteres.';
        } elseif (mb_strlen($nome) > self::NOME_MAX) {
            $erros['nome'] = 'O nome deve ter no máximo ' . self::NOME_MAX . ' caracteres.';
        } elseif (!preg_match('/\p{L}/u', $nome)) {
            $erros['nome'] = 'O nome deve conter letras.';
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }
        return ['tipo_pessoa' => (string)$tipo, 'documento' => (string)$documento, 'nome' => (string)$nome];
    }

    private function comTraducaoDeDuplicidade(callable $operacao): mixed
    {
        try {
            return $operacao();
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_entregadores_documento')) {
                throw new ValidationException(['documento' => self::MSG_DOCUMENTO_DUPLICADO]);
            }
            throw $e;
        }
    }
}
