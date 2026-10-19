<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Prazo;
use App\Repositories\AcaoRepository;
use App\Repositories\AnotacaoRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\DesignacaoRepository;
use App\Repositories\EventoAdministrativoRepository;
use App\Repositories\IntimacaoPessoalRepository;
use App\Repositories\LotacaoRepository;
use App\Repositories\PrioridadeRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\ProdutoSaudeRepository;
use App\Repositories\SolicitacaoRepository;
use App\Repositories\VinculoExtrajudicialRepository;
use App\Repositories\VinculoRepository;
use App\Services\AcaoService;
use App\Services\ConfiguracaoService;
use App\Services\IntimacaoPessoalService;
use App\Services\PrioridadeService;
use App\Services\ProcessoExtrajudicialService;
use App\Services\ProdutoSaudeService;
use App\Services\SolicitacaoService;
use App\Validators\Normalizador;
use App\Validators\UrlExternaValidator;

$id = entrada_int('id');
if ($id === null) {
    flash('erro', 'Processo administrativo não informado.');
    redirecionar('processos-administrativos.php');
}

$registro = (new ProcessoAdministrativoRepository())->buscarPorId($id);
if ($registro === null) {
    flash('erro', 'Processo administrativo não encontrado.');
    redirecionar('processos-administrativos.php');
}

$relacionados = (new VinculoRepository())->judiciaisDoAdministrativo($id);
$eventos = (new EventoAdministrativoRepository())->listarPorProcesso($id);
$designados = (new DesignacaoRepository())->listarPorProcesso('administrativo', $id);
$acoes = (new AcaoRepository())->listarPorProcesso($id);
$acoesAbertas = array_values(array_filter($acoes, static fn(array $a): bool => !AcaoService::finalizada($a['status'])));
$solicitacoes = (new SolicitacaoRepository())->listarPorProcesso($id);
$solicitacoesAguardando = count(array_filter($solicitacoes, static fn(array $s): bool => $s['status'] === 'AGUARDANDO'));
$anotacoes = (new AnotacaoRepository())->listarPorProcesso($id);
$extrajudiciais = (new VinculoExtrajudicialRepository())->extrajudiciaisDoAdministrativo($id);
$produtos = (new ProdutoSaudeRepository())->listarPorProcesso($id);
$repoPrioridades = new PrioridadeRepository();
$prioridades = $repoPrioridades->doProcesso($id);
$idsAtribuidos = array_map('intval', array_column($prioridades, 'id'));
$prioridadesDisponiveis = array_values(array_filter(
    $repoPrioridades->opcoesAtivas(),
    static fn(array $p): bool => !in_array((int)$p['id'], $idsAtribuidos, true)
));
$historico = (new AuditoriaRepository())->listarPorEntidade('processos_administrativos', $id, 200);
$lotacoes = (new LotacaoRepository())->opcoes();

// Historico do processo: traducao dos campos gravados na auditoria.
$nomesLotacao = array_column((new LotacaoRepository())->todos(), 'nome', 'id');
$rotulosCampo = [
    'descricao' => 'Descrição', 'prazo' => 'Prazo', 'prazo_hora' => 'Hora', 'status' => 'Status', 'observacao' => 'Observação',
    'data_solicitacao' => 'Data', 'expediente' => 'Expediente', 'destinatario_lotacao_id' => 'Destinatário',
    'assunto' => 'Assunto', 'texto' => 'Texto', 'categoria' => 'Categoria', 'sigtap' => 'SIGTAP',
];
$valorAuditoria = static fn(string $campo, mixed $valor): string => match (true) {
    $valor === null || $valor === ''          => '—',
    $campo === 'status'                       => AcaoService::STATUS[$valor] ?? SolicitacaoService::STATUS[$valor] ?? (string)$valor,
    $campo === 'categoria'                    => ProdutoSaudeService::CATEGORIAS[$valor] ?? (string)$valor,
    $campo === 'destinatario_lotacao_id'      => (string)($nomesLotacao[$valor] ?? '#' . $valor),
    in_array($campo, ['prazo', 'data_solicitacao'], true) => data_br((string)$valor),
    default                                   => mb_strimwidth((string)$valor, 0, 140, '…'),
};
$historicoVazio = 'Nenhum registro no histórico. Providências, prioridades, produtos e vínculos extrajudiciais passam a aparecer aqui.';

