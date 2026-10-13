<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AgentePublicoRepository;
use App\Repositories\CargoRepository;
use App\Repositories\LotacaoRepository;
use App\Repositories\UsuarioRepository;
use App\Validators\UsuarioValidator;
use PDOException;

/**
 * Regras de negocio do cadastro de usuarios. Cada operacao grava os dados
 * e o evento de auditoria na mesma transacao. Nao ha exclusao fisica.
 *
 * Todo usuario pertence a um Agente Publico (a pessoa); o usuario e a
 * credencial (login, perfil, status). O caminho normal e criar a conta a
 * partir do agente ja cadastrado (criarParaAgente). O cadastro direto
 * (criar sem agente, usado por scripts e testes) liga a conta ao agente sem
 * usuario de mesma matricula ou cria o agente na hora, para que nenhuma
 * conta fique sem pessoa. Os dados pessoais e funcionais ficam iguais nos
 * dois cadastros: alterar um atualiza o outro.
 */
final class UsuarioService
{
    public const MSG_EMAIL_DUPLICADO     = 'Já existe um usuário cadastrado com este e-mail.';
    public const MSG_MATRICULA_DUPLICADA = 'Já existe um usuário cadastrado com esta matrícula.';
    public const MSG_SO_ADMIN_PERFIL     = 'Somente administradores podem alterar o perfil de acesso.';
    public const MSG_ULTIMO_ADMIN        = 'O sistema precisa manter ao menos um administrador ativo.';
    public const MSG_AGENTE_COM_USUARIO  = 'Este agente público já possui usuário do sistema.';

    private const ENTIDADE = 'usuarios';

    public function __construct(
        private readonly UsuarioRepository $repo = new UsuarioRepository(),
        private readonly CargoRepository $cargos = new CargoRepository(),
        private readonly LotacaoRepository $lotacoes = new LotacaoRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService(),
        private readonly AgentePublicoRepository $agentes = new AgentePublicoRepository()
    ) {
    }

    /**
     * Cadastro de usuario: somente administrador. Com agente_publico_id, a
     * conta e criada a partir do agente (criarParaAgente).
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function criar(array $entrada): int
    {
        Auth::exigirPerfil(Perfil::ADMINISTRADOR);
        if (!empty($entrada['agente_publico_id'])) {
            return $this->criarParaAgente((int)$entrada['agente_publico_id'], $entrada);
        }
        $dados = $this->validar($entrada, null);

        return $this->comTraducaoDeDuplicidade(fn(): int => Database::transacao(function () use ($dados): int {
            $agente = $this->agentes->semUsuarioPorMatricula($dados['matricula']);
            if ($agente !== null) {
                $agenteId = (int)$agente['id'];
                $this->sincronizarAgente($agenteId, $dados);
            } else {
                $agenteId = $this->criarAgente($dados);
            }
            return $this->inserirComAuditoria($dados + ['agente_publico_id' => $agenteId]);
        }));
    }

    /**
     * Cria a conta de acesso de um Agente Publico ja cadastrado, aproveitando
     * os dados dele: da entrada so vem o que e da credencial (e-mail de
     * acesso e perfil).
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function criarParaAgente(int $agenteId, array $acesso): int
    {
        Auth::exigirPerfil(Perfil::ADMINISTRADOR);
        $agente = $this->agentes->buscarPorId($agenteId);
        if ($agente === null) {
            throw new ValidationException(['agente_publico_id' => 'Agente público não encontrado.']);
        }
        if ($agente['usuario_id'] !== null) {
            throw new ValidationException(['agente_publico_id' => self::MSG_AGENTE_COM_USUARIO]);
        }
        $pendencias = [];
        if ($agente['situacao'] === 'DESLIGADO') {
            $pendencias[] = 'Agente público desligado não pode receber acesso ao sistema.';
        }
        if ($agente['matricula'] === null) {
            $pendencias[] = 'Informe a matrícula no cadastro do agente público.';
        }
        if ($agente['cargo_id'] === null) {
            $pendencias[] = 'Informe o cargo no cadastro do agente público.';
        }
        if ($pendencias !== []) {
            throw new ValidationException(['agente_publico_id' => implode(' ', $pendencias)]);
        }

        $email = is_string($acesso['email'] ?? null) && trim($acesso['email']) !== ''
            ? $acesso['email']
            : (string)($agente['email_institucional'] ?? '');
        $entrada = [
            'nome'            => $agente['nome'],
            'email'           => $email,
            'matricula'       => $agente['matricula'],
            'cargo_id'        => (string)$agente['cargo_id'],
            'lotacao_id'      => (string)$agente['lotacao_id'],
            'data_nascimento' => (string)($agente['data_nascimento'] ?? ''),
        ];
        if (array_key_exists('perfil', $acesso)) {
            $entrada['perfil'] = $acesso['perfil'];
        }
        $dados = $this->validar($entrada, null);

        return $this->comTraducaoDeDuplicidade(fn(): int => Database::transacao(
            fn(): int => $this->inserirComAuditoria($dados + ['agente_publico_id' => $agenteId])
        ));
    }

    private function inserirComAuditoria(array $dados): int
    {
        $id = $this->repo->inserir($dados);
        $this->auditoria->registrar(AuditoriaService::USUARIO_CRIADO, self::ENTIDADE, $id, null, $dados + ['ativo' => 1]);
        return $id;
    }

    /** Agente criado junto com a conta, no cadastro direto (chamar em transacao). */
    private function criarAgente(array $dados): int
    {
        $agente = array_intersect_key($dados, array_flip(AgentePublicoService::CAMPOS_DO_USUARIO)) + [
            'email_institucional' => $this->agentes->valorEmUso('email_institucional', $dados['email']) ? null : $dados['email'],
            'tipo_vinculo'        => 'OUTRO',
            'situacao'            => 'ATIVO',
        ];
        $id = $this->agentes->inserir($agente, usuario_logado_id());
        $this->auditoria->registrar(AuditoriaService::AGENTE_CRIADO, 'agentes_publicos', $id, null, $agente + ['origem' => 'cadastro de usuário']);
        return $id;
    }

