<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AgentePublicoRepository;
use App\Repositories\CargoRepository;
use App\Repositories\LotacaoRepository;
use App\Repositories\UsuarioRepository;
use App\Validators\CpfValidator;
use App\Validators\Normalizador;
use App\Validators\UsuarioValidator;
use PDOException;

/**
 * Agentes Publicos: cadastro centralizado das pessoas (servidores,
 * estagiarios, terceirizados...), base para os usuarios do sistema.
 * Somente administradores cadastram e alteram.
 *
 * O agente e a fonte dos dados pessoais e funcionais do usuario vinculado:
 * alterar nome, matricula, cargo, unidade ou nascimento aqui atualiza a
 * conta, e desligar o agente inativa o acesso.
 */
final class AgentePublicoService
{
    public const TIPOS_VINCULO = [
        'EFETIVO'           => 'Servidor efetivo',
        'COMISSIONADO'      => 'Servidor comissionado',
        'EMPREGADO_PUBLICO' => 'Empregado público',
        'ESTAGIARIO'        => 'Estagiário',
        'RESIDENTE_TECNICO' => 'Residente técnico',
        'TERCEIRIZADO'      => 'Profissional terceirizado',
        'CEDIDO'            => 'Cedido',
        'COLABORADOR'       => 'Colaborador',
        'OUTRO'             => 'Outro',
    ];

    public const SITUACOES = [
        'ATIVO'     => 'Ativo',
        'AFASTADO'  => 'Afastado',
        'DESLIGADO' => 'Desligado',
        'OUTRO'     => 'Outro',
    ];

    public const FUNCAO_MAX      = 120;
    public const OBSERVACOES_MAX = 2000;

    public const MSG_CPF_DUPLICADO       = 'Já existe um agente público cadastrado com este CPF.';
    public const MSG_MATRICULA_DUPLICADA = 'Já existe um agente público cadastrado com esta matrícula.';
    public const MSG_EMAIL_DUPLICADO     = 'Já existe um agente público cadastrado com este e-mail institucional.';

    private const ENTIDADE = 'agentes_publicos';

    /** Campos do agente copiados para a conta de usuario vinculada. */
    public const CAMPOS_DO_USUARIO = ['nome', 'matricula', 'cargo_id', 'lotacao_id', 'data_nascimento'];

    public function __construct(
        private readonly AgentePublicoRepository $repo = new AgentePublicoRepository(),
        private readonly CargoRepository $cargos = new CargoRepository(),
        private readonly LotacaoRepository $lotacoes = new LotacaoRepository(),
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService()
    ) {
    }

    public static function classeBadgeSituacao(?string $situacao): string
    {
        return match ($situacao) {
            'ATIVO'     => 'badge--verde',
            'AFASTADO'  => 'badge--ambar',
            'DESLIGADO' => 'badge--neutro',
            default     => 'badge--azul',
        };
    }

