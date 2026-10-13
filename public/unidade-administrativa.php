<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Repositories\AuditoriaRepository;
use App\Repositories\InstituicaoRepository;
use App\Repositories\LotacaoRepository;
use App\Repositories\NivelOrganizacionalRepository;
use App\Services\AuditoriaService;
use App\Services\UnidadeAdministrativaService;

$id = entrada_int('id');
$repo = new LotacaoRepository();
$unidade = $id !== null ? $repo->buscarDetalhado($id) : null;
if ($unidade === null) {
    flash('erro', 'Unidade administrativa não encontrada.');
    redirecionar('unidades-administrativas.php');
}

$estrutura = $repo->estrutura();
$caminho = UnidadeAdministrativaService::caminho($estrutura, $id);
// Ids gravados na auditoria exibidos pelo nome.
$nomesPorCampo = [
    'instituicao_id'      => array_column((new InstituicaoRepository())->todos(), 'nome', 'id'),
    'nivel_id'            => array_column((new NivelOrganizacionalRepository())->todos(), 'nome', 'id'),
    'unidade_superior_id' => array_column($estrutura, 'nome', 'id'),
];
$valorHistorico = static function (string $campo, mixed $valor) use ($nomesPorCampo): string {
    if ($valor === null || $valor === '') {
        return '—';
    }
    return (string)($nomesPorCampo[$campo][(int)$valor] ?? $valor);
};
array_pop($caminho); // a propria unidade
$subordinadas = $repo->subordinadas($id);
$servidores = $repo->servidores($id);
$usuariosAtivos = $repo->usuariosAtivos($id);
$historico = (new AuditoriaRepository())->listarPorEntidade('lotacoes', $id);
$ativa = (int)$unidade['ativo'] === 1;
$rotulosCampo = [
    'instituicao_id' => 'Instituição', 'nivel_id' => 'Nível', 'unidade_superior_id' => 'Unidade superior',
    'nome' => 'Nome', 'sigla' => 'Sigla', 'gestor_nome' => 'Gestor', 'gestor_matricula' => 'Matrícula do gestor',
    'competencias' => 'Competências', 'lotacionograma' => 'Lotacionograma', 'ativo' => 'Status',
];

