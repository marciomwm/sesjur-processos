<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\AcaoRepository;
use App\Repositories\DesignacaoRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Services\ConfiguracaoService;

$conexao = Database::testar();

$erroBanco = null;
$totalAdministrativos = $totalJudiciais = $totalDesignados = $acoesAbertas = $acoesVencidas = 0;
$recentesAdm = $recentesJud = $designacoes = [];

if ($conexao['ok']) {
    try {
        $repoAdm = new ProcessoAdministrativoRepository();
        $repoJud = new ProcessoJudicialRepository();

        $totalAdministrativos = $repoAdm->contar();
        $totalJudiciais       = $repoJud->contar();
        $recentesAdm          = $repoAdm->recentes(5);
        $recentesJud          = $repoJud->recentes(5);

        $repoDesig      = new DesignacaoRepository();
        $totalDesignados = $repoDesig->contarDoUsuario((int)usuario_logado_id());
        $designacoes     = $repoDesig->doUsuario((int)usuario_logado_id(), 10);

        $repoAcoes     = new AcaoRepository();
        $acoesAbertas  = $repoAcoes->contarAbertas();
        $acoesVencidas = $repoAcoes->contarVencidas();
    } catch (Throwable $e) {
        $erroBanco = $e->getMessage();
    }
} else {
    $erroBanco = $conexao['erro'];
}

$diagnostico = ConfiguracaoService::diagnostico();

$titulo    = 'Painel Principal';
$menuAtivo = 'dashboard';
$breadcrumbs = ['Visão Geral' => null, 'Painel Principal' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if ($erroBanco !== null): ?>
    <div class="alert alert--erro">
        <span class="alert__icone">✕</span>
        <div>
            <strong>Banco de dados indisponível.</strong><br>
            <?= e($erroBanco) ?><br>
            Importe <code>database/database.sql</code> e confira as credenciais em <code>app/config/config.php</code>.
        </div>
    </div>
<?php endif; ?>

<?php if (!$diagnostico['sigadoc_pronto'] || !$diagnostico['pje_pronto']): ?>
    <div class="alert alert--aviso">
        <span class="alert__icone">⚠</span>
        <div>
            <strong>Integração pendente de configuração.</strong>
            Os domínios institucionais autorizados
            <?= !$diagnostico['sigadoc_pronto'] ? 'do SIGADOC' : '' ?>
            <?= (!$diagnostico['sigadoc_pronto'] && !$diagnostico['pje_pronto']) ? ' e ' : '' ?>
            <?= !$diagnostico['pje_pronto'] ? 'do PJe' : '' ?>
            ainda não foram cadastrados. O cadastro manual funciona normalmente.
            <?php if (Auth::ehAdministrador()): ?>
                <a href="<?= e(url('configuracoes.php')) ?>">Configurar agora</a>.
            <?php else: ?>
                Peça a um administrador para configurá-los.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<section class="indicadores">
    <article class="indicador">
        <span class="indicador__rotulo">Processos Administrativos</span>
        <span class="indicador__valor"><?= e((string)$totalAdministrativos) ?></span>
        <span class="indicador__nota">Origem: SIGADOC</span>
    </article>
    <article class="indicador indicador--judicial">
        <span class="indicador__rotulo">Processos Judiciais</span>
        <span class="indicador__valor"><?= e((string)$totalJudiciais) ?></span>
        <span class="indicador__nota">Origem: PJe</span>
    </article>
    <article class="indicador indicador--designados">
        <span class="indicador__rotulo">Designados a mim</span>
        <span class="indicador__valor"><?= e((string)$totalDesignados) ?></span>
        <span class="indicador__nota">Processos sob seu acompanhamento</span>
    </article>
    <a class="indicador indicador--acoes" href="<?= e(url('acoes.php?status=abertas')) ?>">
        <span class="indicador__rotulo">Tarefas em aberto</span>
        <span class="indicador__valor"><?= e((string)$acoesAbertas) ?></span>
        <span class="indicador__nota">Gerenciador de tarefas</span>
    </a>
    <a class="indicador <?= $acoesVencidas > 0 ? 'indicador--alerta' : '' ?>" href="<?= e(url('acoes.php?prazo=vencidas')) ?>">
        <span class="indicador__rotulo">Tarefas vencidas</span>
        <span class="indicador__valor"><?= e((string)$acoesVencidas) ?></span>
        <span class="indicador__nota">Em aberto com o prazo expirado</span>
    </a>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Meus processos designados</h2>
            <p class="card__subtitulo">
                <?= $totalDesignados > count($designacoes)
                    ? 'As ' . count($designacoes) . ' designações mais recentes de ' . $totalDesignados
                    : 'Processos que administradores ou gestores designaram a você' ?>
            </p>
        </div>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('processos-administrativos.php?designado=eu')) ?>">Ver todos</a>
    </div>
    <?php if ($designacoes === []): ?>
        <div class="card__corpo"><p class="texto-secundario mb-0">Nenhum processo designado a você no momento.</p></div>
    <?php else: ?>
        <?php require APP_ROOT . '/Views/partials/lista-designados.php'; ?>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card__cabecalho">
        <h2 class="card__titulo">Atalhos</h2>
    </div>
    <div class="card__corpo flex gap-8" style="flex-wrap:wrap">
        <a class="btn btn--primario" href="<?= e(url('novo-processo-administrativo.php')) ?>">＋ Novo Processo Administrativo</a>
        <a class="btn btn--primario" href="<?= e(url('novo-processo-judicial.php')) ?>">＋ Novo Processo Judicial</a>
        <a class="btn btn--secundario" href="<?= e(url('processos-administrativos.php')) ?>">Consultar Administrativos</a>
        <a class="btn btn--secundario" href="<?= e(url('processos-judiciais.php')) ?>">Consultar Judiciais</a>
    </div>
</section>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:var(--densidade-secao)">
    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Processos Administrativos recentes</h2>
                <p class="card__subtitulo">Últimos 5 cadastros</p>
            </div>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('processos-administrativos.php')) ?>">Ver todos</a>
        </div>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Protocolo</th><th>Abertura</th><th>Cadastrado em</th></tr></thead>
                <tbody>
                <?php if ($recentesAdm === []): ?>
                    <tr><td colspan="3" class="tabela__vazia">Nenhum processo administrativo cadastrado.</td></tr>
                <?php else: foreach ($recentesAdm as $p): ?>
                    <tr>
                        <td><a class="mono" href="<?= e(url('processo-administrativo.php?id=' . $p['id'])) ?>"><?= e($p['protocolo']) ?></a></td>
                        <td class="nowrap"><?= e(data_br($p['data_abertura'])) ?></td>
                        <td class="nowrap texto-secundario"><?= e(datahora_br($p['created_at'])) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Processos Judiciais recentes</h2>
                <p class="card__subtitulo">Últimos 5 cadastros</p>
            </div>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('processos-judiciais.php')) ?>">Ver todos</a>
        </div>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Processo</th><th>Tribunal</th><th>Cadastrado em</th></tr></thead>
                <tbody>
                <?php if ($recentesJud === []): ?>
                    <tr><td colspan="3" class="tabela__vazia">Nenhum processo judicial cadastrado.</td></tr>
                <?php else: foreach ($recentesJud as $p): ?>
                    <tr>
                        <td><a class="mono" href="<?= e(url('processo-judicial.php?id=' . $p['id'])) ?>"><?= e($p['numero_cnj']) ?></a></td>
                        <td><?= ou_traco($p['tribunal']) ?></td>
                        <td class="nowrap texto-secundario"><?= e(datahora_br($p['created_at'])) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
