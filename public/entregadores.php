<?php
declare(strict_types=1);

/**
 * Entregadores: pessoas fisicas e juridicas que entregam o objeto (prestam
 * servicos ou fornecem bens) a Administracao. Cadastro e listagem.
 *
 * Vindo dos Dados do Pagamento (?processo_id=N), o entregador cadastrado
 * volta para a ficha do processo ja escolhido como Credor.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\EntregadorRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Services\EntregadorService;

// Retorno para a ficha do processo (somente id de processo existente).
$processoRetornoId = entrada_int('processo_id', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);
$processoRetorno = $processoRetornoId !== null ? (new ProcessoAdministrativoRepository())->buscarPorId($processoRetornoId) : null;

$erros = [];
$dados = ['documento' => '', 'nome' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = ['documento' => entrada('documento'), 'nome' => entrada('nome')];
    try {
        $id = (new EntregadorService())->criar($dados);
        flash('sucesso', 'Entregador cadastrado com sucesso.');
        redirecionar($processoRetorno !== null
            ? 'processo-administrativo.php?id=' . (int)$processoRetorno['id'] . '&credor=' . $id . '#dados-pagamento'
            : 'entregadores.php');
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'entregador.criar');
        $erros['_geral'] = "Não foi possível cadastrar o entregador (código {$codigo}).";
    }
}

$tipo = strtoupper(entrada('tipo_pessoa', $_GET));
$filtros = [
    'busca'       => mb_substr(entrada('busca', $_GET), 0, 150),
    'tipo_pessoa' => isset(EntregadorService::TIPOS[$tipo]) ? $tipo : '',
];
$pagina    = entrada_int('pagina') ?? 1;
$porPagina = (int)config('app.itens_por_pagina', 20);
$paginacao = (new EntregadorRepository())->listar($filtros, $pagina, $porPagina);
$query = array_filter($filtros, static fn($v) => $v !== '');

$titulo      = 'Entregadores';
$menuAtivo   = 'entregadores';
$breadcrumbs = $processoRetorno !== null
    ? ['Processos Administrativos' => 'processos-administrativos.php',
       $processoRetorno['protocolo'] => 'processo-administrativo.php?id=' . (int)$processoRetorno['id'] . '#dados-pagamento',
       'Cadastrar entregador' => null]
    : ['Cadastros' => null, 'Entregadores' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Novo entregador</h2>
            <p class="card__subtitulo">Pessoa física ou jurídica que entrega o objeto à Administração (presta serviços ou fornece bens). É o credor no Pagamento Indenizatório.</p>
        </div>
    </div>
    <div class="card__corpo">
        <?php if ($processoRetorno !== null): ?>
            <div class="alert alert--info"><span class="alert__icone">ℹ</span>
                <span>Após o cadastro, você volta aos Dados do Pagamento do processo
                    <strong class="mono"><?= e($processoRetorno['protocolo']) ?></strong> com o entregador já escolhido como credor.</span></div>
        <?php endif; ?>
        <?php require APP_ROOT . '/Views/partials/form-entregador.php'; ?>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Entregadores cadastrados</h2>
            <p class="card__subtitulo">Em ordem alfabética, com a quantidade de pagamentos em que cada um é o credor.</p>
        </div>
    </div>
    <div class="card__corpo">
        <form method="get" class="filtros" role="search">
            <div class="campo filtros__busca">
                <label class="campo__rotulo" for="busca">Nome ou CPF/CNPJ</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>" maxlength="150" placeholder="Pesquisar">
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="tipo_pessoa">Tipo</label>
                <select class="select" id="tipo_pessoa" name="tipo_pessoa">
                    <option value="">Todos</option>
                    <?php foreach (EntregadorService::TIPOS as $codigo => $rotulo): ?>
                        <option value="<?= e($codigo) ?>" <?= $filtros['tipo_pessoa'] === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filtros__acoes">
                <button type="submit" class="btn btn--primario">Buscar</button>
                <a class="btn btn--ghost" href="<?= e(url('entregadores.php')) ?>">Limpar</a>
            </div>
        </form>
    </div>

    <div class="tabela-wrap">
        <table class="tabela tabela--providencias">
            <thead>
                <tr><th>Nome</th><th>CPF/CNPJ</th><th>Tipo</th><th title="Pagamentos em que é o credor">Pagamentos</th><th class="texto-direita">Ações</th></tr>
            </thead>
            <tbody>
            <?php if ($paginacao->vazio()): ?>
                <tr><td colspan="5" class="tabela__vazia">
                    <?= $query === [] ? 'Nenhum entregador cadastrado.' : 'Nenhum entregador encontrado com os filtros informados.' ?>
                </td></tr>
            <?php else: foreach ($paginacao->itens as $ent): ?>
                <tr>
                    <td data-rotulo="Nome"><strong><?= e($ent['nome']) ?></strong></td>
                    <td data-rotulo="CPF/CNPJ" class="mono nowrap"><?= e(EntregadorService::formatarDocumento($ent['documento'])) ?></td>
                    <td data-rotulo="Tipo"><span class="badge <?= $ent['tipo_pessoa'] === 'JURIDICA' ? 'badge--azul' : 'badge--neutro' ?>"><?= e(EntregadorService::TIPOS[$ent['tipo_pessoa']] ?? $ent['tipo_pessoa']) ?></span></td>
                    <td data-rotulo="Pagamentos" class="texto-centro"><?= (int)$ent['total_pagamentos'] ?></td>
                    <td class="texto-direita nowrap">
                        <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-entregador.php?id=' . (int)$ent['id'])) ?>">Editar</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php require APP_ROOT . '/Views/partials/paginacao.php'; ?>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
