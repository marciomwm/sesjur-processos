<?php
declare(strict_types=1);

/**
 * Manutencao das tabelas de apoio simples:
 *   tabela-apoio.php?tipo=cargos | instituicoes | niveis | instituicoes_externas | orgaos_externos
 * Lista, pesquisa e cadastro rapido (POST na propria pagina).
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Administradores (Pastas e Areas de Recursos: tambem gestores).
App\Services\TabelaApoioService::exigirAcesso(entrada('tipo', $_GET));

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\TabelaApoioService;
use App\Validators\Normalizador;

$tipo = entrada('tipo', $_GET);
// Lotações viraram Unidades Administrativas, com tela própria.
if ($tipo === 'lotacoes') {
    redirecionar('unidades-administrativas.php');
}
if (!TabelaApoioService::tipoValido($tipo)) {
    flash('erro', 'Tabela de apoio não encontrada.');
    redirecionar('usuarios.php');
}

$servico = new TabelaApoioService($tipo);
$meta    = $servico->meta();
$singular = $meta['singular'];
$artigo   = $meta['artigo'];
$vinculo  = $meta['vinculo'];
// Item subordinado (orgao externo -> instituicao externa): seletor do pai.
$paiMeta  = $meta['pai'] ?? null;
$pais     = $paiMeta !== null ? (new TabelaApoioService($paiMeta['tipo']))->repositorio()->todos() : [];
$paiIdGet = entrada_int('pai_id');

$erros = [];
$dados = ['nome' => '', 'sigla' => '', 'pai' => (string)($paiIdGet ?? '')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = ['nome' => entrada('nome'), 'sigla' => entrada('sigla'), 'pai' => entrada('pai')];
    try {
        $servico->criar($dados + ($paiMeta !== null ? [$paiMeta['campo'] => $dados['pai']] : []));
        flash('sucesso', ucfirst($singular) . ' “' . (string)Normalizador::texto($dados['nome']) . '” cadastrad' . $artigo . ' com sucesso.');
        redirecionar('tabela-apoio.php?tipo=' . $tipo . ($paiMeta !== null ? '&pai_id=' . (int)$dados['pai'] : ''));
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, $tipo . '.criar');
        $erros['_geral'] = "Não foi possível salvar. Se o problema persistir, informe o código {$codigo} ao suporte.";
    }
}

$status  = entrada('status', $_GET);
$filtros = [
    'busca'  => mb_substr(entrada('busca', $_GET), 0, 150),
    'status' => in_array($status, ['ativos', 'inativos', 'todos'], true) ? $status : 'todos',
    'pai_id' => $paiMeta !== null ? (string)($paiIdGet ?? '') : '',
];
$itens = $servico->repositorio()->listar($filtros);
$query = array_filter(['tipo' => $tipo] + $filtros, static fn($v) => $v !== '');

$titulo      = $meta['titulo'];
$menuAtivo   = $meta['menu'];
$breadcrumbs = $paiMeta !== null
    ? ['Sistema' => null, TabelaApoioService::TIPOS[$paiMeta['tipo']]['titulo'] => 'tabela-apoio.php?tipo=' . $paiMeta['tipo'], $meta['titulo'] => null]
    : [($meta['menu'] === 'recursos' ? 'Recursos' : 'Sistema') => ($meta['menu'] === 'recursos' ? 'recursos.php' : null), $meta['titulo'] => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo"><?= $artigo === 'a' ? 'Nova' : 'Novo' ?> <?= e($singular) ?></h2>
            <p class="card__subtitulo">
                <?= e($meta['ajuda']) ?>
            </p>
        </div>
        <?php if ($meta['menu'] === 'recursos'): ?>
            <div class="acoes-linha">
                <?php $outro = $tipo === 'tipos_recurso' ? 'categorias_recurso' : 'tipos_recurso'; ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('tabela-apoio.php?tipo=' . $outro)) ?>"><?= e(TabelaApoioService::TIPOS[$outro]['titulo']) ?></a>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('recursos.php')) ?>">Voltar aos recursos</a>
            </div>
        <?php elseif ($tipo === 'instituicoes_externas'): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('tabela-apoio.php?tipo=orgaos_externos')) ?>">Órgãos externos</a>
        <?php elseif ($paiMeta !== null): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('tabela-apoio.php?tipo=' . $paiMeta['tipo'])) ?>"><?= e(TabelaApoioService::TIPOS[$paiMeta['tipo']]['titulo']) ?></a>
        <?php endif; ?>
    </div>
    <div class="card__corpo">
        <form method="post" class="form-apoio" novalidate>
            <?= Csrf::campo() ?>
            <?php if ($paiMeta !== null): ?>
                <div class="campo form-apoio__pai">
                    <label class="campo__rotulo" for="pai"><?= e($paiMeta['rotulo']) ?> <span class="obrigatorio">*</span></label>
                    <select class="select <?= isset($erros[$paiMeta['campo']]) ? 'is-invalido' : '' ?>" id="pai" name="pai" required>
                        <option value="">Selecione</option>
                        <?php foreach ($pais as $p): ?>
                            <?php if ((int)$p['ativo'] === 1): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= $dados['pai'] === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['nome']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($erros[$paiMeta['campo']])): ?><span class="campo__erro"><?= e($erros[$paiMeta['campo']]) ?></span><?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="campo form-apoio__nome">
                <label class="campo__rotulo" for="nome">Nome <span class="obrigatorio">*</span></label>
                <input class="input <?= isset($erros['nome']) ? 'is-invalido' : '' ?>" type="text" id="nome" name="nome"
                       required maxlength="<?= (int)$meta['nome_max'] ?>" value="<?= e($dados['nome']) ?>"
                       placeholder="<?= e($meta['exemplo']) ?>">
                <?php if (isset($erros['nome'])): ?><span class="campo__erro"><?= e($erros['nome']) ?></span><?php endif; ?>
            </div>
            <?php if ($meta['sigla']): ?>
                <div class="campo form-apoio__sigla">
                    <label class="campo__rotulo" for="sigla">Sigla</label>
                    <input class="input mono <?= isset($erros['sigla']) ? 'is-invalido' : '' ?>" type="text" id="sigla" name="sigla"
                           maxlength="<?= TabelaApoioService::SIGLA_MAX ?>" value="<?= e($dados['sigla']) ?>" placeholder="SES-MT">
                    <?php if (isset($erros['sigla'])): ?><span class="campo__erro"><?= e($erros['sigla']) ?></span><?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="form-apoio__acao">
                <button type="submit" class="btn btn--primario">＋ Adicionar</button>
            </div>
        </form>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo"><?= e($meta['titulo']) ?> cadastrad<?= $artigo ?>s</h2>
            <p class="card__subtitulo">Não há exclusão: <?= e($singular) ?> em desuso é inativad<?= $artigo ?> e continua valendo para quem já <?= $artigo === 'a' ? 'a' : 'o' ?> possui.</p>
        </div>
    </div>
    <div class="card__corpo">
        <form method="get" class="filtros">
            <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
            <?php if ($paiMeta !== null): ?>
                <div class="campo">
                    <label class="campo__rotulo" for="filtroPai"><?= e($paiMeta['rotulo']) ?></label>
                    <select class="select" id="filtroPai" name="pai_id">
                        <option value="">Todas</option>
                        <?php foreach ($pais as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $filtros['pai_id'] === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="campo">
                <label class="campo__rotulo" for="busca">Pesquisar</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>"
                       maxlength="150" placeholder="<?= $meta['sigla'] ? 'Nome ou sigla' : 'Nome' ?>">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="status">Status</label>
                <select class="select" id="status" name="status">
                    <?php foreach (['todos' => 'Todos', 'ativos' => 'Ativos', 'inativos' => 'Inativos'] as $valor => $rotulo): ?>
                        <option value="<?= e($valor) ?>" <?= $filtros['status'] === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filtros__acoes">
                <button type="submit" class="btn btn--primario">Buscar</button>
                <a class="btn btn--ghost" href="<?= e(url('tabela-apoio.php?tipo=' . $tipo)) ?>">Limpar</a>
            </div>
        </form>
    </div>

    <div class="tabela-wrap">
        <table class="tabela">
            <thead>
                <tr>
                    <th>Nome</th>
                    <?php if ($paiMeta !== null): ?><th><?= e($paiMeta['rotulo']) ?></th><?php endif; ?>
                    <?php if ($meta['sigla']): ?><th>Sigla</th><?php endif; ?>
                    <th><?= e($vinculo['rotulo']) ?></th>
                    <th>Status</th>
                    <th class="texto-direita">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($itens === []): ?>
                <tr><td colspan="<?= 4 + ($meta['sigla'] ? 1 : 0) + ($paiMeta !== null ? 1 : 0) ?>" class="tabela__vazia">Nenhum registro encontrado.</td></tr>
            <?php else: foreach ($itens as $item): ?>
                <?php
                $ativo = (int)$item['ativo'] === 1;
                $qtdAtivos = (int)$item['usuarios_ativos'];
                $confirmacao = $ativo
                    ? 'Inativar ' . $artigo . ' ' . $singular . ' “' . $item['nome'] . '”? '
                        . ($qtdAtivos > 0
                            ? $qtdAtivos . ' ' . $vinculo['texto'] . ' continuarão vinculado(s), mas ' . $artigo . ' ' . $singular . ' deixará de aparecer em novos cadastros.'
                            : 'Deixará de aparecer em novos cadastros.')
                    : 'Reativar ' . $artigo . ' ' . $singular . ' “' . $item['nome'] . '”?';
                ?>
                <tr class="<?= $ativo ? '' : 'is-inativo' ?>">
                    <td><strong><?= e($item['nome']) ?></strong></td>
                    <?php if ($paiMeta !== null): ?><td><?= e($item['pai_sigla'] ?: $item['pai_nome']) ?></td><?php endif; ?>
                    <?php if ($meta['sigla']): ?><td class="mono"><?= ou_traco($item['sigla']) ?></td><?php endif; ?>
                    <td>
                        <a href="<?= e(url($vinculo['url'] . '?' . http_build_query([$vinculo['param'] => $item['id']] + $vinculo['extra']))) ?>"
                           title="Ver vinculados">
                            <?= e((string)$qtdAtivos) ?>
                            <?php if ((int)$item['total_usuarios'] > $qtdAtivos): ?>
                                <span class="texto-secundario">(+<?= e((string)((int)$item['total_usuarios'] - $qtdAtivos)) ?> inativo(s))</span>
                            <?php endif; ?>
                        </a>
                    </td>
                    <td><span class="badge <?= $ativo ? 'badge--verde' : 'badge--neutro' ?>"><?= $ativo ? ($artigo === 'a' ? 'Ativa' : 'Ativo') : ($artigo === 'a' ? 'Inativa' : 'Inativo') ?></span></td>
                    <td class="texto-direita nowrap">
                        <div class="acoes-linha" style="justify-content:flex-end">
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-tabela-apoio.php?tipo=' . $tipo . '&id=' . $item['id'])) ?>">Editar</a>
                            <form method="post" action="<?= e(url('alterar-status-tabela-apoio.php')) ?>" style="display:inline"
                                  data-confirmar="<?= e($confirmacao) ?>">
                                <?= Csrf::campo() ?>
                                <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
                                <input type="hidden" name="id" value="<?= e((string)$item['id']) ?>">
                                <input type="hidden" name="ativo" value="<?= $ativo ? '0' : '1' ?>">
                                <input type="hidden" name="retorno" value="<?= e(http_build_query($query)) ?>">
                                <button type="submit" class="btn btn--ghost btn--sm <?= $ativo ? 'texto-perigo' : 'texto-sucesso' ?>">
                                    <?= $ativo ? 'Inativar' : 'Ativar' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