// Prazo vigente: o gravado, e a tarefa que o determina (se não for o prazo próprio).
$vigente = Prazo::vigente($registro['prazo'], $registro['prazo_hora'], $acoesAbertas);
$acaoDoPrazo = null;
foreach ($acoesAbertas as $a) {
    if ($vigente['acao_id'] !== null && (int)$a['id'] === $vigente['acao_id']) {
        $acaoDoPrazo = $a;
    }
}
$situacaoPrazo = Prazo::situacao($registro['prazo_vigente'], $registro['prazo_vigente_hora']);
$ativo = (int)$registro['ativo'] === 1;

$situacao = $registro['situacao'] ?? null;
$classeSituacao = match ($situacao) {
    'AGUARDANDO_ANDAMENTO' => 'badge--verde',
    'SOBRESTADO'           => 'badge--ambar',
    'ARQUIVADO'            => 'badge--neutro',
    'EM_TRANSITO'          => 'badge--azul',
    default                => 'badge--neutro',
};
// Link para o SIGADOC apenas se a URL gravada ainda pertence à allowlist.
$urlSigadoc = null;
if (!empty($registro['url_origem'])) {
    $check = UrlExternaValidator::validar($registro['url_origem'], ConfiguracaoService::hostsSigadoc(), ConfiguracaoService::exigirHttps());
    $urlSigadoc = $check['ok'] ? $check['url'] : null;
}

$intimacoes = IntimacaoPessoalService::podeAcessar() ? (new IntimacaoPessoalRepository())->listarPorProcesso('ADMINISTRATIVO', $id) : null;
$retornoIntimacao = 'processo-administrativo.php?id=' . $id;

$titulo      = 'Processo Administrativo';
$menuAtivo   = 'pa-lista';
$breadcrumbs = ['Processos Administrativos' => 'processos-administrativos.php', $registro['protocolo'] => null];
$scripts     = ['abas.js', 'modal.js', 'produtos-saude.js', 'intimacoes.js'];
$retornoFicha = 'processo-administrativo.php?id=' . $id;

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<div class="ficha__cabecalho">
    <div>
        <h2 class="ficha__numero"><?= e($registro['protocolo']) ?></h2>
        <div class="ficha__meta">
            <span class="badge badge--azul">Processo Administrativo</span>
            <span class="badge <?= $registro['origem'] === 'SIGADOC' ? 'badge--verde' : 'badge--neutro' ?>">
                Origem: <?= e($registro['origem']) ?>
            </span>
            <?php if ($situacao !== null): ?>
                <span class="badge <?= e($classeSituacao) ?>"><?= e(Normalizador::SITUACOES[$situacao] ?? $situacao) ?></span>
            <?php endif; ?>
            <span class="badge <?= $ativo ? 'badge--verde' : 'badge--neutro' ?>"
                  title="<?= $ativo ? 'Há tarefa em aberto' : 'Sem tarefas em aberto' ?>"><?= $ativo ? '● Ativo' : '○ Inativo' ?></span>
            <?php if ($registro['prazo_vigente'] !== null): ?>
                <span class="badge <?= e(Prazo::classeBadge($situacaoPrazo)) ?>" title="<?= e(Prazo::ROTULOS_SITUACAO[$situacaoPrazo] ?? '') ?>">
                    ⏱ Prazo <?= e(Prazo::formatar($registro['prazo_vigente'], $registro['prazo_vigente_hora'])) ?>
                </span>
            <?php endif; ?>
            <span class="badge"><?= count($relacionados) ?> processo(s) judicial(is) vinculado(s)</span>
            <?php if ($extrajudiciais !== []): ?>
                <span class="badge"><?= count($extrajudiciais) ?> extrajudicial(is)</span>
            <?php endif; ?>
        </div>
        <?php if ($prioridades !== []): ?>
            <ul class="chips-prioridade" aria-label="Prioridades do processo">
                <?php foreach ($prioridades as $p): ?>
                    <li><a class="badge <?= e(PrioridadeService::classeBadge($p['nivel'])) ?>" href="#prioridades"
                           title="<?= e(PrioridadeService::rotuloNivel($p['nivel']) . ($p['descricao'] ? ' — ' . $p['descricao'] : '')) ?>">
                        ⚑ <?= e($p['nome']) ?> · nível <?= (int)$p['nivel'] ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <div class="acoes-linha">
        <?php if ($urlSigadoc !== null): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e($urlSigadoc) ?>" target="_blank" rel="noopener noreferrer">↗ Abrir no SIGADOC</a>
        <?php endif; ?>
        <a class="btn btn--secundario btn--sm" href="<?= e(url('expediente.php?processo_id=' . $id)) ?>"
           title="Abre o Gerador de Expedientes com os dados deste processo e dos processos vinculados">📝 Gerar expediente</a>
        <a class="btn btn--secundario btn--sm" href="<?= e(url('termo-registro-processo-administrativo.php?id=' . $id)) ?>"
           target="_blank" rel="noopener" title="Gera o termo em A4 para salvar em PDF">📄 Termo de Registro</a>
        <a class="btn btn--secundario btn--sm" href="<?= e(url('editar-processo-administrativo.php?id=' . $id)) ?>">✎ Editar</a>
        <form method="post" action="<?= e(url('excluir-processo-administrativo.php')) ?>"
              data-confirmar="Excluir o processo administrativo <?= e($registro['protocolo']) ?>? Os processos judiciais vinculados NÃO serão excluídos."
              style="display:inline">
            <?= Csrf::campo() ?>
            <input type="hidden" name="id" value="<?= e((string)$id) ?>">
            <button type="submit" class="btn btn--perigo btn--sm">🗑 Excluir</button>
        </form>
    </div>
