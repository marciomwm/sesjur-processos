<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Perfil;
use App\Repositories\AgentePublicoRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\CargoRepository;
use App\Repositories\LotacaoRepository;
use App\Services\AgentePublicoService;
use App\Validators\CpfValidator;

$id = entrada_int('id');
$registro = $id !== null ? (new AgentePublicoRepository())->buscarPorId($id) : null;
if ($registro === null) {
    flash('erro', 'Agente público não encontrado.');
    redirecionar('agentes-publicos.php');
}

$historico = (new AuditoriaRepository())->listarPorEntidade('agentes_publicos', $id);
$nomesCargo   = array_column((new CargoRepository())->todos(), 'nome', 'id');
$nomesLotacao = array_column((new LotacaoRepository())->todos(), 'nome', 'id');
$rotulosCampo = [
    'nome' => 'Nome', 'nome_social' => 'Nome social', 'cpf' => 'CPF', 'data_nascimento' => 'Nascimento',
    'email_institucional' => 'E-mail institucional', 'email_alternativo' => 'E-mail alternativo', 'telefone' => 'Telefone',
    'matricula' => 'Matrícula', 'tipo_vinculo' => 'Tipo de vínculo', 'cargo_id' => 'Cargo', 'funcao' => 'Função',
    'lotacao_id' => 'Unidade', 'data_ingresso' => 'Ingresso', 'data_desligamento' => 'Desligamento',
    'situacao' => 'Situação', 'observacoes' => 'Observações',
];
$valorAuditoria = static fn(string $campo, mixed $valor): string => match (true) {
    $valor === null || $valor === ''  => '—',
    $campo === 'cargo_id'             => (string)($nomesCargo[$valor] ?? '#' . $valor),
    $campo === 'lotacao_id'           => (string)($nomesLotacao[$valor] ?? '#' . $valor),
    $campo === 'tipo_vinculo'         => AgentePublicoService::TIPOS_VINCULO[$valor] ?? (string)$valor,
    $campo === 'situacao'             => AgentePublicoService::SITUACOES[$valor] ?? (string)$valor,
    $campo === 'cpf'                  => CpfValidator::formatar((string)$valor),
    $campo === 'telefone'             => telefone_br((string)$valor),
    str_starts_with($campo, 'data_')  => data_br((string)$valor),
    default                           => mb_strimwidth((string)$valor, 0, 120, '…'),
};
$temUsuario = $registro['usuario_id'] !== null;
$podeCriarUsuario = !$temUsuario && $registro['situacao'] !== 'DESLIGADO';
$pendenciasUsuario = array_filter([
    $registro['matricula'] === null ? 'matrícula' : null,
    $registro['cargo_id'] === null ? 'cargo' : null,
]);