    /**
     * @return int id do agente criado
     * @throws ValidationException
     */
    public function criar(array $entrada): int
    {
        Auth::exigirPerfil(Perfil::ADMINISTRADOR);
        $dados = $this->validar($entrada, null);

        return $this->comTraducaoDeDuplicidade(fn(): int => Database::transacao(function () use ($dados): int {
            $id = $this->repo->inserir($dados, usuario_logado_id());
            $this->auditoria->registrar(AuditoriaService::AGENTE_CRIADO, self::ENTIDADE, $id, null, $dados);
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
                throw new ValidationException([], 'Agente público não encontrado.');
            }
            $completo = $this->repo->buscarPorId($id);
            $usuarioId = $completo['usuario_id'] !== null ? (int)$completo['usuario_id'] : null;

            $dados = $this->validar($entrada, $atual, $usuarioId !== null);
            [$antes, $depois] = AuditoriaService::diferencas($atual, $dados);
            if ($depois === []) {
                return;
            }
            $this->repo->atualizar($id, $dados, usuario_logado_id());
            $this->auditoria->registrar(AuditoriaService::AGENTE_EDITADO, self::ENTIDADE, $id, $antes, $depois);

            if ($usuarioId !== null) {
                $this->sincronizarUsuario($usuarioId, $dados);
                // Desligamento encerra o acesso (as regras de ultimo administrador valem).
                if ($dados['situacao'] === 'DESLIGADO' && $atual['situacao'] !== 'DESLIGADO' && (int)$completo['usuario_ativo'] === 1) {
                    try {
                        (new UsuarioService())->alterarStatus($usuarioId, false);
                    } catch (ValidationException $e) {
                        throw new ValidationException(['situacao' => 'O usuário do agente não pode ser inativado: ' . $e->getMessage()]);
                    }
                }
            }
        }));
    }

    /** Copia os dados pessoais e funcionais para a conta de usuario (chamar em transacao). */
    private function sincronizarUsuario(int $usuarioId, array $dados): void
    {
        $usuario = $this->usuarios->buscarParaAtualizar($usuarioId);
        if ($usuario === null) {
            return;
        }
        $novos = array_intersect_key($dados, array_flip(self::CAMPOS_DO_USUARIO));
        [$antes, $depois] = AuditoriaService::diferencas($usuario, $novos);
        if ($depois === []) {
            return;
        }
        $this->usuarios->sincronizarDoAgente($usuarioId, $novos + $usuario);
        $this->auditoria->registrar(AuditoriaService::USUARIO_EDITADO, 'usuarios', $usuarioId, $antes, $depois);
    }

    /**
     * Valida e normaliza os dados.
     * @param array|null $atual     registro gravado (edicao)
     * @param bool       $temUsuario com conta de usuario, matricula e cargo sao obrigatorios
     * @throws ValidationException
     */
    public function validar(array $entrada, ?array $atual, bool $temUsuario = false): array
    {
        $erros = [];
        $ignorarId = $atual !== null ? (int)$atual['id'] : null;
        $texto = static fn(string $campo): ?string => Normalizador::texto(is_string($entrada[$campo] ?? null) ? $entrada[$campo] : null);

        [$nome, $erro] = UsuarioValidator::nome($entrada['nome'] ?? null);
        if ($erro !== null) {
            $erros['nome'] = $erro;
        }

        $nomeSocial = $texto('nome_social');
        if ($nomeSocial !== null) {
            [$nomeSocial, $erro] = UsuarioValidator::nome($nomeSocial);
            if ($erro !== null) {
                $erros['nome_social'] = str_replace('O nome', 'O nome social', $erro);
            }
        }

        $cpf = CpfValidator::normalizar($entrada['cpf'] ?? null);
        if ($cpf !== null) {
            if (!CpfValidator::valido($cpf)) {
                $erros['cpf'] = 'CPF inválido. Confira os 11 dígitos.';
            } elseif ($this->repo->valorEmUso('cpf', $cpf, $ignorarId)) {
                $erros['cpf'] = self::MSG_CPF_DUPLICADO;
            }
        }

        [$dataNascimento, $erro] = UsuarioValidator::dataNascimento($entrada['data_nascimento'] ?? null);
        if ($erro !== null) {
            $erros['data_nascimento'] = $erro;
        }

        $emailInstitucional = null;
        if ($texto('email_institucional') !== null) {
            [$emailInstitucional, $erro] = UsuarioValidator::email($entrada['email_institucional']);
            if ($erro !== null) {
                $erros['email_institucional'] = $erro;
            } elseif ($this->repo->valorEmUso('email_institucional', $emailInstitucional, $ignorarId)) {
                $erros['email_institucional'] = self::MSG_EMAIL_DUPLICADO;
            }
        }
        $emailAlternativo = null;
        if ($texto('email_alternativo') !== null) {
            [$emailAlternativo, $erro] = UsuarioValidator::email($entrada['email_alternativo']);
            if ($erro !== null) {
                $erros['email_alternativo'] = str_replace('institucional', 'alternativo', $erro);
            }
        }

        $telefone = Normalizador::digitos(is_string($entrada['telefone'] ?? null) ? $entrada['telefone'] : null) ?: null;
        if ($telefone !== null && !in_array(strlen($telefone), [10, 11], true)) {
            $erros['telefone'] = 'Informe o telefone com DDD (10 ou 11 dígitos), por exemplo (65) 3613-5300.';
        }

        $matricula = null;
        if ($texto('matricula') !== null) {
            [$matricula, $erro] = UsuarioValidator::matricula($entrada['matricula']);
            if ($erro !== null) {
                $erros['matricula'] = $erro;
            } elseif ($this->repo->valorEmUso('matricula', $matricula, $ignorarId)) {
                $erros['matricula'] = self::MSG_MATRICULA_DUPLICADA;
            }
        } elseif ($temUsuario) {
            $erros['matricula'] = 'Este agente tem usuário do sistema: a matrícula é obrigatória.';
        }

        $tipoVinculo = strtoupper($texto('tipo_vinculo') ?? '');
        if (!isset(self::TIPOS_VINCULO[$tipoVinculo])) {
            $erros['tipo_vinculo'] = 'Selecione o tipo de vínculo.';
        }

        $cargoId = UsuarioValidator::id($entrada['cargo_id'] ?? null);
        if ($cargoId !== null) {
            $cargo = $this->cargos->buscarPorId($cargoId);
            if ($cargo === null || ((int)$cargo['ativo'] !== 1 && $cargoId !== (int)($atual['cargo_id'] ?? 0))) {
                $erros['cargo_id'] = 'Selecione um cargo ativo.';
            }
        } elseif ($temUsuario) {
            $erros['cargo_id'] = 'Este agente tem usuário do sistema: o cargo é obrigatório.';
        }

        $funcao = $texto('funcao');
        if ($funcao !== null && mb_strlen($funcao) > self::FUNCAO_MAX) {
            $erros['funcao'] = 'A função deve ter no máximo ' . self::FUNCAO_MAX . ' caracteres.';
        }

        $lotacaoId = UsuarioValidator::id($entrada['lotacao_id'] ?? null);
        $lotacao = $lotacaoId !== null ? $this->lotacoes->buscarPorId($lotacaoId) : null;
        if ($lotacao === null) {
            $erros['lotacao_id'] = 'Selecione a unidade administrativa de lotação.';
        } elseif ((int)$lotacao['ativo'] !== 1 && $lotacaoId !== (int)($atual['lotacao_id'] ?? 0)) {
            $erros['lotacao_id'] = 'A unidade ' . $lotacao['nome'] . ' está inativa.';
        }

        $ingresso = $this->data($entrada['data_ingresso'] ?? null, 'data_ingresso', $erros);
        $desligamento = $this->data($entrada['data_desligamento'] ?? null, 'data_desligamento', $erros);
        if ($ingresso !== null && $dataNascimento !== null && !isset($erros['data_ingresso']) && $ingresso <= $dataNascimento) {
            $erros['data_ingresso'] = 'A data de ingresso deve ser posterior ao nascimento.';
        }
        if ($ingresso !== null && $desligamento !== null && !isset($erros['data_desligamento']) && $desligamento < $ingresso) {
            $erros['data_desligamento'] = 'A data de desligamento não pode ser anterior à de ingresso.';
        }

        $situacao = strtoupper($texto('situacao') ?? 'ATIVO');
        if (!isset(self::SITUACOES[$situacao])) {
            $erros['situacao'] = 'Selecione a situação.';
        }

        $observacoes = Normalizador::textoLongo($entrada['observacoes'] ?? null);
        if ($observacoes !== null && mb_strlen($observacoes) > self::OBSERVACOES_MAX) {
            $erros['observacoes'] = 'As observações devem ter no máximo ' . self::OBSERVACOES_MAX . ' caracteres.';
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }

        return [
            'nome'                => $nome,
            'nome_social'         => $nomeSocial,
            'cpf'                 => $cpf,
            'data_nascimento'     => $dataNascimento,
            'email_institucional' => $emailInstitucional,
            'email_alternativo'   => $emailAlternativo,
            'telefone'            => $telefone,
            'matricula'           => $matricula,
            'tipo_vinculo'        => $tipoVinculo,
            'cargo_id'            => $cargoId,
            'funcao'              => $funcao,
            'lotacao_id'          => $lotacaoId,
            'data_ingresso'       => $ingresso,
            'data_desligamento'   => $desligamento,
            'situacao'            => $situacao,
            'observacoes'         => $observacoes,
        ];
    }

    /** Data opcional em ISO; registra o erro no campo. */
    private function data(mixed $valor, string $campo, array &$erros): ?string
    {
        $texto = Normalizador::texto(is_string($valor) ? $valor : null);
        if ($texto === null) {
            return null;
        }
        $iso = Normalizador::dataIso($texto);
        if ($iso === null || $iso < UsuarioValidator::DATA_MINIMA) {
            $erros[$campo] = 'Data inválida.';
            return null;
        }
        return $iso;
    }

    private function comTraducaoDeDuplicidade(callable $operacao): mixed
    {
        try {
            return $operacao();
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $mensagem = $e->getMessage();
            foreach (['uq_agentes_cpf' => ['cpf', self::MSG_CPF_DUPLICADO],
                      'uq_agentes_matricula' => ['matricula', self::MSG_MATRICULA_DUPLICADA],
                      'uq_agentes_email' => ['email_institucional', self::MSG_EMAIL_DUPLICADO],
                      'uq_usuarios_matricula' => ['matricula', UsuarioService::MSG_MATRICULA_DUPLICADA]] as $indice => [$campo, $texto]) {
                if (str_contains($mensagem, $indice)) {
                    throw new ValidationException([$campo => $texto]);
                }
            }
            throw $e;
        }
    }
}
