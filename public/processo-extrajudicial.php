<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Repositories\AuditoriaRepository;
use App\Repositories\ProcessoExtrajudicialRepository;
use App\Repositories\VinculoExtrajudicialRepository;
use App\Services\ProcessoExtrajudicialService;

$id = entrada_int('id');
$registro = $id !== null ? (new ProcessoExtrajudicialRepository())->buscarPorId($id) : null;
if ($registro === null) {
    flash('erro', 'Processo extrajudicial não encontrado.');
    redirecionar('processos-extrajudiciais.php');
}

$administrativos = (new VinculoExtrajudicialRepository())->administrativosDoExtrajudicial($id);
$historico = (new AuditoriaRepository())->listarPorEntidade('processos_extrajudiciais', $id);
$rotulosCampo = [
    'protocolo' => 'Protocolo', 'data_expediente' => 'Data', 'instituicao_externa_id' => 'Instituição',
    'orgao_externo_id' => 'Órgão', 'assunto' => 'Assunto', 'descricao' => 'Descrição',
    'situacao' => 'Situação', 'observacoes' => 'Observações',
];
$valorAuditoria = static fn(string $campo, mixed $valor): string => match (true) {
    $valor === null || $valor === '' => '—',
    $campo === 'situacao'            => ProcessoExtrajudicialService::SITUACOES[$valor] ?? (string)$valor,
    $campo === 'data_expediente'     => data_br((string)$valor),
    in_array($campo, ['instituicao_externa_id', 'orgao_externo_id'], true) => '#' . $valor,
    default                          => mb_strimwidth((string)$valor, 0, 120, '…'),
};
$situacao = $registro['situacao'];

$titulo      = 'Processo Extrajudicial';
$menuAtivo   = 'pe-lista';
$breadcrumbs = ['Processos Extrajudiciais' => 'processos-extrajudiciais.php', $registro['protocolo'] => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<div class="ficha__cabecalho">
    <div>
        <h2 class="ficha__numero"><?= e($registro['protocolo']) ?></h2>
        <div class="ficha__meta">
            <span class="badge badge--azul">Processo Extrajudicial</span>
            <span class="badge" title="<?= e($registro['instituicao_nome']) ?>"><?= e($registro['instituicao_sigla'] ?: $registro['instituicao_nome']) ?></span>
            <span class="badge <?= e(ProcessoExtrajudicialService::classeBadge($situacao)) ?>"><?= e(ProcessoExtrajudicialService::SITUACOES[$situacao] ?? $situacao) ?></span>
            <span class="badge"><?= count($administrativos) ?> processo(s) administrativo(s) vinculado(s)</span>
        </div>
    </div>
    <div class="acoes-linha">
        <a class="btn btn--secundario btn--sm" href="<?= e(url('editar-processo-extrajudicial.php?id=' . $id)) ?>">✎ Editar</a>
        <form method="post" action="<?= e(url('excluir-processo-extrajudicial.php')) ?>"
              data-confirmar="Excluir o processo extrajudicial <?= e($registro['protocolo']) ?>? Os processos administrativos vinculados NÃO serão excluídos.">
            <?= Csrf::campo() ?>
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <button type="submit" class="btn btn--perigo btn--sm">🗑 Excluir</button>
        </form>
    </div>
</div>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Informações</h2></div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao"><span class="definicao__rotulo">Instituição</span><span class="definicao__valor"><?= e($registro['instituicao_nome']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Órgão / Unidade</span><span class="definicao__valor"><?= ou_traco($registro['orgao_nome']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Data</span><span class="definicao__valor"><?= e(data_br($registro['data_expediente'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Situação</span><span class="definicao__valor"><?= e(ProcessoExtrajudicialService::SITUACOES[$situacao] ?? $situacao) ?></span></div>
            <div class="definicao definicao--larga"><span class="definicao__rotulo">Assunto</span><span class="definicao__valor"><?= e($registro['assunto']) ?></span></div>
            <div class="definicao definicao--larga"><span class="definicao__rotulo">Descrição</span><span class="definicao__valor texto-longo"><?= nl2br(ou_traco($registro['descricao'])) ?></span></div>
            <div class="definicao definicao--larga"><span class="definicao__rotulo">Observações</span><span class="definicao__valor texto-longo"><?= nl2br(ou_traco($registro['observacoes'])) ?></span></div>
            <div class="definicao">
                <span class="definicao__rotulo">Cadastro</span>
                <span class="definicao__valor"><?= e(datahora_br($registro['created_at'])) ?><br><span class="texto-secundario"><?= e($registro['criado_por_nome'] ?? 'Não identificado') ?></span></span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Última alteração</span>
                <span class="definicao__valor"><?= e(datahora_br($registro['updated_at'])) ?><br><span class="texto-secundario"><?= e($registro['atualizado_por_nome'] ?? 'Não identificado') ?></span></span>
            </div>
        </div>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Processos Administrativos vinculados</h2>
            <p class="card__subtitulo">Remover o vínculo não exclui nenhum processo</p>
        </div>
        <a class="btn btn--secundario btn--sm" href="<?= e(url('editar-processo-extrajudicial.php?id=' . $id)) ?>">＋ Gerenciar vínculos</a>
    </div>
    <div class="card__corpo">
        <?php if ($administrativos === []): ?>
            <p class="texto-secundario mb-0">Nenhum processo administrativo vinculado.</p>
        <?php else: ?>
            <ul class="lista-relacionados">
                <?php foreach ($administrativos as $pa): ?>
                    <li class="lista-relacionados__item">
                        <a class="lista-relacionados__link" href="<?= e(url('processo-administrativo.php?id=' . $pa['id'] . '#extrajudiciais')) ?>"><?= e($pa['protocolo']) ?></a>
                        <span class="lista-relacionados__detalhe"><?= e(mb_strimwidth((string)$pa['objeto'], 0, 140, '…')) ?: '—' ?></span>
                        <span class="texto-secundario nowrap">Vínculo em <?= e(data_br($pa['data_vinculo'])) ?></span>
                        <form method="post" action="<?= e(url('remover-vinculo.php')) ?>"
                              data-confirmar="Remover apenas o vínculo entre <?= e($pa['protocolo']) ?> e <?= e($registro['protocolo']) ?>? Nenhum processo será excluído.">
                            <?= Csrf::campo() ?>
                            <input type="hidden" name="administrativo_id" value="<?= (int)$pa['id'] ?>">
                            <input type="hidden" name="extrajudicial_id" value="<?= (int)$id ?>">
                            <input type="hidden" name="retorno" value="processo-extrajudicial.php?id=<?= (int)$id ?>">
                            <button type="submit" class="btn btn--ghost btn--sm">Remover vínculo</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Histórico de alterações</h2></div>
    <?php require APP_ROOT . '/Views/partials/historico-auditoria.php'; ?>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
