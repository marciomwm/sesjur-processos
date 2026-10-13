<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\Prazo;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\VinculoRepository;

$repo    = new ProcessoAdministrativoRepository();
$repoVin = new VinculoRepository();

[$designadoUrl, $designado] = filtro_designado();
$filtros = [
    'busca'        => entrada('busca', $_GET),
    'procedimento' => entrada('procedimento', $_GET),
    'de'           => entrada('de', $_GET),
    'ate'          => entrada('ate', $_GET),
    'atividade'    => entrada('atividade', $_GET),
    'prazo'        => entrada('prazo', $_GET),
    'designado'    => $designadoUrl,
    'ordenar'      => entrada('ordenar', $_GET) ?: 'created_at',
    'direcao'      => entrada('direcao', $_GET) ?: 'DESC',
];
$procedimentosDisponiveis = $repo->procedimentosDistintos();
$pagina = entrada_int('pagina') ?? 1;
$porPagina = (int)config('app.itens_por_pagina', 20);

$erroBanco = null;
$paginacao = null;
try {
    $paginacao = $repo->listar(['designado' => $designado] + $filtros, $pagina, $porPagina);
} catch (Throwable $e) {
    $erroBanco = $e->getMessage();
}

/** Inverte a direcao ao clicar novamente na mesma coluna. */
$linkOrdenacao = static function (string $campo, string $rotulo) use ($filtros): string {
    $query = array_filter($filtros, static fn($v) => $v !== '');
    $direcao = ($filtros['ordenar'] === $campo && strtoupper($filtros['direcao']) === 'ASC') ? 'DESC' : 'ASC';
    $query['ordenar'] = $campo;
    $query['direcao'] = $direcao;
    $seta = $filtros['ordenar'] === $campo ? (strtoupper($filtros['direcao']) === 'ASC' ? '▲' : '▼') : '';
    return '<a href="?' . e(http_build_query($query)) . '">' . e($rotulo) . ' <span>' . $seta . '</span></a>';
};

$titulo      = 'Processos Administrativos';
$menuAtivo   = 'pa-lista';
$breadcrumbs = ['Processos' => null, 'Processos Administrativos' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if ($erroBanco !== null): ?>
    <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erroBanco) ?></span></div>
