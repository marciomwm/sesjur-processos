<?php
declare(strict_types=1);

/**
 * Agentes Publicos: cadastro centralizado das pessoas com vinculo com a
 * Administracao, base para a criacao dos usuarios do sistema.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Perfil;
use App\Repositories\AgentePublicoRepository;
use App\Repositories\CargoRepository;
use App\Repositories\InstituicaoRepository;
use App\Repositories\LotacaoRepository;
use App\Services\AgentePublicoService;

$tipo = strtoupper(entrada('tipo_vinculo', $_GET));
$situacao = strtoupper(entrada('situacao', $_GET));
$acesso = entrada('acesso', $_GET);
$filtros = [
    'busca'          => mb_substr(entrada('busca', $_GET), 0, 150),
    'matricula'      => mb_substr(entrada('matricula', $_GET), 0, 30),
    'instituicao_id' => (string)(entrada_int('instituicao_id') ?? ''),
    'lotacao_id'     => (string)(entrada_int('lotacao_id') ?? ''),
    'cargo_id'       => (string)(entrada_int('cargo_id') ?? ''),
    'tipo_vinculo'   => isset(AgentePublicoService::TIPOS_VINCULO[$tipo]) ? $tipo : '',
    'situacao'       => isset(AgentePublicoService::SITUACOES[$situacao]) ? $situacao : '',
    'acesso'         => in_array($acesso, ['com', 'sem'], true) ? $acesso : '',
];
$pagina    = entrada_int('pagina') ?? 1;
$porPagina = (int)config('app.itens_por_pagina', 20);

$paginacao = (new AgentePublicoRepository())->listar([
    'instituicao_id' => $filtros['instituicao_id'] !== '' ? (int)$filtros['instituicao_id'] : null,
    'lotacao_id'     => $filtros['lotacao_id'] !== '' ? (int)$filtros['lotacao_id'] : null,
    'cargo_id'       => $filtros['cargo_id'] !== '' ? (int)$filtros['cargo_id'] : null,
] + $filtros, $pagina, $porPagina);

$instituicoes = (new InstituicaoRepository())->todos();
$lotacoes     = (new LotacaoRepository())->todos();
$cargos       = (new CargoRepository())->todos();
$query = array_filter($filtros, static fn($v) => $v !== '');

$titulo      = 'Agentes Públicos';
$menuAtivo   = 'agentes';
$breadcrumbs = ['Sistema' => null, 'Agentes Públicos' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Agentes Públicos</h2>
            <p class="card__subtitulo">Pessoas com vínculo com a Administração. Nem todo agente precisa de acesso ao sistema; todo usuário pertence a um agente.</p>
        </div>
        <a class="btn btn--primario btn--sm" href="<?= e(url('novo-agente-publico.php')) ?>">＋ Cadastrar agente público</a>
    </div>
    <div class="card__corpo">
        <form method="get" class="filtros" role="search">
            <div class="campo">
                <label class="campo__rotulo" for="busca">Nome</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>" maxlength="150" placeholder="Nome, CPF ou e-mail">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="matricula">Matrícula</label>
                <input class="input mono" type="search" id="matricula" name="matricula" value="<?= e($filtros['matricula']) ?>" maxlength="30">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="instituicao_id">Órgão</label>
                <select class="select" id="instituicao_id" name="instituicao_id" data-filtro-instituicao="lotacao_id">
                    <option value="">Todos</option>
                    <?php foreach ($instituicoes as $i): ?>
                        <option value="<?= (int)$i['id'] ?>" <?= $filtros['instituicao_id'] === (string)$i['id'] ? 'selected' : '' ?>><?= e($i['sigla'] ?? $i['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="lotacao_id">Unidade Administrativa</label>
                <select class="select" id="lotacao_id" name="lotacao_id">
                    <option value="">Todas</option>
                    <?php foreach ($lotacoes as $l): ?>
                        <option value="<?= (int)$l['id'] ?>" data-instituicao="<?= (int)($l['instituicao_id'] ?? 0) ?>" <?= $filtros['lotacao_id'] === (string)$l['id'] ? 'selected' : '' ?>><?= e($l['rotulo']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="cargo_id">Cargo</label>
                <select class="select" id="cargo_id" name="cargo_id">
                    <option value="">Todos</option>
                    <?php foreach ($cargos as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $filtros['cargo_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="tipo_vinculo">Tipo de vínculo</label>
                <select class="select" id="tipo_vinculo" name="tipo_vinculo">
                    <option value="">Todos</option>
                    <?php foreach (AgentePublicoService::TIPOS_VINCULO as $codigo => $rotulo): ?>
                        <option value="<?= e($codigo) ?>" <?= $filtros['tipo_vinculo'] === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="situacao">Situação</label>
                <select class="select" id="situacao" name="situacao">
                    <option value="">Todas</option>
                    <?php foreach (AgentePublicoService::SITUACOES as $codigo => $rotulo): ?>
                        <option value="<?= e($codigo) ?>" <?= $filtros['situacao'] === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="acesso">Acesso ao sistema</label>
                <select class="select" id="acesso" name="acesso">
                    <?php foreach (['' => 'Todos', 'com' => 'Com usuário', 'sem' => 'Sem usuário'] as $valor => $rotulo): ?>
                        <option value="<?= e($valor) ?>" <?= $filtros['acesso'] === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filtros__acoes">
                <button type="submit" class="btn btn--primario">Filtrar</button>
                <a class="btn btn--ghost" href="<?= e(url('agentes-publicos.php')) ?>">Limpar</a>
            </div>
        </form>
    </div>

    <div class="tabela-wrap">
        <table class="tabela tabela--usuarios">
            <thead>
                <tr><th>Nome</th><th>Matrícula</th><th>Vínculo</th><th>Cargo / Função</th><th>Órgão / Unidade</th><th>Situação</th><th>Usuário</th></tr>
            </thead>
            <tbody>
            <?php if ($paginacao->vazio()): ?>
                <tr><td colspan="7" class="tabela__vazia">Nenhum agente público encontrado com os filtros informados.</td></tr>
            <?php else: foreach ($paginacao->itens as $a): ?>
                <tr class="<?= $a['situacao'] === 'DESLIGADO' ? 'is-inativo' : '' ?>">
                    <td data-rotulo="Nome">
                        <a href="<?= e(url('agente-publico.php?id=' . $a['id'])) ?>"><strong><?= e($a['nome']) ?></strong></a>
                        <?php if ($a['nome_social'] !== null): ?><br><span class="texto-secundario">Nome social: <?= e($a['nome_social']) ?></span><?php endif; ?>
                    </td>
                    <td data-rotulo="Matrícula" class="mono nowrap"><?= ou_traco($a['matricula']) ?></td>
                    <td data-rotulo="Vínculo"><?= e(AgentePublicoService::TIPOS_VINCULO[$a['tipo_vinculo']] ?? $a['tipo_vinculo']) ?></td>
                    <td data-rotulo="Cargo"><?= ou_traco($a['cargo']) ?><?php if ($a['funcao'] !== null): ?><br><span class="texto-secundario"><?= e($a['funcao']) ?></span><?php endif; ?></td>
                    <td data-rotulo="Lotação">
                        <?= e($a['lotacao']) ?>
                        <?php if ($a['instituicao_sigla'] !== null || $a['instituicao_nome'] !== null): ?><br><span class="texto-secundario"><?= e($a['instituicao_sigla'] ?? $a['instituicao_nome']) ?></span><?php endif; ?>
                    </td>
                    <td data-rotulo="Situação"><span class="badge <?= e(AgentePublicoService::classeBadgeSituacao($a['situacao'])) ?>"><?= e(AgentePublicoService::SITUACOES[$a['situacao']] ?? $a['situacao']) ?></span></td>
                    <td data-rotulo="Usuário">
                        <?php if ($a['usuario_id'] !== null): ?>
                            <a class="badge <?= (int)$a['usuario_ativo'] === 1 ? e(Perfil::classeBadge($a['usuario_perfil'])) : 'badge--neutro' ?>" href="<?= e(url('usuario.php?id=' . $a['usuario_id'])) ?>">
                                <?= e(Perfil::rotulo($a['usuario_perfil'])) ?><?= (int)$a['usuario_ativo'] === 1 ? '' : ' · inativo' ?>
                            </a>
                        <?php else: ?>
                            <span class="texto-secundario">Sem acesso</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php require APP_ROOT . '/Views/partials/paginacao.php'; ?>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
