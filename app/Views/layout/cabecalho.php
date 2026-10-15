<?php
/**
 * Cabecalho do layout institucional.
 * Variaveis esperadas: $titulo (string), $breadcrumbs (array<string,?string>), $menuAtivo (string)
 */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Perfil;

$titulo      = $titulo      ?? 'SESJUR';
$breadcrumbs = $breadcrumbs ?? [];
$menuAtivo   = $menuAtivo   ?? '';
$mensagens   = flash_consumir();
$logado      = Auth::usuario();
$ehAdmin     = Auth::ehAdministrador();
?>
<!DOCTYPE html>
<html lang="pt-BR" data-density="<?= e(densidade_interface()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <meta name="base-url" content="<?= e(url('')) ?>">
    <meta name="api-url" content="<?= e(rtrim((string)config('app.api_url'), '/')) ?>">
    <title><?= e($titulo) ?> · <?= e(config('app.nome')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='26' font-size='26'>⚖️</text></svg>">
</head>
<body>
<div class="layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar__marca">
            <span class="sidebar__simbolo"><?= e(mb_substr((string)config('app.nome'), 0, 1)) ?></span>
            <span class="sidebar__titulo">
                <strong><?= e(config('app.nome')) ?></strong>
                <small><?= e(config('app.unidade')) ?></small>
            </span>
        </div>

        <nav class="sidebar__nav">
            <p class="sidebar__grupo">Visão Geral</p>
            <a class="sidebar__item <?= $menuAtivo === 'dashboard' ? 'is-ativo' : '' ?>" href="<?= e(url('index.php')) ?>">
                <span class="sidebar__icone">▦</span> Painel Principal
            </a>
            <a class="sidebar__item <?= $menuAtivo === 'recursos' ? 'is-ativo' : '' ?>" href="<?= e(url('recursos.php')) ?>">
                <span class="sidebar__icone">🔗</span> Recursos
            </a>

            <p class="sidebar__grupo">Processos</p>
            <a class="sidebar__item <?= $menuAtivo === 'pa-lista' ? 'is-ativo' : '' ?>" href="<?= e(url('processos-administrativos.php')) ?>">
                <span class="sidebar__icone">🗂</span> Processos Administrativos
            </a>
            <a class="sidebar__item <?= $menuAtivo === 'pj-lista' ? 'is-ativo' : '' ?>" href="<?= e(url('processos-judiciais.php')) ?>">
                <span class="sidebar__icone">⚖</span> Processos Judiciais
            </a>
            <a class="sidebar__item <?= $menuAtivo === 'bloqueios' ? 'is-ativo' : '' ?>" href="<?= e(url('bloqueios-judiciais.php')) ?>">
                <span class="sidebar__icone">🔒</span> Bloqueios e Depósitos
            </a>
            <a class="sidebar__item <?= $menuAtivo === 'pe-lista' ? 'is-ativo' : '' ?>" href="<?= e(url('processos-extrajudiciais.php')) ?>">
                <span class="sidebar__icone">🏛</span> Processos Extrajudiciais
            </a>
            <a class="sidebar__item <?= $menuAtivo === 'acoes' ? 'is-ativo' : '' ?>" href="<?= e(url('acoes.php')) ?>">
                <span class="sidebar__icone">☑</span> Tarefas
            </a>
            <a class="sidebar__item <?= $menuAtivo === 'solicitacoes' ? 'is-ativo' : '' ?>" href="<?= e(url('solicitacoes.php')) ?>">
                <span class="sidebar__icone">✉</span> Solicitações
            </a>
            <a class="sidebar__item <?= $menuAtivo === 'anotacoes' ? 'is-ativo' : '' ?>" href="<?= e(url('anotacoes.php')) ?>">
                <span class="sidebar__icone">✎</span> Anotações
            </a>
            <a class="sidebar__item <?= $menuAtivo === 'expediente' ? 'is-ativo' : '' ?>" href="<?= e(url('expediente.php')) ?>">
                <span class="sidebar__icone">📝</span> Gerador de Expedientes
            </a>

            <p class="sidebar__grupo">Cadastros</p>
            <a class="sidebar__item <?= $menuAtivo === 'pa-novo' ? 'is-ativo' : '' ?>" href="<?= e(url('novo-processo-administrativo.php')) ?>">
                <span class="sidebar__icone">＋</span> Novo Processo Administrativo
            </a>
            <a class="sidebar__item <?= $menuAtivo === 'pj-novo' ? 'is-ativo' : '' ?>" href="<?= e(url('novo-processo-judicial.php')) ?>">
                <span class="sidebar__icone">＋</span> Novo Processo Judicial
            </a>
            <a class="sidebar__item <?= $menuAtivo === 'pe-novo' ? 'is-ativo' : '' ?>" href="<?= e(url('novo-processo-extrajudicial.php')) ?>">
                <span class="sidebar__icone">＋</span> Novo Processo Extrajudicial
            </a>

            <?php if ($ehAdmin): ?>
                <p class="sidebar__grupo">Sistema</p>
                <a class="sidebar__item <?= $menuAtivo === 'agentes' ? 'is-ativo' : '' ?>" href="<?= e(url('agentes-publicos.php')) ?>">
                    <span class="sidebar__icone">🧑‍💼</span> Agentes Públicos
                </a>
                <a class="sidebar__item <?= $menuAtivo === 'usuarios' ? 'is-ativo' : '' ?>" href="<?= e(url('usuarios.php')) ?>">
                    <span class="sidebar__icone">👤</span> Usuários
                </a>
                <a class="sidebar__item <?= $menuAtivo === 'prioridades' ? 'is-ativo' : '' ?>" href="<?= e(url('prioridades.php')) ?>">
                    <span class="sidebar__icone">⚑</span> Prioridades
                </a>
                <a class="sidebar__item <?= $menuAtivo === 'cargos' ? 'is-ativo' : '' ?>" href="<?= e(url('tabela-apoio.php?tipo=cargos')) ?>">
                    <span class="sidebar__icone">🏷</span> Cargos
                </a>
                <a class="sidebar__item <?= $menuAtivo === 'unidades' ? 'is-ativo' : '' ?>" href="<?= e(url('unidades-administrativas.php')) ?>">
                    <span class="sidebar__icone">🏢</span> Unidades Administrativas
                </a>
                <a class="sidebar__item <?= $menuAtivo === 'instituicoes' ? 'is-ativo' : '' ?>" href="<?= e(url('tabela-apoio.php?tipo=instituicoes')) ?>">
                    <span class="sidebar__icone">🏛</span> Instituições
                </a>
                <a class="sidebar__item <?= $menuAtivo === 'niveis' ? 'is-ativo' : '' ?>" href="<?= e(url('tabela-apoio.php?tipo=niveis')) ?>">
                    <span class="sidebar__icone">≣</span> Níveis Organizacionais
                </a>
                <a class="sidebar__item <?= $menuAtivo === 'instituicoes-externas' ? 'is-ativo' : '' ?>" href="<?= e(url('tabela-apoio.php?tipo=instituicoes_externas')) ?>">
                    <span class="sidebar__icone">⌂</span> Instituições Externas
                </a>
                <a class="sidebar__item <?= $menuAtivo === 'config' ? 'is-ativo' : '' ?>" href="<?= e(url('configuracoes.php')) ?>">
                    <span class="sidebar__icone">⚙</span> Configurações
                </a>
            <?php endif; ?>

            <?php if ($logado !== null): ?>
                <p class="sidebar__grupo">Minha conta</p>
                <a class="sidebar__item <?= $menuAtivo === 'meu-cadastro' ? 'is-ativo' : '' ?>" href="<?= e(url('usuario.php?id=' . (int)$logado['id'])) ?>">
                    <span class="sidebar__icone">🪪</span> Meu cadastro
                </a>
                <a class="sidebar__item <?= $menuAtivo === 'senha' ? 'is-ativo' : '' ?>" href="<?= e(url('alterar-senha.php')) ?>">
                    <span class="sidebar__icone">🔑</span> Alterar senha
                </a>
            <?php endif; ?>
        </nav>

        <p class="sidebar__nota">
            <strong><?= e(config('app.nome')) ?></strong> reúne processos, providências, unidades e recursos da equipe em um só lugar.
        </p>
    </aside>

    <div class="conteudo">
        <header class="header">
            <button class="header__menu" type="button" id="btnMenu" aria-label="Alternar menu">☰</button>
            <div class="header__texto">
                <h1 class="header__titulo"><?= e($titulo) ?></h1>
                <?php if ($breadcrumbs !== []): ?>
                    <nav class="breadcrumbs" aria-label="Trilha de navegação">
                        <?php $ultimo = array_key_last($breadcrumbs); ?>
                        <?php foreach ($breadcrumbs as $rotulo => $href): ?>
                            <?php if ($href !== null && $rotulo !== $ultimo): ?>
                                <a href="<?= e(url($href)) ?>"><?= e($rotulo) ?></a>
                            <?php else: ?>
                                <span class="breadcrumbs__atual"><?= e($rotulo) ?></span>
                            <?php endif; ?>
                            <?php if ($rotulo !== $ultimo): ?>
                                <span class="breadcrumbs__sep">/</span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
            </div>
            <?php if ($logado !== null): ?>
                <form class="pesquisa-geral" method="get" action="<?= e(url('pesquisa.php')) ?>" role="search" data-pesquisa-geral>
                    <label class="sr-only" for="pesquisaGeral">Pesquisar no SESJUR</label>
                    <span class="pesquisa-geral__icone" aria-hidden="true">⌕</span>
                    <input class="pesquisa-geral__campo" type="search" id="pesquisaGeral" name="q" autocomplete="off"
                           value="<?= e($menuAtivo === 'pesquisa' ? ($termoPesquisa ?? '') : '') ?>"
                           placeholder="Pesquisar protocolo, CNJ, parte, tarefa…"
                           aria-autocomplete="list" aria-controls="pesquisaGeralSugestoes" aria-expanded="false">
                    <kbd class="pesquisa-geral__atalho" title="Atalho: tecla /">/</kbd>
                    <div class="pesquisa-geral__sugestoes" id="pesquisaGeralSugestoes" role="listbox" hidden></div>
                </form>
            <?php endif; ?>
            <div class="header__acoes">
                <span class="chip chip--extensao" id="statusExtensao" title="Situação da extensão do Chrome">
                    <span class="chip__ponto"></span> Extensão: verificando…
                </span>
                <?php if ($logado !== null): ?>
                    <details class="menu-usuario">
                        <summary class="menu-usuario__botao" aria-label="Menu do usuário">
                            <span class="avatar avatar--sm" aria-hidden="true"><?= e(iniciais($logado['nome'])) ?></span>
                            <span class="menu-usuario__nome">
                                <strong><?= e($logado['nome']) ?></strong>
                                <small><?= e(Perfil::rotulo($logado['perfil'])) ?></small>
                            </span>
                        </summary>
                        <div class="menu-usuario__painel">
                            <a href="<?= e(url('usuario.php?id=' . (int)$logado['id'])) ?>">Meu cadastro</a>
                            <a href="<?= e(url('alterar-senha.php')) ?>">Alterar senha</a>
                            <div class="densidade" role="group" aria-labelledby="densidadeRotulo">
                                <span class="densidade__rotulo" id="densidadeRotulo">Densidade da tela</span>
                                <div class="densidade__opcoes">
                                    <?php // Variaveis proprias: o layout e incluido no escopo da pagina ($modo, $rotulo...). ?>
                                    <?php foreach (['compact' => 'Compacta', 'comfortable' => 'Confortável'] as $modoDensidade => $rotuloDensidade): ?>
                                        <button type="button" class="densidade__opcao" data-densidade="<?= e($modoDensidade) ?>"
                                                aria-pressed="<?= densidade_interface() === $modoDensidade ? 'true' : 'false' ?>"><?= e($rotuloDensidade) ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <form method="post" action="<?= e(url('logout.php')) ?>">
                                <?= Csrf::campo() ?>
                                <button type="submit">Sair do sistema</button>
                            </form>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        </header>

        <main class="main">
            <?php foreach ($mensagens as $msg): ?>
                <div class="alert alert--<?= e($msg['tipo']) ?>" role="alert">
                    <span class="alert__icone"><?= $msg['tipo'] === 'sucesso' ? '✓' : ($msg['tipo'] === 'erro' ? '✕' : 'ℹ') ?></span>
                    <span><?= e($msg['mensagem']) ?></span>
                    <button type="button" class="alert__fechar" aria-label="Fechar">×</button>
                </div>
            <?php endforeach; ?>
