<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Repositories\AssuntoProcessualRepository;
use App\Repositories\BloqueioJudicialRepository;
use App\Repositories\DocumentoJudicialRepository;
use App\Repositories\GuiaDepositoRepository;
use App\Repositories\IntimacaoPessoalRepository;
use App\Repositories\MovimentacaoJudicialRepository;
use App\Repositories\ParteProcessualRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Repositories\VinculoRepository;
use App\Services\IntimacaoPessoalService;

$id = entrada_int('id');
if ($id === null) {
    flash('erro', 'Processo judicial não informado.');
    redirecionar('processos-judiciais.php');
}

$registro = (new ProcessoJudicialRepository())->buscarPorId($id);
if ($registro === null) {
    flash('erro', 'Processo judicial não encontrado.');
    redirecionar('processos-judiciais.php');
}

$partes        = (new ParteProcessualRepository())->listarPorProcesso($id);
$assuntos      = (new AssuntoProcessualRepository())->listarPorProcesso($id);
$relacionados  = (new VinculoRepository())->administrativosDoJudicial($id);
$movimentacoes = (new MovimentacaoJudicialRepository())->listarPorProcesso($id);
$documentos    = (new DocumentoJudicialRepository())->listarPorProcesso($id);
$documentosPorMovimentacao = agrupar_por_movimentacao($documentos);
$lancamentos   = (new BloqueioJudicialRepository())->listarPorProcesso($id);
$guias         = (new GuiaDepositoRepository())->listarPorProcesso($id);
$intimacoes    = IntimacaoPessoalService::podeAcessar() ? (new IntimacaoPessoalRepository())->listarPorProcesso('JUDICIAL', $id) : null;
$retornoIntimacao = 'processo-judicial.php?id=' . $id;

$polos = ['ATIVO' => 'Polo ativo', 'PASSIVO' => 'Polo passivo', 'TERCEIRO' => 'Outros interessados', 'OUTRO' => 'Outros'];

/**
 * Sinalizador tri-estado: "Não informado" nao equivale a "Nao".
 * O driver devolve TINYINT como int, entao o parametro nao e tipado.
 */
$sinalizador = static fn(mixed $valor): string => $valor === null ? 'Não informado' : ((int)$valor === 1 ? 'Sim' : 'Não');

$titulo      = 'Processo Judicial';
$menuAtivo   = 'pj-lista';
$breadcrumbs = ['Processos Judiciais' => 'processos-judiciais.php', $registro['numero_cnj'] => null];
$scripts     = ['modal.js', 'bloqueios.js', 'intimacoes.js'];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<div class="ficha__cabecalho">
    <div>
        <h2 class="ficha__numero">
            <?php if ($registro['classe_abreviada']): ?><span class="ficha__sigla"><?= e($registro['classe_abreviada']) ?></span><?php endif; ?>
            <?= e($registro['numero_cnj']) ?>
        </h2>
        <?php if ($registro['resumo_partes']): ?>
            <p class="ficha__resumo"><?= e($registro['resumo_partes']) ?></p>
        <?php endif; ?>
        <div class="ficha__meta">
            <span class="badge badge--azul">Processo Judicial</span>
            <span class="badge <?= $registro['origem'] === 'PJE' ? 'badge--verde' : 'badge--neutro' ?>">Origem: <?= e($registro['origem']) ?></span>
            <?php if ($registro['situacao']): ?><span class="badge"><?= e($registro['situacao']) ?></span><?php endif; ?>
            <?php if ((int)($registro['segredo_justica'] ?? 0) === 1): ?>
                <span class="badge badge--vermelho">Segredo de justiça</span>
            <?php endif; ?>
            <span class="badge"><?= count($relacionados) ?> processo(s) administrativo(s) vinculado(s)</span>
        </div>
    </div>
    <div class="acoes-linha">
        <?php if ($registro['url_origem']): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e($registro['url_origem']) ?>" target="_blank" rel="noopener noreferrer">Abrir no PJe ↗</a>
        <?php endif; ?>
        <a class="btn btn--secundario btn--sm" href="<?= e(url('editar-processo-judicial.php?id=' . $id)) ?>">✎ Editar</a>
        <form method="post" action="<?= e(url('excluir-processo-judicial.php')) ?>"
              data-confirmar="Excluir o processo judicial <?= e($registro['numero_cnj']) ?>? Partes e assuntos serão removidos. Os processos administrativos vinculados NÃO serão excluídos."
              style="display:inline">
            <?= Csrf::campo() ?>
            <input type="hidden" name="id" value="<?= e((string)$id) ?>">
            <button type="submit" class="btn btn--perigo btn--sm">🗑 Excluir</button>
        </form>
    </div>
