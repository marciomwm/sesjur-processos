<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\ProcedimentoRepository;
use App\Validators\Normalizador;
use PDOException;

/**
 * Catalogo de Procedimentos: cadastro somente por administradores
 * (Configuracoes) e escolha controlada no processo administrativo.
 *
 * O processo so recebe item ativo. Item inativado continua valendo nos
 * processos que ja o possuem (a ficha e a edicao exibem o valor atual sem
 * substitui-lo), mas nao pode ser escolhido para novos vinculos.
 */
final class ProcedimentoService
{
    public const NOME_MIN      = 2;
    public const NOME_MAX      = 160;
    public const DESCRICAO_MAX = 500;

    public const MSG_NOME_DUPLICADO = 'Já existe um procedimento cadastrado com este nome.';

    private const ENTIDADE = 'procedimentos';

    public function __construct(
        private readonly ProcedimentoRepository $repo = new ProcedimentoRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    /**
     * @return int id do procedimento criado
     * @throws ValidationException
     */
    public function criar(array $entrada): int
    {
        Auth::exigirPerfil(Perfil::ADMINISTRADOR);
        return $this->comTraducaoDeDuplicidade(fn(): int => Database::transacao(function () use ($entrada): int {
            $dados = $this->validar($entrada, null);
            $id = $this->repo->inserir($dados, usuario_logado_id());
            $this->auditoria->registrar('PROCEDIMENTO_CRIADO', self::ENTIDADE, $id, null, $dados + ['ativo' => 1]);
            return $id;
        }));
    }

    /** @throws ValidationException */
    public function atualizar(int $id, array $entrada): void
    {
        Auth::exigirPerfil(Perfil::ADMINISTRADOR);
        $this->comTraducaoDeDuplicidade(fn() => Database::transacao(function () use ($id, $entrada): void {
            $atual = $this->repo->buscarParaAtualizar($id);
            if ($atual === null) {
                throw new ValidationException([], 'Procedimento não encontrado.');
            }
            $dados = $this->validar($entrada, $id);
            [$antes, $depois] = AuditoriaService::diferencas($atual, $dados);
            if ($depois !== []) {
                $this->repo->atualizar($id, $dados, usuario_logado_id());
                $this->auditoria->registrar('PROCEDIMENTO_EDITADO', self::ENTIDADE, $id, $antes, $depois);
            }
        }));
    }

    /**
     * Ativa ou inativa. Retorna false quando ja estava no status pedido.
     * Os processos vinculados nunca sao alterados.
     * @throws ValidationException
     */
    public function alterarStatus(int $id, bool $ativo): bool
    {
        Auth::exigirPerfil(Perfil::ADMINISTRADOR);
        return Database::transacao(function () use ($id, $ativo): bool {
            $atual = $this->repo->buscarParaAtualizar($id);
            if ($atual === null) {
                throw new ValidationException([], 'Procedimento não encontrado.');
            }
            if (((int)$atual['ativo'] === 1) === $ativo) {
                return false;
            }
            $this->repo->definirAtivo($id, $ativo, usuario_logado_id());
            $this->auditoria->registrar(
                $ativo ? 'PROCEDIMENTO_ATIVADO' : 'PROCEDIMENTO_INATIVADO',
                self::ENTIDADE,
                $id,
                ['ativo' => $ativo ? 0 : 1],
                ['ativo' => $ativo ? 1 : 0]
            );
            return true;
        });
    }

    /** @throws ValidationException */
    public function validar(array $entrada, ?int $ignorarId): array
    {
        $erros = [];

        // Normaliza espacos (inclusive repetidos); caixa e acentos ficam a
        // cargo da collation na checagem de duplicidade.
        $nome = Normalizador::texto(is_string($entrada['nome'] ?? null) ? $entrada['nome'] : null);
        if ($nome === null) {
            $erros['nome'] = 'Informe o nome do procedimento.';
        } elseif (mb_strlen($nome) < self::NOME_MIN) {
            $erros['nome'] = 'O nome deve ter pelo menos ' . self::NOME_MIN . ' caracteres.';
        } elseif (mb_strlen($nome) > self::NOME_MAX) {
            $erros['nome'] = 'O nome deve ter no máximo ' . self::NOME_MAX . ' caracteres.';
        } elseif (!preg_match('/\p{L}/u', $nome)) {
            $erros['nome'] = 'O nome deve conter letras.';
        } elseif ($this->repo->nomeEmUso($nome, $ignorarId)) {
            $erros['nome'] = self::MSG_NOME_DUPLICADO;
        }

        $descricao = Normalizador::textoLongo($entrada['descricao'] ?? null);
        if ($descricao !== null && mb_strlen($descricao) > self::DESCRICAO_MAX) {
            $erros['descricao'] = 'A descrição deve ter no máximo ' . self::DESCRICAO_MAX . ' caracteres.';
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }
        return ['nome' => $nome, 'descricao' => $descricao];
    }

    /**
     * Valida o procedimento escolhido para um processo administrativo.
     *
     *  - vazio: aceito, salvo quando obrigatorio;
     *  - o mesmo ja gravado no processo: aceito mesmo inativo (o historico
     *    nunca e substituido automaticamente);
     *  - outro: precisa existir e estar ativo.
     *
     * @param ?int $atualId procedimento_id ja gravado no processo (edicao)
     * @return array{0:?int,1:?string} [id escolhido, mensagem de erro]
     */
    public function escolhaParaProcesso(mixed $valor, ?int $atualId, bool $obrigatorio): array
    {
        $bruto = is_string($valor) || is_int($valor) ? trim((string)$valor) : '';
        if ($bruto === '') {
            return [null, $obrigatorio ? 'Selecione o procedimento.' : null];
        }
        if (!ctype_digit($bruto) || (int)$bruto <= 0) {
            return [null, 'Procedimento inválido. Selecione uma das opções da lista.'];
        }
        $id = (int)$bruto;
        if ($atualId !== null && $id === $atualId) {
            return [$id, null];
        }
        $item = $this->repo->buscarPorId($id);
        if ($item === null) {
            return [null, 'Procedimento inválido. Selecione uma das opções da lista.'];
        }
        if ((int)$item['ativo'] !== 1) {
            return [null, 'O procedimento “' . $item['nome'] . '” está inativo e não pode ser escolhido. Selecione um procedimento ativo.'];
        }
        return [$id, null];
    }

    /**
     * O procedimento e obrigatorio nos novos cadastros a partir do momento em
     * que o catalogo tem ao menos um item ativo (sem catalogo, o cadastro de
     * processos nao fica bloqueado durante a implantacao).
     */
    public function obrigatorioEmNovos(): bool
    {
        return $this->repo->existeAtivo();
    }

    /**
     * Conciliacao dos textos livres antigos com o catalogo. So ha
     * correspondencia quando o texto, com espacos normalizados, e igual ao
     * nome de um item (sem distincao de caixa e acentos) - nunca por
     * aproximacao. Com $aplicar, vincula apenas esses processos; o texto
     * original e mantido em todos os casos.
     *
     * @return array{conciliados:list<array>,pendentes:list<array>,vinculados:int}
     */
    public function conciliarTextosLegados(bool $aplicar): array
    {
        Auth::exigirPerfil(Perfil::ADMINISTRADOR);
        $conciliados = [];
        $pendentes = [];
        $vinculados = 0;

        $operacao = function () use ($aplicar, &$conciliados, &$pendentes, &$vinculados): void {
            foreach ($this->repo->textosLegadosSemVinculo() as $linha) {
                $texto = (string)$linha['procedimento'];
                $normalizado = Normalizador::texto($texto);
                $item = $normalizado !== null ? $this->repo->buscarPorNome($normalizado) : null;
                if ($item === null) {
                    $pendentes[] = ['texto' => $texto, 'total' => (int)$linha['total']];
                    continue;
                }
                $registro = ['texto' => $texto, 'total' => (int)$linha['total'], 'procedimento_id' => (int)$item['id'], 'procedimento' => $item['nome']];
                if ($aplicar) {
                    $registro['vinculados'] = $this->repo->vincularTextoLegado($texto, (int)$item['id']);
                    $vinculados += $registro['vinculados'];
                    $this->auditoria->registrar('PROCEDIMENTO_CONCILIADO', self::ENTIDADE, (int)$item['id'], null, [
                        'texto_legado' => $texto,
                        'processos'    => $registro['vinculados'],
                    ]);
                }
                $conciliados[] = $registro;
            }
        };

        $aplicar ? Database::transacao($operacao) : $operacao();

        return ['conciliados' => $conciliados, 'pendentes' => $pendentes, 'vinculados' => $vinculados];
    }

    private function comTraducaoDeDuplicidade(callable $operacao): mixed
    {
        try {
            return $operacao();
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_procedimentos_nome')) {
                throw new ValidationException(['nome' => self::MSG_NOME_DUPLICADO]);
            }
            throw $e;
        }
    }
}