$titulo      = 'Agente Público';
$menuAtivo   = 'agentes';
$breadcrumbs = ['Sistema' => null, 'Agentes Públicos' => 'agentes-publicos.php', $registro['nome'] => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<div class="ficha__cabecalho">
    <div class="flex gap-8" style="align-items:center">
        <span class="avatar" aria-hidden="true"><?= e(iniciais($registro['nome_social'] ?? $registro['nome'])) ?></span>
        <div>
            <h2 class="ficha__numero ficha__numero--nome"><?= e($registro['nome_social'] ?? $registro['nome']) ?></h2>
            <div class="ficha__meta">
                <span class="badge <?= e(AgentePublicoService::classeBadgeSituacao($registro['situacao'])) ?>"><?= e(AgentePublicoService::SITUACOES[$registro['situacao']]) ?></span>
                <span class="badge badge--azul"><?= e(AgentePublicoService::TIPOS_VINCULO[$registro['tipo_vinculo']] ?? $registro['tipo_vinculo']) ?></span>
                <?php if ($registro['cargo'] !== null): ?><span class="badge"><?= e($registro['cargo']) ?></span><?php endif; ?>
                <span class="badge"><?= $temUsuario ? 'Com acesso ao sistema' : 'Sem acesso ao sistema' ?></span>
            </div>
        </div>
    </div>
    <div class="acoes-linha">
        <a class="btn btn--ghost btn--sm" href="<?= e(url('agentes-publicos.php')) ?>">‹ Voltar</a>
        <a class="btn btn--secundario btn--sm" href="<?= e(url('editar-agente-publico.php?id=' . $id)) ?>">✎ Editar</a>
    </div>
</div>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Dados pessoais</h2></div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao"><span class="definicao__rotulo">Nome completo</span><span class="definicao__valor"><?= e($registro['nome']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Nome social</span><span class="definicao__valor"><?= ou_traco($registro['nome_social']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">CPF</span><span class="definicao__valor mono"><?= $registro['cpf'] !== null ? e(CpfValidator::formatar($registro['cpf'])) : '—' ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Data de nascimento</span><span class="definicao__valor"><?= e(data_br($registro['data_nascimento'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">E-mail institucional</span><span class="definicao__valor"><?= ou_traco($registro['email_institucional']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">E-mail alternativo</span><span class="definicao__valor"><?= ou_traco($registro['email_alternativo']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Telefone</span><span class="definicao__valor"><?= $registro['telefone'] !== null ? e(telefone_br($registro['telefone'])) : '—' ?></span></div>
        </div>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Dados funcionais e lotação</h2></div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao"><span class="definicao__rotulo">Matrícula</span><span class="definicao__valor mono"><?= ou_traco($registro['matricula']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Tipo de vínculo</span><span class="definicao__valor"><?= e(AgentePublicoService::TIPOS_VINCULO[$registro['tipo_vinculo']] ?? $registro['tipo_vinculo']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Cargo</span><span class="definicao__valor"><?= ou_traco($registro['cargo']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Função</span><span class="definicao__valor"><?= ou_traco($registro['funcao']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Órgão ou Entidade</span><span class="definicao__valor"><?= ou_traco($registro['instituicao_nome']) ?></span></div>
            <div class="definicao">
                <span class="definicao__rotulo">Unidade Administrativa</span>
                <span class="definicao__valor"><a href="<?= e(url('unidade-administrativa.php?id=' . $registro['lotacao_id'])) ?>"><?= e($registro['lotacao']) ?></a></span>
            </div>
            <div class="definicao"><span class="definicao__rotulo">Ingresso</span><span class="definicao__valor"><?= e(data_br($registro['data_ingresso'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Desligamento</span><span class="definicao__valor"><?= e(data_br($registro['data_desligamento'])) ?></span></div>
            <div class="definicao definicao--larga"><span class="definicao__rotulo">Observações</span><span class="definicao__valor texto-longo"><?= nl2br(ou_traco($registro['observacoes'])) ?></span></div>
            <div class="definicao">
                <span class="definicao__rotulo">Cadastro</span>
                <span class="definicao__valor"><?= e(datahora_br($registro['created_at'])) ?><br><span class="texto-secundario"><?= e($registro['criado_por_nome'] ?? 'Não identificado') ?></span></span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Última alteração</span>
                <span class="definicao__valor"><?= e(datahora_br($registro['updated_at'])) ?><br><span class="texto-secundario"><?= e($registro['atualizado_por_nome'] ?? 'Não identificado') ?></span></span>
            </div>
        </div>
    </div>
</section>

<section class="card" id="usuario">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Usuário do sistema</h2>
            <p class="card__subtitulo">A conta guarda só o acesso (login, perfil e status); os dados da pessoa vêm deste cadastro.</p>
        </div>
        <?php if ($podeCriarUsuario && $pendenciasUsuario === []): ?>
            <a class="btn btn--primario btn--sm" href="<?= e(url('novo-usuario.php?agente_id=' . $id)) ?>">＋ Criar usuário do sistema</a>
        <?php endif; ?>
    </div>
    <div class="card__corpo">
        <?php if ($temUsuario): ?>
            <div class="definicoes">
                <div class="definicao">
                    <span class="definicao__rotulo">Conta</span>
                    <span class="definicao__valor"><a href="<?= e(url('usuario.php?id=' . $registro['usuario_id'])) ?>"><?= e($registro['usuario_email']) ?></a></span>
                </div>
                <div class="definicao">
                    <span class="definicao__rotulo">Perfil</span>
                    <span class="definicao__valor"><span class="badge <?= e(Perfil::classeBadge($registro['usuario_perfil'])) ?>"><?= e(Perfil::rotulo($registro['usuario_perfil'])) ?></span></span>
                </div>
                <div class="definicao">
                    <span class="definicao__rotulo">Status da conta</span>
                    <span class="definicao__valor"><span class="badge <?= (int)$registro['usuario_ativo'] === 1 ? 'badge--verde' : 'badge--neutro' ?>"><?= (int)$registro['usuario_ativo'] === 1 ? 'Ativa' : 'Inativa' ?></span></span>
                </div>
            </div>
        <?php elseif (!$podeCriarUsuario): ?>
            <p class="texto-secundario mb-0">Agente desligado: não pode receber acesso ao sistema.</p>
        <?php elseif ($pendenciasUsuario !== []): ?>
            <p class="texto-secundario mb-0">Para criar o usuário, informe antes <?= e(implode(' e ', $pendenciasUsuario)) ?> no cadastro do agente.
                <a href="<?= e(url('editar-agente-publico.php?id=' . $id)) ?>">Completar cadastro</a></p>
        <?php else: ?>
            <p class="texto-secundario mb-0">Este agente ainda não tem acesso ao sistema. Nome, matrícula, cargo e unidade serão aproveitados na criação do usuário.</p>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Histórico de alterações</h2></div>
    <?php require APP_ROOT . '/Views/partials/historico-auditoria.php'; ?>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
