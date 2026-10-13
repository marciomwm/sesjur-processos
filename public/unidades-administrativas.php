<?php
declare(strict_types=1);

/**
 * Unidades Administrativas (antiga "Lotação"): estrutura organizacional das
 * instituições em três visualizações sobre os mesmos dados — Lista (árvore
 * recolhível), Hierarquia (organograma) e Nível (uma faixa por nível, com
 * conectores até a unidade superior).
 *
 * A estrutura inteira vai para o navegador numa consulta só: expandir um
 * ramo, focar, buscar, dar zoom ou trocar de visualização não custa ida ao
 * servidor (assets/js/unidades-visoes.js). Sem JavaScript, a tabela em
 * árvore continua disponível.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Repositories\InstituicaoRepository;
use App\Repositories\LotacaoRepository;
use App\Repositories\NivelOrganizacionalRepository;
use App\Services\UnidadeAdministrativaService;

$repo = new LotacaoRepository();
$linhas = $repo->listarUnidades(['status' => 'todos']);
$instituicoes = (new InstituicaoRepository())->todos();
$niveis = (new NivelOrganizacionalRepository())->todos();
$dadosVisao = UnidadeAdministrativaService::paraVisualizacao($linhas, $instituicoes, $niveis);
$arvore = UnidadeAdministrativaService::emArvore($linhas, $repo->estrutura());

// Filtros iniciais vindos de links (ex.: "Unidades ativas" de uma instituição ou nível).
$status = entrada('status', $_GET);
$filtrosIniciais = [
    'busca'          => mb_substr(entrada('busca', $_GET), 0, 150),
    'instituicao_id' => entrada_int('instituicao_id'),
    'nivel_id'       => entrada_int('nivel_id'),
    'status'         => in_array($status, ['ativos', 'inativos', 'todos'], true) ? $status : 'ativos',
];

$ativas = array_filter($linhas, static fn(array $u): bool => (int)$u['ativo'] === 1);
$semInstituicao = count(array_filter($linhas, static fn(array $u): bool => $u['instituicao_id'] === null));
$estatisticas = [
    'instituicoes' => count($instituicoes),
    'unidades'     => count($ativas),
    'niveis'       => count(array_unique(array_filter(array_column($ativas, 'nivel_id')))),
    'gestores'     => count(array_unique(array_filter(array_column($ativas, 'gestor_nome')))),
];

$json = json_encode(
    $dadosVisao + [
        'filtros' => $filtrosIniciais,
        'urls'    => [
            'ficha'    => url('unidade-administrativa.php?id='),
            'editar'   => url('editar-unidade-administrativa.php?id='),
            'nova'     => url('nova-unidade-administrativa.php'),
            'status'   => url('alterar-status-unidade-administrativa.php'),
            'usuarios' => url('usuarios.php?status=todos&lotacao_id='),
        ],
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$titulo      = 'Unidades Administrativas';
$menuAtivo   = 'unidades';
$breadcrumbs = ['Sistema' => null, 'Unidades Administrativas' => null];
$scripts     = ['unidades-visoes.js'];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if ($semInstituicao > 0): ?>
    <div class="alert alert--aviso">
        <span class="alert__icone">⚠</span>
        <span><?= $semInstituicao ?> unidade(s) cadastrada(s) antes das Unidades Administrativas ainda sem instituição. Edite-as para completar o cadastro.</span>
    </div>
<?php endif; ?>

<div class="cabecalho-pagina">
    <div class="cabecalho-pagina__texto">
        <p class="sobrelinha">Estrutura Organizacional</p>
        <h2 class="cabecalho-pagina__titulo">Unidades Administrativas</h2>
        <p class="cabecalho-pagina__descricao">Explore a estrutura das instituições em três visualizações — Lista, Hierarquia e Nível — sobre os mesmos dados. As unidades também são a lotação dos usuários e o destinatário das solicitações.</p>
    </div>
    <ul class="estatisticas" data-estatisticas>
        <li class="estatistica"><b data-estatistica="instituicoes"><?= $estatisticas['instituicoes'] ?></b><span>Instituições</span></li>
        <li class="estatistica"><b data-estatistica="unidades"><?= $estatisticas['unidades'] ?></b><span>Unidades</span></li>
        <li class="estatistica"><b data-estatistica="niveis"><?= $estatisticas['niveis'] ?></b><span>Níveis</span></li>
        <li class="estatistica"><b data-estatistica="gestores"><?= $estatisticas['gestores'] ?></b><span>Gestores</span></li>
        <li class="estatistica estatistica--destaque" data-estatistica-resultados hidden><b data-estatistica="resultados">0</b><span>Resultados</span></li>
    </ul>
</div>

<div class="barra-ferramentas" data-unidades-barra hidden>
    <div class="segmentado" role="tablist" aria-label="Visualização">
        <button type="button" class="segmentado__item" role="tab" aria-selected="true" aria-controls="palcoUnidades" data-visao="lista">
            <span aria-hidden="true">☰</span> Lista
        </button>
        <button type="button" class="segmentado__item" role="tab" aria-selected="false" aria-controls="palcoUnidades" data-visao="hierarquia">
            <span aria-hidden="true">⑂</span> Hierarquia
        </button>
        <button type="button" class="segmentado__item" role="tab" aria-selected="false" aria-controls="palcoUnidades" data-visao="nivel">
            <span aria-hidden="true">≣</span> Nível
        </button>
    </div>
    <span class="barra-ferramentas__separador" aria-hidden="true"></span>

    <label class="campo-busca">
        <span class="campo-busca__icone" aria-hidden="true">⌕</span>
        <span class="sr-only">Buscar unidade</span>
        <input type="search" class="campo-busca__campo" data-unidades-busca maxlength="150" autocomplete="off"
               placeholder="Buscar unidade, sigla ou gestor…" value="<?= e($filtrosIniciais['busca']) ?>">
    </label>

    <label class="sr-only" for="filtroInstituicao">Instituição</label>
    <select class="select select--barra" id="filtroInstituicao" data-unidades-instituicao>
        <option value="">Todas as instituições</option>
        <?php foreach ($instituicoes as $i): ?>
            <option value="<?= (int)$i['id'] ?>"><?= e($i['sigla'] ?: $i['nome']) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="sr-only" for="filtroNivel">Nível</label>
    <select class="select select--barra" id="filtroNivel" data-unidades-nivel>
        <option value="">Todos os níveis</option>
        <?php foreach ($niveis as $n): ?>
            <option value="<?= (int)$n['id'] ?>"><?= e($n['nome']) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="sr-only" for="filtroStatus">Status</label>
    <select class="select select--barra" id="filtroStatus" data-unidades-status>
        <option value="ativos">Ativas</option>
        <option value="inativos">Inativas</option>
        <option value="todos">Todas</option>
    </select>

    <button type="button" class="btn btn--ghost btn--sm" data-expandir-tudo title="Expandir tudo"><span aria-hidden="true">⤢</span><span class="rotulo-largo">Expandir tudo</span></button>
    <button type="button" class="btn btn--ghost btn--sm" data-recolher-tudo title="Recolher tudo"><span aria-hidden="true">⤡</span><span class="rotulo-largo">Recolher tudo</span></button>

    <div class="barra-ferramentas__fim">
        <label class="barra-ferramentas__check" data-so-visao="nivel" hidden>
            <input type="checkbox" data-conexoes checked> Conexões
        </label>
        <div class="zoom" data-so-visao="hierarquia" hidden>
            <button type="button" class="zoom__botao" data-zoom="-" aria-label="Reduzir zoom" title="Reduzir zoom">−</button>
            <span class="zoom__valor" data-zoom-valor>100%</span>
            <button type="button" class="zoom__botao" data-zoom="+" aria-label="Aumentar zoom" title="Aumentar zoom">+</button>
            <button type="button" class="zoom__botao" data-zoom="1" aria-label="Restaurar zoom" title="Restaurar zoom">⤾</button>
        </div>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('tabela-apoio.php?tipo=instituicoes')) ?>">Instituições</a>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('tabela-apoio.php?tipo=niveis')) ?>">Níveis</a>
        <a class="btn btn--primario btn--sm" href="<?= e(url('nova-unidade-administrativa.php')) ?>" data-nova-unidade>＋ Nova unidade</a>
    </div>
</div>

<nav class="trilha-foco" aria-label="Cadeia hierárquica" data-trilha-foco hidden></nav>

<section class="palco" id="palcoUnidades" role="tabpanel" aria-label="Unidades administrativas">
    <div class="palco__area" data-palco>
        <div data-unidades-conteudo></div>

        <div data-unidades-fallback>
            <div class="tabela-wrap">
                <table class="tabela tabela--providencias">
                    <thead>
                        <tr>
                            <th>Unidade</th>
                            <th>Instituição</th>
                            <th>Nível</th>
                            <th>Gestor</th>
                            <th title="Servidores no lotacionograma">Lotados</th>
                            <th title="Usuários do sistema com esta lotação">Usuários</th>
                            <th>Status</th>
                            <th class="texto-direita">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($arvore === []): ?>
                        <tr><td colspan="8" class="tabela__vazia">Nenhuma unidade cadastrada.</td></tr>
                    <?php else: foreach ($arvore as $u): ?>
                        <?php $ativa = (int)$u['ativo'] === 1; ?>
                        <tr class="<?= $ativa ? '' : 'is-inativo' ?>">
                            <td>
                                <div class="arvore-unidade" style="--profundidade: <?= (int)$u['profundidade'] ?>">
                                    <?php if ($u['profundidade'] > 0): ?><span class="arvore-unidade__ramo" aria-hidden="true">└</span><?php endif; ?>
                                    <span>
                                        <a href="<?= e(url('unidade-administrativa.php?id=' . $u['id'])) ?>"><strong><?= e($u['nome']) ?></strong></a>
                                        <?php if (!empty($u['sigla'])): ?><span class="texto-secundario mono"><?= e($u['sigla']) ?></span><?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td class="nowrap"><?= $u['instituicao_sigla'] !== null ? '<span title="' . e($u['instituicao_nome']) . '">' . e($u['instituicao_sigla']) . '</span>' : ou_traco($u['instituicao_nome']) ?></td>
                            <td><?= ou_traco($u['nivel_nome']) ?></td>
                            <td><?= ou_traco($u['gestor_nome']) ?></td>
                            <td class="texto-centro"><?= (int)$u['total_servidores'] ?></td>
                            <td class="texto-centro">
                                <a href="<?= e(url('usuarios.php?' . http_build_query(['lotacao_id' => $u['id'], 'status' => 'todos']))) ?>" title="Ver usuários"><?= (int)$u['usuarios_ativos'] ?></a>
                            </td>
                            <td><span class="badge <?= $ativa ? 'badge--verde' : 'badge--neutro' ?>"><?= $ativa ? 'Ativa' : 'Inativa' ?></span></td>
                            <td class="texto-direita nowrap">
                                <div class="acoes-linha tabela__comandos">
                                    <a class="btn btn--ghost btn--sm" href="<?= e(url('unidade-administrativa.php?id=' . $u['id'])) ?>">Ver</a>
                                    <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-unidade-administrativa.php?id=' . $u['id'])) ?>">Editar</a>
                                    <form method="post" action="<?= e(url('alterar-status-unidade-administrativa.php')) ?>"
                                          data-confirmar="<?= e($ativa
                                              ? 'Inativar a unidade “' . $u['nome'] . '”? Usuários e solicitações que já a usam continuam vinculados, mas ela deixa de aparecer em novos cadastros.'
                                              : 'Reativar a unidade “' . $u['nome'] . '”?') ?>">
                                        <?= Csrf::campo() ?>
                                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                        <input type="hidden" name="ativo" value="<?= $ativa ? '0' : '1' ?>">
                                        <input type="hidden" name="retorno" value="unidades-administrativas.php">
                                        <button type="submit" class="btn btn--ghost btn--sm <?= $ativa ? 'texto-perigo' : 'texto-sucesso' ?>"><?= $ativa ? 'Inativar' : 'Ativar' ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<div class="gaveta" data-gaveta hidden>
    <div class="gaveta__fundo" data-gaveta-fechar></div>
    <aside class="gaveta__painel" role="dialog" aria-modal="true" aria-labelledby="gavetaTitulo" data-gaveta-painel tabindex="-1"></aside>
</div>

<script type="application/json" id="dadosUnidades"><?= $json ?></script>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
