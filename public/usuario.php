<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Perfil;
use App\Repositories\AuditoriaRepository;
use App\Repositories\CargoRepository;
use App\Repositories\CredencialRepository;
use App\Repositories\DesignacaoRepository;
use App\Repositories\LotacaoRepository;
use App\Repositories\UsuarioRepository;
use App\Services\AuditoriaService;
use App\Validators\SenhaValidator;

$id = entrada_int('id');
if ($id === null) {
    flash('erro', 'Usuário não informado.');
    redirecionar('configuracoes.php?aba=usuarios');
}

// Administrador ve qualquer ficha; os demais, apenas a propria.
$ehAdmin = Auth::ehAdministrador();
$proprio = usuario_logado_id() === $id;
if (!$ehAdmin && !$proprio) {
    throw new AcessoNegadoException('Você só pode consultar o seu próprio cadastro.');
}

$registro = (new UsuarioRepository())->buscarPorId($id);
if ($registro === null) {
    flash('erro', 'Usuário não encontrado.');
    redirecionar('configuracoes.php?aba=usuarios');
}

$historico = (new AuditoriaRepository())->listarPorEntidade('usuarios', $id, 50, [
    AuditoriaService::USUARIO_CRIADO, AuditoriaService::USUARIO_EDITADO,
    AuditoriaService::USUARIO_ATIVADO, AuditoriaService::USUARIO_INATIVADO,
    AuditoriaService::SENHA_DEFINIDA, AuditoriaService::SENHA_ALTERADA,
]);
$acesso      = (new CredencialRepository())->situacao($id);
$designacoes = (new DesignacaoRepository())->doUsuario($id, 100);

// Traducao dos campos gravados na auditoria para exibicao.
$nomesCargo   = array_column((new CargoRepository())->todos(), 'nome', 'id');
$nomesLotacao = array_column((new LotacaoRepository())->todos(), 'nome', 'id');
$rotulosCampo = [
    'nome' => 'Nome', 'email' => 'E-mail', 'matricula' => 'Matrícula', 'cargo_id' => 'Cargo',
    'lotacao_id' => 'Unidade Administrativa', 'perfil' => 'Perfil', 'data_nascimento' => 'Data de nascimento', 'ativo' => 'Status',
];
$valorAuditoria = static function (string $campo, mixed $valor) use ($nomesCargo, $nomesLotacao): string {
    return match (true) {
        $valor === null || $valor === ''  => '—',
        $campo === 'cargo_id'             => (string)($nomesCargo[$valor] ?? '#' . $valor),
        $campo === 'lotacao_id'           => (string)($nomesLotacao[$valor] ?? '#' . $valor),
        $campo === 'perfil'               => Perfil::rotulo((string)$valor),
        $campo === 'data_nascimento'      => data_br((string)$valor),
        $campo === 'ativo'                => (int)$valor === 1 ? 'Ativo' : 'Inativo',
        default                           => (string)$valor,
    };
};

$ativo = (int)$registro['ativo'] === 1;
$errosSenha = $_SESSION['erros_senha'] ?? [];
unset($_SESSION['erros_senha']);

