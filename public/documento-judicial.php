<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Repositories\DocumentoJudicialRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Validators\Normalizador;

/*
 * Leitura da íntegra de um documento do processo judicial (sentença, decisão…),
 * importada do PJe. A ficha e o formulário listam os documentos sem o texto,
 * que pode ter centenas de KB; ele é exibido só aqui.
 */

$id = entrada_int('id');
$documento = $id !== null ? (new DocumentoJudicialRepository())->buscarPorId($id) : null;
if ($documento === null) {
    flash('erro', 'Documento não encontrado.');
    redirecionar('processos-judiciais.php');
}

$processo = (new ProcessoJudicialRepository())->buscarPorId((int)$documento['processo_judicial_id']);
if ($processo === null) {
    flash('erro', 'Processo judicial não encontrado.');
    redirecionar('processos-judiciais.php');
}

$rotuloDocumento = trim($documento['documento_id'] . ' - ' . ($documento['tipo'] ?? ''), ' -');
$classificacao = $documento['classificacao'] ?? null;
$metodo = $documento['metodo_extracao'] ?? null;

$titulo      = 'Documento ' . $documento['documento_id'];
$menuAtivo   = 'pj-lista';
$breadcrumbs = [
    'Processos Judiciais'   => 'processos-judiciais.php',
    $processo['numero_cnj'] => 'processo-judicial.php?id=' . (int)$processo['id'],
    'Documento ' . $documento['documento_id'] => null,
];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<div class="ficha__cabecalho">
    <div>
        <h2 class="ficha__numero"><?= e($rotuloDocumento) ?></h2>
        <div class="ficha__meta">
            <?php if ($classificacao): ?>
                <span class="badge badge--azul"><?= e(Normalizador::CLASSIFICACOES_DOCUMENTO[$classificacao] ?? $classificacao) ?></span>
            <?php endif; ?>
            <span class="badge">Processo <?= e($processo['numero_cnj']) ?></span>
            <?php if ((int)($processo['segredo_justica'] ?? 0) === 1): ?>
                <span class="badge badge--vermelho">Segredo de justiça</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="acoes-linha">
        <a class="btn btn--ghost btn--sm" href="<?= e(url('processo-judicial.php?id=' . (int)$processo['id'] . '#secao-documentos')) ?>">← Voltar ao processo</a>
        <button type="button" class="btn btn--secundario btn--sm" onclick="window.print()">Imprimir</button>
    </div>
</div>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Dados do documento</h2></div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao"><span class="definicao__rotulo">Número no PJe</span><span class="definicao__valor mono"><?= e($documento['documento_id']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Espécie</span><span class="definicao__valor"><?= ou_traco($documento['tipo']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Data na cronologia</span><span class="definicao__valor"><?= e(data_br($documento['data_documento'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Juntado por</span><span class="definicao__valor"><?= ou_traco($documento['juntado_por']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Juntado em</span><span class="definicao__valor"><?= e(datahora_br($documento['data_juntada'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Íntegra importada em</span><span class="definicao__valor"><?= e(datahora_br($documento['integra_extraida_em'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Como foi obtida</span>
                <span class="definicao__valor"><?= $metodo ? e(Normalizador::METODOS_EXTRACAO[$metodo] ?? $metodo) : '—' ?></span>
            </div>
        </div>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Íntegra</h2>
            <p class="card__subtitulo">Texto copiado do visualizador do PJe · confira no original antes de citar</p>
        </div>
    </div>
    <div class="card__corpo">
        <?php if ($documento['texto_integral'] === null || $documento['texto_integral'] === ''): ?>
            <p class="texto-secundario mb-0">A íntegra deste documento não foi importada. Refaça a importação no cadastro
                do processo marcando “Extrair a íntegra das peças decisórias”.</p>
        <?php else: ?>
            <div class="integra-leitura"><?= e($documento['texto_integral']) ?></div>
        <?php endif; ?>
    </div>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