    /** Leva ao agente os dados pessoais e funcionais da conta (chamar em transacao). */
    private function sincronizarAgente(int $agenteId, array $dados): void
    {
        $atual = $this->agentes->buscarParaAtualizar($agenteId);
        if ($atual === null) {
            return;
        }
        $identidade = array_intersect_key($dados, array_flip(AgentePublicoService::CAMPOS_DO_USUARIO));
        [$antes, $depois] = AuditoriaService::diferencas($atual, $identidade);
        if ($depois !== []) {
            $this->agentes->atualizar($agenteId, $depois, usuario_logado_id());
            $this->auditoria->registrar(AuditoriaService::AGENTE_EDITADO, 'agentes_publicos', $agenteId, $antes, $depois);
        }
    }

    /**
     * Atualiza os dados cadastrais e, se informado em $entrada['ativo'], o status.
     * O administrador edita qualquer usuario; os demais, apenas o proprio
     * cadastro, sem alterar perfil nem status.
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function atualizar(int $id, array $entrada): void
    {
        if (!self::podeEditar($id)) {
            throw new AcessoNegadoException('Você só pode editar o seu próprio cadastro.');
        }

        $this->comTraducaoDeDuplicidade(fn() => Database::transacao(function () use ($id, $entrada): void {
            $atual = $this->repo->buscarParaAtualizar($id);
            if ($atual === null) {
                throw new ValidationException([], 'Usuário não encontrado.');
            }

            $dados = $this->validar($entrada, $atual);

            $ativo = null;
            if (array_key_exists('ativo', $entrada)) {
                $ativo = UsuarioValidator::ativo($entrada['ativo']);
                if ($ativo === null) {
                    throw new ValidationException(['ativo' => 'Selecione o status do usuário.']);
                }
            }

            $mudaPerfil = $dados['perfil'] !== $atual['perfil'];
            $mudaStatus = $ativo !== null && $ativo !== ((int)$atual['ativo'] === 1);
            if ($mudaPerfil && !Auth::ehAdministrador()) {
                throw new AcessoNegadoException(self::MSG_SO_ADMIN_PERFIL);
            }
            if ($mudaStatus) {
                $this->verificarAlteracaoDeStatus($atual, $ativo);
            }
            if ($mudaPerfil || $mudaStatus) {
                $this->garantirAdministradorRestante($atual, $dados['perfil'], $ativo ?? ((int)$atual['ativo'] === 1));
            }

            [$antes, $depois] = AuditoriaService::diferencas($atual, $dados);
            if ($depois !== []) {
                $this->repo->atualizar($id, $dados);
                $this->auditoria->registrar(AuditoriaService::USUARIO_EDITADO, self::ENTIDADE, $id, $antes, $depois);
                if ($atual['agente_publico_id'] !== null) {
                    $this->sincronizarAgente((int)$atual['agente_publico_id'], $dados);
                }
            }

            if ($mudaStatus) {
                $this->gravarStatus($id, $ativo);
            }
        }));
        Auth::esquecer();
    }

    /**
     * Ativa ou inativa o usuario (somente administrador). Retorna false
     * quando ja estava no status pedido.
     * @throws ValidationException
     * @throws AcessoNegadoException
     */
    public function alterarStatus(int $id, bool $ativo): bool
    {
        Auth::exigirPerfil(Perfil::ADMINISTRADOR);

        return Database::transacao(function () use ($id, $ativo): bool {
            $atual = $this->repo->buscarParaAtualizar($id);
            if ($atual === null) {
                throw new ValidationException([], 'Usuário não encontrado.');
            }
            if (((int)$atual['ativo'] === 1) === $ativo) {
                return false;
            }
            $this->verificarAlteracaoDeStatus($atual, $ativo);
            $this->garantirAdministradorRestante($atual, $atual['perfil'], $ativo);
            $this->gravarStatus($id, $ativo);
            return true;
        });
    }

