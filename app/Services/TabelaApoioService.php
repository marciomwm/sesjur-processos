<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\CargoRepository;
use App\Repositories\CategoriaRecursoRepository;
use App\Repositories\InstituicaoExternaRepository;
use App\Repositories\InstituicaoRepository;
use App\Repositories\NivelOrganizacionalRepository;
use App\Repositories\OrgaoExternoRepository;
use App\Repositories\TabelaApoioRepository;
use App\Repositories\TipoRecursoRepository;
use App\Validators\Normalizador;
use InvalidArgumentException;
use PDOException;

/**
 * Manutencao das tabelas de apoio simples: cargos (usuarios), instituicoes
 * e niveis organizacionais (unidades administrativas). Sem exclusao: itens
 * obsoletos sao inativados e continuam valendo para quem ja os possui.
 * As unidades administrativas tem servico proprio (UnidadeAdministrativaService).
 */
final class TabelaApoioService
{
    /** Metadados de cada tabela de apoio. A chave e o parametro ?tipo= das telas. */
    public const TIPOS = [
        'cargos' => [
            'titulo'   => 'Cargos',
            'singular' => 'cargo',
            'artigo'   => 'o',
            'nome_max' => 100,
            'sigla'    => false,
            'acao'     => 'CARGO',
            'sufixo'   => 'O',
            'menu'     => 'cargos',
            'vinculo'  => ['rotulo' => 'Usuários ativos', 'texto' => 'usuário(s) ativo(s)', 'url' => 'usuarios.php', 'param' => 'cargo_id', 'extra' => ['status' => 'todos']],
            'ajuda'    => 'O cargo ficará disponível imediatamente no cadastro de usuários.',
            'exemplo'  => 'Ex.: Assessor Jurídico',
        ],
        'instituicoes' => [
            'titulo'   => 'Instituições',
            'singular' => 'instituição',
            'artigo'   => 'a',
            'nome_max' => 150,
            'sigla'    => true,
            'acao'     => 'INSTITUICAO',
            'sufixo'   => 'A',
            'menu'     => 'instituicoes',
            'vinculo'  => ['rotulo' => 'Unidades ativas', 'texto' => 'unidade(s) administrativa(s) ativa(s)', 'url' => 'unidades-administrativas.php', 'param' => 'instituicao_id', 'extra' => []],
            'ajuda'    => 'Órgão ou entidade a que as unidades administrativas pertencem. Use o nome oficial e a sigla.',
            'exemplo'  => 'Ex.: Secretaria de Estado de Saúde de Mato Grosso',
        ],
        'niveis' => [
            'titulo'   => 'Níveis Organizacionais',
            'singular' => 'nível',
            'artigo'   => 'o',
            'nome_max' => 100,
            'sigla'    => false,
            'acao'     => 'NIVEL',
            'sufixo'   => 'O',
            'menu'     => 'niveis',
            'vinculo'  => ['rotulo' => 'Unidades ativas', 'texto' => 'unidade(s) administrativa(s) ativa(s)', 'url' => 'unidades-administrativas.php', 'param' => 'nivel_id', 'extra' => []],
            'ajuda'    => 'Nível da estrutura organizacional. Também pode ser criado direto no cadastro da unidade.',
            'exemplo'  => 'Ex.: Nível I - Decisão Colegiada',
        ],
        'instituicoes_externas' => [
            'titulo'   => 'Instituições Externas',
            'singular' => 'instituição externa',
            'artigo'   => 'a',
            'nome_max' => 150,
            'sigla'    => true,
            'acao'     => 'INSTITUICAO_EXTERNA',
            'sufixo'   => 'A',
            'menu'     => 'instituicoes-externas',
            'vinculo'  => ['rotulo' => 'Órgãos ativos', 'texto' => 'órgão(s) externo(s) ativo(s)', 'url' => 'tabela-apoio.php', 'param' => 'pai_id', 'extra' => ['tipo' => 'orgaos_externos']],
            'ajuda'    => 'Instituição de fora do Poder Executivo estadual de onde vêm os processos extrajudiciais (Ministério Público, Defensoria, Tribunal de Contas…).',
            'exemplo'  => 'Ex.: Ministério Público do Estado de Mato Grosso',
        ],
        'orgaos_externos' => [
            'titulo'   => 'Órgãos Externos',
            'singular' => 'órgão externo',
            'artigo'   => 'o',
            'nome_max' => 150,
            'sigla'    => false,
            'acao'     => 'ORGAO_EXTERNO',
            'sufixo'   => 'O',
            'menu'     => 'instituicoes-externas',
            // Subordinado a uma instituicao externa: o nome e unico dentro dela.
            'pai'      => ['campo' => 'instituicao_externa_id', 'tipo' => 'instituicoes_externas', 'rotulo' => 'Instituição externa'],
            'vinculo'  => ['rotulo' => 'Processos', 'texto' => 'processo(s) extrajudicial(is)', 'url' => 'processos-extrajudiciais.php', 'param' => 'orgao_id', 'extra' => []],
            'ajuda'    => 'Órgão ou unidade interna da instituição externa. Também pode ser criado direto no cadastro do processo extrajudicial.',
            'exemplo'  => 'Ex.: 1ª Promotoria de Justiça de Cuiabá',
        ],
        // Central de Recursos: mantidas tambem por gestores.
        'tipos_recurso' => [
            'titulo'   => 'Pastas de Recursos',
            'singular' => 'pasta',
            'artigo'   => 'a',
            'nome_max' => 100,
            'sigla'    => false,
            'acao'     => 'TIPO_RECURSO',
            'sufixo'   => 'O',
            'menu'     => 'recursos',
            'perfis'   => [Perfil::ADMINISTRADOR, Perfil::GESTOR],
            'vinculo'  => ['rotulo' => 'Recursos', 'texto' => 'recurso(s)', 'url' => 'recursos.php', 'param' => 'pasta', 'extra' => []],
            'ajuda'    => 'Tipo do recurso: define a Pasta em que ele aparece (cada recurso fica em uma só).',
            'exemplo'  => 'Ex.: Sistemas',
        ],
        'categorias_recurso' => [
            'titulo'   => 'Áreas de Recursos',
            'singular' => 'área',
            'artigo'   => 'a',
            'nome_max' => 100,
            'sigla'    => false,
            'acao'     => 'CATEGORIA_RECURSO',
            'sufixo'   => 'A',
            'menu'     => 'recursos',
            'perfis'   => [Perfil::ADMINISTRADOR, Perfil::GESTOR],
            'vinculo'  => ['rotulo' => 'Recursos', 'texto' => 'recurso(s)', 'url' => 'recursos.php', 'param' => 'area', 'extra' => []],
            'ajuda'    => 'Categoria do recurso: define as Áreas em que ele aparece (um recurso pode estar em várias).',
            'exemplo'  => 'Ex.: Judicial',
        ],
    ];

