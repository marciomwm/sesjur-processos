<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\ProdutoSaudeRepository;
use App\Repositories\SigtapRepository;
use App\Validators\Normalizador;
use PDOException;

/**
 * Produtos de saude do processo administrativo: o objeto material da
 * demanda (medicamento, procedimento, exame...), com a classificacao SIGTAP
 * opcional.
 *
 * A classificacao aponta para sigtap_procedimentos. Enquanto a tabela
 * oficial nao e importada, um codigo ainda desconhecido e cadastrado ali
 * mesmo (origem MANUAL), com a descricao digitada; um codigo ja cadastrado
 * e reaproveitado, sem duplicar.
 *
 * Mesma regra de alteracao das Acoes: qualquer usuario inclui; alterar ou
 * excluir cabe ao administrador, ao gestor ou a quem incluiu.
 */
final class ProdutoSaudeService
{
    public const CATEGORIAS = [
        'MEDICAMENTO'          => 'Medicamento',
        'MATERIAL'             => 'Material médico-hospitalar',
        'PROCEDIMENTO'         => 'Procedimento',
        'EXAME'                => 'Exame',
        'CONSULTA'             => 'Consulta',
        'CIRURGIA'             => 'Cirurgia',
        'ORTESE'               => 'Órtese',
        'PROTESE'              => 'Prótese',
        'INSUMO'               => 'Insumo',
        'EQUIPAMENTO'          => 'Equipamento',
        'TRANSPORTE_SANITARIO' => 'Transporte sanitário',
        'OUTRO'                => 'Outro',
    ];

    public const DESCRICAO_MAX   = 500;
    public const SIGTAP_NOME_MAX = 250;
    public const ESTRUTURA_MAX   = 150;

    public const MSG_SEM_PERMISSAO = 'Somente administradores, gestores ou quem incluiu o produto podem alterá-lo.';

