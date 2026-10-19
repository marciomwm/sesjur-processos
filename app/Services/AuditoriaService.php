<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;

/**
 * Registro de eventos na trilha de auditoria. Deve ser chamado dentro da
 * mesma transacao da alteracao auditada: se a gravacao falhar, o evento
 * tambem nao e registrado (e vice-versa).
 */
final class AuditoriaService
{
    public const USUARIO_CRIADO    = 'USUARIO_CRIADO';
    public const USUARIO_EDITADO   = 'USUARIO_EDITADO';
    public const USUARIO_ATIVADO   = 'USUARIO_ATIVADO';
    public const USUARIO_INATIVADO = 'USUARIO_INATIVADO';
    public const LOGIN_REALIZADO   = 'LOGIN_REALIZADO';
    public const LOGIN_FALHOU      = 'LOGIN_FALHOU';
    public const LOGOUT            = 'LOGOUT';
    public const SENHA_DEFINIDA    = 'SENHA_DEFINIDA';
    public const SENHA_ALTERADA    = 'SENHA_ALTERADA';
    public const DESIGNACAO_CRIADA   = 'DESIGNACAO_CRIADA';
    public const DESIGNACAO_REMOVIDA = 'DESIGNACAO_REMOVIDA';
    public const ACAO_CRIADA   = 'ACAO_CRIADA';
    public const ACAO_EDITADA  = 'ACAO_EDITADA';
    public const ACAO_EXCLUIDA = 'ACAO_EXCLUIDA';
    public const SOLICITACAO_CRIADA   = 'SOLICITACAO_CRIADA';
    public const SOLICITACAO_EDITADA  = 'SOLICITACAO_EDITADA';
    public const SOLICITACAO_EXCLUIDA = 'SOLICITACAO_EXCLUIDA';
    public const ANOTACAO_CRIADA   = 'ANOTACAO_CRIADA';
    public const ANOTACAO_EDITADA  = 'ANOTACAO_EDITADA';
    public const ANOTACAO_EXCLUIDA = 'ANOTACAO_EXCLUIDA';
    public const PRIORIDADE_ATRIBUIDA = 'PRIORIDADE_ATRIBUIDA';
    public const PRIORIDADE_REMOVIDA  = 'PRIORIDADE_REMOVIDA';
    public const PRODUTO_SAUDE_CRIADO   = 'PRODUTO_SAUDE_CRIADO';
    public const PRODUTO_SAUDE_EDITADO  = 'PRODUTO_SAUDE_EDITADO';
    public const PRODUTO_SAUDE_EXCLUIDO = 'PRODUTO_SAUDE_EXCLUIDO';
    public const EXTRAJUDICIAL_VINCULADO    = 'EXTRAJUDICIAL_VINCULADO';
    public const EXTRAJUDICIAL_DESVINCULADO = 'EXTRAJUDICIAL_DESVINCULADO';
    public const PROCESSO_EXTRAJUDICIAL_CRIADO   = 'PROCESSO_EXTRAJUDICIAL_CRIADO';
    public const PROCESSO_EXTRAJUDICIAL_EDITADO  = 'PROCESSO_EXTRAJUDICIAL_EDITADO';
    public const PROCESSO_EXTRAJUDICIAL_EXCLUIDO = 'PROCESSO_EXTRAJUDICIAL_EXCLUIDO';
    public const ADMINISTRATIVO_VINCULADO    = 'ADMINISTRATIVO_VINCULADO';
    public const ADMINISTRATIVO_DESVINCULADO = 'ADMINISTRATIVO_DESVINCULADO';
    public const AGENTE_CRIADO  = 'AGENTE_CRIADO';
    public const AGENTE_EDITADO = 'AGENTE_EDITADO';

