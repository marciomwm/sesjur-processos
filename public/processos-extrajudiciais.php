<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Repositories\InstituicaoExternaRepository;
use App\Repositories\OrgaoExternoRepository;
use App\Repositories\ProcessoExtrajudicialRepository;
use App\Services\ProcessoExtrajudicialService;
use App\Validators\Normalizador;

$situacao = strtoupper(entrada('situacao', $_GET));
$filtros = [
    'busca'          => mb_substr(entrada('busca', $_GET), 0, 150),
    'instituicao_id' => (string)(entrada_int('instituicao_id') ?? ''),
    'orgao_id'       => (string)(entrada_int('orgao_id') ?? ''),
    'data_de'        => (string)(Normalizador::dataIso(entrada('data_de', $_GET)) ?? ''),
    'data_ate'       => (string)(Normalizador::dataIso(entrada('data_ate', $_GET)) ?? ''),
    'situacao'       => isset(ProcessoExtrajudicialService::SITUACOES[$situacao]) ? $situacao : '',
];
$pagina    = entrada_int('pagina') ?? 1;
$porPagina = (int)config('app.itens_por_pagina', 20);

$erroPeriodo = $filtros['data_de'] !== '' && $filtros['data_ate'] !== '' && $filtros['data_de'] > $filtros['data_ate'];
$paginacao = (new ProcessoExtrajudicialRepository())->listar([
    'busca'          => $filtros['busca'],
    'instituicao_id' => $filtros['instituicao_id'] !== '' ? (int)$filtros['instituicao_id'] : null,
    'orgao_id'       => $filtros['orgao_id'] !== '' ? (int)$filtros['orgao_id'] : null,
    'data_de'        => $filtros['data_de'] ?: null,
    'data_ate'       => $filtros['data_ate'] ?: null,
    'situacao'       => $filtros['situacao'],
], $pagina, $porPagina);

$instituicoes = (new InstituicaoExternaRepository())->todos();
$orgaos = (new OrgaoExternoRepository())->todosComInstituicao();
$query = array_filter($filtros, static fn($v) => $v !== '');

$titulo      = 'Processos Extrajudiciais';
$menuAtivo   = 'pe-lista';
$breadcrumbs = ['Processos' => null, 'Processos Extrajudiciais' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Processos Extrajudiciais</h2>
            <p class="card__subtitulo">Procedimentos de instituições externas (Ministério Público, Defensoria, Tribunal de Contas…) · distintos dos processos administrativos e judiciais</p>
        </div>
        <a class="btn btn--primario btn--sm" href="<?= e(url('novo-processo-extrajudicial.php')) ?>">＋ Novo processo extrajudicial</a>
    </div>
    <div class="card__corpo">
        <form method="get" class="filtros" role="search">
            <div class="campo">
                <label class="campo__rotulo" for="busca">Protocolo ou assunto</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>" maxlength="150" placeholder="Ex.: SIMP 000123">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="instituicao_id">Instituição</label>
                <select class="select" id="instituicao_id" name="instituicao_id">
                    <option value="">Todas</option>
                    <?php foreach ($instituicoes as $i): ?>
                        <option value="<?= (int)$i['id'] ?>" <?= $filtros['instituicao_id'] === (string)$i['id'] ? 'selected' : '' ?>><?= e($i['sigla'] ?: $i['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="orgao_id">Órgão</label>
                <select class="select" id="orgao_id" name="orgao_id">
                    <option value="">Todos</option>
                    <?php foreach ($orgaos as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= $filtros['orgao_id'] === (string)$o['id'] ? 'selected' : '' ?>><?= e($o['instituicao_sigla'] . ' · ' . $o['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="data_de">Data — de</label>
                <input class="input <?= $erroPeriodo ? 'is-invalido' : '' ?>" type="date" id="data_de" name="data_de" value="<?= e($filtros['data_de']) ?>">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="data_ate">até</label>
                <input class="input <?= $erroPeriodo ? 'is-invalido' : '' ?>" type="date" id="data_ate" name="data_ate" value="<?= e($filtros['data_ate']) ?>">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="situacao">Situação</label>
                <select class="select" id="situacao" name="situacao">
                    <option value="">Todas</option>
                    <?php foreach (ProcessoExtrajudicialService::SITUACOES as $codigo => $rotulo): ?>
                        <option value="<?= e($codigo) ?>" <?= $filtros['situacao'] === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filtros__acoes">
                <button type="submit" class="btn btn--primario">Filtrar</button>
                <a class="btn btn--ghost" href="<?= e(url('processos-extrajudiciais.php')) ?>">Limpar</a>
            </div>
        </form>
        <?php if ($erroPeriodo): ?>
            <p class="campo__erro mb-0">A data inicial do período é posterior à final: nenhum processo pode ser encontrado.</p>
        <?php endif; ?>
    </div>

    <div class="tabela-wrap">
        <table class="tabela tabela--providencias">
            <thead>
                <tr><th>Protocolo</th><th>Data</th><th>Instituição / Órgão</th><th>Assunto</th><th>Situação</th><th title="Processos administrativos vinculados">Vínculos</th></tr>
            </thead>
            <tbody>
            <?php if ($paginacao->vazio()): ?>
                <tr><td colspan="6" class="tabela__vazia">Nenhum processo extrajudicial encontrado.</td></tr>
            <?php else: foreach ($paginacao->itens as $pe): ?>
                <tr>
                    <td class="nowrap"><a class="mono" href="<?= e(url('processo-extrajudicial.php?id=' . $pe['id'])) ?>"><strong><?= e($pe['protocolo']) ?></strong></a></td>
                    <td class="nowrap"><?= e(data_br($pe['data_expediente'])) ?></td>
                    <td>
                        <span title="<?= e($pe['instituicao_nome']) ?>"><?= e($pe['instituicao_sigla'] ?: $pe['instituicao_nome']) ?></span>
                        <?php if ($pe['orgao_nome'] !== null): ?><br><span class="texto-secundario"><?= e($pe['orgao_nome']) ?></span><?php endif; ?>
                    </td>
                    <td class="tabela__texto"><?= e($pe['assunto']) ?></td>
                    <td><span class="badge <?= e(ProcessoExtrajudicialService::classeBadge($pe['situacao'])) ?>"><?= e(ProcessoExtrajudicialService::SITUACOES[$pe['situacao']] ?? $pe['situacao']) ?></span></td>
                    <td class="texto-centro"><?= (int)$pe['total_vinculos'] ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php require APP_ROOT . '/Views/partials/paginacao.php'; ?>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