</div>

<div class="barra-ficha">
    <nav class="abas" data-abas aria-label="Seções do processo">
        <a class="abas__item" href="#informacoes" data-aba="informacoes">Informações</a>
        <a class="abas__item" href="#providencias" data-aba="providencias">
            Providências
            <span class="abas__contador" title="Tarefas em aberto e solicitações aguardando resposta"><?= count($acoesAbertas) + $solicitacoesAguardando ?: '' ?></span>
        </a>
        <a class="abas__item" href="#judiciais" data-aba="judiciais" title="Processos Judiciais">
            Judiciais <span class="abas__contador"><?= count($relacionados) ?: '' ?></span>
        </a>
        <a class="abas__item" href="#extrajudiciais" data-aba="extrajudiciais" title="Processos Extrajudiciais">
            Extrajudiciais <span class="abas__contador"><?= count($extrajudiciais) ?: '' ?></span>
        </a>
        <?php if ($intimacoes !== null): ?>
        <a class="abas__item" href="#intimacoes" data-aba="intimacoes" title="Intimações Pessoais">
            Intimações <span class="abas__contador"><?= count($intimacoes) ?: '' ?></span>
        </a>
        <?php endif; ?>
        <a class="abas__item" href="#produtos-saude" data-aba="produtos-saude">
            Produtos de Saúde <span class="abas__contador"><?= count($produtos) ?: '' ?></span>
        </a>
        <a class="abas__item" href="#prioridades" data-aba="prioridades">
            Prioridades <span class="abas__contador"><?= count($prioridades) ?: '' ?></span>
        </a>
        <a class="abas__item" href="#movimentacoes" data-aba="movimentacoes">
            Movimentações <span class="abas__contador"><?= count($eventos) ?: '' ?></span>
        </a>
        <a class="abas__item" href="#historico" data-aba="historico">Histórico</a>
    </nav>
</div>

<?php // Sempre visiveis (barra flutuante), em qualquer guia. Sem JavaScript, os botoes levam as paginas de cadastro comuns (com retorno para a ficha). ?>
<div class="acoes-rapidas" role="toolbar" aria-label="Providências rápidas">
    <span class="acoes-rapidas__rotulo" aria-hidden="true">Registrar</span>
    <a class="btn btn--primario btn--sm" data-modal-abrir="modalNovaAcao" title="Registrar uma tarefa no processo"
       href="<?= e(url('nova-acao.php?processo_id=' . $id . '&retorno=' . rawurlencode($retornoFicha))) ?>">＋ Nova Tarefa</a>
    <a class="btn btn--secundario btn--sm" data-modal-abrir="modalNovaSolicitacao" title="Registrar uma solicitação a outra unidade"
       href="<?= e(url('nova-solicitacao.php?processo_id=' . $id . '&retorno=' . rawurlencode($retornoFicha))) ?>">＋ Nova Solicitação</a>
    <a class="btn btn--secundario btn--sm" data-modal-abrir="modalNovaAnotacao" title="Registrar uma anotação interna"
       href="<?= e(url('nova-anotacao.php?processo_id=' . $id . '&retorno=' . rawurlencode($retornoFicha))) ?>">＋ Nova Anotação</a>
</div>

