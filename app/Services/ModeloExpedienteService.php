<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\ModeloExpedienteRepository;
use App\Validators\Normalizador;

/**
 * Modelos do Gerador de Expedientes.
 *
 * Todo usuario cria os proprios modelos. A visibilidade e:
 *   INDIVIDUAL    (padrao) - so quem criou ve, usa, altera e exclui;
 *   COMPARTILHADO - todos veem e usam; so Administrador ou Gestor cria,
 *                   altera, exclui ou muda a visibilidade.
 * Quem so pode usar um compartilhado ainda pode duplica-lo: a copia e
 * um modelo individual de quem duplicou.
 *
 * O que o gerador guarda dentro do modelo (texto, propriedades,
 * formatacao...) vai, sem interpretacao, na coluna JSON `conteudo`.
 * Favorito e contagem de uso sao de cada usuario.
 */
final class ModeloExpedienteService
{
    public const INDIVIDUAL    = 'INDIVIDUAL';
    public const COMPARTILHADO = 'COMPARTILHADO';
    public const VISIBILIDADES = [self::INDIVIDUAL => 'Individual', self::COMPARTILHADO => 'Compartilhado'];

    public const NOME_MAX      = 150;
    public const TIPO_MAX      = 80;
    public const DESCRICAO_MAX = 1000;
    public const TIPO_PADRAO   = 'Outro';
    /** Limite do JSON do modelo (texto com formatacao e tabelas). */
    public const CONTEUDO_MAX_BYTES = 2_000_000;

    /** Chaves de `conteudo` que o gerador grava (ver assets/expediente/templates.js). */
    private const CHAVES_CONTEUDO = ['base', 'html', 'personalizadas', 'selecao', 'formato', 'assuntoPadrao'];

    public const MSG_SEM_PERMISSAO = 'Somente administradores e gestores podem alterar modelos compartilhados.';
    public const MSG_SO_GESTAO_COMPARTILHA = 'Somente administradores e gestores podem compartilhar modelos.';