$titulo      = 'Unidade Administrativa';
$menuAtivo   = 'unidades';
$breadcrumbs = ['Sistema' => null, 'Unidades Administrativas' => 'unidades-administrativas.php', $unidade['nome'] => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<div class="ficha__cabecalho">
    <div>
        <h2 class="ficha__numero"><?= e($unidade['nome']) ?></h2>
        <div class="ficha__meta">
            <?php if ($unidade['instituicao_nome'] !== null): ?>
                <span class="badge badge--azul" title="<?= e($unidade['instituicao_nome']) ?>"><?= e($unidade['instituicao_sigla'] ?? $unidade['instituicao_nome']) ?></span>
            <?php else: ?>
                <span class="badge badge--ambar">Sem instituição</span>
            <?php endif; ?>
            <?php if (!empty($unidade['sigla'])): ?><span class="badge mono"><?= e($unidade['sigla']) ?></span><?php endif; ?>
            <?php if ($unidade['nivel_nome'] !== null): ?><span class="badge"><?= e($unidade['nivel_nome']) ?></span><?php endif; ?>
            <span class="badge <?= $ativa ? 'badge--verde' : 'badge--neutro' ?>"><?= $ativa ? 'Ativa' : 'Inativa' ?></span>
        </div>
        <?php if ($caminho !== []): ?>
            <nav class="caminho-unidade" aria-label="Hierarquia">
                <?php foreach ($caminho as $c): ?>
                    <a href="<?= e(url('unidade-administrativa.php?id=' . $c['id'])) ?>"><?= e($c['nome']) ?></a> ›
                <?php endforeach; ?>
                <span><?= e($unidade['nome']) ?></span>
            </nav>
        <?php endif; ?>
    </div>
    <div class="acoes-linha">
        <a class="btn btn--secundario btn--sm" href="<?= e(url('nova-unidade-administrativa.php?superior=' . $id)) ?>">＋ Subordinada</a>
        <a class="btn btn--secundario btn--sm" href="<?= e(url('editar-unidade-administrativa.php?id=' . $id)) ?>">✎ Editar</a>
        <form method="post" action="<?= e(url('alterar-status-unidade-administrativa.php')) ?>" style="display:inline"
              data-confirmar="<?= e($ativa ? 'Inativar a unidade “' . $unidade['nome'] . '”? Usuários e solicitações que já a usam continuam vinculados.' : 'Reativar a unidade “' . $unidade['nome'] . '”?') ?>">
            <?= Csrf::campo() ?>
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="ativo" value="<?= $ativa ? '0' : '1' ?>">
            <input type="hidden" name="retorno" value="unidade-administrativa.php?id=<?= (int)$id ?>">
            <button type="submit" class="btn btn--sm <?= $ativa ? 'btn--perigo' : 'btn--primario' ?>"><?= $ativa ? 'Inativar' : 'Reativar' ?></button>
        </form>
    </div>
</div>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Informações</h2></div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao">
                <span class="definicao__rotulo">Instituição</span>
                <span class="definicao__valor"><?= ou_traco($unidade['instituicao_nome']) ?></span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Nível</span>
                <span class="definicao__valor"><?= ou_traco($unidade['nivel_nome']) ?></span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Unidade superior</span>
                <span class="definicao__valor">
                    <?php if ($unidade['unidade_superior_id'] !== null): ?>
                        <a href="<?= e(url('unidade-administrativa.php?id=' . $unidade['unidade_superior_id'])) ?>"><?= e($unidade['superior_nome']) ?></a>
                    <?php else: ?>— <span class="texto-secundario">(topo da estrutura)</span><?php endif; ?>
                </span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Gestor da unidade</span>
                <span class="definicao__valor">
                    <?= ou_traco($unidade['gestor_nome']) ?>
                    <?php if (!empty($unidade['gestor_matricula'])): ?>
                        <br><span class="texto-secundario mono"><?= e($unidade['gestor_matricula']) ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Usuários do sistema</span>
                <span class="definicao__valor">
                    <a href="<?= e(url('usuarios.php?' . http_build_query(['lotacao_id' => $id, 'status' => 'todos']))) ?>"><?= $usuariosAtivos ?> ativo(s) com esta lotação</a>
                </span>
            </div>
        </div>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Lotacionograma</h2>
            <p class="card__subtitulo"><?= count($servidores) ?> servidor(es) lotado(s)</p>
        </div>
    </div>
    <?php if ($servidores === []): ?>
        <div class="card__corpo"><p class="texto-secundario mb-0">Nenhum servidor informado. Edite a unidade para preencher o lotacionograma.</p></div>
    <?php else: ?>
        <div class="tabela-wrap">
            <table class="tabela tabela--compacta">
                <thead><tr><th>#</th><th>Nome</th><th>Matrícula</th><th>Cargo ou função</th></tr></thead>
                <tbody>
                <?php foreach ($servidores as $i => $s): ?>
                    <tr>
                        <td class="texto-secundario"><?= $i + 1 ?></td>
                        <td><?= e($s['nome']) ?></td>
                        <td class="mono"><?= ou_traco($s['matricula']) ?></td>
                        <td><?= ou_traco($s['cargo']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Competências</h2></div>
    <div class="card__corpo">
        <?php if (empty($unidade['competencias'])): ?>
            <p class="texto-secundario mb-0">Competências não informadas.</p>
        <?php else: ?>
            <div class="texto-longo"><?= nl2br(e($unidade['competencias'])) ?></div>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Unidades subordinadas</h2>
            <p class="card__subtitulo">Imediatamente abaixo desta na estrutura</p>
        </div>
        <a class="btn btn--primario btn--sm" href="<?= e(url('nova-unidade-administrativa.php?superior=' . $id)) ?>">＋ Nova subordinada</a>
    </div>
    <?php if ($subordinadas === []): ?>
        <div class="card__corpo"><p class="texto-secundario mb-0">Nenhuma unidade subordinada.</p></div>
    <?php else: ?>
        <div class="tabela-wrap">
            <table class="tabela tabela--compacta">
                <thead><tr><th>Unidade</th><th>Nível</th><th>Gestor</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($subordinadas as $s): ?>
                    <tr class="<?= (int)$s['ativo'] === 1 ? '' : 'is-inativo' ?>">
                        <td>
                            <a href="<?= e(url('unidade-administrativa.php?id=' . $s['id'])) ?>"><strong><?= e($s['nome']) ?></strong></a>
                            <?php if (!empty($s['sigla'])): ?><span class="texto-secundario mono"><?= e($s['sigla']) ?></span><?php endif; ?>
                        </td>
                        <td><?= ou_traco($s['nivel_nome']) ?></td>
                        <td><?= ou_traco($s['gestor_nome']) ?></td>
                        <td><span class="badge <?= (int)$s['ativo'] === 1 ? 'badge--verde' : 'badge--neutro' ?>"><?= (int)$s['ativo'] === 1 ? 'Ativa' : 'Inativa' ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Histórico de alterações</h2></div>
    <?php if ($historico === []): ?>
        <div class="card__corpo">
            <p class="texto-secundario mb-0">Nenhum evento registrado. Unidades criadas pela instalação do banco não têm evento de cadastro.</p>
        </div>
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
                            <?php if (str_contains($h['acao'], '_EDITAD')): ?>
                                <ul class="lista-alteracoes">
                                    <?php foreach ($depois as $campo => $novo): ?>
                                        <li><strong><?= e($rotulosCampo[$campo] ?? $campo) ?>:</strong>
                                            <?php if (in_array($campo, ['competencias', 'lotacionograma'], true)): ?>
                                                <span class="texto-secundario">alterado</span>
                                            <?php else: ?>
                                                <span class="texto-secundario"><?= e($valorHistorico($campo, $antes[$campo] ?? null)) ?></span> → <?= e($valorHistorico($campo, $novo)) ?>
                                            <?php endif; ?>
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
