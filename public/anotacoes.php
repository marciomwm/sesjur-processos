<?php
declare(strict_types=1);

/**
 * Anotações de todos os processos administrativos: notas internas da
 * equipe, da mais recente para a mais antiga.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Repositories\AnotacaoRepository;
use App\Repositories\ProcessoAdministrativoRepository;

$filtros = [
    'busca'       => entrada('busca', $_GET),
    'minhas'      => entrada('minhas', $_GET) === '1' ? '1' : '',
    'processo_id' => (string)(entrada_int('processo_id') ?? ''),
    'ordenar'     => entrada('ordenar', $_GET) ?: 'created_at',
    'direcao'     => entrada('direcao', $_GET) ?: 'DESC',
];

$processo = $filtros['processo_id'] !== ''
    ? (new ProcessoAdministrativoRepository())->buscarPorId((int)$filtros['processo_id'])
    : null;

$paginacao = (new AnotacaoRepository())->listar([
    'busca'       => $filtros['busca'],
    'processo_id' => $processo !== null ? (int)$processo['id'] : null,
    'criado_por'  => $filtros['minhas'] === '1' ? usuario_logado_id() : null,
    'ordenar'     => $filtros['ordenar'],
    'direcao'     => $filtros['direcao'],
], entrada_int('pagina') ?? 1, (int)config('app.itens_por_pagina', 20));

$query = array_filter($filtros, static fn($v) => $v !== '');
$retorno = 'anotacoes.php' . ($query !== [] ? '?' . http_build_query($query) : '');

$titulo      = 'Anotações';
$menuAtivo   = 'anotacoes';
$breadcrumbs = ['Processos' => null, 'Anotações' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Anotações</h2>
            <p class="card__subtitulo">
                <?php if ($processo !== null): ?>
                    Anotações do processo <a class="mono" href="<?= e(url('processo-administrativo.php?id=' . $processo['id'])) ?>"><?= e($processo['protocolo']) ?></a>
                <?php else: ?>
                    Notas internas da equipe sobre os processos administrativos
                <?php endif; ?>
            </p>
        </div>
        <a class="btn btn--primario btn--sm"
           href="<?= e(url('nova-anotacao.php' . ($processo !== null ? '?processo_id=' . $processo['id'] : ''))) ?>">＋ Nova anotação</a>
    </div>
    <div class="card__corpo">
        <form method="get" class="filtros">
            <?php if ($processo !== null): ?>
                <input type="hidden" name="processo_id" value="<?= (int)$processo['id'] ?>">
            <?php endif; ?>
            <div class="campo">
                <label class="campo__rotulo" for="busca">Texto ou protocolo</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>" placeholder="Termo da anotação ou SES-PRO-2026/…">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="ordenacao">Ordem</label>
                <select class="select" id="ordenacao" name="direcao">
                    <option value="DESC" <?= strtoupper($filtros['direcao']) !== 'ASC' ? 'selected' : '' ?>>Mais recentes primeiro</option>
                    <option value="ASC" <?= strtoupper($filtros['direcao']) === 'ASC' ? 'selected' : '' ?>>Mais antigas primeiro</option>
                </select>
            </div>
            <div class="campo">
                <span class="campo__rotulo">Autoria</span>
                <label class="checkbox-linha filtros__checkbox">
                    <input type="checkbox" name="minhas" value="1" <?= $filtros['minhas'] === '1' ? 'checked' : '' ?>> Criadas por mim
                </label>
            </div>
            <div class="filtros__acoes">
                <button type="submit" class="btn btn--primario">Filtrar</button>
                <a class="btn btn--ghost" href="<?= e(url('anotacoes.php' . ($processo !== null ? '?processo_id=' . $processo['id'] : ''))) ?>">Limpar</a>
            </div>
        </form>
    </div>
</section>

<section class="card">
    <div class="card__corpo">
        <?php
        $anotacoes = $paginacao->itens;
        $mostrarProcesso = true;
        require APP_ROOT . '/Views/partials/anotacoes-lista.php';
        ?>
    </div>
    <?php $query = $filtros; require APP_ROOT . '/Views/partials/paginacao.php'; ?>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
