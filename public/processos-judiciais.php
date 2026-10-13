<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Repositories\ProcessoJudicialRepository;
use App\Repositories\VinculoRepository;

$repo    = new ProcessoJudicialRepository();
$repoVin = new VinculoRepository();

// Visão em cards (padrão) ou tabela; a escolha fica na sessão para as
// próximas visitas à listagem.
$visao = entrada('visao', $_GET);
if (in_array($visao, ['cards', 'tabela'], true)) {
    $_SESSION['pj_visao'] = $visao;
} else {
    $visao = $_SESSION['pj_visao'] ?? 'cards';
}

$filtros = [
    'busca'    => entrada('busca', $_GET),
    'tribunal' => entrada('tribunal', $_GET),
    'situacao' => entrada('situacao', $_GET),
    'de'       => entrada('de', $_GET),
    'ate'      => entrada('ate', $_GET),
    'ordenar'  => entrada('ordenar', $_GET) ?: 'created_at',
    'direcao'  => entrada('direcao', $_GET) ?: 'DESC',
];
$pagina    = entrada_int('pagina') ?? 1;
$porPagina = (int)config('app.itens_por_pagina', 20);

$erroBanco = null;
$paginacao = null;
$tribunais = $situacoes = [];

try {
    $paginacao = $repo->listar($filtros, $pagina, $porPagina);
    $tribunais = $repo->valoresDistintos('tribunal');
    $situacoes = $repo->valoresDistintos('situacao');
} catch (Throwable $e) {
    $erroBanco = $e->getMessage();
}

$linkOrdenacao = static function (string $campo, string $rotulo) use ($filtros): string {
    $query = array_filter($filtros, static fn($v) => $v !== '');
    $direcao = ($filtros['ordenar'] === $campo && strtoupper($filtros['direcao']) === 'ASC') ? 'DESC' : 'ASC';
    $query['ordenar'] = $campo;
    $query['direcao'] = $direcao;
    $seta = $filtros['ordenar'] === $campo ? (strtoupper($filtros['direcao']) === 'ASC' ? '▲' : '▼') : '';
    return '<a href="?' . e(http_build_query($query)) . '">' . e($rotulo) . ' <span>' . $seta . '</span></a>';
};

/** Link da outra visão, preservando filtros e página. */
$linkVisao = static function (string $alvo) use ($filtros, $pagina): string {
    $query = array_filter($filtros, static fn($v) => $v !== '') + ['visao' => $alvo];
    if ($pagina > 1) {
        $query['pagina'] = $pagina;
    }
    return '?' . http_build_query($query);
};

$ordenacoes = [
    'created_at'        => 'Data do cadastro',
    'numero_cnj'        => 'Número CNJ',
    'tribunal'          => 'Tribunal',
    'data_distribuicao' => 'Última distribuição',
    'data_autuacao'     => 'Autuação',
];

$titulo      = 'Processos Judiciais';
$menuAtivo   = 'pj-lista';
$breadcrumbs = ['Processos' => null, 'Processos Judiciais' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if ($erroBanco !== null): ?>
    <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erroBanco) ?></span></div>
