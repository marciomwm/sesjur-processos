<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\CategoriaRecursoRepository;
use App\Repositories\RecursoRepository;
use App\Repositories\TipoRecursoRepository;
use App\Validators\Normalizador;

/**
 * Central de Recursos: links para sites, sistemas, documentos e paineis da
 * equipe. O Tipo define a Pasta (uma so); as Categorias definem as Areas
 * (nenhuma, uma ou varias).
 *
 * Qualquer usuario cadastra um recurso; so Administrador, Gestor ou quem o
 * criou o altera ou exclui (mesma regra da Acao e da Anotacao). O autor e a
 * data ficam registrados no proprio recurso e na auditoria.
 */
final class RecursoService
{
    public const TITULO_MAX    = 160;
    public const DESCRICAO_MAX = 600;
    public const URL_MAX       = 500;

    public const MSG_SEM_PERMISSAO = 'Somente administradores, gestores ou quem cadastrou o recurso podem alterá-lo.';

    public function __construct(
        private readonly RecursoRepository $repo = new RecursoRepository(),
        private readonly TipoRecursoRepository $tipos = new TipoRecursoRepository(),
        private readonly CategoriaRecursoRepository $categorias = new CategoriaRecursoRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    /**
     * Cores de agrupamento (pasta/area), atribuidas pela posicao na lista:
     * o mesmo item tem sempre a mesma cor, sem coluna `cor` no banco.
     */
    private const PALETA = [
        ['#3461fd', 'linear-gradient(135deg,#eaf0ff,#f4f7ff)'],
        ['#12b7a3', 'linear-gradient(135deg,#e5faf6,#f1fdfb)'],
        ['#8b5cf6', 'linear-gradient(135deg,#f3ecff,#f9f5ff)'],
        ['#ea8a3b', 'linear-gradient(135deg,#fff2e3,#fff8ef)'],
        ['#e0507a', 'linear-gradient(135deg,#ffeef3,#fff6f9)'],
        ['#2fa84f', 'linear-gradient(135deg,#eafcef,#f4fef7)'],
        ['#0ea5e9', 'linear-gradient(135deg,#e6f6fe,#f2fbff)'],
        ['#d6336c', 'linear-gradient(135deg,#ffecf2,#fff5f8)'],
    ];

    /** @return array{forte:string,fundo:string} */
    public static function cor(int $indice): array
    {
        $n = count(self::PALETA);
        [$forte, $fundo] = self::PALETA[(($indice % $n) + $n) % $n];
        return ['forte' => $forte, 'fundo' => $fundo];
    }

    /** O usuario autenticado pode alterar o recurso (administrador, gestor ou autor). */
    public static function podeAlterar(array $recurso): bool
    {
        if (Auth::autorizado(Perfil::ADMINISTRADOR, Perfil::GESTOR)) {
            return true;
        }
        $eu = usuario_logado_id();
        return $eu !== null && (int)($recurso['criado_por'] ?? 0) === $eu;
    }

    /** Pastas e Areas sao mantidas por administradores e gestores. */
    public static function podeGerenciarListas(): bool
    {
        return Auth::autorizado(Perfil::ADMINISTRADOR, Perfil::GESTOR);
    }

    /**
     * @param array $entrada titulo, descricao, url, tipo_id, categorias (lista de ids)
     * @throws ValidationException
     */
    public function criar(array $entrada): int
    {
        return Database::transacao(function () use ($entrada): int {
            [$dados, $categorias] = $this->validar($entrada, null);
            $id = $this->repo->inserir($dados, usuario_logado_id());
            $this->repo->substituirCategorias($id, $categorias);
            $this->auditoria->registrar('RECURSO_CRIADO', 'recursos', $id, null, self::resumo($dados, $categorias));
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
        Database::transacao(function () use ($id, $atual, $entrada): void {
            [$dados, $categorias] = $this->validar($entrada, $atual);
            [$antes, $depois] = AuditoriaService::diferencas(
                self::resumo($atual, $this->repo->categorias($id)),
                self::resumo($dados, $categorias)
            );
            if ($depois === []) {
                return;
            }
            $this->repo->atualizar($id, $dados, usuario_logado_id());
            $this->repo->substituirCategorias($id, $categorias);
            $this->auditoria->registrar('RECURSO_EDITADO', 'recursos', $id, $antes, $depois);
        });
    }

    /**
     * @return string titulo do recurso excluido
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function excluir(int $id): string
    {
        $atual = $this->exigirAlteravel($id);
        Database::transacao(function () use ($id, $atual): void {
            $categorias = $this->repo->categorias($id);
            $this->repo->excluir($id);
            $this->auditoria->registrar('RECURSO_EXCLUIDO', 'recursos', $id, self::resumo($atual, $categorias), null);
        });
        return (string)$atual['titulo'];
    }

    /**
     * Valida e normaliza. $atual e o recurso gravado (edicao) ou null: um
     * tipo ou categoria inativados depois continuam valendo para ele.
     * @return array{0:array{titulo:string,descricao:?string,url:string,tipo_id:int},1:list<int>}
     * @throws ValidationException
     */
    public function validar(array $entrada, ?array $atual): array
    {
        $erros = [];

        $titulo = Normalizador::texto(is_string($entrada['titulo'] ?? null) ? $entrada['titulo'] : null);
        if ($titulo === null) {
            $erros['titulo'] = 'Informe o título do recurso.';
        } elseif (mb_strlen($titulo) > self::TITULO_MAX) {
            $erros['titulo'] = 'O título deve ter no máximo ' . self::TITULO_MAX . ' caracteres.';
        }

        $descricao = Normalizador::textoLongo($entrada['descricao'] ?? null);
        if ($descricao !== null && mb_strlen($descricao) > self::DESCRICAO_MAX) {
            $erros['descricao'] = 'A descrição deve ter no máximo ' . self::DESCRICAO_MAX . ' caracteres.';
        }

        [$url, $erroUrl] = self::url($entrada['url'] ?? null);
        if ($erroUrl !== null) {
            $erros['url'] = $erroUrl;
        }

        $tipoId = is_numeric($entrada['tipo_id'] ?? null) && (int)$entrada['tipo_id'] > 0 ? (int)$entrada['tipo_id'] : null;
        $tipo = $tipoId !== null ? $this->tipos->buscarPorId($tipoId) : null;
        if ($tipo === null) {
            $erros['tipo_id'] = 'Escolha o tipo — é ele que define a Pasta do recurso.';
        } elseif ((int)$tipo['ativo'] !== 1 && $tipoId !== (int)($atual['tipo_id'] ?? 0)) {
            $erros['tipo_id'] = 'O tipo “' . $tipo['nome'] . '” está inativo.';
        }

        $categorias = [];
        $jaGravadas = $atual !== null ? $this->repo->categorias((int)$atual['id']) : [];
        foreach (is_array($entrada['categorias'] ?? null) ? $entrada['categorias'] : [] as $valor) {
            if (!is_numeric($valor) || (int)$valor <= 0) {
                continue;
            }
            $categoriaId = (int)$valor;
            $categoria = $this->categorias->buscarPorId($categoriaId);
            if ($categoria === null) {
                $erros['categorias'] = 'Categoria não encontrada.';
            } elseif ((int)$categoria['ativo'] !== 1 && !in_array($categoriaId, $jaGravadas, true)) {
                $erros['categorias'] = 'A categoria “' . $categoria['nome'] . '” está inativa.';
            } else {
                $categorias[$categoriaId] = $categoriaId;
            }
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }
        sort($categorias);
        return [
            ['titulo' => $titulo, 'descricao' => $descricao, 'url' => $url, 'tipo_id' => $tipoId],
            array_values($categorias),
        ];
    }

    /**
     * URL de acesso: sem esquema, assume https://. So http(s) com host —
     * nunca javascript:, data: e afins, que virariam link clicavel.
     * @return array{0:?string,1:?string} [url normalizada, mensagem de erro]
     */
    public static function url(mixed $valor): array
    {
        $url = Normalizador::texto(is_string($valor) ? $valor : null);
        if ($url === null) {
            return [null, 'Informe a URL de acesso.'];
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^https?://#i', $url)) {
            return [null, 'Somente endereços http:// ou https:// são aceitos.'];
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (mb_strlen($url) > self::URL_MAX) {
            return [null, 'A URL deve ter no máximo ' . self::URL_MAX . ' caracteres.'];
        }
        $partes = parse_url($url);
        if ($partes === false || empty($partes['host']) || preg_match('/\s/u', $url)
            || !preg_match('/^[\p{L}\p{N}.-]+$/u', $partes['host'])) {
            return [null, 'A URL informada não é válida.'];
        }
        return [$url, null];
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    private function exigirAlteravel(int $id): array
    {
        $recurso = $this->repo->buscarPorId($id);
        if ($recurso === null) {
            throw new ValidationException([], 'Recurso não encontrado.');
        }
        if (!self::podeAlterar($recurso)) {
            throw new AcessoNegadoException(self::MSG_SEM_PERMISSAO);
        }
        return $recurso;
    }

    /** Campos auditados; as categorias viram uma lista de ids. */
    private static function resumo(array $dados, array $categorias): array
    {
        return [
            'titulo'     => $dados['titulo'] ?? null,
            'descricao'  => $dados['descricao'] ?? null,
            'url'        => $dados['url'] ?? null,
            'tipo_id'    => isset($dados['tipo_id']) ? (int)$dados['tipo_id'] : null,
            'categorias' => implode(',', $categorias) ?: null,
        ];
    }
}