    public function __construct(
        private readonly ProdutoSaudeRepository $repo = new ProdutoSaudeRepository(),
        private readonly SigtapRepository $sigtap = new SigtapRepository(),
        private readonly ProcessoAdministrativoService $processos = new ProcessoAdministrativoService(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    /** Codigo SIGTAP com 10 digitos (aceita a mascara 03.01.01.007-2); null quando vazio ou invalido. */
    public static function codigoSigtap(mixed $valor): ?string
    {
        $digitos = Normalizador::digitos(is_scalar($valor) ? (string)$valor : null);
        return strlen($digitos) === 10 ? $digitos : null;
    }

    /** "0301010072" -> "03.01.01.007-2". */
    public static function formatarSigtap(?string $codigo): string
    {
        if ($codigo === null || strlen($codigo) !== 10) {
            return (string)$codigo;
        }
        return substr($codigo, 0, 2) . '.' . substr($codigo, 2, 2) . '.' . substr($codigo, 4, 2) . '.'
            . substr($codigo, 6, 3) . '-' . substr($codigo, 9, 1);
    }

    public static function podeAlterar(array $produto): bool
    {
        if (Auth::autorizado(Perfil::ADMINISTRADOR, Perfil::GESTOR)) {
            return true;
        }
        $eu = usuario_logado_id();
        return $eu !== null && (int)($produto['criado_por'] ?? 0) === $eu;
    }

    /**
     * @param array $entrada processo_id, categoria, descricao, sigtap_codigo, sigtap_nome,
     *                       sigtap_grupo_nome, sigtap_subgrupo_nome, sigtap_forma_organizacao_nome
     * @throws ValidationException
     */
    public function criar(array $entrada): int
    {
        $processoId = $this->processos->resolverId($entrada);
        $dados = $this->validar($entrada, $processoId, null);

        return $this->comTraducaoDeDuplicidade(fn(): int => Database::transacao(function () use ($processoId, $dados): int {
            $dados['processo_administrativo_id'] = $processoId;
            $dados['sigtap_procedimento_id'] = $this->resolverSigtap($dados['sigtap']);
            $id = $this->repo->inserir($dados, usuario_logado_id());
            $this->auditoria->registrar(
                AuditoriaService::PRODUTO_SAUDE_CRIADO,
                'processos_administrativos',
                $processoId,
                null,
                ['produto_id' => $id] + self::resumo($dados)
            );
            return $id;
        }));
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function atualizar(int $id, array $entrada): void
    {
        $atual = $this->exigirAlteravel($id);
        $processoId = (int)$atual['processo_administrativo_id'];
        $dados = $this->validar($entrada, $processoId, $id);

        $this->comTraducaoDeDuplicidade(fn() => Database::transacao(function () use ($id, $atual, $processoId, $dados): void {
            $dados['sigtap_procedimento_id'] = $this->resolverSigtap($dados['sigtap']);
            [$antes, $depois] = AuditoriaService::diferencas(self::resumo($atual), self::resumo($dados));
            if ($depois === [] && (int)($atual['sigtap_procedimento_id'] ?? 0) === (int)($dados['sigtap_procedimento_id'] ?? 0)) {
                return;
            }
            $this->repo->atualizar($id, $dados, usuario_logado_id());
            if ($depois !== []) {
                $this->auditoria->registrar(
                    AuditoriaService::PRODUTO_SAUDE_EDITADO,
                    'processos_administrativos',
                    $processoId,
                    ['produto_id' => $id] + $antes,
                    ['produto_id' => $id] + $depois
                );
            }
        }));
    }

    /**
     * @return int id do processo do produto excluido
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function excluir(int $id): int
    {
        $atual = $this->exigirAlteravel($id);
        $processoId = (int)$atual['processo_administrativo_id'];
        Database::transacao(function () use ($id, $atual, $processoId): void {
            $this->repo->excluir($id);
            $this->auditoria->registrar(
                AuditoriaService::PRODUTO_SAUDE_EXCLUIDO,
                'processos_administrativos',
                $processoId,
                ['produto_id' => $id] + self::resumo($atual),
                null
            );
        });
        return $processoId;
    }

    /**
     * Valida e normaliza. `sigtap` fica null (sem classificacao) ou com
     * codigo, nome e a estrutura digitada.
     * @throws ValidationException
     */
    public function validar(array $entrada, int $processoId, ?int $ignorarId): array
    {
        $erros = [];

        $categoria = strtoupper(trim((string)($entrada['categoria'] ?? '')));
        if (!isset(self::CATEGORIAS[$categoria])) {
            $erros['categoria'] = 'Escolha a categoria do produto.';
        }

        $descricao = Normalizador::texto(is_string($entrada['descricao'] ?? null) ? $entrada['descricao'] : null);
        if ($descricao === null) {
            $erros['descricao'] = 'Descreva o produto, por exemplo “Rituximabe 500 mg”.';
        } elseif (mb_strlen($descricao) > self::DESCRICAO_MAX) {
            $erros['descricao'] = 'A descrição deve ter no máximo ' . self::DESCRICAO_MAX . ' caracteres.';
        } elseif (!isset($erros['categoria']) && $this->repo->itemExiste($processoId, $categoria, $descricao, $ignorarId)) {
            $erros['descricao'] = 'Este produto já está relacionado ao processo.';
        }

        $sigtap = null;
        $codigoBruto = Normalizador::texto(is_string($entrada['sigtap_codigo'] ?? null) ? $entrada['sigtap_codigo'] : null);
        $nome = Normalizador::texto(is_string($entrada['sigtap_nome'] ?? null) ? $entrada['sigtap_nome'] : null);
        $estrutura = [];
        foreach (['grupo_nome', 'subgrupo_nome', 'forma_organizacao_nome'] as $campo) {
            $valor = Normalizador::texto(is_string($entrada['sigtap_' . $campo] ?? null) ? $entrada['sigtap_' . $campo] : null);
            if ($valor !== null && mb_strlen($valor) > self::ESTRUTURA_MAX) {
                $erros['sigtap_' . $campo] = 'Use no máximo ' . self::ESTRUTURA_MAX . ' caracteres.';
            }
            $estrutura[$campo] = $valor;
        }

        if ($codigoBruto !== null) {
            $codigo = self::codigoSigtap($codigoBruto);
            if ($codigo === null) {
                $erros['sigtap_codigo'] = 'O código SIGTAP tem 10 dígitos, por exemplo 03.01.01.007-2.';
            } else {
                $existente = $this->sigtap->buscarPorCodigo($codigo);
                if ($existente === null && $nome === null) {
                    $erros['sigtap_nome'] = 'Código ainda não cadastrado: informe a descrição oficial do procedimento.';
                } elseif ($nome !== null && mb_strlen($nome) > self::SIGTAP_NOME_MAX) {
                    $erros['sigtap_nome'] = 'A descrição deve ter no máximo ' . self::SIGTAP_NOME_MAX . ' caracteres.';
                }
                $sigtap = ['codigo' => $codigo, 'nome' => $existente['nome'] ?? $nome] + $estrutura;
            }
        } elseif ($nome !== null || array_filter($estrutura) !== []) {
            $erros['sigtap_codigo'] = 'Informe o código SIGTAP ou deixe a classificação em branco.';
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }
        return ['categoria' => $categoria, 'descricao' => $descricao, 'sigtap' => $sigtap];
    }

    /** Id do procedimento SIGTAP, cadastrando o codigo novo (chamar em transacao). */
    private function resolverSigtap(?array $sigtap): ?int
    {
        if ($sigtap === null) {
            return null;
        }
        $existente = $this->sigtap->buscarPorCodigo($sigtap['codigo']);
        if ($existente !== null) {
            $this->sigtap->completarEstrutura((int)$existente['id'], $sigtap, usuario_logado_id());
            return (int)$existente['id'];
        }
        $id = $this->sigtap->inserir($sigtap, usuario_logado_id());
        $this->auditoria->registrar('SIGTAP_CRIADO', 'sigtap_procedimentos', $id, null, $sigtap + ['origem' => 'MANUAL']);
        return $id;
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    private function exigirAlteravel(int $id): array
    {
        $produto = $this->repo->buscarPorId($id);
        if ($produto === null) {
            throw new ValidationException([], 'Produto de saúde não encontrado.');
        }
        if (!self::podeAlterar($produto)) {
            throw new AcessoNegadoException(self::MSG_SEM_PERMISSAO);
        }
        return $produto;
    }

    /** Campos gravados na auditoria do processo. */
    private static function resumo(array $dados): array
    {
        $codigo = $dados['sigtap']['codigo'] ?? $dados['sigtap_codigo'] ?? null;
        return [
            'categoria' => $dados['categoria'] ?? null,
            'descricao' => $dados['descricao'] ?? null,
            'sigtap'    => $codigo !== null ? self::formatarSigtap($codigo) : null,
        ];
    }

    private function comTraducaoDeDuplicidade(callable $operacao): mixed
    {
        try {
            return $operacao();
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_paps_item')) {
                throw new ValidationException(['descricao' => 'Este produto já está relacionado ao processo.']);
            }
            throw $e;
        }
    }
}