if ($proprio && !$ehAdmin) {
    $titulo      = 'Meu cadastro';
    $menuAtivo   = 'meu-cadastro';
    $breadcrumbs = ['Minha conta' => null, 'Meu cadastro' => null];
} else {
    $titulo      = 'Usuário';
    $menuAtivo   = $proprio ? 'meu-cadastro' : 'config';
    $breadcrumbs = ['Usuários' => 'configuracoes.php?aba=usuarios', $registro['nome'] => null];
}

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<div class="ficha__cabecalho">
    <div class="flex gap-8" style="align-items:center">
        <span class="avatar" aria-hidden="true"><?= e(iniciais($registro['nome'])) ?></span>
        <div>
            <h2 class="ficha__numero ficha__numero--nome"><?= e($registro['nome']) ?></h2>
            <div class="ficha__meta">
                <span class="badge <?= $ativo ? 'badge--verde' : 'badge--neutro' ?>"><?= $ativo ? 'Ativo' : 'Inativo' ?></span>
                <span class="badge <?= e(Perfil::classeBadge($registro['perfil'])) ?>"><?= e(Perfil::rotulo($registro['perfil'])) ?></span>
                <span class="badge badge--azul"><?= e($registro['cargo']) ?></span>
                <span class="badge"><?= e($registro['lotacao']) ?></span>
            </div>
        </div>
    </div>
    <div class="acoes-linha">
        <?php if ($ehAdmin): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('configuracoes.php?aba=usuarios')) ?>">‹ Voltar</a>
        <?php endif; ?>
        <a class="btn btn--secundario btn--sm" href="<?= e(url('editar-usuario.php?id=' . $id)) ?>">✎ Editar</a>
        <?php if ($ehAdmin && !$proprio): ?>
            <form method="post" action="<?= e(url('alterar-status-usuario.php')) ?>" style="display:inline"
                  data-confirmar="<?= e($ativo
                      ? 'Inativar ' . $registro['nome'] . '? O cadastro e o histórico serão preservados, e o acesso ao sistema será bloqueado.'
                      : 'Reativar ' . $registro['nome'] . '?') ?>">
                <?= Csrf::campo() ?>
                <input type="hidden" name="id" value="<?= e((string)$id) ?>">
                <input type="hidden" name="ativo" value="<?= $ativo ? '0' : '1' ?>">
                <input type="hidden" name="origem" value="ficha">
                <button type="submit" class="btn btn--sm <?= $ativo ? 'btn--perigo' : 'btn--primario' ?>">
                    <?= $ativo ? 'Inativar usuário' : 'Reativar usuário' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (!$ativo): ?>
    <div class="alert alert--aviso" role="status">
        <span class="alert__icone">ℹ</span>
        <span>Usuário inativo desde <?= e(datahora_br($registro['inativado_em'])) ?>. O cadastro é mantido para preservar o histórico.</span>
    </div>