<div class="abas__painel" id="informacoes" data-aba-painel>
<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Informações</h2></div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao">
                <span class="definicao__rotulo">Data de Abertura</span>
                <span class="definicao__valor"><?= e(data_br($registro['data_abertura'])) ?></span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Data do cadastro</span>
                <span class="definicao__valor"><?= e(datahora_br($registro['created_at'])) ?></span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Última atualização</span>
                <span class="definicao__valor"><?= e(datahora_br($registro['updated_at'])) ?></span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Origem dos dados</span>
                <span class="definicao__valor">
                    <?= e($registro['origem']) ?>
                    <?php if ($registro['data_extracao']): ?>
                        <br><span class="texto-secundario">Extraído em <?= e(datahora_br($registro['data_extracao'])) ?></span>
                    <?php endif; ?>
                    <?php if ($registro['extrator_versao']): ?>
                        <br><span class="texto-secundario">Extrator v<?= e($registro['extrator_versao']) ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Procedimento</span>
                <span class="definicao__valor">
                    <?php if (!empty($registro['procedimento_nome'])): ?>
                        <?= e($registro['procedimento_nome']) ?>
                        <?php if ((int)$registro['procedimento_ativo'] !== 1): ?>
                            <span class="badge badge--neutro" title="Inativado no catálogo; a classificação do processo é mantida">Inativo</span>
                        <?php endif; ?>
                    <?php elseif (($registro['procedimento'] ?? '') !== ''): ?>
                        <?= e($registro['procedimento']) ?>
                        <br><span class="texto-secundario">Classificação anterior (texto livre), não vinculada ao catálogo</span>
                    <?php else: ?>
                        <span class="texto-secundario">—</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Prazo do processo</span>
                <span class="definicao__valor"><?= e(Prazo::formatar($registro['prazo'], $registro['prazo_hora'])) ?></span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Prazo vigente</span>
                <span class="definicao__valor">
                    <?php if ($registro['prazo_vigente'] !== null): ?>
                        <span class="badge <?= e(Prazo::classeBadge($situacaoPrazo)) ?>"><?= e(Prazo::formatar($registro['prazo_vigente'], $registro['prazo_vigente_hora'])) ?></span>
                        <br><span class="texto-secundario">
                            <?php if ($acaoDoPrazo !== null): ?>
                                Definido pela tarefa <a href="#acao-<?= (int)$acaoDoPrazo['id'] ?>">“<?= e(mb_strimwidth($acaoDoPrazo['descricao'], 0, 60, '…')) ?>”</a>, até ser finalizada
                            <?php else: ?>
                                Prazo do próprio processo
                            <?php endif; ?>
                        </span>
                    <?php else: ?>—<?php endif; ?>
                </span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Atividade</span>
                <span class="definicao__valor">
                    <span class="badge <?= $ativo ? 'badge--verde' : 'badge--neutro' ?>"><?= $ativo ? 'Ativo' : 'Inativo' ?></span>
                    <br><span class="texto-secundario">
                        <?= $acoes === []
                            ? 'Nenhuma tarefa cadastrada'
                            : count($acoesAbertas) . ' de ' . count($acoes) . ' tarefa(s) em aberto' ?>
                    </span>
                </span>
            </div>
            <div class="definicao definicao--larga">
                <span class="definicao__rotulo">Objeto</span>
                <span class="definicao__valor"><?= nl2br(ou_traco($registro['objeto'])) ?></span>
            </div>
        </div>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Situação no SIGADOC</h2>
            <p class="card__subtitulo">
                <?= $registro['data_extracao']
                    ? 'Conforme extração de ' . e(datahora_br($registro['data_extracao']))
                    : 'Informada manualmente' ?>
            </p>
        </div>
    </div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao">
                <span class="definicao__rotulo">Situação</span>
                <span class="definicao__valor">
                    <?php if ($situacao !== null): ?>
                        <span class="badge <?= e($classeSituacao) ?>"><?= e(Normalizador::SITUACOES[$situacao] ?? $situacao) ?></span>
                        <?php if (!empty($registro['situacao_descricao'])): ?>
                            <br><span class="texto-secundario">No SIGADOC: <?= e($registro['situacao_descricao']) ?></span>
                        <?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Servidor responsável</span>
                <span class="definicao__valor">
                    <?= ou_traco($registro['responsavel_nome'] ?? null) ?>
                    <?php if (!empty($registro['responsavel_matricula'])): ?>
                        <br><span class="texto-secundario mono"><?= e($registro['responsavel_matricula']) ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Lotação</span>
                <span class="definicao__valor">
                    <?php if (!empty($registro['responsavel_lotacao'])): ?>
                        <span class="mono"><?= e($registro['responsavel_lotacao']) ?></span>
                        <?php if (!empty($registro['responsavel_lotacao_nome'])): ?>
                            <br><span class="texto-secundario"><?= e($registro['responsavel_lotacao_nome']) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <?= ou_traco($registro['responsavel_lotacao_nome'] ?? null) ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Última movimentação</span>
                <span class="definicao__valor">
                    <?php if ($eventos !== []): ?>
                        <?= e($eventos[0]['tipo_descricao'] ?? '') ?> em <?= e(data_br($eventos[0]['data_evento'])) ?>
                    <?php else: ?>—<?php endif; ?>
                </span>
            </div>
        </div>
    </div>