    /** Administrador edita qualquer cadastro; os demais, somente o proprio. */
    public static function podeEditar(int $usuarioId): bool
    {
        return Auth::ehAdministrador() || usuario_logado_id() === $usuarioId;
    }

    /** @throws AcessoNegadoException|ValidationException */
    private function verificarAlteracaoDeStatus(array $atual, bool $ativo): void
    {
        if (!Auth::ehAdministrador()) {
            throw new AcessoNegadoException('Somente administradores podem ativar ou inativar usuários.');
        }
        if (!$ativo && usuario_logado_id() === (int)$atual['id']) {
            throw new ValidationException(['ativo' => 'Você não pode inativar o seu próprio usuário.'], 'Você não pode inativar o seu próprio usuário.');
        }
    }

    /**
     * Impede deixar o sistema sem administrador ativo (rebaixando ou
     * inativando o ultimo).
     * @throws ValidationException
     */
    private function garantirAdministradorRestante(array $atual, string $novoPerfil, bool $novoAtivo): void
    {
        $eraAdminAtivo = $atual['perfil'] === Perfil::ADMINISTRADOR && (int)$atual['ativo'] === 1;
        $continuaAdminAtivo = $novoPerfil === Perfil::ADMINISTRADOR && $novoAtivo;
        if ($eraAdminAtivo && !$continuaAdminAtivo && $this->repo->outrosAdministradoresAtivos((int)$atual['id']) === 0) {
            $campo = $novoPerfil !== Perfil::ADMINISTRADOR ? 'perfil' : 'ativo';
            throw new ValidationException([$campo => self::MSG_ULTIMO_ADMIN], self::MSG_ULTIMO_ADMIN);
        }
    }

    private function gravarStatus(int $id, bool $ativo): void
    {
        $this->repo->definirAtivo($id, $ativo);
        $this->auditoria->registrar(
            $ativo ? AuditoriaService::USUARIO_ATIVADO : AuditoriaService::USUARIO_INATIVADO,
            self::ENTIDADE,
            $id,
            ['ativo' => $ativo ? 0 : 1],
            ['ativo' => $ativo ? 1 : 0]
        );
    }