<?php endif; ?>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Dados cadastrais</h2></div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao"><span class="definicao__rotulo">Nome completo</span><span class="definicao__valor"><?= e($registro['nome']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">E-mail institucional</span><span class="definicao__valor"><?= e($registro['email']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Matrícula</span><span class="definicao__valor mono"><?= e($registro['matricula']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Data de nascimento</span><span class="definicao__valor"><?= e(data_br($registro['data_nascimento'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Cargo</span><span class="definicao__valor"><?= e($registro['cargo']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Unidade Administrativa</span><span class="definicao__valor"><?= e($registro['lotacao']) ?></span></div>
            <div class="definicao">
                <span class="definicao__rotulo">Agente público</span>
                <span class="definicao__valor">
                    <?php if ($registro['agente_publico_id'] === null): ?>
                        <span class="texto-secundario">Conta sem agente vinculado</span>
                    <?php elseif ($ehAdmin): ?>
                        <a href="<?= e(url('agente-publico.php?id=' . (int)$registro['agente_publico_id'])) ?>">Abrir cadastro do agente</a>
                    <?php else: ?>
                        Vinculado <span class="texto-secundario">(dados pessoais e funcionais)</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="definicao"><span class="definicao__rotulo">Cadastrado em</span><span class="definicao__valor"><?= e(datahora_br($registro['created_at'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Última atualização</span><span class="definicao__valor"><?= e(datahora_br($registro['updated_at'])) ?></span></div>
        </div>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Acesso ao sistema</h2>
            <p class="card__subtitulo">Perfil e situação da senha. O login é feito com o e-mail institucional.</p>
        </div>
        <?php if ($proprio): ?>
            <a class="btn btn--secundario btn--sm" href="<?= e(url('alterar-senha.php')) ?>">🔑 Alterar minha senha</a>
        <?php endif; ?>
    </div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao">
                <span class="definicao__rotulo">Perfil de acesso</span>
                <span class="definicao__valor">
                    <span class="badge <?= e(Perfil::classeBadge($registro['perfil'])) ?>"><?= e(Perfil::rotulo($registro['perfil'])) ?></span>
                    <br><span class="texto-secundario"><?= e(Perfil::DESCRICOES[$registro['perfil']] ?? '') ?></span>
                </span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Senha</span>
                <span class="definicao__valor">
                    <?php if ($acesso === null): ?>
                        <span class="badge badge--ambar">Não definida</span>
                        <br><span class="texto-secundario">Sem senha, o usuário não consegue entrar.</span>
                    <?php elseif ((int)$acesso['bloqueado'] === 1): ?>
                        <span class="badge badge--vermelho">Bloqueada temporariamente</span>
                        <br><span class="texto-secundario">Até <?= e(datahora_br($acesso['bloqueado_ate'])) ?>, por tentativas incorretas.</span>
                    <?php elseif ((int)$acesso['exigir_troca_senha'] === 1): ?>
                        <span class="badge badge--ambar">Provisória</span>
                        <br><span class="texto-secundario">Será trocada no próximo login.</span>
                    <?php else: ?>
                        <span class="badge badge--verde">Definida</span>
                        <br><span class="texto-secundario">Alterada em <?= e(datahora_br($acesso['senha_alterada_em'])) ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Último acesso</span>
                <span class="definicao__valor"><?= e(datahora_br($acesso['ultimo_login'] ?? null)) ?></span>
            </div>
        </div>

        <?php if ($ehAdmin && !$proprio): ?>
            <form method="post" action="<?= e(url('definir-senha-usuario.php')) ?>" class="form-senha-provisoria" novalidate>
                <?= Csrf::campo() ?>
                <input type="hidden" name="id" value="<?= e((string)$id) ?>">
                <h3 class="subtitulo-secao"><?= $acesso === null ? 'Definir senha provisória' : 'Redefinir senha provisória' ?></h3>
                <p class="texto-secundario mt-0">
                    Informe a senha ao usuário por um canal seguro. Ele terá de criar uma senha pessoal no primeiro acesso.
                    Redefinir também desbloqueia o acesso.
                </p>
                <?php if ($errosSenha !== []): ?>
                    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e(implode(' ', $errosSenha)) ?></span></div>
                <?php endif; ?>
                <div class="form-apoio form-apoio--senha">
                    <div class="campo">
                        <label class="campo__rotulo" for="senha">Senha provisória</label>
                        <input class="input" type="password" id="senha" name="senha" required autocomplete="new-password"
                               minlength="<?= SenhaValidator::MIN_CARACTERES ?>">
                    </div>
                    <div class="campo">
                        <label class="campo__rotulo" for="senha_confirmacao">Confirmação</label>
                        <input class="input" type="password" id="senha_confirmacao" name="senha_confirmacao" required
                               autocomplete="new-password" minlength="<?= SenhaValidator::MIN_CARACTERES ?>">
                    </div>
                    <div class="form-apoio__acao">
                        <button type="submit" class="btn btn--primario">Salvar senha</button>
                    </div>
                </div>
                <span class="campo__ajuda">Mínimo de <?= SenhaValidator::MIN_CARACTERES ?> caracteres, com letras e números.</span>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Processos designados</h2>
            <p class="card__subtitulo"><?= count($designacoes) ?> processo(s) sob acompanhamento<?= $proprio ? ' seu' : ' deste usuário' ?></p>
        </div>
    </div>
    <?php if ($designacoes === []): ?>
        <div class="card__corpo"><p class="texto-secundario mb-0">Nenhum processo designado.</p></div>
    <?php else: ?>
        <?php require APP_ROOT . '/Views/partials/lista-designados.php'; ?>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Histórico de alterações</h2>
            <p class="card__subtitulo">Trilha de auditoria: cadastro, edições, ativações e inativações</p>
        </div>
    </div>
    <?php if ($historico === []): ?>
        <div class="card__corpo"><p class="texto-secundario mb-0">Nenhum evento registrado.</p></div>
    <?php else: ?>
        <div class="tabela-wrap">
            <table class="tabela tabela--compacta">
                <thead><tr><th>Data/hora</th><th>Evento</th><th>Alterações</th><th>Responsável</th></tr></thead>
                <tbody>
                <?php foreach ($historico as $h): ?>
                    <?php
                    $antes  = json_decode((string)($h['dados_anteriores'] ?? ''), true) ?: [];
                    $depois = json_decode((string)($h['dados_novos'] ?? ''), true) ?: [];
                    ?>
                    <tr>
                        <td class="nowrap"><?= e(datahora_br($h['created_at'])) ?></td>
                        <td class="nowrap"><span class="badge"><?= e(AuditoriaService::rotulo($h['acao'])) ?></span></td>
                        <td>
                            <?php if ($h['acao'] === AuditoriaService::USUARIO_EDITADO): ?>
                                <ul class="lista-alteracoes">
                                    <?php foreach ($depois as $campo => $novo): ?>
                                        <li>
                                            <strong><?= e($rotulosCampo[$campo] ?? $campo) ?>:</strong>
                                            <span class="texto-secundario"><?= e($valorAuditoria($campo, $antes[$campo] ?? null)) ?></span>
                                            → <?= e($valorAuditoria($campo, $novo)) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <span class="texto-secundario">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $h['autor_nome'] !== null ? e($h['autor_nome']) : '<span class="texto-secundario">Não identificado</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