</section>

<?php
$tipoProcesso = 'administrativo';
require APP_ROOT . '/Views/partials/designacao.php';
?>
</div>

<div class="abas__painel" id="judiciais" data-aba-painel>
<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Processos Judiciais Relacionados</h2>
            <p class="card__subtitulo">Clique no número para abrir a ficha judicial</p>
        </div>
        <a class="btn btn--secundario btn--sm" href="<?= e(url('editar-processo-administrativo.php?id=' . $id)) ?>">＋ Gerenciar vínculos</a>
    </div>
    <div class="card__corpo">
        <?php if ($relacionados === []): ?>
            <p class="texto-secundario mb-0">Nenhum processo judicial vinculado a este processo administrativo.</p>
        <?php else: ?>
            <ul class="lista-relacionados">
                <?php foreach ($relacionados as $pj): ?>
                    <li class="lista-relacionados__item">
                        <a class="lista-relacionados__link" href="<?= e(url('processo-judicial.php?id=' . $pj['id'])) ?>">
                            <?= e($pj['numero_cnj']) ?>
                        </a>
                        <span class="lista-relacionados__detalhe">
                            <?= e(implode(' · ', array_filter([
                                $pj['tribunal'] ?? null,
                                $pj['orgao_julgador'] ?? null,
                                $pj['classe_judicial'] ?? null,
                            ]))) ?: '—' ?>
                        </span>
                        <?php if ($pj['situacao']): ?>
                            <span class="badge"><?= e($pj['situacao']) ?></span>
                        <?php endif; ?>
                        <span class="texto-secundario nowrap">Vínculo em <?= e(data_br($pj['data_vinculo'])) ?></span>
                        <form method="post" action="<?= e(url('remover-vinculo.php')) ?>"
                              data-confirmar="Remover apenas o vínculo entre <?= e($registro['protocolo']) ?> e <?= e($pj['numero_cnj']) ?>? Nenhum processo será excluído."
                              style="display:inline">
                            <?= Csrf::campo() ?>
                            <input type="hidden" name="administrativo_id" value="<?= e((string)$id) ?>">
                            <input type="hidden" name="judicial_id" value="<?= e((string)$pj['id']) ?>">
                            <input type="hidden" name="retorno" value="processo-administrativo.php?id=<?= e((string)$id) ?>">
                            <button type="submit" class="btn btn--ghost btn--sm">Remover vínculo</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
</div>

<div class="abas__painel" id="extrajudiciais" data-aba-painel>
<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Processos Extrajudiciais Relacionados</h2>
            <p class="card__subtitulo">Procedimentos de instituições externas (Ministério Público, Defensoria, Tribunal de Contas…) · remover o vínculo não exclui nenhum processo</p>
        </div>
        <div class="acoes-linha">
            <a class="btn btn--ghost btn--sm" href="<?= e(url('editar-processo-administrativo.php?id=' . $id . '#extrajudiciais')) ?>">Vincular existente</a>
            <a class="btn btn--primario btn--sm" href="<?= e(url('novo-processo-extrajudicial.php?administrativo_id=' . $id)) ?>">＋ Novo processo extrajudicial</a>
        </div>
    </div>
    <div class="card__corpo">
        <?php if ($extrajudiciais === []): ?>
            <p class="texto-secundario mb-0">Nenhum processo extrajudicial vinculado a este processo administrativo.</p>
        <?php else: ?>
            <ul class="lista-relacionados">
                <?php foreach ($extrajudiciais as $pe): ?>
                    <li class="lista-relacionados__item">
                        <a class="lista-relacionados__link" href="<?= e(url('processo-extrajudicial.php?id=' . $pe['id'])) ?>"><?= e($pe['protocolo']) ?></a>
                        <span class="lista-relacionados__detalhe">
                            <?= e(implode(' · ', array_filter([
                                $pe['instituicao_sigla'] ?: $pe['instituicao_nome'],
                                $pe['orgao_nome'],
                                data_br($pe['data_expediente']),
                                $pe['assunto'],
                            ]))) ?>
                        </span>
                        <span class="badge <?= e(ProcessoExtrajudicialService::classeBadge($pe['situacao'])) ?>"><?= e(ProcessoExtrajudicialService::SITUACOES[$pe['situacao']] ?? $pe['situacao']) ?></span>
                        <span class="texto-secundario nowrap">Vínculo em <?= e(data_br($pe['data_vinculo'])) ?></span>
                        <form method="post" action="<?= e(url('remover-vinculo.php')) ?>"
                              data-confirmar="Remover apenas o vínculo entre <?= e($registro['protocolo']) ?> e <?= e($pe['protocolo']) ?>? Nenhum processo será excluído.">
                            <?= Csrf::campo() ?>
                            <input type="hidden" name="administrativo_id" value="<?= (int)$id ?>">
                            <input type="hidden" name="extrajudicial_id" value="<?= (int)$pe['id'] ?>">
                            <input type="hidden" name="retorno" value="processo-administrativo.php?id=<?= (int)$id ?>#extrajudiciais">
                            <button type="submit" class="btn btn--ghost btn--sm">Remover vínculo</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
