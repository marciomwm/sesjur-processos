<?php
declare(strict_types=1);

/**
 * Configuracoes do Sistema: area administrativa centralizada, em guias
 *   configuracoes.php?aba=usuarios | extensao | procedimentos | prioridades
 *
 * Somente administradores: verificado aqui e novamente em cada servico.
 * Cada guia reaproveita os repositorios, servicos e endpoints ja existentes
 * (novo-usuario.php, alterar-status-*.php, editar-*.php); as antigas
 * usuarios.php e prioridades.php redirecionam para a guia correspondente.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\CargoRepository;
use App\Repositories\LotacaoRepository;
use App\Repositories\PrioridadeRepository;
use App\Repositories\ProcedimentoRepository;
use App\Repositories\UsuarioRepository;
use App\Services\ConfiguracaoService;
use App\Services\PrioridadeService;
use App\Services\ProcedimentoService;

$abas = [
    'usuarios'      => ['rotulo' => 'Usuários',      'icone' => '👤'],
    'extensao'      => ['rotulo' => 'Extensão',      'icone' => '🧩'],
    'procedimentos' => ['rotulo' => 'Procedimentos', 'icone' => '📋'],
    'prioridades'   => ['rotulo' => 'Prioridades',   'icone' => '⚑'],
];
$aba = entrada('aba', $_GET);
if (!isset($abas[$aba])) {
    $aba = 'usuarios';
}

$erros = [];
$porPagina = (int)config('app.itens_por_pagina', 20);
$pagina    = entrada_int('pagina') ?? 1;
$status    = entrada('status', $_GET);
$statusTabela = in_array($status, ['ativos', 'inativos', 'todos'], true) ? $status : 'todos';

// ---------------------------------------------------------------------
// Gravações (POST na própria guia, com CSRF). Sucesso: redireciona (PRG).
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    try {
        switch ($aba) {
            case 'extensao':
                $alterou = ConfiguracaoService::salvarIntegracao([
                    'sigadoc_hosts'        => (string)($_POST['sigadoc_hosts'] ?? ''),
                    'pje_hosts'            => (string)($_POST['pje_hosts'] ?? ''),
                    'exigir_https'         => isset($_POST['exigir_https']),
                    'sigadoc_url_template' => (string)($_POST['sigadoc_url_template'] ?? ''),
                ]);
                flash($alterou ? 'sucesso' : 'info', $alterou
                    ? 'Configurações salvas. Sincronize a allowlist com a extensão para que os novos domínios sejam aceitos.'
                    : 'Nenhuma alteração para salvar.');
                break;
            case 'procedimentos':
                $dados = ['nome' => entrada('nome'), 'descricao' => (string)($_POST['descricao'] ?? '')];
                (new ProcedimentoService())->criar($dados);
                flash('sucesso', 'Procedimento “' . trim(preg_replace('/\s+/u', ' ', $dados['nome']) ?? '') . '” cadastrado com sucesso.');
                break;
            case 'prioridades':
                $dados = ['nome' => entrada('nome'), 'nivel' => entrada('nivel'), 'descricao' => (string)($_POST['descricao'] ?? '')];
                (new PrioridadeService())->criar($dados);
                flash('sucesso', 'Prioridade “' . trim($dados['nome']) . '” cadastrada com sucesso.');
                break;
            default:
                flash('erro', 'Operação não disponível nesta guia.');
        }
        redirecionar('configuracoes.php?aba=' . $aba);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'configuracoes.' . $aba);
        $erros['_geral'] = "Não foi possível salvar. Se o problema persistir, informe o código {$codigo} ao suporte.";
    }
}

// ---------------------------------------------------------------------
// Dados da guia ativa. $query guarda os filtros (paginação e retorno).
// ---------------------------------------------------------------------
$query = ['aba' => $aba];
$erroCarga = null;
$scripts = [];

try {
    switch ($aba) {
        case 'usuarios':
            $statusUsuario = array_key_exists($status, UsuarioRepository::STATUS) ? $status : 'ativos';
            $filtros = [
                'busca'      => mb_substr(entrada('busca', $_GET), 0, 150),
                'cargo_id'   => entrada_int('cargo_id'),
                'lotacao_id' => entrada_int('lotacao_id'),
                'perfil'     => Perfil::valido(entrada('perfil', $_GET)) ? entrada('perfil', $_GET) : '',
                'status'     => $statusUsuario,
            ];
            $paginacao = (new UsuarioRepository())->listar($filtros, $pagina, $porPagina);
            $cargos    = (new CargoRepository())->todos();
            $lotacoes  = (new LotacaoRepository())->todos();
            $query += array_filter($filtros, static fn($v) => $v !== null && $v !== '');
            break;

        case 'extensao':
            $conexao = Database::testar();
            $diagnostico = ConfiguracaoService::diagnostico();
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Reexibe o que foi digitado quando a validação falha.
                $diagnostico['sigadoc_hosts'] = ConfiguracaoService::normalizarListaHosts((string)($_POST['sigadoc_hosts'] ?? ''));
                $diagnostico['pje_hosts'] = ConfiguracaoService::normalizarListaHosts((string)($_POST['pje_hosts'] ?? ''));
                $diagnostico['exigir_https'] = isset($_POST['exigir_https']);
                $diagnostico['sigadoc_url_template'] = trim((string)($_POST['sigadoc_url_template'] ?? ''));
            }
            $versaoDistribuida = ConfiguracaoService::versaoExtensaoDistribuida();
            $historico = (new AuditoriaRepository())->listarPorEntidadeSemId(ConfiguracaoService::ENTIDADE, 20);
            $scripts[] = 'configuracoes.js';
            break;

        case 'procedimentos':
            $filtros = [
                'busca'   => mb_substr(entrada('busca', $_GET), 0, 150),
                'status'  => $statusTabela,
                'direcao' => entrada('direcao', $_GET) === 'desc' ? 'desc' : 'asc',
            ];
            $paginacao = (new ProcedimentoRepository())->listar($filtros, $pagina, $porPagina);
            $query += array_filter($filtros, static fn($v) => $v !== '' && $v !== 'todos' && $v !== 'asc');
            $dados = $_SERVER['REQUEST_METHOD'] === 'POST'
                ? ['nome' => entrada('nome'), 'descricao' => (string)($_POST['descricao'] ?? '')]
                : ['nome' => '', 'descricao' => ''];
            break;

        case 'prioridades':
            $filtros = [
                'busca'  => mb_substr(entrada('busca', $_GET), 0, 150),
                'nivel'  => (string)(PrioridadeService::nivel(entrada('nivel', $_GET)) ?? ''),
                'status' => $statusTabela,
            ];
            $paginacao = (new PrioridadeRepository())->listar([
                'busca'  => $filtros['busca'],
                'nivel'  => $filtros['nivel'] !== '' ? (int)$filtros['nivel'] : null,
                'status' => $filtros['status'],
            ], $pagina, $porPagina);
            $query += array_filter($filtros, static fn($v) => $v !== '' && $v !== 'todos');
            $dados = $_SERVER['REQUEST_METHOD'] === 'POST'
                ? ['nome' => entrada('nome'), 'nivel' => entrada('nivel'), 'descricao' => (string)($_POST['descricao'] ?? '')]
                : ['nome' => '', 'nivel' => '', 'descricao' => ''];
            break;
    }
} catch (Throwable $e) {
    $codigo = log_erro($e, 'configuracoes.' . $aba . '.listar');
    $erroCarga = "Não foi possível carregar esta guia. Se o problema persistir, informe o código {$codigo} ao suporte.";
}
if ($pagina > 1) {
    $query['pagina'] = $pagina;
}
/** Endereço da guia com filtros (sempre interno). */
$urlAba = static fn(array $params = []): string => url('configuracoes.php?' . http_build_query(['aba' => $aba] + $params));

$titulo      = 'Configurações do Sistema';
$menuAtivo   = 'config';
$breadcrumbs = ['Sistema' => null, 'Configurações' => 'configuracoes.php', $abas[$aba]['rotulo'] => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<section class="config-cabecalho">
    <div>
        <h2 class="config-cabecalho__titulo">Configurações do Sistema</h2>
        <p class="config-cabecalho__descricao">Gerencie usuários, integrações, procedimentos e critérios de prioridade do SESJUR.</p>
    </div>
</section>

<nav class="abas config-abas" aria-label="Guias de configuração">
    <?php foreach ($abas as $chave => $info): ?>
        <a class="abas__item <?= $chave === $aba ? 'is-ativo' : '' ?>" href="<?= e(url('configuracoes.php?aba=' . $chave)) ?>"
           <?= $chave === $aba ? 'aria-current="page"' : '' ?>>
            <span aria-hidden="true"><?= $info['icone'] ?></span> <?= e($info['rotulo']) ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<div class="config-painel" id="painel-<?= e($aba) ?>">
    <?php if ($erroCarga !== null): ?>
        <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erroCarga) ?></span></div>
    <?php else: ?>
        <?php require APP_ROOT . '/Views/configuracoes/' . $aba . '.php'; ?>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
