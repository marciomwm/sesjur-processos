<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\ValidationException;
use App\Repositories\InstituicaoRepository;
use App\Repositories\LotacaoRepository;
use App\Repositories\NivelOrganizacionalRepository;
use App\Validators\Normalizador;
use PDOException;

/**
 * Unidades Administrativas (antiga "Lotacao"): instituicao, nivel, unidade
 * superior, nome, sigla, gestor, lotacionograma e competencias.
 *
 * Gestor e lotacionograma sao servidores digitados na propria unidade,
 * independentes do cadastro de usuarios. Sem exclusao: unidade extinta e
 * inativada e continua valendo para usuarios e solicitacoes que ja a usam.
 * A auditoria segue na entidade `lotacoes` (LOTACAO_CRIADA...), preservando
 * o historico anterior.
 */
final class UnidadeAdministrativaService
{
    public const NOME_MIN          = 2;
    public const NOME_MAX          = 150;
    public const PESSOA_MAX        = 150;
    public const MATRICULA_MAX     = 30;
    public const CARGO_MAX         = 100;
    public const COMPETENCIAS_MAX  = 20000;
    public const SERVIDORES_MAX    = 500;

    public function __construct(
        private readonly LotacaoRepository $repo = new LotacaoRepository(),
        private readonly InstituicaoRepository $instituicoes = new InstituicaoRepository(),
        private readonly NivelOrganizacionalRepository $niveis = new NivelOrganizacionalRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    /**
     * @param array $entrada instituicao_id, nivel_id ou nivel_novo, unidade_superior_id, nome, sigla,
     *                       gestor_nome, gestor_matricula, competencias,
     *                       servidores (lista de {nome, matricula, cargo})
     * @return int id da unidade criada
     * @throws ValidationException
     */
    public function criar(array $entrada): int
    {
        return $this->comTraducaoDeDuplicidade(fn(): int => Database::transacao(function () use ($entrada): int {
            [$dados, $servidores, $nivelNovo] = $this->validar($entrada, null);
            $dados['nivel_id'] = $nivelNovo !== null ? $this->nivelPorNome($nivelNovo) : $dados['nivel_id'];

            $id = $this->repo->inserir($dados);
            $this->repo->substituirServidores($id, $servidores);
            $this->auditoria->registrar('LOTACAO_CRIADA', 'lotacoes', $id, null, self::resumo($dados, $servidores) + ['ativo' => 1]);
            return $id;
        }));
    }

    /** @throws ValidationException */
    public function atualizar(int $id, array $entrada): void
    {
        $this->comTraducaoDeDuplicidade(fn() => Database::transacao(function () use ($id, $entrada): void {
            $atual = $this->repo->buscarParaAtualizar($id);
            if ($atual === null) {
                throw new ValidationException([], 'Unidade administrativa não encontrada.');
            }
            [$dados, $servidores, $nivelNovo] = $this->validar($entrada, $atual);
            $dados['nivel_id'] = $nivelNovo !== null ? $this->nivelPorNome($nivelNovo) : $dados['nivel_id'];

            [$antes, $depois] = AuditoriaService::diferencas(
                self::resumo($atual, $this->repo->servidores($id)),
                self::resumo($dados, $servidores)
            );
            if ($depois === []) {
                return;
            }
            $this->repo->atualizar($id, $dados);
            $this->repo->substituirServidores($id, $servidores);
            $this->auditoria->registrar('LOTACAO_EDITADA', 'lotacoes', $id, $antes, $depois);
        }));
    }

    /**
     * Ativa ou inativa. Retorna false quando ja estava no status pedido.
     * @throws ValidationException
     */
    public function alterarStatus(int $id, bool $ativo): bool
    {
        return Database::transacao(function () use ($id, $ativo): bool {
            $atual = $this->repo->buscarParaAtualizar($id);
            if ($atual === null) {
                throw new ValidationException([], 'Unidade administrativa não encontrada.');
            }
            if (((int)$atual['ativo'] === 1) === $ativo) {
                return false;
            }
            $this->repo->definirAtivo($id, $ativo);
            $this->auditoria->registrar(
                $ativo ? 'LOTACAO_ATIVADA' : 'LOTACAO_INATIVADA',
                'lotacoes',
                $id,
                ['ativo' => $ativo ? 0 : 1],
                ['ativo' => $ativo ? 1 : 0]
            );
            return true;
        });
    }

    /**
     * Valida e normaliza. $atual e a unidade gravada (edicao) ou null.
     * @return array{0:array,1:list<array{nome:string,matricula:?string,cargo:?string}>,2:?string}
     *         [colunas da unidade, lotacionograma, nome de nivel novo a criar]
     * @throws ValidationException
     */
    public function validar(array $entrada, ?array $atual): array
    {
        $erros = [];
        $id = $atual !== null ? (int)$atual['id'] : null;

        // Instituicao: obrigatoria; inativa so se ja era a gravada.
        $instituicaoId = self::id($entrada['instituicao_id'] ?? null);
        if ($instituicaoId === null) {
            $erros['instituicao_id'] = 'Selecione a instituição.';
        } else {
            $instituicao = $this->instituicoes->buscarPorId($instituicaoId);
            if ($instituicao === null) {
                $erros['instituicao_id'] = 'Instituição não encontrada.';
            } elseif ((int)$instituicao['ativo'] !== 1 && $instituicaoId !== self::id($atual['instituicao_id'] ?? null)) {
                $erros['instituicao_id'] = 'A instituição ' . $instituicao['nome'] . ' está inativa.';
            }
        }

        // Nivel: escolhido na lista ou digitado (criado ao salvar).
        $nivelId = null;
        $nivelNovo = Normalizador::texto(is_string($entrada['nivel_novo'] ?? null) ? $entrada['nivel_novo'] : null);
        if ($nivelNovo !== null) {
            if (mb_strlen($nivelNovo) < self::NOME_MIN || mb_strlen($nivelNovo) > 100) {
                $erros['nivel_id'] = 'O novo nível deve ter entre ' . self::NOME_MIN . ' e 100 caracteres.';
            } elseif (!preg_match('/\p{L}/u', $nivelNovo)) {
                $erros['nivel_id'] = 'O nome do nível deve conter letras.';
            }
        } else {
            $nivelId = self::id($entrada['nivel_id'] ?? null);
            if ($nivelId !== null) {
                $nivel = $this->niveis->buscarPorId($nivelId);
                if ($nivel === null) {
                    $erros['nivel_id'] = 'Nível não encontrado.';
                } elseif ((int)$nivel['ativo'] !== 1 && $nivelId !== self::id($atual['nivel_id'] ?? null)) {
                    $erros['nivel_id'] = 'O nível ' . $nivel['nome'] . ' está inativo.';
                }
            }
        }

        // Nome: unico dentro da instituicao.
        $nome = Normalizador::texto(is_string($entrada['nome'] ?? null) ? $entrada['nome'] : null);
        if ($nome === null) {
            $erros['nome'] = 'Informe o nome da unidade.';
        } elseif (mb_strlen($nome) < self::NOME_MIN || mb_strlen($nome) > self::NOME_MAX) {
            $erros['nome'] = 'O nome deve ter entre ' . self::NOME_MIN . ' e ' . self::NOME_MAX . ' caracteres.';
        } elseif (!preg_match('/\p{L}/u', $nome)) {
            $erros['nome'] = 'O nome deve conter letras.';
        } elseif (!isset($erros['instituicao_id']) && $this->repo->nomeEmUso($instituicaoId, $nome, $id)) {
            $erros['nome'] = 'Já existe uma unidade com este nome nesta instituição.';
        }

        [$sigla, $erroSigla] = TabelaApoioService::sigla($entrada['sigla'] ?? null);
        if ($erroSigla !== null) {
            $erros['sigla'] = $erroSigla;
        } elseif ($sigla !== null && $this->repo->valorEmUso('sigla', $sigla, $id)) {
            $erros['sigla'] = 'Já existe uma unidade cadastrada com esta sigla.';
        }

        // Unidade superior: mesma instituicao e sem formar ciclo.
        $superiorId = self::id($entrada['unidade_superior_id'] ?? null);
        if ($superiorId !== null) {
            $erro = $this->erroSuperior($superiorId, $id, $instituicaoId, self::id($atual['unidade_superior_id'] ?? null));
            if ($erro !== null) {
                $erros['unidade_superior_id'] = $erro;
            }
        }

        // Gestor: servidor digitado.
        $gestorNome = Normalizador::texto(is_string($entrada['gestor_nome'] ?? null) ? $entrada['gestor_nome'] : null);
        $gestorMatricula = Normalizador::texto(is_string($entrada['gestor_matricula'] ?? null) ? $entrada['gestor_matricula'] : null);
        if ($gestorNome !== null && mb_strlen($gestorNome) > self::PESSOA_MAX) {
            $erros['gestor_nome'] = 'O nome do gestor deve ter no máximo ' . self::PESSOA_MAX . ' caracteres.';
        }
        if ($gestorMatricula !== null) {
            if ($gestorNome === null) {
                $erros['gestor_nome'] = 'Informe o nome do gestor da matrícula ' . $gestorMatricula . '.';
            } elseif (mb_strlen($gestorMatricula) > self::MATRICULA_MAX) {
                $erros['gestor_matricula'] = 'A matrícula deve ter no máximo ' . self::MATRICULA_MAX . ' caracteres.';
            }
        }

        $competencias = Normalizador::textoLongo($entrada['competencias'] ?? null);
        if ($competencias !== null && mb_strlen($competencias) > self::COMPETENCIAS_MAX) {
            $erros['competencias'] = 'As competências devem ter no máximo ' . self::COMPETENCIAS_MAX . ' caracteres.';
        }

        [$servidores, $erroServidores] = self::servidores($entrada['servidores'] ?? []);
        if ($erroServidores !== null) {
            $erros['servidores'] = $erroServidores;
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }

        return [[
            'instituicao_id'      => $instituicaoId,
            'nivel_id'            => $nivelId,
            'unidade_superior_id' => $superiorId,
            'nome'                => $nome,
            'sigla'               => $sigla,
            'gestor_nome'         => $gestorNome,
            'gestor_matricula'    => $gestorNome !== null ? $gestorMatricula : null,
            'competencias'        => $competencias,
        ], $servidores, $nivelNovo];
    }

    /**
     * Lotacionograma enviado pelo formulario (linhas totalmente vazias sao
     * ignoradas).
     * @return array{0:list<array{nome:string,matricula:?string,cargo:?string}>,1:?string}
     */
    public static function servidores(mixed $lista): array
    {
        if (!is_array($lista)) {
            return [[], null];
        }
        $saida = [];
        $matriculas = [];
        $linha = 0;
        foreach ($lista as $item) {
            $linha++;
            if (!is_array($item)) {
                continue;
            }
            $campo = static fn(string $c): ?string => Normalizador::texto(is_string($item[$c] ?? null) ? $item[$c] : null);
            [$nome, $matricula, $cargo] = [$campo('nome'), $campo('matricula'), $campo('cargo')];
            if ($nome === null && $matricula === null && $cargo === null) {
                continue;
            }
            if ($nome === null) {
                return [[], "Lotacionograma, linha {$linha}: informe o nome do servidor."];
            }
            if (mb_strlen($nome) > self::PESSOA_MAX || ($matricula !== null && mb_strlen($matricula) > self::MATRICULA_MAX)
                || ($cargo !== null && mb_strlen($cargo) > self::CARGO_MAX)) {
                return [[], "Lotacionograma, linha {$linha}: texto longo demais (nome até " . self::PESSOA_MAX
                    . ', matrícula até ' . self::MATRICULA_MAX . ', cargo até ' . self::CARGO_MAX . ' caracteres).'];
            }
            if ($matricula !== null) {
                $chave = mb_strtoupper($matricula);
                if (isset($matriculas[$chave])) {
                    return [[], "Lotacionograma: a matrícula {$matricula} aparece mais de uma vez."];
                }
                $matriculas[$chave] = true;
            }
            $saida[] = ['nome' => $nome, 'matricula' => $matricula, 'cargo' => $cargo];
        }
        if (count($saida) > self::SERVIDORES_MAX) {
            return [[], 'O lotacionograma aceita no máximo ' . self::SERVIDORES_MAX . ' servidores.'];
        }
        return [$saida, null];
    }

    /**
     * Unidades de $estrutura (LotacaoRepository::estrutura) do caminho entre a
     * raiz e a unidade, inclusive.
     * @return list<array>
     */
    public static function caminho(array $estrutura, int $id): array
    {
        $caminho = [];
        $visitados = [];
        $atual = $id;
        while ($atual !== null && isset($estrutura[$atual]) && !isset($visitados[$atual])) {
            $visitados[$atual] = true;
            array_unshift($caminho, $estrutura[$atual]);
            $atual = $estrutura[$atual]['unidade_superior_id'];
        }
        return $caminho;
    }

    /**
     * Ordena as unidades como arvore (cada uma logo abaixo da sua superior),
     * com a profundidade de cada uma. Unidades cuja superior nao esta na
     * lista (filtro) aparecem na posicao da superior mais proxima presente.
     * @return list<array> unidades com a chave `profundidade`
     */
    public static function emArvore(array $unidades, array $estrutura): array
    {
        $chaves = [];
        foreach ($unidades as $i => $u) {
            $caminho = self::caminho($estrutura, (int)$u['id']);
            $partes = array_map(static fn(array $c): string => mb_strtolower($c['nome']) . '#' . $c['id'], $caminho);
            $chaves[$i] = ($u['instituicao_sigla'] ?? '~') . '|' . implode('|', $partes);
            $unidades[$i]['profundidade'] = max(0, count($caminho) - 1);
        }
        uksort($unidades, static fn($a, $b): int => strcmp($chaves[$a], $chaves[$b]));
        return array_values($unidades);
    }

    /** Cores das instituicoes nas visualizacoes, pela posicao na lista (estaveis entre sessoes). */
    public const PALETA = ['#3461fd', '#12b7a3', '#8b5cf6', '#ea8a3b', '#e0507a', '#2fa84f', '#0ea5e9', '#d6336c'];
    public const COR_SEM_INSTITUICAO = '#8a94a6';

    /**
     * Dados das visualizacoes Lista, Hierarquia e Nivel (montadas no navegador):
     * instituicoes com cor, niveis e as unidades com os campos exibidos.
     * A unidade cuja superior nao esta na lista vira raiz, para nunca sumir.
     * @param list<array> $unidades linhas de LotacaoRepository::listarUnidades
     * @param list<array> $instituicoes linhas de InstituicaoRepository::todos
     * @param list<array> $niveis linhas de NivelOrganizacionalRepository::todos
     */
    public static function paraVisualizacao(array $unidades, array $instituicoes, array $niveis): array
    {
        $ids = array_flip(array_map(static fn(array $u): int => (int)$u['id'], $unidades));
        $inteiro = static fn(mixed $v): ?int => $v !== null && $v !== '' ? (int)$v : null;

        return [
            'instituicoes' => array_map(static fn(array $i, int $k): array => [
                'id'    => (int)$i['id'],
                'nome'  => $i['nome'],
                'sigla' => $i['sigla'] ?? null,
                'ativo' => (int)$i['ativo'] === 1,
                'cor'   => self::PALETA[$k % count(self::PALETA)],
            ], array_values($instituicoes), array_keys(array_values($instituicoes))),
            'niveis' => array_map(static fn(array $n): array => [
                'id'   => (int)$n['id'],
                'nome' => $n['nome'],
            ], array_values($niveis)),
            'unidades' => array_map(static function (array $u) use ($ids, $inteiro): array {
                $superior = $inteiro($u['unidade_superior_id'] ?? null);
                return [
                    'id'               => (int)$u['id'],
                    'nome'             => $u['nome'],
                    'sigla'            => $u['sigla'] ?? null,
                    'instituicao_id'   => $inteiro($u['instituicao_id'] ?? null),
                    'nivel_id'         => $inteiro($u['nivel_id'] ?? null),
                    'superior_id'      => $superior !== null && isset($ids[$superior]) ? $superior : null,
                    'gestor'           => $u['gestor_nome'] ?? null,
                    'gestor_matricula' => $u['gestor_matricula'] ?? null,
                    'competencias'     => $u['competencias'] ?? null,
                    'ativo'            => (int)$u['ativo'] === 1,
                    'servidores'       => (int)($u['total_servidores'] ?? 0),
                    'usuarios'         => (int)($u['usuarios_ativos'] ?? 0),
                ];
            }, array_values($unidades)),
        ];
    }

    /**
     * Opcoes do campo "Unidade superior": unidades ativas (e a ja gravada),
     * exceto a propria e as que estao abaixo dela, rotuladas pelo caminho
     * na hierarquia e ordenadas como arvore.
     * @return list<array{id:int,rotulo:string,instituicao_id:?int,ativo:int}>
     */
    public static function opcoesSuperior(array $estrutura, ?int $id, ?int $superiorAtual): array
    {
        $excluir = $id !== null ? array_flip(self::descendentes($estrutura, $id)) : [];
        $opcoes = [];
        foreach ($estrutura as $u) {
            if (isset($excluir[$u['id']]) || ($u['ativo'] !== 1 && $u['id'] !== $superiorAtual)) {
                continue;
            }
            $nomes = array_column(self::caminho($estrutura, $u['id']), 'nome');
            $opcoes[] = [
                'id'             => $u['id'],
                'rotulo'         => implode(' › ', $nomes),
                'instituicao_id' => $u['instituicao_id'],
                'ativo'          => $u['ativo'],
                'ordem'          => sprintf('%05d', $u['instituicao_id'] ?? 99999) . '|' . mb_strtolower(implode('|', $nomes)),
            ];
        }
        usort($opcoes, static fn(array $a, array $b): int => strcmp($a['ordem'], $b['ordem']));
        return $opcoes;
    }

    /** Ids da unidade e de todas as que estao abaixo dela. */
    public static function descendentes(array $estrutura, int $id): array
    {
        $ids = [$id => true];
        do {
            $mudou = false;
            foreach ($estrutura as $u) {
                if ($u['unidade_superior_id'] !== null && isset($ids[$u['unidade_superior_id']]) && !isset($ids[$u['id']])) {
                    $ids[$u['id']] = true;
                    $mudou = true;
                }
            }
        } while ($mudou);
        return array_keys($ids);
    }

    private function erroSuperior(int $superiorId, ?int $id, ?int $instituicaoId, ?int $superiorAtual): ?string
    {
        $estrutura = $this->repo->estrutura();
        $superior = $estrutura[$superiorId] ?? null;
        if ($superior === null) {
            return 'Unidade superior não encontrada.';
        }
        if ($id !== null && in_array($superiorId, self::descendentes($estrutura, $id), true)) {
            return $superiorId === $id
                ? 'A unidade não pode ser superior a si mesma.'
                : 'A unidade escolhida está abaixo desta na hierarquia; isso formaria um ciclo.';
        }
        if ($instituicaoId !== null && $superior['instituicao_id'] !== $instituicaoId) {
            return 'A unidade superior deve ser da mesma instituição.';
        }
        if ($superior['ativo'] !== 1 && $superiorId !== $superiorAtual) {
            return 'A unidade ' . $superior['nome'] . ' está inativa.';
        }
        return null;
    }

    /** Id do nivel com esse nome, criado se ainda nao existir. */
    private function nivelPorNome(string $nome): int
    {
        $existente = $this->niveis->buscarPorNome($nome);
        if ($existente !== null) {
            if ((int)$existente['ativo'] !== 1) {
                throw new ValidationException(['nivel_id' => 'O nível ' . $existente['nome'] . ' já existe e está inativo. Reative-o em Níveis Organizacionais.']);
            }
            return (int)$existente['id'];
        }
        $id = $this->niveis->inserir(['nome' => $nome]);
        $this->auditoria->registrar('NIVEL_CRIADO', 'niveis', $id, null, ['nome' => $nome, 'ativo' => 1]);
        return $id;
    }

    private static function id(mixed $valor): ?int
    {
        return is_numeric($valor) && (int)$valor > 0 ? (int)$valor : null;
    }

    /** Campos auditados; o lotacionograma vira uma linha por servidor. */
    private static function resumo(array $dados, array $servidores): array
    {
        $resumo = [];
        foreach (['instituicao_id', 'nivel_id', 'unidade_superior_id', 'nome', 'sigla', 'gestor_nome', 'gestor_matricula', 'competencias'] as $c) {
            $resumo[$c] = $dados[$c] ?? null;
        }
        $resumo['lotacionograma'] = implode("\n", array_map(
            static fn(array $s): string => implode(' · ', array_filter([$s['nome'], $s['matricula'] ?? null, $s['cargo'] ?? null])),
            $servidores
        )) ?: null;
        return $resumo;
    }

    /** Converte violacao de UNIQUE (concorrencia) na mensagem amigavel. */
    private function comTraducaoDeDuplicidade(callable $operacao): mixed
    {
        try {
            return $operacao();
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            if (str_contains($e->getMessage(), 'uq_lotacoes_sigla')) {
                throw new ValidationException(['sigla' => 'Já existe uma unidade cadastrada com esta sigla.']);
            }
            if (str_contains($e->getMessage(), 'uq_lotacoes_nome')) {
                throw new ValidationException(['nome' => 'Já existe uma unidade com este nome nesta instituição.']);
            }
            throw $e;
        }
    }
}