</div>

<div class="abas__painel" id="produtos-saude" data-aba-painel>
<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Produtos de Saúde</h2>
            <p class="card__subtitulo">Medicamentos, procedimentos, exames e demais tecnologias objeto do processo, com a classificação SIGTAP quando houver</p>
        </div>
        <button type="button" class="btn btn--primario btn--sm" data-modal-abrir="modalProdutoSaude" data-modal-dados='{"id":""}'>＋ Incluir produto</button>
    </div>
    <?php if ($produtos === []): ?>
        <div class="card__corpo"><p class="texto-secundario mb-0">Nenhum produto de saúde relacionado ao processo.</p></div>
    <?php else: ?>
        <div class="tabela-wrap">
            <table class="tabela tabela--providencias">
                <thead><tr><th>Categoria</th><th>Descrição</th><th>Classificação SIGTAP</th><th>Incluído por</th><th class="texto-direita">Ações</th></tr></thead>
                <tbody>
                <?php foreach ($produtos as $ps): ?>
                    <?php
                    $dadosProduto = [
                        'id' => (int)$ps['id'], 'categoria' => $ps['categoria'], 'descricao' => $ps['descricao'],
                        'sigtap_codigo' => $ps['sigtap_codigo'] !== null ? ProdutoSaudeService::formatarSigtap($ps['sigtap_codigo']) : '',
                        'sigtap_nome' => $ps['sigtap_nome'] ?? '', 'sigtap_grupo_nome' => $ps['sigtap_grupo_nome'] ?? '',
                        'sigtap_subgrupo_nome' => $ps['sigtap_subgrupo_nome'] ?? '', 'sigtap_forma_organizacao_nome' => $ps['sigtap_forma_organizacao_nome'] ?? '',
                    ];
                    ?>
                    <tr id="produto-<?= (int)$ps['id'] ?>">
                        <td class="nowrap"><span class="badge badge--azul"><?= e(ProdutoSaudeService::CATEGORIAS[$ps['categoria']] ?? $ps['categoria']) ?></span></td>
                        <td class="tabela__texto"><strong><?= e($ps['descricao']) ?></strong></td>
                        <td class="tabela__texto">
                            <?php if ($ps['sigtap_codigo'] !== null): ?>
                                <span class="mono"><?= e(ProdutoSaudeService::formatarSigtap($ps['sigtap_codigo'])) ?></span> — <?= e($ps['sigtap_nome']) ?>
                                <?php $estrutura = array_filter([$ps['sigtap_grupo_nome'], $ps['sigtap_subgrupo_nome'], $ps['sigtap_forma_organizacao_nome']]); ?>
                                <?php if ($estrutura !== []): ?><br><span class="texto-secundario"><?= e(implode(' › ', $estrutura)) ?></span><?php endif; ?>
                                <?php if ($ps['sigtap_origem'] === 'MANUAL'): ?><br><span class="texto-secundario">Cadastro manual</span><?php endif; ?>
                            <?php else: ?>
                                <span class="texto-secundario">Não classificado</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap"><?= e($ps['criado_por_nome'] ?? '—') ?><br><span class="texto-secundario"><?= e(datahora_br($ps['created_at'])) ?></span></td>
                        <td class="texto-direita nowrap">
                            <?php if (ProdutoSaudeService::podeAlterar($ps)): ?>
                                <div class="acoes-linha tabela__comandos">
                                    <button type="button" class="btn btn--ghost btn--sm" data-modal-abrir="modalProdutoSaude"
                                            data-modal-titulo="Editar produto de saúde"
                                            data-modal-dados="<?= e(json_encode($dadosProduto, JSON_UNESCAPED_UNICODE)) ?>">Editar</button>
                                    <form method="post" action="<?= e(url('excluir-produto-saude.php')) ?>"
                                          data-confirmar="Excluir “<?= e($ps['descricao']) ?>” dos produtos deste processo?">
                                        <?= Csrf::campo() ?>
                                        <input type="hidden" name="id" value="<?= (int)$ps['id'] ?>">
                                        <input type="hidden" name="processo_id" value="<?= (int)$id ?>">
                                        <button type="submit" class="btn btn--ghost btn--sm acao__excluir">Excluir</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