    /**
     * Valida e normaliza os dados cadastrais.
     * @param array|null $atual registro gravado (edicao) ou null (cadastro)
     * @throws ValidationException
     */
    public function validar(array $entrada, ?array $atual): array
    {
        $erros = [];
        $ignorarId = $atual !== null ? (int)$atual['id'] : null;

        [$nome, $erro] = UsuarioValidator::nome($entrada['nome'] ?? null);
        if ($erro !== null) {
            $erros['nome'] = $erro;
        }

        [$email, $erro] = UsuarioValidator::email($entrada['email'] ?? null);
        if ($erro !== null) {
            $erros['email'] = $erro;
        } elseif ($this->repo->emailEmUso($email, $ignorarId)) {
            $erros['email'] = self::MSG_EMAIL_DUPLICADO;
        }

        [$matricula, $erro] = UsuarioValidator::matricula($entrada['matricula'] ?? null);
        if ($erro !== null) {
            $erros['matricula'] = $erro;
        } elseif ($this->repo->matriculaEmUso($matricula, $ignorarId)) {
            $erros['matricula'] = self::MSG_MATRICULA_DUPLICADA;
        }

        $obrigatoria = (bool)config('usuarios.data_nascimento_obrigatoria', false);
        [$dataNascimento, $erro] = UsuarioValidator::dataNascimento($entrada['data_nascimento'] ?? null, $obrigatoria);
        if ($erro !== null) {
            $erros['data_nascimento'] = $erro;
        }

        $cargoId = UsuarioValidator::id($entrada['cargo_id'] ?? null);
        if (!$this->itemSelecionavel($cargoId, $this->cargos->buscarPorId(...), $atual['cargo_id'] ?? null)) {
            $erros['cargo_id'] = 'Selecione o cargo.';
        }

        $lotacaoId = UsuarioValidator::id($entrada['lotacao_id'] ?? null);
        if (!$this->itemSelecionavel($lotacaoId, $this->lotacoes->buscarPorId(...), $atual['lotacao_id'] ?? null)) {
            $erros['lotacao_id'] = 'Selecione a unidade administrativa.';
        }

        // Perfil ausente (campo nao enviado, ex.: usuario editando o proprio
        // cadastro): mantem o gravado, ou USUARIO no cadastro novo.
        $perfil = $atual['perfil'] ?? Perfil::USUARIO;
        if (array_key_exists('perfil', $entrada)) {
            [$perfil, $erro] = UsuarioValidator::perfil($entrada['perfil']);
            if ($erro !== null) {
                $erros['perfil'] = $erro;
            }
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }

        return [
            'nome'            => $nome,
            'email'           => $email,
            'matricula'       => $matricula,
            'cargo_id'        => $cargoId,
            'lotacao_id'      => $lotacaoId,
            'perfil'          => $perfil,
            'data_nascimento' => $dataNascimento,
        ];
    }

    /**
     * O item deve existir e estar ativo. Na edicao, o item ja gravado no
     * usuario continua aceito mesmo que tenha sido inativado depois.
     */
    private function itemSelecionavel(?int $id, callable $buscar, mixed $idAtual): bool
    {
        if ($id === null) {
            return false;
        }
        $item = $buscar($id);
        if ($item === null) {
            return false;
        }
        return (int)$item['ativo'] === 1 || ($idAtual !== null && (int)$idAtual === $id);
    }

    /**
     * A verificacao previa de unicidade nao elimina a concorrencia (dois
     * cadastros simultaneos). A restricao UNIQUE do banco e a garantia
     * final; aqui ela e convertida na mesma mensagem amigavel.
     */
    private function comTraducaoDeDuplicidade(callable $operacao): mixed
    {
        try {
            return $operacao();
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $mensagem = $e->getMessage();
            if (str_contains($mensagem, 'uq_usuarios_email')) {
                throw new ValidationException(['email' => self::MSG_EMAIL_DUPLICADO]);
            }
            if (str_contains($mensagem, 'uq_usuarios_matricula')) {
                throw new ValidationException(['matricula' => self::MSG_MATRICULA_DUPLICADA]);
            }
            if (str_contains($mensagem, 'uq_agentes_matricula')) {
                throw new ValidationException(['matricula' => AgentePublicoService::MSG_MATRICULA_DUPLICADA]);
            }
            if (str_contains($mensagem, 'uq_usuarios_agente')) {
                throw new ValidationException(['agente_publico_id' => self::MSG_AGENTE_COM_USUARIO]);
            }
            throw $e;
        }
    }
}