    public const ROTULOS = [
        self::PRIORIDADE_ATRIBUIDA => 'Prioridade atribuída',
        self::PRIORIDADE_REMOVIDA  => 'Prioridade removida',
        self::PRODUTO_SAUDE_CRIADO   => 'Produto de saúde incluído',
        self::PRODUTO_SAUDE_EDITADO  => 'Produto de saúde alterado',
        self::PRODUTO_SAUDE_EXCLUIDO => 'Produto de saúde excluído',
        self::EXTRAJUDICIAL_VINCULADO    => 'Processo extrajudicial vinculado',
        self::EXTRAJUDICIAL_DESVINCULADO => 'Processo extrajudicial desvinculado',
        self::PROCESSO_EXTRAJUDICIAL_EXCLUIDO => 'Exclusão',
        self::ADMINISTRATIVO_VINCULADO    => 'Processo administrativo vinculado',
        self::ADMINISTRATIVO_DESVINCULADO => 'Processo administrativo desvinculado',
        self::ACAO_CRIADA   => 'Tarefa cadastrada',
        self::ACAO_EDITADA  => 'Tarefa alterada',
        self::ACAO_EXCLUIDA => 'Tarefa excluída',
        self::SOLICITACAO_CRIADA   => 'Solicitação cadastrada',
        self::SOLICITACAO_EDITADA  => 'Solicitação alterada',
        self::SOLICITACAO_EXCLUIDA => 'Solicitação excluída',
        self::ANOTACAO_CRIADA   => 'Anotação cadastrada',
        self::ANOTACAO_EDITADA  => 'Anotação alterada',
        self::ANOTACAO_EXCLUIDA => 'Anotação excluída',
        self::USUARIO_CRIADO    => 'Cadastro',
        self::USUARIO_EDITADO   => 'Edição',
        self::USUARIO_ATIVADO   => 'Ativação',
        self::USUARIO_INATIVADO => 'Inativação',
        self::LOGIN_REALIZADO   => 'Login realizado',
        self::LOGIN_FALHOU      => 'Falha de login',
        self::LOGOUT            => 'Saída do sistema',
        self::SENHA_DEFINIDA    => 'Senha definida pelo administrador',
        self::SENHA_ALTERADA    => 'Senha alterada pelo usuário',
        self::DESIGNACAO_CRIADA   => 'Designação',
        self::DESIGNACAO_REMOVIDA => 'Designação removida',
        'RECURSO_EXCLUIDO'        => 'Exclusão',
        'BLOQUEIO_JUDICIAL_CRIADO'   => 'Lançamento de bloqueio registrado',
        'BLOQUEIO_JUDICIAL_EDITADO'  => 'Lançamento de bloqueio alterado',
        'BLOQUEIO_JUDICIAL_EXCLUIDO' => 'Lançamento de bloqueio excluído',
        'GUIA_DEPOSITO_CRIADA'       => 'Guia de depósito registrada',
        'GUIA_DEPOSITO_EDITADA'      => 'Guia de depósito alterada',
        'GUIA_DEPOSITO_EXCLUIDA'     => 'Guia de depósito excluída',
        'INTIMACAO_CRIADA'           => 'Intimação registrada',
        'INTIMACAO_EDITADA'          => 'Intimação alterada',
        'INTIMACAO_EXCLUIDA'         => 'Intimação excluída',
        'MODELO_EXPEDIENTE_COMPARTILHADO'   => 'Modelo de expediente compartilhado',
        'MODELO_EXPEDIENTE_INDIVIDUALIZADO' => 'Modelo de expediente deixou de ser compartilhado',
        'MODELO_EXPEDIENTE_EXCLUIDO'        => 'Modelo de expediente compartilhado excluído',
        'CONFIGURACAO_ALTERADA'             => 'Configuração da extensão alterada',
        'PROCEDIMENTO_CONCILIADO'           => 'Processos antigos vinculados (conciliação)',
        'PAGAMENTO_REGISTRADO'              => 'Dados do pagamento registrados',
        'PAGAMENTO_EDITADO'                 => 'Dados do pagamento alterados',
    ];

    /** Rotulo de exibicao de qualquer acao (USUARIO_EDITADO, LOTACAO_INATIVADA...). */
    public static function rotulo(string $acao): string
    {
        if (isset(self::ROTULOS[$acao])) {
            return self::ROTULOS[$acao];
        }
        return match (true) {
            (bool)preg_match('/_CRIAD[OA]$/', $acao)    => 'Cadastro',
            (bool)preg_match('/_EDITAD[OA]$/', $acao)   => 'Edição',
            (bool)preg_match('/_INATIVAD[OA]$/', $acao) => 'Inativação',
            (bool)preg_match('/_ATIVAD[OA]$/', $acao)   => 'Ativação',
            default                                     => $acao,
        };
    }

    /**
     * Resumo legivel de um evento que nao e edicao (inclusao, vinculo,
     * exclusao...): o identificador ou o texto principal do item afetado.
     */
    public static function resumo(array $antes, array $depois): string
    {
        $dados = $depois + $antes;
        $partes = [];
        if (!empty($dados['prioridade'])) {
            $partes[] = $dados['prioridade'] . (isset($dados['nivel']) ? ' (nível ' . $dados['nivel'] . ')' : '');
        }
        foreach (['protocolo', 'instituicao', 'expediente', 'descricao', 'assunto', 'texto', 'texto_legado', 'sigtap', 'motivo', 'credor'] as $chave) {
            if (isset($dados[$chave]) && is_scalar($dados[$chave]) && (string)$dados[$chave] !== '') {
                $partes[] = (string)$dados[$chave];
            }
        }
        return mb_strimwidth(preg_replace('/\s+/u', ' ', implode(' · ', $partes)) ?? '', 0, 200, '…');
    }

    public function __construct(
        private readonly AuditoriaRepository $repo = new AuditoriaRepository()
    ) {
    }

    public function registrar(
        string $acao,
        string $entidade,
        ?int $entidadeId,
        ?array $antes = null,
        ?array $depois = null,
        ?int $autorId = null
    ): void {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $this->repo->inserir([
            // Autor explicito quando ainda nao ha sessao (ex.: o proprio login).
            'usuario_id'       => $autorId ?? usuario_logado_id(),
            'acao'             => $acao,
            'entidade'         => $entidade,
            'entidade_id'      => $entidadeId,
            'dados_anteriores' => self::json($antes),
            'dados_novos'      => self::json($depois),
            'ip'               => ip_cliente(),
            'user_agent'       => is_string($userAgent) ? mb_substr($userAgent, 0, 255) : null,
        ]);
    }

    /**
     * Apenas os campos que mudaram entre dois estados.
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    public static function diferencas(array $antes, array $depois): array
    {
        $a = [];
        $d = [];
        foreach ($depois as $campo => $valor) {
            $anterior = $antes[$campo] ?? null;
            if ((string)$anterior !== (string)$valor) {
                $a[$campo] = $anterior;
                $d[$campo] = $valor;
            }
        }
        return [$a, $d];
    }

    private static function json(?array $dados): ?string
    {
        return $dados === null ? null : json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
