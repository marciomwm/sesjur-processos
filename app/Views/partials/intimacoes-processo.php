<?php
/**
 * Intimacoes Pessoais na ficha de um processo (judicial, extrajudicial ou
 * administrativo), com o atalho de cadastro ja com o processo escolhido.
 * Variaveis: $intimacoes, $processoTipo, $processoId, $retornoIntimacao.
 * Os modais (modais-intimacao.php) e intimacoes.js ficam a cargo da pagina.
 */
use App\Services\IntimacaoPessoalService;

$abertasProcesso = array_filter($intimacoes, static fn(array $i): bool => in_array($i['status'], ['PENDENTE', 'EM_ANDAMENTO'], true));
$vencidasProcesso = array_filter($intimacoes, static fn(array $i): bool => IntimacaoPessoalService::situacao($i) === 'VENCIDA');
?>
<section class="card" id="secao-intimacoes">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Intimações Pessoais</h2>
            <p class="card__subtitulo">
                <?= count($intimacoes) ?> intimação(ões) · <?= count($abertasProcesso) ?> em aberto<?= $vencidasProcesso !== [] ? ' · <strong class="texto-perigo">' . count($vencidasProcesso) . ' vencida(s)</strong>' : '' ?>
                <?php if ($intimacoes !== []): ?>
                    · <a href="<?= e(url('intimacoes-pessoais.php?' . http_build_query(['processo_tipo' => $processoTipo, 'processo_id' => $processoId]))) ?>">ver no painel</a>
                <?php endif; ?>
            </p>
        </div>
        <a class="btn btn--primario btn--sm"
           href="<?= e(url('nova-intimacao.php?' . http_build_query(['processo_tipo' => $processoTipo, 'processo_id' => $processoId, 'retorno' => $retornoIntimacao]))) ?>">＋ Nova intimação</a>
    </div>
    <?php
    $mostrarProcesso = false;
    $vazioIntimacoes = 'Nenhuma intimação pessoal registrada neste processo.';
    require APP_ROOT . '/Views/partials/tabela-intimacoes.php';
    ?>
</section>
