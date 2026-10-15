<?php
declare(strict_types=1);

/**
 * Intimacoes Pessoais: painel de indicadores, filtros combinaveis e a
 * tabela de acompanhamento (abertas primeiro, do prazo mais antigo ao mais
 * distante). Os indicadores contam todas as intimacoes nao excluidas e,
 * clicados, aplicam o filtro correspondente.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores e gestores.
App\Services\IntimacaoPessoalService::exigirAcesso();

use App\Repositories\AgentePublicoRepository;
use App\Repositories\IntimacaoPessoalRepository;
use App\Services\IntimacaoPessoalService as Intimacoes;
use App\Validators\Normalizador;

$data = static fn(string $chave): string => Normalizador::dataIso(entrada($chave, $_GET) ?: null) ?? '';
$filtros = [
    'busca'         => mb_substr(entrada('busca', $_GET), 0, 100),
    'tipo'          => isset(Intimacoes::TIPOS_PROCESSO[entrada('tipo', $_GET)]) ? entrada('tipo', $_GET) : '',
    'status'        => isset(Intimacoes::STATUS[entrada('status', $_GET)]) ? entrada('status', $_GET) : '',
    'situacao'      => isset(Intimacoes::SITUACOES[entrada('situacao', $_GET)]) ? entrada('situacao', $_GET) : '',
    'agente_id'     => (string)(entrada_int('agente_id') ?? ''),
    'processo_tipo' => isset(Intimacoes::TIPOS_PROCESSO[entrada('processo_tipo', $_GET)]) ? entrada('processo_tipo', $_GET) : '',
    'processo_id'   => (string)(entrada_int('processo_id') ?? ''),
    'recebido_de'   => $data('recebido_de'),
    'recebido_ate'  => $data('recebido_ate'),
    'prazo_de'      => $data('prazo_de'),
    'prazo_ate'     => $data('prazo_ate'),
    'ordenar'       => in_array(entrada('ordenar', $_GET), ['prazo', 'prazo_desc', 'recebimento', 'recentes'], true) ? entrada('ordenar', $_GET) : '',
];

$processo = $filtros['processo_tipo'] !== '' && $filtros['processo_id'] !== ''
    ? Intimacoes::descreverProcesso($filtros['processo_tipo'], (int)$filtros['processo_id']) : null;
if ($processo === null) {
    $filtros['processo_tipo'] = $filtros['processo_id'] = '';
}
$agente = $filtros['agente_id'] !== '' ? (new AgentePublicoRepository())->buscarPorId((int)$filtros['agente_id']) : null;
if ($agente === null) {
    $filtros['agente_id'] = '';
}

$hoje = date('Y-m-d');
$repo = new IntimacaoPessoalRepository();
$paginacao = $repo->listar($filtros + [
    'hoje' => $hoje, 'limite_alerta' => Intimacoes::limiteAlerta($hoje),
], entrada_int('pagina') ?? 1, (int)config('app.itens_por_pagina', 20));
$ind = $repo->indicadores($hoje, Intimacoes::limiteAlerta($hoje));

$query = array_filter($filtros, static fn($v) => $v !== '');
$retornoIntimacao = 'intimacoes-pessoais.php' . ($query !== [] ? '?' . http_build_query($query) : '');
$filtrado = array_diff_key($query, ['ordenar' => 1]) !== [];

/** Cartao de indicador; clicado, filtra a tabela (o filtro ativo fica marcado). */
$cartao = static function (string $rotulo, int $valor, string $nota, array $filtro, string $classe = '') use ($filtros): void {
    $ativo = $filtro !== [] && array_intersect_assoc($filtro, $filtros) === $filtro
        && count(array_filter(array_diff_key($filtros, $filtro + ['ordenar' => 1]), static fn($v) => $v !== '')) === 0;
    $href = url('intimacoes-pessoais.php' . ($filtro !== [] ? '?' . http_build_query($filtro) : ''));
    ?>
    <a class="indicador <?= e($classe) ?> <?= $ativo ? 'is-ativo' : '' ?>" href="<?= e($href) ?>" <?= $ativo ? 'aria-current="true"' : '' ?>>
        <span class="indicador__rotulo"><?= e($rotulo) ?></span>
        <span class="indicador__valor"><?= $valor ?></span>
        <span class="indicador__nota"><?= e($nota) ?></span>
    </a>
    <?php
};