    /** Repositorio de cada tipo. */
    private const REPOSITORIOS = [
        'cargos'       => CargoRepository::class,
        'instituicoes' => InstituicaoRepository::class,
        'niveis'       => NivelOrganizacionalRepository::class,
        'instituicoes_externas' => InstituicaoExternaRepository::class,
        'orgaos_externos'       => OrgaoExternoRepository::class,
        'tipos_recurso'         => TipoRecursoRepository::class,
        'categorias_recurso'    => CategoriaRecursoRepository::class,
    ];

    public const NOME_MIN  = 2;
    public const SIGLA_MAX = 30;

    private readonly array $meta;

    public function __construct(
        private readonly string $tipo,
        private ?TabelaApoioRepository $repo = null,
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
        if (!isset(self::TIPOS[$tipo])) {
            throw new InvalidArgumentException('Tabela de apoio desconhecida.');
        }
        $this->meta = self::TIPOS[$tipo];
        $classe = self::REPOSITORIOS[$tipo];
        $this->repo ??= new $classe();
    }

    public static function tipoValido(string $tipo): bool
    {
        return isset(self::TIPOS[$tipo]);
    }

    /** Perfis que mantem a tabela (padrao: so administradores). */
    public static function perfis(string $tipo): array
    {
        return self::TIPOS[$tipo]['perfis'] ?? [Perfil::ADMINISTRADOR];
    }

    /** Exige um dos perfis que mantem a tabela; tipo invalido exige administrador. */
    public static function exigirAcesso(string $tipo): void
    {
        Auth::exigirPerfil(...self::perfis($tipo));
    }

    public function meta(): array
    {
        return $this->meta;
    }

    public function repositorio(): TabelaApoioRepository
    {
        return $this->repo;
    }

    /** Nome da acao de auditoria, ex.: CARGO_CRIADO, LOTACAO_INATIVADA. */
    private function acao(string $evento): string
    {
        return $this->meta['acao'] . '_' . $evento . $this->meta['sufixo'];
    }

    /** @throws ValidationException */
    public function criar(array $entrada): int
    {
        return $this->comTraducaoDeDuplicidade(fn(): int => Database::transacao(function () use ($entrada): int {
            $dados = $this->validar($entrada, null);
            $id = $this->repo->inserir($dados);
            $this->auditoria->registrar($this->acao('CRIAD'), $this->tipo, $id, null, $dados + ['ativo' => 1]);
            return $id;
        }));
    }

