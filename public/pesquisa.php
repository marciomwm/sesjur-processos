<?php
declare(strict_types=1);

/**
 * Resultados da pesquisa geral (campo da barra superior).
 * Um protocolo ou número CNJ exato abre direto a ficha do processo.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Services\PesquisaService;

$termoPesquisa = mb_substr(entrada('q', $_GET), 0, 150);
$servico = new PesquisaService();

$grupos = [];
$erro = null;
if (mb_strlen($termoPesquisa) >= PesquisaService::TERMO_MINIMO) {
    try {
        $exato = entrada('todos', $_GET) === '1' ? null : $servico->identificadorExato($termoPesquisa);
        if ($exato !== null) {
            redirecionar(($exato['tipo'] === 'judicial' ? 'processo-judicial.php' : 'processo-administrativo.php') . '?id=' . $exato['id']);
        }
        $grupos = $servico->pesquisar($termoPesquisa, 20, Auth::ehAdministrador());
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'pesquisa');
        $erro = "Não foi possível concluir a pesquisa (código {$codigo}).";
    }
}
$total = array_sum(array_column($grupos, 'total'));

$titulo      = 'Pesquisa';
$menuAtivo   = 'pesquisa';
$breadcrumbs = ['Pesquisa' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if ($erro !== null): ?>
    <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erro) ?></span></div>
<?php endif; ?>

<section class="card">
    <div class="card__corpo">
        <form method="get" class="pesquisa-pagina" role="search">
            <label class="sr-only" for="q">Termo pesquisado</label>
            <input class="input" type="search" id="q" name="q" value="<?= e($termoPesquisa) ?>" autofocus
                   placeholder="Protocolo, número CNJ, objeto, parte, ação…">
            <button type="submit" class="btn btn--primario">Pesquisar</button>
        </form>
        <p class="texto-secundario mb-0 mt-16">
            <?php if (mb_strlen($termoPesquisa) < PesquisaService::TERMO_MINIMO): ?>
                Digite ao menos <?= PesquisaService::TERMO_MINIMO ?> caracteres. Um protocolo ou número CNJ completo abre direto a ficha do processo.
            <?php elseif ($total === 0): ?>
                Nenhum resultado para <strong>“<?= e($termoPesquisa) ?>”</strong>.
            <?php else: ?>
                <?= $total ?> resultado(s) para <strong>“<?= e($termoPesquisa) ?>”</strong>
                em <?= count($grupos) ?> <?= count($grupos) === 1 ? 'categoria' : 'categorias' ?>.
            <?php endif; ?>
        </p>
    </div>
</section>

<?php foreach ($grupos as $chave => $grupo): ?>
    <section class="card" id="resultados-<?= e($chave) ?>">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo"><?= e($grupo['titulo']) ?></h2>
                <p class="card__subtitulo">
                    <?= $grupo['total'] ?> encontrado(s)<?= $grupo['total'] > count($grupo['itens']) ? ' · exibindo os ' . count($grupo['itens']) . ' primeiros' : '' ?>
                </p>
            </div>
            <?php if ($grupo['verTodos'] !== null): ?>
                <a class="btn btn--ghost btn--sm" href="<?= e($grupo['verTodos']) ?>">Ver todos</a>
            <?php endif; ?>
        </div>
        <ul class="resultados">
            <?php foreach ($grupo['itens'] as $item): ?>
                <li>
                    <a class="resultados__item" href="<?= e($item['url']) ?>">
                        <span class="resultados__rotulo"><?= e($item['rotulo']) ?></span>
                        <?php if ($item['marca'] !== null): ?><span class="badge badge--neutro"><?= e($item['marca']) ?></span><?php endif; ?>
                        <span class="resultados__detalhe"><?= e($item['detalhe']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
