<?php
declare(strict_types=1);

/**
 * Solicitações de todos os processos administrativos: pedidos enviados a
 * outras unidades, com filtros de status, destinatário, prazo e autoria.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Repositories\LotacaoRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\SolicitacaoRepository;
use App\Services\SolicitacaoService;

$filtros = [
    'busca'        => entrada('busca', $_GET),
    'status'       => entrada('status', $_GET),
    'destinatario' => (string)(entrada_int('destinatario') ?? ''),
    'vencidas'     => entrada('vencidas', $_GET) === '1' ? '1' : '',
    'minhas'       => entrada('minhas', $_GET) === '1' ? '1' : '',
    'processo_id'  => (string)(entrada_int('processo_id') ?? ''),
    'ordenar'      => entrada('ordenar', $_GET) ?: 'data_solicitacao',
    'direcao'      => entrada('direcao', $_GET) ?: 'DESC',
];

$processo = $filtros['processo_id'] !== ''
    ? (new ProcessoAdministrativoRepository())->buscarPorId((int)$filtros['processo_id'])
    : null;

$repo = new SolicitacaoRepository();
$paginacao = $repo->listar([
    'busca'        => $filtros['busca'],
    'status'       => $filtros['status'],
    'destinatario' => $filtros['destinatario'] !== '' ? (int)$filtros['destinatario'] : null,
    'vencidas'     => $filtros['vencidas'] === '1',
    'processo_id'  => $processo !== null ? (int)$processo['id'] : null,
    'criado_por'   => $filtros['minhas'] === '1' ? usuario_logado_id() : null,
    'ordenar'      => $filtros['ordenar'],
    'direcao'      => $filtros['direcao'],
], entrada_int('pagina') ?? 1, (int)config('app.itens_por_pagina', 20));

$lotacoes = (new LotacaoRepository())->todos();
$totalAguardando = $repo->contarAguardando();
$totalVencidas = $repo->contarVencidas();

$query = array_filter($filtros, static fn($v) => $v !== '');
$retorno = 'solicitacoes.php' . ($query !== [] ? '?' . http_build_query($query) : '');

/** Inverte a direcao ao clicar novamente na mesma coluna. */
$linkOrdenacao = static function (string $campo, string $rotulo) use ($filtros): string {
    $query = array_filter($filtros, static fn($v) => $v !== '');
    $direcao = ($filtros['ordenar'] === $campo && strtoupper($filtros['direcao']) === 'ASC') ? 'DESC' : 'ASC';
    $query['ordenar'] = $campo;
    $query['direcao'] = $direcao;
    $seta = $filtros['ordenar'] === $campo ? (strtoupper($filtros['direcao']) === 'ASC' ? '▲' : '▼') : '';
    return '<a href="?' . e(http_build_query($query)) . '">' . e($rotulo) . ' <span>' . $seta . '</span></a>';
};

$titulo      = 'Solicitações';
$menuAtivo   = 'solicitacoes';
$breadcrumbs = ['Processos' => null, 'Solicitações' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<section class="indicadores indicadores--compactos">
    <a class="indicador" href="<?= e(url('solicitacoes.php?status=AGUARDANDO')) ?>">
        <span class="indicador__rotulo">Aguardando resposta</span>
        <span class="indicador__valor"><?= $totalAguardando ?></span>
        <span class="indicador__nota">Solicitações ainda não atendidas</span>
    </a>
    <a class="indicador indicador--alerta" href="<?= e(url('solicitacoes.php?vencidas=1')) ?>">
        <span class="indicador__rotulo">Vencidas</span>
        <span class="indicador__valor"><?= $totalVencidas ?></span>
        <span class="indicador__nota">Aguardando com o prazo expirado</span>
    </a>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Solicitações</h2>
            <p class="card__subtitulo">
                <?php if ($processo !== null): ?>
                    Solicitações do processo <a class="mono" href="<?= e(url('processo-administrativo.php?id=' . $processo['id'])) ?>"><?= e($processo['protocolo']) ?></a>
                <?php else: ?>
                    Pedidos enviados a outras unidades · alterados por administradores, gestores ou por quem os criou
                <?php endif; ?>
            </p>
        </div>
        <a class="btn btn--primario btn--sm"
           href="<?= e(url('nova-solicitacao.php' . ($processo !== null ? '?processo_id=' . $processo['id'] : ''))) ?>">＋ Nova solicitação</a>
    </div>
    <div class="card__corpo">
        <form method="get" class="filtros">
            <?php if ($processo !== null): ?>
                <input type="hidden" name="processo_id" value="<?= (int)$processo['id'] ?>">
            <?php endif; ?>
            <div class="campo">
                <label class="campo__rotulo" for="busca">Assunto, expediente ou protocolo</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>" placeholder="Termo, SES-OFI-2026/… ou SES-PRO-2026/…">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="status">Status</label>
                <select class="select" id="status" name="status">
                    <option value="">Todos</option>
                    <?php foreach (SolicitacaoService::STATUS as $codigo => $rotulo): ?>
                        <option value="<?= e($codigo) ?>" <?= $filtros['status'] === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="destinatario">Destinatário</label>
                <select class="select" id="destinatario" name="destinatario">
                    <option value="">Todas as unidades</option>
                    <?php foreach ($lotacoes as $l): ?>
                        <option value="<?= (int)$l['id'] ?>" <?= $filtros['destinatario'] === (string)$l['id'] ? 'selected' : '' ?>>
                            <?= e($l['rotulo'] ?? $l['nome']) ?><?= (int)$l['ativo'] === 1 ? '' : ' (inativa)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <span class="campo__rotulo">Mostrar</span>
                <label class="checkbox-linha filtros__checkbox">
                    <input type="checkbox" name="vencidas" value="1" <?= $filtros['vencidas'] === '1' ? 'checked' : '' ?>> Só vencidas
                </label>
                <label class="checkbox-linha filtros__checkbox">
                    <input type="checkbox" name="minhas" value="1" <?= $filtros['minhas'] === '1' ? 'checked' : '' ?>> Criadas por mim
                </label>
            </div>
            <div class="filtros__acoes">
                <button type="submit" class="btn btn--primario">Filtrar</button>
                <a class="btn btn--ghost" href="<?= e(url('solicitacoes.php' . ($processo !== null ? '?processo_id=' . $processo['id'] : ''))) ?>">Limpar</a>
            </div>
        </form>
    </div>

    <?php
    $solicitacoes = $paginacao->itens;
    $mostrarProcesso = true;
    require APP_ROOT . '/Views/partials/solicitacoes-tabela.php';
    ?>

    <?php $query = $filtros; require APP_ROOT . '/Views/partials/paginacao.php'; ?>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