<?php else: ?>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Consulta</h2>
            <p class="card__subtitulo">Pesquise por protocolo, objeto ou período de abertura</p>
        </div>
        <a class="btn btn--primario btn--sm" href="<?= e(url('novo-processo-administrativo.php')) ?>">＋ Novo Processo Administrativo</a>
    </div>
    <div class="card__corpo">
        <form method="get" class="filtros">
            <div class="campo">
                <label class="campo__rotulo" for="busca">Protocolo ou objeto</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>"
                       placeholder="SEPLAG-PRO-2026/16696 ou termo do objeto">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="procedimento">Procedimento</label>
                <select class="select" id="procedimento" name="procedimento">
                    <option value="">— Todos —</option>
                    <?php foreach ($procedimentosDisponiveis as $p): ?>
                        <option value="<?= e($p) ?>" <?= $filtros['procedimento'] === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="de">Abertura de</label>
                <input class="input" type="date" id="de" name="de" value="<?= e($filtros['de']) ?>">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="ate">Abertura até</label>
                <input class="input" type="date" id="ate" name="ate" value="<?= e($filtros['ate']) ?>">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="atividade">Atividade</label>
                <select class="select" id="atividade" name="atividade">
                    <option value="">Todos</option>
                    <option value="ativos" <?= $filtros['atividade'] === 'ativos' ? 'selected' : '' ?>>Ativos (com ação em aberto)</option>
                    <option value="inativos" <?= $filtros['atividade'] === 'inativos' ? 'selected' : '' ?>>Inativos</option>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="prazo">Prazo</label>
                <select class="select" id="prazo" name="prazo">
                    <option value="">Qualquer</option>
                    <option value="vencidos" <?= $filtros['prazo'] === 'vencidos' ? 'selected' : '' ?>>Vencidos</option>
                    <option value="semana" <?= $filtros['prazo'] === 'semana' ? 'selected' : '' ?>>Vencem em 7 dias</option>
                </select>
            </div>
            <?php require APP_ROOT . '/Views/partials/filtro-designado.php'; ?>
            <div class="filtros__acoes">
                <button type="submit" class="btn btn--primario">Filtrar</button>
                <a class="btn btn--ghost" href="<?= e(url('processos-administrativos.php')) ?>">Limpar</a>
            </div>
        </form>
    </div>

    <div class="tabela-wrap">
        <table class="tabela">
            <thead>
                <tr>
                    <th><?= $linkOrdenacao('protocolo', 'Protocolo') ?></th>
                    <th><?= $linkOrdenacao('data_abertura', 'Data de Abertura') ?></th>
                    <th><?= $linkOrdenacao('prazo_vigente', 'Prazo') ?></th>
                    <th>Atividade</th>
                    <th>Procedimento</th>
                    <th>Objeto</th>
                    <th>Processos Judiciais</th>
                    <th>Designados</th>
                    <th class="texto-direita">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($paginacao->vazio()): ?>
                <tr><td colspan="9" class="tabela__vazia">
                    Nenhum processo administrativo encontrado com os filtros informados.
                </td></tr>
            <?php else: foreach ($paginacao->itens as $p): ?>
                <?php $vinculados = $repoVin->judiciaisDoAdministrativo((int)$p['id']); ?>
                <tr>
                    <td class="nowrap">
                        <a class="mono" href="<?= e(url('processo-administrativo.php?id=' . $p['id'])) ?>"><?= e($p['protocolo']) ?></a>
                        <?php if ($p['origem'] === 'SIGADOC'): ?>
                            <span class="badge badge--verde" title="Dados obtidos do SIGADOC">SIGADOC</span>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap"><?= e(data_br($p['data_abertura'])) ?></td>
                    <?php $sp = Prazo::situacao($p['prazo_vigente'], $p['prazo_vigente_hora']); ?>
                    <td class="nowrap">
                        <?php if ($p['prazo_vigente'] !== null): ?>
                            <span class="badge <?= e(Prazo::classeBadge($sp)) ?>" title="<?= e(Prazo::ROTULOS_SITUACAO[$sp] ?? '') ?>"><?= e(Prazo::formatar($p['prazo_vigente'], $p['prazo_vigente_hora'])) ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="nowrap">
                        <?php if ((int)$p['ativo'] === 1): ?>
                            <a class="badge badge--verde" href="<?= e(url('acoes.php?status=abertas&processo_id=' . $p['id'])) ?>" title="Ver as ações em aberto">● Ativo · <?= (int)$p['acoes_abertas'] ?></a>
                        <?php else: ?>
                            <span class="badge badge--neutro">○ Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td><?= ou_traco($p['procedimento'] ?? null) ?></td>
                    <td><span class="tabela__truncado" title="<?= e($p['objeto']) ?>"><?= ou_traco($p['objeto']) ?></span></td>
                    <td>
                        <?php if ($vinculados === []): ?>
                            <span class="texto-secundario">—</span>
                        <?php else: foreach ($vinculados as $pj): ?>
                            <a class="mono" style="display:block" href="<?= e(url('processo-judicial.php?id=' . $pj['id'])) ?>"><?= e($pj['numero_cnj']) ?></a>
                        <?php endforeach; endif; ?>
                    </td>
                    <td><?= ou_traco($p['designados'] ?? null) ?></td>
                    <td class="texto-direita nowrap">
                        <div class="acoes-linha" style="justify-content:flex-end">
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('processo-administrativo.php?id=' . $p['id'])) ?>">Visualizar</a>
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-processo-administrativo.php?id=' . $p['id'])) ?>">Editar</a>
                            <form method="post" action="<?= e(url('excluir-processo-administrativo.php')) ?>"
                                  data-confirmar="Excluir o processo <?= e($p['protocolo']) ?>? Os processos judiciais vinculados NÃO serão excluídos."
                                  style="display:inline">
                                <?= Csrf::campo() ?>
                                <input type="hidden" name="id" value="<?= e((string)$p['id']) ?>">
                                <input type="hidden" name="retorno" value="processos-administrativos.php">
                                <button type="submit" class="btn btn--ghost btn--sm" style="color:var(--vermelho)">Excluir</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php $query = $filtros; require APP_ROOT . '/Views/partials/paginacao.php'; ?>
</section>

<?php endif; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
