<?php
declare(strict_types=1);

/**
 * Ficha da Intimacao Pessoal: dados, situacao calculada, metadados do
 * registro e historico de alteracoes e cumprimento. Uma intimacao excluida
 * (logicamente) continua acessivel aqui, so para consulta.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores e gestores.
App\Services\IntimacaoPessoalService::exigirAcesso();

use App\Core\Auth;
use App\Repositories\AuditoriaRepository;
use App\Repositories\IntimacaoPessoalRepository;
use App\Services\IntimacaoPessoalService as Intimacoes;

$id = entrada_int('id');
$registro = $id !== null ? (new IntimacaoPessoalRepository())->buscarPorId($id) : null;
if ($registro === null) {
    flash('erro', 'Intimação não encontrada.');
    redirecionar('intimacoes-pessoais.php');
}

$excluida = $registro['excluida_em'] !== null;
$situacao = Intimacoes::situacao($registro);
$dias = Intimacoes::diasRestantes($registro);
$podeAlterar = Intimacoes::podeAlterar($registro);
$aberta = in_array($registro['status'], ['PENDENTE', 'EM_ANDAMENTO'], true);
$verAgentes = Auth::ehAdministrador(); // a ficha do agente e restrita a administradores
$processo = Intimacoes::descreverProcesso($registro['processo_tipo'], (int)$registro['processo_id']);

$historico = (new AuditoriaRepository())->listarPorEntidade(Intimacoes::ENTIDADE, $id);
$rotulosCampo = [
    'processo' => 'Processo', 'agente_intimado' => 'Pessoa intimada', 'agente_recebedor' => 'Recebido por',
    'data_recebimento' => 'Recebimento', 'data_prazo' => 'Prazo', 'data_cumprimento' => 'Cumprimento', 'status' => 'Status',
    'documento_referencia' => 'Documento comprobatório', 'observacoes' => 'Observações', 'justificativa' => 'Justificativa do novo prazo',
];
$valorAuditoria = static fn(string $campo, mixed $valor): string => match (true) {
    $valor === null || $valor === '' => '—',
    str_starts_with($campo, 'data_') => data_br((string)$valor),
    default                          => mb_strimwidth((string)$valor, 0, 160, '…'),
};

$agente = static function (string $prefixo, int $agenteId) use ($registro, $verAgentes): string {
    $nome = e($registro[$prefixo . '_nome']);
    $detalhe = implode(' · ', array_filter([
        $registro[$prefixo . '_matricula'] ? 'Mat. ' . $registro[$prefixo . '_matricula'] : null,
        $registro[$prefixo . '_cargo'], $registro[$prefixo . '_lotacao'],
    ]));
    return ($verAgentes ? '<a href="' . e(url('agente-publico.php?id=' . $agenteId . '#intimacoes')) . '">' . $nome . '</a>' : $nome)
        . ($detalhe !== '' ? '<br><span class="texto-secundario">' . e($detalhe) . '</span>' : '');
};

$titulo      = 'Intimação Pessoal';
$menuAtivo   = 'intimacoes';
$breadcrumbs = ['Intimações Pessoais' => 'intimacoes-pessoais.php', 'Intimação nº ' . $id => null];
$scripts     = ['modal.js', 'intimacoes.js'];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if ($excluida): ?>
    <div class="alert alert--aviso" role="status">
        <span class="alert__icone">!</span>
        <span>Intimação <strong>excluída</strong> em <?= e(datahora_br($registro['excluida_em'])) ?> por <?= e($registro['excluida_por_nome'] ?? 'usuário não identificado') ?>.
            Motivo: <?= e($registro['motivo_exclusao']) ?>. O registro é mantido apenas para consulta e não aparece no acompanhamento.</span>
    </div>
<?php endif; ?>

<div class="ficha__cabecalho">
    <div>
        <p class="sobrelinha">Intimação pessoal nº <?= (int)$id ?></p>
        <h2 class="ficha__numero ficha__numero--nome"><?= e($registro['intimado_nome']) ?></h2>
        <div class="ficha__meta">
            <span class="badge <?= e(Intimacoes::classeBadgeStatus($registro['status'])) ?>"><?= e(Intimacoes::STATUS[$registro['status']]) ?></span>
            <?php if ($situacao !== null): ?>
                <span class="badge <?= e(Intimacoes::classeBadgeSituacao($situacao)) ?>"><?= e(Intimacoes::SITUACOES[$situacao]) ?></span>
            <?php endif; ?>
            <?php if ($dias !== null): ?>
                <span class="dias-restantes dias-restantes--<?= e(strtolower((string)$situacao)) ?>"><?= e(Intimacoes::textoDiasRestantes($dias)) ?></span>
            <?php endif; ?>
            <span class="badge"><?= e(Intimacoes::TIPOS_PROCESSO[$registro['processo_tipo']]) ?> · <?= e($registro['processo_numero']) ?></span>
        </div>
    </div>
    <div class="acoes-linha acoes-linha--quebra">
        <a class="btn btn--ghost btn--sm" href="<?= e(url('intimacoes-pessoais.php')) ?>">‹ Voltar</a>
        <?php if ($podeAlterar): ?>
            <?php if ($aberta): ?>
                <button type="button" class="btn btn--primario btn--sm" data-modal-abrir="modalCumprimentoIntimacao"
                        data-modal-titulo="Registrar cumprimento — <?= e($registro['processo_numero']) ?>"
                        data-modal-dados='<?= e(json_encode(['id' => (int)$id, 'data_cumprimento' => date('Y-m-d')])) ?>'
                        data-prazo="<?= e($registro['data_prazo']) ?>" data-recebimento="<?= e($registro['data_recebimento']) ?>"
                        data-intimado="<?= e($registro['intimado_nome']) ?>">✓ Registrar cumprimento</button>
            <?php endif; ?>
            <a class="btn btn--secundario btn--sm" href="<?= e(url('editar-intimacao.php?id=' . $id)) ?>">✎ Editar</a>
            <button type="button" class="btn btn--perigo btn--sm" data-modal-abrir="modalExcluirIntimacao"
                    data-modal-titulo="Excluir a intimação nº <?= (int)$id ?>?" data-modal-dados='<?= e(json_encode(['id' => (int)$id])) ?>'>🗑 Excluir</button>
        <?php endif; ?>
    </div>
</div>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Processo vinculado</h2></div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao">
                <span class="definicao__rotulo">Processo</span>
                <span class="definicao__valor"><a class="mono" href="<?= e(url(Intimacoes::urlProcesso($registro['processo_tipo'], (int)$registro['processo_id']))) ?>"><?= e($registro['processo_numero']) ?></a></span>
            </div>
            <div class="definicao"><span class="definicao__rotulo">Tipo</span><span class="definicao__valor"><?= e(Intimacoes::TIPOS_PROCESSO[$registro['processo_tipo']]) ?></span></div>
            <div class="definicao definicao--larga"><span class="definicao__rotulo">Dados do processo</span><span class="definicao__valor"><?= ou_traco($processo['detalhe'] ?? null) ?></span></div>
        </div>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Intimação</h2></div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao"><span class="definicao__rotulo">Pessoa intimada</span><span class="definicao__valor"><?= $agente('intimado', (int)$registro['agente_intimado_id']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Recebido por</span><span class="definicao__valor"><?= $agente('recebedor', (int)$registro['agente_recebedor_id']) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Data do recebimento</span><span class="definicao__valor"><?= e(data_br($registro['data_recebimento'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Data do prazo</span><span class="definicao__valor"><strong><?= e(data_br($registro['data_prazo'])) ?></strong></span></div>
            <div class="definicao"><span class="definicao__rotulo">Dias restantes</span><span class="definicao__valor"><?= e(Intimacoes::textoDiasRestantes($dias)) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Data do cumprimento</span><span class="definicao__valor"><?= e(data_br($registro['data_cumprimento'])) ?></span></div>
            <div class="definicao"><span class="definicao__rotulo">Documento comprobatório</span><span class="definicao__valor"><?= ou_traco($registro['documento_referencia']) ?></span></div>
            <div class="definicao definicao--larga"><span class="definicao__rotulo">Observações</span><span class="definicao__valor texto-longo"><?= nl2br(ou_traco($registro['observacoes'])) ?></span></div>
        </div>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Registro no sistema</h2>
            <p class="card__subtitulo">Quem registrou no sistema não é necessariamente quem recebeu a intimação.</p>
        </div>
    </div>
    <div class="card__corpo">
        <div class="definicoes">
            <div class="definicao">
                <span class="definicao__rotulo">Registrado em</span>
                <span class="definicao__valor"><?= e(datahora_br($registro['created_at'])) ?><br><span class="texto-secundario"><?= e($registro['criado_por_nome'] ?? 'Não identificado') ?></span></span>
            </div>
            <div class="definicao">
                <span class="definicao__rotulo">Última alteração</span>
                <span class="definicao__valor"><?= e(datahora_br($registro['updated_at'])) ?><br><span class="texto-secundario"><?= e($registro['atualizado_por_nome'] ?? 'Não identificado') ?></span></span>
            </div>
        </div>
    </div>
</section>

<section class="card" id="historico">
    <div class="card__cabecalho"><h2 class="card__titulo">Histórico de alterações e cumprimento</h2></div>
    <?php require APP_ROOT . '/Views/partials/historico-auditoria.php'; ?>
</section>

<?php
if ($podeAlterar) {
    $retornoIntimacao = 'intimacao.php?id=' . $id;
    require APP_ROOT . '/Views/partials/modais-intimacao.php';
}
?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
