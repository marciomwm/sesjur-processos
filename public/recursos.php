<?php
declare(strict_types=1);

/**
 * Central de Recursos: sites, sistemas, documentos e painéis da equipe.
 *
 * Duas formas de organização sobre os MESMOS itens:
 *   Áreas  = pela Categoria (N:N — um item aparece em várias áreas)
 *   Pastas = pelo Tipo      (1:1 — um item aparece em uma só pasta)
 *
 * Os cartões são renderizados aqui uma única vez; assets/js/recursos.js os
 * agrupa por área ou pasta, filtra pela busca e alterna as abas sem ida ao
 * servidor. Sem JavaScript, todos os cartões aparecem numa grade única.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Repositories\CategoriaRecursoRepository;
use App\Repositories\RecursoRepository;
use App\Repositories\TipoRecursoRepository;
use App\Services\RecursoService;

$tipos = array_values(array_filter((new TipoRecursoRepository())->todos(), static fn(array $t): bool => (int)$t['ativo'] === 1));
$categorias = array_values(array_filter((new CategoriaRecursoRepository())->todos(), static fn(array $c): bool => (int)$c['ativo'] === 1));
$itens = (new RecursoRepository())->listar();
$podeGerenciar = RecursoService::podeGerenciarListas();

// Itens podem estar num tipo ou categoria inativados depois: continuam aparecendo.
$nomeTipo = array_column((new TipoRecursoRepository())->todos(), 'nome', 'id');
$nomeCategoria = array_column((new CategoriaRecursoRepository())->todos(), 'nome', 'id');
$indiceTipo = array_flip(array_map('intval', array_keys($nomeTipo)));

// Abertura direta numa pasta ou área (links das tabelas de apoio).
$pastaInicial = entrada_int('pasta');
$areaInicial = entrada_int('area');

$titulo      = 'Recursos';
$menuAtivo   = 'recursos';
$breadcrumbs = ['Recursos' => null];
$scripts     = ['modal.js', 'recursos.js'];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<section class="heroi">
    <span class="heroi__brilho" aria-hidden="true"></span>
    <div class="heroi__conteudo">
        <div class="heroi__texto">
            <span class="heroi__selo">Central de Recursos</span>
            <h2 class="heroi__titulo">Recursos</h2>
            <p class="heroi__descricao">Sites, sistemas, documentos e painéis usados pela equipe. Os mesmos itens, organizados por Área ou por Pasta.</p>
        </div>
        <ul class="heroi__numeros">
            <li><b><?= count($itens) ?></b><span>Itens</span></li>
            <li><b><?= count($categorias) ?></b><span>Áreas</span></li>
            <li><b><?= count($tipos) ?></b><span>Pastas</span></li>
        </ul>
    </div>
</section>

<div class="barra-ferramentas" data-recursos-barra
     data-inicial-aba="<?= $pastaInicial !== null ? 'pastas' : 'areas' ?>"
     data-inicial-grupo="<?= (int)($pastaInicial ?? $areaInicial ?? 0) ?>">
    <div class="segmentado" role="tablist" aria-label="Forma de organização" data-so-js hidden>
        <button type="button" class="segmentado__item" role="tab" aria-selected="true" aria-controls="palcoRecursos" data-aba="areas">
            <span aria-hidden="true">▦</span> Áreas
        </button>
        <button type="button" class="segmentado__item" role="tab" aria-selected="false" aria-controls="palcoRecursos" data-aba="pastas">
            <span aria-hidden="true">▤</span> Pastas
        </button>
    </div>
    <span class="barra-ferramentas__separador" aria-hidden="true" data-so-js hidden></span>

    <div class="fichas" role="group" aria-label="Filtrar por grupo" data-fichas></div>

    <div class="barra-ferramentas__fim">
        <label class="campo-busca" data-so-js hidden>
            <span class="campo-busca__icone" aria-hidden="true">⌕</span>
            <span class="sr-only">Buscar recurso</span>
            <input type="search" class="campo-busca__campo" data-recursos-busca maxlength="150" autocomplete="off" placeholder="Buscar por título ou descrição…">
        </label>
        <?php if ($podeGerenciar): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('tabela-apoio.php?tipo=tipos_recurso')) ?>">Pastas</a>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('tabela-apoio.php?tipo=categorias_recurso')) ?>">Áreas</a>
        <?php endif; ?>
        <button type="button" class="btn btn--primario btn--sm" data-modal-abrir="modalRecurso"
                data-modal-titulo="Novo recurso" data-pode-excluir="0"
                <?= $tipos === [] ? 'disabled title="Cadastre ao menos uma Pasta antes."' : '' ?>>＋ Novo item</button>
    </div>
</div>

<section class="palco" id="palcoRecursos" role="tabpanel" aria-label="Recursos">
    <div class="palco__area palco__area--livre">
        <?php if ($tipos === [] && $itens === []): ?>
            <div class="estado-vazio">
                <span class="estado-vazio__icone" aria-hidden="true">🗂</span>
                <b>Nenhuma Pasta cadastrada</b>
                <p>Antes de registrar itens é preciso criar ao menos um Tipo (Pasta). Administradores e Gestores fazem isso em Pastas de Recursos.</p>
                <?php if ($podeGerenciar): ?>
                    <a class="btn btn--primario btn--sm" href="<?= e(url('tabela-apoio.php?tipo=tipos_recurso')) ?>">Cadastrar Pastas</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div data-recursos-secoes></div>

            <div class="estado-vazio" data-recursos-vazio hidden>
                <span class="estado-vazio__icone" aria-hidden="true">🔎</span>
                <b data-vazio-titulo>Nenhum item aqui ainda</b>
                <p data-vazio-texto></p>
                <button type="button" class="btn btn--primario btn--sm" data-modal-abrir="modalRecurso" data-modal-titulo="Novo recurso" data-pode-excluir="0"
                        <?= $tipos === [] ? 'disabled' : '' ?>>＋ Cadastrar item</button>
            </div>

            <?php // Todos os cartões, uma vez só. O script os distribui nas seções. ?>
            <section class="secao-recursos" data-recursos-todos>
                <div class="secao-recursos__cabecalho">
                    <h3 class="secao-recursos__titulo">Todos os recursos</h3>
                    <span class="secao-recursos__total"><?= count($itens) ?></span>
                    <span class="faixa-nivel__linha" aria-hidden="true"></span>
                </div>
                <ul class="grade-recursos">
                    <?php foreach ($itens as $item): ?>
                        <?php
                        $cor = RecursoService::cor($indiceTipo[(int)$item['tipo_id']] ?? 0);
                        $areas = array_values(array_filter(array_map(static fn(int $c) => $nomeCategoria[$c] ?? null, $item['categorias'])));
                        $podeEditar = RecursoService::podeAlterar($item);
                        $autoria = 'Registrado por ' . ($item['criado_por_nome'] ?? 'usuário removido')
                            . ' em ' . datahora_br($item['created_at']) . '.';
                        ?>
                        <li data-recurso data-id="<?= (int)$item['id'] ?>" data-tipo="<?= (int)$item['tipo_id'] ?>"
                            data-categorias="<?= e(implode(',', $item['categorias'])) ?>">
                            <article class="cartao-recurso" style="--cor-forte: <?= e($cor['forte']) ?>; --cor-fundo: <?= e($cor['fundo']) ?>">
                                <a class="cartao-recurso__link" href="<?= e($item['url']) ?>" target="_blank" rel="noopener noreferrer">
                                    <div class="cartao-recurso__capa">
                                        <span class="cartao-recurso__tipo"><?= e($nomeTipo[$item['tipo_id']] ?? '—') ?></span>
                                        <span class="cartao-recurso__icone" aria-hidden="true">↗</span>
                                    </div>
                                    <div class="cartao-recurso__corpo">
                                        <h4 class="cartao-recurso__titulo" data-busca-texto><?= e($item['titulo']) ?></h4>
                                        <?php if (!empty($item['descricao'])): ?>
                                            <p class="cartao-recurso__descricao" data-busca-texto><?= e($item['descricao']) ?></p>
                                        <?php endif; ?>
                                        <div class="cartao-recurso__rodape">
                                            <span title="<?= e($autoria) ?>"><?= e(data_br(substr((string)$item['created_at'], 0, 10))) ?></span>
                                            <span class="cartao-recurso__abrir">Abrir →<span class="sr-only"> (abre em nova aba)</span></span>
                                        </div>
                                        <?php if ($areas !== []): ?>
                                            <ul class="cartao-recurso__areas">
                                                <?php foreach ($areas as $area): ?><li><?= e($area) ?></li><?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                </a>
                                <?php if ($podeEditar): ?>
                                    <button type="button" class="cartao-recurso__editar" data-modal-abrir="modalRecurso"
                                            data-modal-titulo="Editar recurso" aria-label="Editar <?= e($item['titulo']) ?>" title="Editar"
                                            data-modal-dados="<?= e(json_encode([
                                                'id'        => (int)$item['id'],
                                                'titulo'    => $item['titulo'],
                                                'url'       => $item['url'],
                                                'descricao' => $item['descricao'],
                                                'tipo_id'   => (int)$item['tipo_id'],
                                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                            data-categorias="<?= e(implode(',', $item['categorias'])) ?>"
                                            data-autoria="<?= e($autoria) ?>" data-pode-excluir="1">✎</button>
                                <?php endif; ?>
                            </article>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($itens === []): ?>
                    <p class="texto-secundario">Nenhum recurso cadastrado ainda.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</section>

<script type="application/json" id="dadosRecursos"><?= json_encode([
    'tipos'      => array_map(static fn(array $t): array => ['id' => (int)$t['id'], 'nome' => $t['nome'], 'cor' => RecursoService::cor($indiceTipo[(int)$t['id']] ?? 0)['forte']], $tipos),
    'categorias' => array_map(static fn(array $c, int $i): array => ['id' => (int)$c['id'], 'nome' => $c['nome'], 'cor' => RecursoService::cor($i)['forte']], $categorias, array_keys($categorias)),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<dialog class="modal" id="modalRecurso" aria-labelledby="modalRecursoTitulo">
    <form method="post" action="<?= e(url('salvar-recurso.php')) ?>" class="modal__form" data-modal-form novalidate>
        <?= Csrf::campo() ?>
        <input type="hidden" name="id" value="" data-padrao="">
        <header class="modal__cabecalho">
            <div>
                <h2 class="modal__titulo" id="modalRecursoTitulo" data-modal-titulo>Novo recurso</h2>
                <p class="modal__contexto"><span>O Tipo define a Pasta em que o item aparece; as Categorias definem as Áreas — um item pode estar em várias.</span></p>
            </div>
            <button type="button" class="modal__fechar" data-modal-fechar aria-label="Fechar">×</button>
        </header>
        <div class="modal__corpo">
            <div class="alert alert--erro" data-modal-erro tabindex="-1" role="alert" hidden></div>
            <div class="form-grid">
                <div class="campo">
                    <label class="campo__rotulo" for="recurso-titulo">Título <span class="obrigatorio">*</span></label>
                    <input class="input" type="text" id="recurso-titulo" name="titulo" required maxlength="<?= RecursoService::TITULO_MAX ?>"
                           placeholder="Ex.: Portal da Transparência" autocomplete="off">
                </div>
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="recurso-tipo">Tipo (Pasta) <span class="obrigatorio">*</span></label>
                    <select class="select" id="recurso-tipo" name="tipo_id" required>
                        <?php if ($tipos === []): ?><option value="">Nenhuma pasta cadastrada</option><?php endif; ?>
                        <?php foreach ($tipos as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= e($t['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="recurso-url">URL de acesso <span class="obrigatorio">*</span></label>
                    <input class="input" type="text" id="recurso-url" name="url" required maxlength="<?= RecursoService::URL_MAX ?>"
                           placeholder="https://…" inputmode="url" autocomplete="off">
                </div>
                <div class="campo">
                    <label class="campo__rotulo" for="recurso-descricao">Descrição</label>
                    <textarea class="textarea textarea--curta" id="recurso-descricao" name="descricao" maxlength="<?= RecursoService::DESCRICAO_MAX ?>"
                              placeholder="Para que serve este item e quando usá-lo."></textarea>
                </div>
                <fieldset class="campo campo--fichas">
                    <legend class="campo__rotulo">Categorias (Áreas)</legend>
                    <?php if ($categorias === []): ?>
                        <p class="campo__ajuda">Nenhuma categoria cadastrada<?= $podeGerenciar ? ' — crie uma em Áreas de Recursos' : '' ?>.</p>
                    <?php else: ?>
                        <div class="seletor-fichas">
                            <?php foreach ($categorias as $c): ?>
                                <label class="seletor-fichas__item">
                                    <input type="checkbox" name="categorias[]" value="<?= (int)$c['id'] ?>" data-categoria>
                                    <span><?= e($c['nome']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <span class="campo__ajuda">Sem categoria, o item aparece só na Pasta do seu tipo.</span>
                    <?php endif; ?>
                </fieldset>
            </div>
            <p class="texto-secundario mb-0 mt-16" data-recurso-autoria hidden></p>
        </div>
        <footer class="modal__rodape">
            <button type="submit" class="btn btn--ghost texto-perigo modal__rodape-esquerda" form="formExcluirRecurso" data-recurso-excluir hidden>🗑 Excluir</button>
            <button type="button" class="btn btn--ghost" data-modal-fechar>Cancelar</button>
            <button type="submit" class="btn btn--primario">💾 Salvar</button>
        </footer>
    </form>
</dialog>
<form method="post" action="<?= e(url('excluir-recurso.php')) ?>" id="formExcluirRecurso" hidden
      data-confirmar="Excluir este recurso definitivamente? Ele sai de todas as Áreas e da sua Pasta.">
    <?= Csrf::campo() ?>
    <input type="hidden" name="id" value="" data-excluir-id>
</form>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