    /** @throws ValidationException */
    public function atualizar(int $id, array $entrada): void
    {
        $this->comTraducaoDeDuplicidade(fn() => Database::transacao(function () use ($id, $entrada): void {
            $atual = $this->repo->buscarParaAtualizar($id);
            if ($atual === null) {
                throw new ValidationException([], ucfirst($this->meta['singular']) . ' não encontrad' . $this->meta['artigo'] . '.');
            }
            $dados = $this->validar($entrada, $id);
            [$antes, $depois] = AuditoriaService::diferencas($atual, $dados);
            if ($depois !== []) {
                $this->repo->atualizar($id, $dados);
                $this->auditoria->registrar($this->acao('EDITAD'), $this->tipo, $id, $antes, $depois);
            }
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
                throw new ValidationException([], ucfirst($this->meta['singular']) . ' não encontrad' . $this->meta['artigo'] . '.');
            }
            if (((int)$atual['ativo'] === 1) === $ativo) {
                return false;
            }
            $this->repo->definirAtivo($id, $ativo);
            $this->auditoria->registrar(
                $this->acao($ativo ? 'ATIVAD' : 'INATIVAD'),
                $this->tipo,
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
        $singular = $this->meta['singular'];
        $artigo = $this->meta['artigo'];

        // Item subordinado: o pai deve existir e estar ativo (ou ser o ja gravado).
        $paiId = null;
        if (isset($this->meta['pai'])) {
            $campoPai = $this->meta['pai']['campo'];
            $paiId = is_numeric($entrada[$campoPai] ?? null) && (int)$entrada[$campoPai] > 0 ? (int)$entrada[$campoPai] : null;
            $pai = $paiId !== null ? (new self($this->meta['pai']['tipo']))->repositorio()->buscarPorId($paiId) : null;
            $atual = $ignorarId !== null ? $this->repo->buscarPorId($ignorarId) : null;
            if ($pai === null) {
                $erros[$campoPai] = 'Selecione a ' . mb_strtolower($this->meta['pai']['rotulo']) . '.';
            } elseif ((int)$pai['ativo'] !== 1 && (int)($atual[$campoPai] ?? 0) !== $paiId) {
                $erros[$campoPai] = 'A ' . mb_strtolower($this->meta['pai']['rotulo']) . ' “' . $pai['nome'] . '” está inativa.';
            }
        }

        $nome = Normalizador::texto(is_string($entrada['nome'] ?? null) ? $entrada['nome'] : null);
        if ($nome === null) {
            $erros['nome'] = "Informe o nome d{$artigo} {$singular}.";
        } elseif (mb_strlen($nome) < self::NOME_MIN) {
            $erros['nome'] = 'O nome deve ter pelo menos ' . self::NOME_MIN . ' caracteres.';
        } elseif (mb_strlen($nome) > $this->meta['nome_max']) {
            $erros['nome'] = 'O nome deve ter no máximo ' . $this->meta['nome_max'] . ' caracteres.';
        } elseif (!preg_match('/\p{L}/u', $nome)) {
            $erros['nome'] = 'O nome deve conter letras.';
        } elseif ($this->repo->valorEmUso('nome', $nome, $ignorarId, $paiId)) {
            $erros['nome'] = $this->mensagemDuplicidade('nome');
        }

        $dados = ['nome' => $nome];
        if (isset($this->meta['pai'])) {
            $dados[$this->meta['pai']['campo']] = $paiId;
        }

        if ($this->meta['sigla']) {
            [$sigla, $erroSigla] = self::sigla($entrada['sigla'] ?? null);
            if ($erroSigla !== null) {
                $erros['sigla'] = $erroSigla;
            } elseif ($sigla !== null && $this->repo->valorEmUso('sigla', $sigla, $ignorarId)) {
                $erros['sigla'] = $this->mensagemDuplicidade('sigla');
            }
            $dados['sigla'] = $sigla;
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }
        return $dados;
    }

    /**
     * Sigla opcional: sem espacos, em maiusculas, so letras, numeros e ". / -".
     * @return array{0:?string,1:?string} [sigla normalizada, mensagem de erro]
     */
    public static function sigla(mixed $valor): array
    {
        $sigla = mb_strtoupper(preg_replace('/\s+/u', '', is_string($valor) ? $valor : '') ?? '');
        if ($sigla === '') {
            return [null, null];
        }
        if (mb_strlen($sigla) > self::SIGLA_MAX) {
            return [$sigla, 'A sigla deve ter no máximo ' . self::SIGLA_MAX . ' caracteres.'];
        }
        if (!preg_match('#^[A-Z0-9](?:[A-Z0-9./-]*[A-Z0-9])?$#', $sigla)) {
            return [$sigla, 'A sigla aceita apenas letras, números e os separadores ". / -".'];
        }
        return [$sigla, null];
    }

    private function mensagemDuplicidade(string $campo): string
    {
        $singular = $this->meta['singular'];
        $cadastrad = 'cadastrad' . $this->meta['artigo'];
        return $campo === 'sigla'
            ? "Já existe uma {$singular} {$cadastrad} com esta sigla."
            : 'Já existe ' . ($this->meta['artigo'] === 'a' ? 'uma' : 'um') . " {$singular} {$cadastrad} com este nome"
                . (isset($this->meta['pai']) ? ' nesta ' . mb_strtolower($this->meta['pai']['rotulo']) : '') . '.';
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
            foreach (['sigla', 'nome'] as $campo) {
                if (str_contains($e->getMessage(), 'uq_' . $this->tipo . '_' . $campo)) {
                    throw new ValidationException([$campo => $this->mensagemDuplicidade($campo)]);
                }
            }
            throw $e;
        }
    }
}