    public function __construct(
        private readonly ModeloExpedienteRepository $repo = new ModeloExpedienteRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    /** O usuario autenticado pode compartilhar modelos e alterar os compartilhados. */
    public static function podeCompartilhar(): bool
    {
        return Auth::autorizado(Perfil::ADMINISTRADOR, Perfil::GESTOR);
    }

    public static function podeVer(array $modelo): bool
    {
        return $modelo['visibilidade'] === self::COMPARTILHADO || self::ehAutor($modelo);
    }

    public static function podeAlterar(array $modelo): bool
    {
        return $modelo['visibilidade'] === self::COMPARTILHADO ? self::podeCompartilhar() : self::ehAutor($modelo);
    }

    private static function ehAutor(array $modelo): bool
    {
        $eu = usuario_logado_id();
        return $eu !== null && (int)$modelo['criado_por'] === $eu;
    }

    /**
     * Os modelos que o usuario autenticado ve, no formato do gerador.
     * @return list<array>
     */
    public function visiveis(): array
    {
        return array_map(self::paraGerador(...), $this->repo->visiveisPara(self::eu()));
    }

    /**
     * Um modelo visivel ao usuario autenticado, no formato do gerador.
     * @throws ValidationException
     */
    public function obter(int $id): array
    {
        return self::paraGerador($this->exigirVisivel($id));
    }

    /**
     * @param array $entrada nome, tipo, descricao, visibilidade, conteudo
     * @return int id do modelo criado
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function criar(array $entrada): int
    {
        $eu = self::eu();
        $dados = $this->validar($entrada);
        if ($dados['visibilidade'] === self::COMPARTILHADO && !self::podeCompartilhar()) {
            throw new AcessoNegadoException(self::MSG_SO_GESTAO_COMPARTILHA);
        }
        $this->exigirNomeLivre($dados['nome'], $eu, null);

        return Database::transacao(function () use ($dados, $eu): int {
            $id = $this->repo->inserir($dados + ['criado_por' => $eu]);
            if ($dados['visibilidade'] === self::COMPARTILHADO) {
                $this->auditar('MODELO_EXPEDIENTE_COMPARTILHADO', $id, null, $dados);
            }
            return $id;
        });
    }

    /**
     * Altera so os campos informados; os demais ficam como estao.
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function atualizar(int $id, array $entrada): void
    {
        $atual = $this->exigirAlteravel($id);
        $base = [
            'nome'         => $atual['nome'],
            'tipo'         => $atual['tipo'],
            'descricao'    => $atual['descricao'],
            'visibilidade' => $atual['visibilidade'],
            'conteudo'     => json_decode((string)$atual['conteudo'], true) ?: [],
        ];
        $dados = $this->validar(array_intersect_key($entrada, $base) + $base);

        if ($dados['visibilidade'] !== $atual['visibilidade'] && !self::podeCompartilhar()) {
            throw new AcessoNegadoException(self::MSG_SO_GESTAO_COMPARTILHA);
        }
        $this->exigirNomeLivre($dados['nome'], (int)$atual['criado_por'], $id);

        Database::transacao(function () use ($id, $atual, $dados): void {
            $this->repo->atualizar($id, $dados + ['atualizado_por' => self::eu()]);
            if ($dados['visibilidade'] !== $atual['visibilidade']) {
                $acao = $dados['visibilidade'] === self::COMPARTILHADO
                    ? 'MODELO_EXPEDIENTE_COMPARTILHADO' : 'MODELO_EXPEDIENTE_INDIVIDUALIZADO';
                $this->auditar($acao, $id, $atual, $dados);
            }
        });
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function excluir(int $id): void
    {
        $atual = $this->exigirAlteravel($id);
        Database::transacao(function () use ($id, $atual): void {
            $this->repo->excluir($id);
            if ($atual['visibilidade'] === self::COMPARTILHADO) {
                $this->auditar('MODELO_EXPEDIENTE_EXCLUIDO', $id, $atual, null);
            }
        });
    }

    /**
     * Copia individual de um modelo visivel ("Copia de X", "Copia de X (2)"...).
     * @return int id da copia
     * @throws ValidationException
     */
    public function duplicar(int $id): int
    {
        $origem = $this->exigirVisivel($id);
        $eu = self::eu();
        $prefixo = mb_substr('Cópia de ' . $origem['nome'], 0, self::NOME_MAX - 6);
        $nome = $prefixo;
        for ($n = 2; $this->repo->nomeEmUso($nome, $eu); $n++) {
            $nome = $prefixo . ' (' . $n . ')';
        }

        return $this->criar([
            'nome'         => $nome,
            'tipo'         => $origem['tipo'],
            'descricao'    => $origem['descricao'],
            'visibilidade' => self::INDIVIDUAL,
            'conteudo'     => json_decode((string)$origem['conteudo'], true) ?: [],
        ]);
    }

    /** @throws ValidationException */
    public function marcarFavorito(int $id, bool $favorito): void
    {
        $this->exigirVisivel($id);
        $this->repo->marcarFavorito($id, self::eu(), $favorito);
    }

    /** @throws ValidationException */
    public function registrarUso(int $id): void
    {
        $this->exigirVisivel($id);
        $this->repo->registrarUso($id, self::eu());
    }

    /**
     * @return array{nome:string,tipo:string,descricao:?string,visibilidade:string,conteudo:string}
     * @throws ValidationException
     */
    public function validar(array $entrada): array
    {
        $erros = [];

        $nome = Normalizador::texto(is_string($entrada['nome'] ?? null) ? $entrada['nome'] : null);
        if ($nome === null) {
            $erros['nome'] = 'Informe um nome para o modelo.';
        } elseif (mb_strlen($nome) > self::NOME_MAX) {
            $erros['nome'] = 'O nome deve ter no máximo ' . self::NOME_MAX . ' caracteres.';
        }

        $tipo = Normalizador::texto(is_string($entrada['tipo'] ?? null) ? $entrada['tipo'] : null) ?? self::TIPO_PADRAO;
        if (mb_strlen($tipo) > self::TIPO_MAX) {
            $erros['tipo'] = 'O tipo deve ter no máximo ' . self::TIPO_MAX . ' caracteres.';
        }

        $descricao = Normalizador::textoLongo($entrada['descricao'] ?? null);
        if ($descricao !== null && mb_strlen($descricao) > self::DESCRICAO_MAX) {
            $erros['descricao'] = 'A descrição deve ter no máximo ' . self::DESCRICAO_MAX . ' caracteres.';
        }

        $visibilidade = $entrada['visibilidade'] ?? self::INDIVIDUAL;
        if ($visibilidade === null || $visibilidade === '') {
            $visibilidade = self::INDIVIDUAL;
        }
        if (!is_string($visibilidade) || !isset(self::VISIBILIDADES[$visibilidade])) {
            $erros['visibilidade'] = 'Visibilidade inválida.';
        }

        $conteudo = $entrada['conteudo'] ?? [];
        if (!is_array($conteudo)) {
            $erros['conteudo'] = 'Conteúdo do modelo inválido.';
            $conteudo = [];
        }
        $conteudo = array_intersect_key($conteudo, array_flip(self::CHAVES_CONTEUDO));
        if (isset($conteudo['html']) && !is_string($conteudo['html'])) {
            $erros['conteudo'] = 'Conteúdo do modelo inválido.';
        }
        $json = json_encode((object)$conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $erros['conteudo'] = 'Conteúdo do modelo inválido.';
        } elseif (strlen($json) > self::CONTEUDO_MAX_BYTES) {
            $erros['conteudo'] = 'O modelo é grande demais para ser gravado.';
        }

        if ($erros) {
            throw new ValidationException($erros, reset($erros));
        }
        return [
            'nome'         => $nome,
            'tipo'         => $tipo,
            'descricao'    => $descricao,
            'visibilidade' => $visibilidade,
            'conteudo'     => $json,
        ];
    }

    /**
     * Registro no formato lido por assets/expediente/templates.js.
     * O id ganha prefixo para nunca colidir com os modelos do sistema.
     */
    public static function paraGerador(array $m): array
    {
        $conteudo = json_decode((string)$m['conteudo'], true) ?: [];
        return [
            'id'             => 'modelo:' . $m['id'],
            'nome'           => $m['nome'],
            'tipo'           => $m['tipo'],
            'descricao'      => (string)($m['descricao'] ?? ''),
            'base'           => $conteudo['base'] ?? null,
            'html'           => is_string($conteudo['html'] ?? null) ? $conteudo['html'] : '',
            'personalizadas' => is_array($conteudo['personalizadas'] ?? null) ? $conteudo['personalizadas'] : [],
            'selecao'        => is_array($conteudo['selecao'] ?? null) ? $conteudo['selecao'] : null,
            'formato'        => is_array($conteudo['formato'] ?? null) ? $conteudo['formato'] : null,
            'assuntoPadrao'  => $conteudo['assuntoPadrao'] ?? null,
            'visibilidade'   => $m['visibilidade'],
            'autor'          => $m['criado_por_nome'] ?? null,
            'proprio'        => self::ehAutor($m),
            'podeAlterar'    => self::podeAlterar($m),
            'criadoEm'       => self::iso($m['created_at']),
            'atualizadoEm'   => self::iso($m['updated_at']),
            'favorito'       => (bool)$m['favorito'],
            'usos'           => (int)$m['usos'],
            'ultimoUsoEm'    => self::iso($m['ultimo_uso_em'] ?? null),
        ];
    }

    private static function iso(?string $dataHora): ?string
    {
        return $dataHora ? str_replace(' ', 'T', $dataHora) : null;
    }

    /** @throws AcessoNegadoException */
    private static function eu(): int
    {
        $eu = usuario_logado_id();
        if ($eu === null) {
            throw new AcessoNegadoException('Entre no sistema para usar os modelos.');
        }
        return $eu;
    }

    /** @throws ValidationException */
    private function exigirVisivel(int $id): array
    {
        $modelo = $this->repo->buscarPorId($id, self::eu());
        // Modelo individual de outra pessoa: para quem pergunta, nao existe.
        if ($modelo === null || !self::podeVer($modelo)) {
            throw new ValidationException([], 'Modelo não encontrado.');
        }
        return $modelo;
    }

    /**
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    private function exigirAlteravel(int $id): array
    {
        $modelo = $this->exigirVisivel($id);
        if (!self::podeAlterar($modelo)) {
            throw new AcessoNegadoException(self::MSG_SEM_PERMISSAO);
        }
        return $modelo;
    }

    /** @throws ValidationException */
    private function exigirNomeLivre(string $nome, int $dono, ?int $ignorarId): void
    {
        if ($this->repo->nomeEmUso($nome, $dono, $ignorarId)) {
            throw new ValidationException(['nome' => 'Já existe um modelo com este nome.'], 'Já existe um modelo com este nome.');
        }
    }

    private function auditar(string $acao, int $id, ?array $antes, ?array $depois): void
    {
        $resumo = static fn(?array $m): ?array => $m === null ? null : [
            'descricao'    => $m['nome'],
            'tipo'         => $m['tipo'],
            'visibilidade' => $m['visibilidade'],
        ];
        $this->auditoria->registrar($acao, 'expediente_modelos', $id, $resumo($antes), $resumo($depois));
    }
}