<?php else: ?>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Consulta</h2>
            <p class="card__subtitulo">Pesquise por número CNJ, classe, assunto ou filtre por tribunal e situação</p>
        </div>
        <a class="btn btn--primario btn--sm" href="<?= e(url('novo-processo-judicial.php')) ?>">＋ Novo Processo Judicial</a>
    </div>
    <div class="card__corpo">
        <form method="get" class="filtros">
            <input type="hidden" name="visao" value="<?= e($visao) ?>">
            <div class="campo">
                <label class="campo__rotulo" for="busca">Número, classe ou assunto</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>"
                       placeholder="1008695-17.2026.8.11.0006">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="tribunal">Tribunal</label>
                <select class="select" id="tribunal" name="tribunal">
                    <option value="">Todos</option>
                    <?php foreach ($tribunais as $t): ?>
                        <option value="<?= e($t) ?>" <?= $filtros['tribunal'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="situacao">Situação</label>
                <select class="select" id="situacao" name="situacao">
                    <option value="">Todas</option>
                    <?php foreach ($situacoes as $s): ?>
                        <option value="<?= e($s) ?>" <?= $filtros['situacao'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="de">Distribuição de</label>
                <input class="input" type="date" id="de" name="de" value="<?= e($filtros['de']) ?>">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="ate">Distribuição até</label>
                <input class="input" type="date" id="ate" name="ate" value="<?= e($filtros['ate']) ?>">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="ordenar">Ordenar por</label>
                <select class="select" id="ordenar" name="ordenar">
                    <?php foreach ($ordenacoes as $valor => $rotulo): ?>
                        <option value="<?= e($valor) ?>" <?= $filtros['ordenar'] === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="direcao" value="<?= e(strtoupper($filtros['direcao']) === 'ASC' ? 'ASC' : 'DESC') ?>">
            </div>
            <div class="filtros__acoes">
                <button type="submit" class="btn btn--primario">Filtrar</button>
                <a class="btn btn--ghost" href="<?= e(url('processos-judiciais.php')) ?>">Limpar</a>
            </div>
        </form>
    </div>

    <div class="barra-visao">
        <span class="texto-secundario"><?= e((string)$paginacao->total) ?> processo(s)</span>
        <div class="alternador" role="group" aria-label="Forma de visualização">
            <a class="alternador__item <?= $visao === 'cards' ? 'is-ativo' : '' ?>" href="<?= e($linkVisao('cards')) ?>"
               <?= $visao === 'cards' ? 'aria-current="true"' : '' ?>>▦ Cards</a>
            <a class="alternador__item <?= $visao === 'tabela' ? 'is-ativo' : '' ?>" href="<?= e($linkVisao('tabela')) ?>"
               <?= $visao === 'tabela' ? 'aria-current="true"' : '' ?>>☰ Tabela</a>
        </div>
    </div>

    <?php if ($visao === 'cards'): ?>
        <div class="cards-processos">
            <?php if ($paginacao->vazio()): ?>
                <p class="tabela__vazia">Nenhum processo judicial encontrado com os filtros informados.</p>
            <?php else: foreach ($paginacao->itens as $p): ?>
                <?php $vinculados = $repoVin->administrativosDoJudicial((int)$p['id']); ?>
                <article class="card-processo">
                    <header class="card-processo__topo">
                        <a class="card-processo__numero mono" href="<?= e(url('processo-judicial.php?id=' . $p['id'])) ?>"><?= e($p['numero_cnj']) ?></a>
                        <?php if ($p['classe_abreviada']): ?><span class="ficha__sigla"><?= e($p['classe_abreviada']) ?></span><?php endif; ?>
                        <?php if ($p['origem'] === 'PJE'): ?><span class="badge badge--verde">PJe</span><?php endif; ?>
                    </header>
                    <?php if ($p['resumo_partes']): ?>
                        <p class="card-processo__partes"><?= e($p['resumo_partes']) ?></p>
                    <?php endif; ?>
                    <p class="card-processo__classe">
                        <?= ou_traco($p['classe_judicial']) ?>
                        <?php if ($p['assunto_principal']): ?><span class="texto-secundario"> · <?= e($p['assunto_principal']) ?></span><?php endif; ?>
                    </p>
                    <dl class="card-processo__dados">
                        <div><dt>Órgão julgador</dt><dd><?= ou_traco($p['orgao_julgador'] ?: $p['tribunal']) ?></dd></div>
                        <div><dt>Jurisdição</dt><dd><?= ou_traco($p['jurisdicao']) ?></dd></div>
                        <div><dt>Distribuição</dt><dd><?= e(data_br($p['data_distribuicao'])) ?></dd></div>
                        <div><dt>Situação</dt><dd><?= $p['situacao'] ? '<span class="badge">' . e($p['situacao']) . '</span>' : '—' ?></dd></div>
                    </dl>
                    <div class="card-processo__vinculos">
                        <span class="card-processo__rotulo">Processos administrativos</span>
                        <?php if ($vinculados === []): ?>
                            <span class="texto-secundario">Nenhum vínculo</span>
                        <?php else: foreach ($vinculados as $pa): ?>
                            <a class="badge badge--azul mono" href="<?= e(url('processo-administrativo.php?id=' . $pa['id'])) ?>"><?= e($pa['protocolo']) ?></a>
                        <?php endforeach; endif; ?>
                    </div>
                    <footer class="card-processo__acoes">
                        <a class="btn btn--secundario btn--sm" href="<?= e(url('processo-judicial.php?id=' . $p['id'])) ?>">Visualizar</a>
                        <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-processo-judicial.php?id=' . $p['id'])) ?>">Editar</a>
                        <form method="post" action="<?= e(url('excluir-processo-judicial.php')) ?>"
                              data-confirmar="Excluir o processo <?= e($p['numero_cnj']) ?>? Os processos administrativos vinculados NÃO serão excluídos.">
                            <?= Csrf::campo() ?>
                            <input type="hidden" name="id" value="<?= e((string)$p['id']) ?>">
                            <button type="submit" class="btn btn--ghost btn--sm" style="color:var(--vermelho)">Excluir</button>
                        </form>
                    </footer>
                </article>
            <?php endforeach; endif; ?>
        </div>
    <?php else: ?>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead>
                    <tr>
                        <th><?= $linkOrdenacao('numero_cnj', 'Processo Judicial') ?></th>
                        <th><?= $linkOrdenacao('tribunal', 'Tribunal') ?></th>
                        <th>Órgão julgador</th>
                        <th>Classe</th>
                        <th><?= $linkOrdenacao('data_distribuicao', 'Distribuição') ?></th>
                        <th>Situação</th>
                        <th>Processos Administrativos</th>
                        <th class="texto-direita">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($paginacao->vazio()): ?>
                    <tr><td colspan="8" class="tabela__vazia">Nenhum processo judicial encontrado com os filtros informados.</td></tr>
                <?php else: foreach ($paginacao->itens as $p): ?>
                    <?php $vinculados = $repoVin->administrativosDoJudicial((int)$p['id']); ?>
                    <tr>
                        <td class="nowrap">
                            <a class="mono" href="<?= e(url('processo-judicial.php?id=' . $p['id'])) ?>"><?= e($p['numero_cnj']) ?></a>
                            <?php if ($p['origem'] === 'PJE'): ?><span class="badge badge--verde">PJe</span><?php endif; ?>
                        </td>
                        <td><?= ou_traco($p['tribunal']) ?></td>
                        <td><?= ou_traco($p['orgao_julgador']) ?></td>
                        <td><span class="tabela__truncado" title="<?= e($p['classe_judicial']) ?>"><?= ou_traco($p['classe_judicial']) ?></span></td>
                        <td class="nowrap"><?= e(data_br($p['data_distribuicao'])) ?></td>
                        <td><?= $p['situacao'] ? '<span class="badge">' . e($p['situacao']) . '</span>' : '—' ?></td>
                        <td>
                            <?php if ($vinculados === []): ?>
                                <span class="texto-secundario">—</span>
                            <?php else: foreach ($vinculados as $pa): ?>
                                <a class="mono" style="display:block" href="<?= e(url('processo-administrativo.php?id=' . $pa['id'])) ?>"><?= e($pa['protocolo']) ?></a>
                            <?php endforeach; endif; ?>
                        </td>
                        <td class="texto-direita nowrap">
                            <div class="acoes-linha" style="justify-content:flex-end">
                                <a class="btn btn--ghost btn--sm" href="<?= e(url('processo-judicial.php?id=' . $p['id'])) ?>">Visualizar</a>
                                <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-processo-judicial.php?id=' . $p['id'])) ?>">Editar</a>
                                <form method="post" action="<?= e(url('excluir-processo-judicial.php')) ?>"
                                      data-confirmar="Excluir o processo <?= e($p['numero_cnj']) ?>? Os processos administrativos vinculados NÃO serão excluídos."
                                      style="display:inline">
                                    <?= Csrf::campo() ?>
                                    <input type="hidden" name="id" value="<?= e((string)$p['id']) ?>">
                                    <button type="submit" class="btn btn--ghost btn--sm" style="color:var(--vermelho)">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php $query = $filtros + ['visao' => $visao]; require APP_ROOT . '/Views/partials/paginacao.php'; ?>
</section>

<?php endif; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