</div>

<div class="abas__painel" id="prioridades" data-aba-painel>
<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Prioridades</h2>
            <p class="card__subtitulo">Características do processo que pedem atenção preferencial · nível de 1 (baixa) a 5 (máxima)</p>
        </div>
        <?php if (Auth::ehAdministrador()): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('configuracoes.php?aba=prioridades')) ?>">Gerenciar prioridades</a>
        <?php endif; ?>
    </div>
    <div class="card__corpo">
        <?php if ($prioridades === []): ?>
            <p class="texto-secundario">Nenhuma prioridade atribuída a este processo.</p>
        <?php else: ?>
            <ul class="lista-relacionados mb-16">
                <?php foreach ($prioridades as $p): ?>
                    <li class="lista-relacionados__item">
                        <span class="badge <?= e(PrioridadeService::classeBadge($p['nivel'])) ?>"><?= e(PrioridadeService::rotuloNivel($p['nivel'])) ?></span>
                        <strong><?= e($p['nome']) ?></strong>
                        <span class="lista-relacionados__detalhe">
                            <?= e(mb_strimwidth((string)($p['observacao'] ?: $p['descricao']), 0, 160, '…')) ?: '—' ?>
                            <?php if ((int)$p['ativo'] !== 1): ?> <span class="badge badge--neutro">Prioridade inativa</span><?php endif; ?>
                        </span>
                        <span class="texto-secundario nowrap">Atribuída em <?= e(datahora_br($p['atribuida_em'])) ?><?= $p['atribuida_por_nome'] ? ' por ' . e($p['atribuida_por_nome']) : '' ?></span>
                        <form method="post" action="<?= e(url('processo-prioridade.php')) ?>"
                              data-confirmar="Remover a prioridade “<?= e($p['nome']) ?>” deste processo?">
                            <?= Csrf::campo() ?>
                            <input type="hidden" name="acao" value="remover">
                            <input type="hidden" name="processo_id" value="<?= (int)$id ?>">
                            <input type="hidden" name="prioridade_id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="btn btn--ghost btn--sm">Remover</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($prioridadesDisponiveis !== []): ?>
            <form method="post" action="<?= e(url('processo-prioridade.php')) ?>" class="form-prioridade" novalidate>
                <?= Csrf::campo() ?>
                <input type="hidden" name="acao" value="adicionar">
                <input type="hidden" name="processo_id" value="<?= (int)$id ?>">
                <div class="campo">
                    <label class="campo__rotulo" for="prioridade_id">Adicionar prioridade</label>
                    <select class="select" id="prioridade_id" name="prioridade_id" required>
                        <option value="">Selecione</option>
                        <?php foreach ($prioridadesDisponiveis as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" title="<?= e((string)$p['descricao']) ?>"><?= e($p['nome']) ?> — nível <?= (int)$p['nivel'] ?> (<?= e(PrioridadeService::NIVEIS[(int)$p['nivel']] ?? '') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label class="campo__rotulo" for="prioridade_observacao">Observação <span class="texto-secundario">(opcional)</span></label>
                    <input class="input" type="text" id="prioridade_observacao" name="observacao" maxlength="<?= PrioridadeService::OBSERVACAO_MAX ?>"
                           placeholder="Ex.: paciente com 72 anos">
                </div>
                <div class="form-prioridade__acao"><button type="submit" class="btn btn--primario">＋ Adicionar</button></div>
            </form>
        <?php elseif ($prioridades !== []): ?>
            <p class="texto-secundario mb-0">Todas as prioridades ativas já estão atribuídas.</p>
        <?php else: ?>
            <p class="texto-secundario mb-0">Não há prioridades ativas cadastradas.</p>
        <?php endif; ?>
    </div>
</section>
</div>

<div class="abas__painel" id="movimentacoes" data-aba-painel>
<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Movimentações</h2>
            <p class="card__subtitulo"><?= count($eventos) ?> evento(s) · da mais recente para a mais antiga</p>
        </div>
    </div>
    <div class="card__corpo">
        <?php
        $eventosVazio = 'Nenhuma movimentação registrada. Edite o processo e use “Completar cadastro pelo SIGADOC” para importar o histórico.';
        require APP_ROOT . '/Views/partials/eventos-administrativos.php';
        ?>
    </div>
</section>
</div>

<div class="abas__painel" id="providencias" data-aba-painel>
<?php $retorno = 'processo-administrativo.php?id=' . $id; ?>
<section class="card" id="acoes">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Tarefas</h2>
            <p class="card__subtitulo">
                O prazo mais próximo entre as tarefas em aberto prevalece sobre o prazo do processo
            </p>
        </div>
        <div class="acoes-linha">
            <?php if ($acoes !== []): ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('acoes.php?processo_id=' . $id)) ?>">Abrir no gerenciador</a>
            <?php endif; ?>
            <a class="btn btn--primario btn--sm" data-modal-abrir="modalNovaAcao" href="<?= e(url('nova-acao.php?processo_id=' . $id . '&retorno=' . rawurlencode($retorno))) ?>">＋ Nova tarefa</a>
        </div>
    </div>
    <?php if ($acoes === []): ?>
        <div class="card__corpo">
            <p class="texto-secundario mb-0">Nenhuma tarefa cadastrada. Sem tarefas, o processo fica inativo.</p>
        </div>
    <?php else: ?>
        <?php require APP_ROOT . '/Views/partials/acoes-tabela.php'; ?>
    <?php endif; ?>