</div>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Identificação</h2></div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao"><span class="definicao__rotulo">Número CNJ</span><span class="definicao__valor mono"><?= e($registro['numero_cnj']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Tribunal</span><span class="definicao__valor"><?= ou_traco($registro['tribunal']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Data de autuação</span><span class="definicao__valor"><?= e(data_br($registro['data_autuacao'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Última distribuição</span><span class="definicao__valor"><?= e(data_br($registro['data_distribuicao'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Situação processual</span><span class="definicao__valor"><?= ou_traco($registro['situacao']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Segredo de justiça</span>
                <span class="definicao__valor"><?= $sinalizador($registro['segredo_justica']) ?></span>
            </div>
            <div class="definicao"><span class="definicao__rotulo">Justiça gratuita</span>
                <span class="definicao__valor"><?= $sinalizador($registro['justica_gratuita']) ?></span>
            </div>
            <div class="definicao"><span class="definicao__rotulo">Tutela / liminar</span>
                <span class="definicao__valor"><?= $sinalizador($registro['tutela_liminar']) ?></span>
            </div>
            <div class="definicao"><span class="definicao__rotulo">Prioridade de tramitação</span>
                <span class="definicao__valor"><?= $sinalizador($registro['prioridade']) ?><?php if ($registro['prioridade_descricao']): ?> — <?= e($registro['prioridade_descricao']) ?><?php endif; ?></span>
            </div>
            <div class="definicao"><span class="definicao__rotulo">Última atualização (fonte)</span><span class="definicao__valor"><?= e(data_br($registro['data_ultima_atualizacao'])) ?></span></div>
        </div>
    </div>
</section>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:var(--densidade-secao)">
    <section class="card">
        <div class="card__cabecalho"><h2 class="card__titulo">Órgão jurisdicional</h2></div>
        <div class="card__corpo">
            <div class="definicoes">
                <div class="definicao"><span class="definicao__rotulo">Órgão julgador</span><span class="definicao__valor"><?= ou_traco($registro['orgao_julgador']) ?></span></div>
                <div class="definicao"><span class="definicao__rotulo">Cargo judicial</span><span class="definicao__valor"><?= ou_traco($registro['cargo_judicial']) ?></span></div>
                <div class="definicao"><span class="definicao__rotulo">Competência</span><span class="definicao__valor"><?= ou_traco($registro['competencia']) ?></span></div>
                <div class="definicao"><span class="definicao__rotulo">Jurisdição</span><span class="definicao__valor"><?= ou_traco($registro['jurisdicao']) ?></span></div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card__cabecalho"><h2 class="card__titulo">Classificação e dados financeiros</h2></div>
        <div class="card__corpo">
            <div class="definicoes">
                <div class="definicao"><span class="definicao__rotulo">Classe judicial</span>
                    <span class="definicao__valor">
                        <?= ou_traco($registro['classe_judicial']) ?><?php if ($registro['classe_codigo']): ?>
                            <span class="texto-secundario mono">(<?= e($registro['classe_codigo']) ?>)</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="definicao"><span class="definicao__rotulo">Valor da causa</span><span class="definicao__valor"><?= e(moeda_br($registro['valor_causa'])) ?></span></div>
            </div>
        </div>
    </section>
</div>

<?php require APP_ROOT . '/Views/partials/bloqueios-processo.php'; ?>