$titulo      = 'Intimações Pessoais';
$menuAtivo   = 'intimacoes';
$breadcrumbs = ['Processos' => null, 'Intimações Pessoais' => null];
$scripts     = ['modal.js', 'intimacoes.js'];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<div class="cabecalho-pagina">
    <div class="cabecalho-pagina__texto">
        <p class="sobrelinha">Acompanhamento de prazos</p>
        <h2 class="cabecalho-pagina__titulo">Intimações pessoais de agentes públicos</h2>
        <p class="cabecalho-pagina__descricao">Intimações dirigidas a agentes públicos nos processos judiciais, extrajudiciais e administrativos
            cadastrados. O status é informado; a situação do prazo é calculada a cada consulta.</p>
    </div>
    <a class="btn btn--primario btn--destaque" href="<?= e(url('nova-intimacao.php')) ?>">＋ Nova Intimação</a>
</div>

<section class="indicadores indicadores--intimacoes" aria-label="Indicadores (clique para filtrar)">
    <?php
    $cartao('Total cadastrado', $ind['total'], $ind['canceladas'] . ' cancelada(s)', [], 'indicador--judicial');
    $cartao('Pendentes', $ind['pendentes'], 'Aguardando providência', ['status' => 'PENDENTE'], 'indicador--designados');
    $cartao('Em andamento', $ind['em_andamento'], 'Providência iniciada', ['status' => 'EM_ANDAMENTO']);
    $cartao('Próximas do vencimento', $ind['proximas'], 'Prazo em até ' . Intimacoes::DIAS_ALERTA . ' dias', ['situacao' => 'PROXIMA'], $ind['proximas'] > 0 ? 'indicador--atencao' : '');
    $cartao('Vencidas', $ind['vencidas'], 'Em aberto com o prazo expirado', ['situacao' => 'VENCIDA'], $ind['vencidas'] > 0 ? 'indicador--alerta' : '');
    $cartao('Cumpridas', $ind['cumpridas'], 'Com data de cumprimento', ['status' => 'CUMPRIDA'], 'indicador--acoes');
    $cartao('Cumpridas fora do prazo', $ind['fora_prazo'], 'Cumprimento após o prazo', ['situacao' => 'CUMPRIDA_FORA_PRAZO'], $ind['fora_prazo'] > 0 ? 'indicador--alerta' : '');
    ?>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Intimações</h2>
            <p class="card__subtitulo">
                <?php if ($processo !== null): ?>
                    Do processo <a class="mono" href="<?= e(url(Intimacoes::urlProcesso($processo['tipo'], $processo['id']))) ?>"><?= e($processo['rotulo']) ?></a>
                    (<?= e(Intimacoes::TIPOS_PROCESSO[$processo['tipo']]) ?>) ·
                <?php endif; ?>
                Abertas primeiro, da mais urgente à mais distante · alteradas por administradores, gestores ou por quem as registrou
            </p>
        </div>
    </div>
    <div class="card__corpo">
        <form method="get" class="filtros filtros--intimacoes">
            <?php if ($processo !== null): ?>
                <input type="hidden" name="processo_tipo" value="<?= e($processo['tipo']) ?>">
                <input type="hidden" name="processo_id" value="<?= (int)$processo['id'] ?>">
            <?php endif; ?>
            <div class="campo filtros__busca">
                <label class="campo__rotulo" for="busca">Pesquisa</label>
                <input class="input" type="search" id="busca" name="busca" value="<?= e($filtros['busca']) ?>"
                       placeholder="Processo, agente, matrícula, documento ou observação">
            </div>
            <?php
            $ac = [
                'id' => 'filtro-agente', 'nome' => 'agente_id', 'rotulo' => 'Agente público',
                'endpoint' => 'api/pesquisar-agentes-publicos.php', 'minimo' => 2, 'placeholder' => 'Intimado ou recebedor…',
                'ajuda' => 'Como intimado ou como recebedor.', 'vazio' => 'Nenhum agente público encontrado.',
                'valor' => $agente !== null ? Intimacoes::descreverAgente($agente) : null,
            ];
            require APP_ROOT . '/Views/partials/campo-pesquisa.php';
            ?>
            <div class="campo">
                <label class="campo__rotulo" for="tipo">Tipo de processo</label>
                <select class="select" id="tipo" name="tipo">
                    <option value="">Todos</option>
                    <?php foreach (Intimacoes::TIPOS_PROCESSO as $codigo => $rotulo): ?>
                        <option value="<?= e($codigo) ?>" <?= $filtros['tipo'] === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="status">Status</label>
                <select class="select" id="status" name="status">
                    <option value="">Todos</option>
                    <?php foreach (Intimacoes::STATUS as $codigo => $rotulo): ?>
                        <option value="<?= e($codigo) ?>" <?= $filtros['status'] === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label class="campo__rotulo" for="situacao">Situação do prazo</label>
                <select class="select" id="situacao" name="situacao">
                    <option value="">Todas</option>
                    <?php foreach (Intimacoes::SITUACOES as $codigo => $rotulo): ?>
                        <option value="<?= e($codigo) ?>" <?= $filtros['situacao'] === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <fieldset class="campo campo--periodo">
                <legend class="campo__rotulo">Recebimento</legend>
                <span class="periodo">
                    <input class="input" type="date" name="recebido_de" value="<?= e($filtros['recebido_de']) ?>" aria-label="Recebimento a partir de">
                    <span aria-hidden="true">a</span>
                    <input class="input" type="date" name="recebido_ate" value="<?= e($filtros['recebido_ate']) ?>" aria-label="Recebimento até">
                </span>
            </fieldset>
            <fieldset class="campo campo--periodo">
                <legend class="campo__rotulo">Vencimento (prazo)</legend>
                <span class="periodo">
                    <input class="input" type="date" name="prazo_de" value="<?= e($filtros['prazo_de']) ?>" aria-label="Prazo a partir de">
                    <span aria-hidden="true">a</span>
                    <input class="input" type="date" name="prazo_ate" value="<?= e($filtros['prazo_ate']) ?>" aria-label="Prazo até">
                </span>
            </fieldset>
            <div class="campo">
                <label class="campo__rotulo" for="ordenar">Ordenar por</label>
                <select class="select" id="ordenar" name="ordenar">
                    <?php foreach (['' => 'Prioridade (vencidas primeiro)', 'prazo' => 'Prazo — mais próximo', 'prazo_desc' => 'Prazo — mais distante',
                                    'recebimento' => 'Recebimento — mais recente', 'recentes' => 'Registro — mais recente'] as $valor => $rotulo): ?>
                        <option value="<?= e($valor) ?>" <?= $filtros['ordenar'] === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filtros__acoes">
                <button type="submit" class="btn btn--primario">Filtrar</button>
                <a class="btn btn--ghost" href="<?= e(url('intimacoes-pessoais.php')) ?>">Limpar</a>
            </div>
        </form>
    </div>

    <?php if ($ind['total'] === 0): ?>
        <div class="estado-vazio">
            <span class="estado-vazio__icone" aria-hidden="true">📨</span>
            <b>Nenhuma intimação pessoal registrada ainda</b>
            <p>Registre a primeira intimação de um agente público. Os indicadores e prazos aparecem aqui assim que houver registros.</p>
            <a class="btn btn--primario btn--sm" href="<?= e(url('nova-intimacao.php')) ?>">＋ Nova Intimação</a>
        </div>
    <?php else: ?>
        <?php
        $intimacoes = $paginacao->itens;
        $mostrarProcesso = true;
        $vazioIntimacoes = $filtrado ? 'Nenhuma intimação atende aos filtros escolhidos.' : 'Nenhuma intimação registrada.';
        require APP_ROOT . '/Views/partials/tabela-intimacoes.php';
        ?>
        <?php $query = $filtros; require APP_ROOT . '/Views/partials/paginacao.php'; ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/Views/partials/modais-intimacao.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