</section>

<section class="card" id="solicitacoes">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Solicitações</h2>
            <p class="card__subtitulo">Pedidos enviados a outras unidades · o prazo é só de acompanhamento e não altera o prazo do processo</p>
        </div>
        <div class="acoes-linha">
            <?php if ($solicitacoes !== []): ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('solicitacoes.php?processo_id=' . $id)) ?>">Ver na listagem</a>
            <?php endif; ?>
            <a class="btn btn--primario btn--sm" data-modal-abrir="modalNovaSolicitacao" href="<?= e(url('nova-solicitacao.php?processo_id=' . $id . '&retorno=' . rawurlencode($retorno))) ?>">＋ Nova solicitação</a>
        </div>
    </div>
    <?php if ($solicitacoes === []): ?>
        <div class="card__corpo">
            <p class="texto-secundario mb-0">Nenhuma solicitação cadastrada.</p>
        </div>
    <?php else: ?>
        <?php
        $mostrarProcesso = false;
        $linkOrdenacao = null;
        require APP_ROOT . '/Views/partials/solicitacoes-tabela.php';
        ?>
    <?php endif; ?>
</section>

<section class="card" id="anotacoes">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Anotações</h2>
            <p class="card__subtitulo">Notas internas da equipe · da mais recente para a mais antiga</p>
        </div>
        <a class="btn btn--primario btn--sm" data-modal-abrir="modalNovaAnotacao" href="<?= e(url('nova-anotacao.php?processo_id=' . $id . '&retorno=' . rawurlencode($retorno))) ?>">＋ Nova anotação</a>
    </div>
    <div class="card__corpo">
        <?php
        $mostrarProcesso = false;
        require APP_ROOT . '/Views/partials/anotacoes-lista.php';
        ?>
    </div>
</section>
</div>

<?php if ($intimacoes !== null): ?>
<div class="abas__painel" id="intimacoes" data-aba-painel>
<?php
$processoTipo = 'ADMINISTRATIVO';
$processoId = $id;
require APP_ROOT . '/Views/partials/intimacoes-processo.php';
?>
</div>
<?php endif; ?>

<div class="abas__painel" id="historico" data-aba-painel>
<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Histórico</h2>
            <p class="card__subtitulo">Trilha de auditoria do processo: providências, prioridades, produtos de saúde e vínculos extrajudiciais · do mais recente ao mais antigo</p>
        </div>
    </div>
    <?php require APP_ROOT . '/Views/partials/historico-auditoria.php'; ?>
</section>
</div>

<?php
// Modais por ultimo: redefinem $dados/$erros no escopo da pagina.
require APP_ROOT . '/Views/partials/modais-providencias.php';
require APP_ROOT . '/Views/partials/modal-produto-saude.php';
if ($intimacoes !== null) {
    require APP_ROOT . '/Views/partials/modais-intimacao.php';
}
?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