<?php
if ($intimacoes !== null) {
    $processoTipo = 'JUDICIAL';
    $processoId = $id;
    require APP_ROOT . '/Views/partials/intimacoes-processo.php';
}
?>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Assuntos</h2></div>
    <div class="card__corpo">
        <?php if ($assuntos === []): ?>
            <p class="texto-secundario mb-0">Nenhum assunto registrado.</p>
        <?php else: ?>
            <div class="flex gap-8" style="flex-wrap:wrap">
                <?php foreach ($assuntos as $a): ?>
                    <span class="badge <?= (int)$a['principal'] === 1 ? 'badge--azul' : '' ?>"
                          <?= $a['codigo'] ? 'title="Código CNJ ' . e($a['codigo']) . '"' : '' ?>>
                        <?= (int)$a['principal'] === 1 ? '★ ' : '' ?><?= e($a['descricao']) ?><?php if ($a['codigo']): ?>
                            <span class="mono">(<?= e($a['codigo']) ?>)</span>
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <h2 class="card__titulo">Partes processuais</h2>
        <span class="texto-secundario"><?= count($partes) ?> parte(s)</span>
    </div>
    <div class="card__corpo">
        <?php if ($partes === []): ?>
            <p class="texto-secundario mb-0">Nenhuma parte cadastrada.</p>
        <?php else: ?>
            <?php foreach ($polos as $polo => $rotuloPolo): ?>
                <?php $doPolo = array_filter($partes, static fn($p) => $p['polo'] === $polo); ?>
                <?php if ($doPolo === []) continue; ?>
                <h3 style="font-size:12px;text-transform:uppercase;letter-spacing:.07em;color:var(--cinza-500);margin:14px 0 8px">
                    <?= e($rotuloPolo) ?>
                </h3>
                <div class="tabela-wrap">
                    <table class="tabela">
                        <thead><tr><th>Nome</th><th>Documento</th><th>Tipo</th><th>Representação</th></tr></thead>
                        <tbody>
                        <?php foreach ($doPolo as $p): ?>
                            <tr>
                                <td><?= e($p['nome']) ?></td>
                                <td class="mono"><?= ou_traco($p['documento']) ?></td>
                                <td><?= ou_traco($p['tipo']) ?></td>
                                <td><?= ou_traco($p['representacao']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<section class="card" id="secao-cronologia">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Cronologia do processo</h2>
            <p class="card__subtitulo">
                <?= count($movimentacoes) ?> movimentação(ões) · da mais recente para a mais antiga
                <?php if ($movimentacoes !== [] && (int)$registro['historico_completo'] !== 1): ?>
                    · <strong>histórico parcial</strong>
                <?php endif; ?>
            </p>
        </div>
    </div>
    <div class="card__corpo">
        <?php if ($movimentacoes !== [] && (int)$registro['historico_completo'] !== 1): ?>
            <div class="alert alert--aviso">
                <span class="alert__icone">!</span>
                <span>A cronologia do PJe é carregada por páginas e esta importação cobriu apenas a parte
                      exibida. Para o histórico completo, edite o processo e importe de novo marcando
                      “Carregar a cronologia completa”.</span>
            </div>
        <?php endif; ?>
        <?php
        $movimentacoesVazio = 'Nenhuma movimentação registrada. Edite o processo e use “Importar do PJe” para trazer a cronologia.';
        require APP_ROOT . '/Views/partials/movimentacoes-judiciais.php';
        ?>
    </div>
</section>

<?php $decisorias = array_filter($documentos, static fn(array $d): bool => !empty($d['classificacao'])); ?>
<section class="card" id="secao-documentos">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Documentos</h2>
            <p class="card__subtitulo">
                <?= count($documentos) ?> documento(s) · <?= count($decisorias) ?> peça(s) decisória(s) ·
                <?= count(array_filter($documentos, static fn(array $d): bool => (int)($d['tamanho_integra'] ?? 0) > 0)) ?> íntegra(s)
            </p>
        </div>
    </div>
    <div class="card__corpo">
        <?php
        // Na ficha, as peças decisórias vêm primeiro: são o que se consulta.
        $documentos = array_merge(array_values($decisorias),
            array_values(array_filter($documentos, static fn(array $d): bool => empty($d['classificacao']))));
        $documentosVazio = 'Nenhum documento registrado. Edite o processo e use “Importar do PJe” para trazer os documentos.';
        require APP_ROOT . '/Views/partials/documentos-judiciais.php';
        ?>
    </div>
</section>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:var(--densidade-secao)">
    <section class="card">
        <div class="card__cabecalho"><h2 class="card__titulo">Resumo</h2></div>
        <div class="card__corpo"><p class="mb-0"><?= nl2br(ou_traco($registro['resumo'])) ?></p></div>
    </section>
    <section class="card">
        <div class="card__cabecalho"><h2 class="card__titulo">Observações</h2></div>
        <div class="card__corpo"><p class="mb-0"><?= nl2br(ou_traco($registro['observacoes'])) ?></p></div>
    </section>
</div>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Processos Administrativos Relacionados</h2>
            <p class="card__subtitulo">Clique no protocolo para abrir a ficha administrativa</p>
        </div>
        <a class="btn btn--secundario btn--sm" href="<?= e(url('editar-processo-judicial.php?id=' . $id)) ?>">＋ Gerenciar vínculos</a>
    </div>
    <div class="card__corpo">
        <?php if ($relacionados === []): ?>
            <p class="texto-secundario mb-0">Nenhum processo administrativo vinculado.</p>
        <?php else: ?>
            <ul class="lista-relacionados">
                <?php foreach ($relacionados as $pa): ?>
                    <li class="lista-relacionados__item">
                        <a class="lista-relacionados__link" href="<?= e(url('processo-administrativo.php?id=' . $pa['id'])) ?>">
                            <?= e($pa['protocolo']) ?>
                        </a>
                        <span class="lista-relacionados__detalhe"><?= ou_traco(mb_substr((string)$pa['objeto'], 0, 120)) ?></span>
                        <span class="texto-secundario nowrap">Abertura <?= e(data_br($pa['data_abertura'])) ?></span>
                        <form method="post" action="<?= e(url('remover-vinculo.php')) ?>"
                              data-confirmar="Remover apenas o vínculo entre <?= e($registro['numero_cnj']) ?> e <?= e($pa['protocolo']) ?>? Nenhum processo será excluído."
                              style="display:inline">
                            <?= Csrf::campo() ?>
                            <input type="hidden" name="administrativo_id" value="<?= e((string)$pa['id']) ?>">
                            <input type="hidden" name="judicial_id" value="<?= e((string)$id) ?>">
                            <input type="hidden" name="retorno" value="processo-judicial.php?id=<?= e((string)$id) ?>">
                            <button type="submit" class="btn btn--ghost btn--sm">Remover vínculo</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Proveniência e auditoria</h2></div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao"><span class="definicao__rotulo">Origem dos dados</span><span class="definicao__valor"><?= e($registro['origem']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Extraído em</span><span class="definicao__valor"><?= e(datahora_br($registro['data_extracao'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Versão do extrator</span><span class="definicao__valor"><?= ou_traco($registro['extrator_versao']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Cadastrado em</span><span class="definicao__valor"><?= e(datahora_br($registro['created_at'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Última modificação</span><span class="definicao__valor"><?= e(datahora_br($registro['updated_at'])) ?></span></div>
            <div class="definicao definicao--larga"><span class="definicao__rotulo">URL de origem</span>
                <span class="definicao__valor"><?= $registro['url_origem'] ? '<a href="' . e($registro['url_origem']) . '" target="_blank" rel="noopener noreferrer">' . e($registro['url_origem']) . '</a>' : '—' ?></span>
            </div>
        </div>
    </div>
</section>

<?php
$processoFixo = $registro;
$retorno = 'processo';
require APP_ROOT . '/Views/partials/modais-bloqueios.php';
if ($intimacoes !== null) {
    require APP_ROOT . '/Views/partials/modais-intimacao.php';
}
?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
