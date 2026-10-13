<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\EventoAdministrativoRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\ProcessoExtrajudicialRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Repositories\VinculoExtrajudicialRepository;
use App\Repositories\VinculoRepository;
use App\Services\ProcessoAdministrativoService;
use App\Validators\Normalizador;

$id = entrada_int('id');
if ($id === null) {
    flash('erro', 'Processo administrativo não informado.');
    redirecionar('processos-administrativos.php');
}

$repo = new ProcessoAdministrativoRepository();
$registro = $repo->buscarPorId($id);
if ($registro === null) {
    flash('erro', 'Processo administrativo não encontrado.');
    redirecionar('processos-administrativos.php');
}

$servico = new ProcessoAdministrativoService();
$repoVin = new VinculoRepository();
$erros = [];

$dados = [
    'protocolo'     => $registro['protocolo'],
    'data_abertura' => $registro['data_abertura'] ?? '',
    'objeto'        => $registro['objeto'] ?? '',
    'procedimento'  => $registro['procedimento'] ?? '',
    'prazo'         => $registro['prazo'] ?? '',
    'prazo_hora'    => $registro['prazo_hora'] ?? '',
    'origem'        => $registro['origem'],
    'data_extracao' => $registro['data_extracao'] ?? '',
    'url_origem'    => $registro['url_origem'] ?? '',
    'extrator_versao' => $registro['extrator_versao'] ?? '',
    'situacao'      => $registro['situacao'] ?? '',
    'situacao_descricao'       => $registro['situacao_descricao'] ?? '',
    'responsavel_nome'         => $registro['responsavel_nome'] ?? '',
    'responsavel_matricula'    => $registro['responsavel_matricula'] ?? '',
    'responsavel_lotacao'      => $registro['responsavel_lotacao'] ?? '',
    'responsavel_lotacao_nome' => $registro['responsavel_lotacao_nome'] ?? '',
    'eventos_json'  => '', // vazio = mantém as movimentações gravadas
];
$eventos = (new EventoAdministrativoRepository())->listarPorProcesso($id);
$eventosSalvos = true;

$judiciaisSelecionados = array_map(static fn(array $v): array => [
    'id'      => (int)$v['id'],
    'rotulo'  => $v['numero_cnj'],
    'detalhe' => trim(($v['tribunal'] ?? '') . ' ' . ($v['orgao_julgador'] ?? '')),
], $repoVin->judiciaisDoAdministrativo($id));
$extrajudiciaisSelecionados = itens_vinculo_extrajudicial((new VinculoExtrajudicialRepository())->extrajudiciaisDoAdministrativo($id));

$procedimentosSugeridos = array_unique(array_merge(
    Normalizador::PROCEDIMENTOS_SUGERIDOS,
    $repo->procedimentosDistintos()
));
sort($procedimentosSugeridos, SORT_FLAG_CASE | SORT_STRING);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();

    $dados = [
        'protocolo'     => entrada('protocolo'),
        'data_abertura' => entrada('data_abertura'),
        'objeto'        => entrada('objeto'),
        'procedimento'  => entrada('procedimento'),
        'prazo'         => entrada('prazo'),
        'prazo_hora'    => entrada('prazo_hora'),
        'origem'        => entrada('origem') ?: $registro['origem'],
        'data_extracao' => entrada('data_extracao'),
        'url_origem'    => entrada('url_origem'),
        'extrator_versao' => entrada('extrator_versao'),
        'judiciais_ids' => array_map('intval', (array)($_POST['judiciais_ids'] ?? [])),
    ] + entrada_situacao_pa();
    if (entrada('extrajudiciais_enviados') === '1') {
        $dados['extrajudiciais_ids'] = array_map('intval', (array)($_POST['extrajudiciais_ids'] ?? []));
    }

    try {
        // Movimentações recém-extraídas substituem as gravadas na reexibição.
        $extraidos = ProcessoAdministrativoService::eventosDaEntrada($dados);
        if ($extraidos !== null) {
            $eventos = $extraidos;
            $eventosSalvos = false;
        }
        $servico->atualizar($id, $dados);
        flash('sucesso', 'Processo administrativo atualizado com sucesso.');
        redirecionar('processo-administrativo.php?id=' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros();
        if ($erros === []) {
            $erros['_geral'] = $e->getMessage();
        }
    } catch (Throwable $e) {
        $erros['_geral'] = 'Erro ao salvar: ' . $e->getMessage();
    }

    $repoJud = new ProcessoJudicialRepository();
    $judiciaisSelecionados = [];
    foreach ($dados['judiciais_ids'] as $jid) {
        $pj = $repoJud->buscarPorId($jid);
        if ($pj !== null) {
            $judiciaisSelecionados[] = [
                'id'      => (int)$pj['id'],
                'rotulo'  => $pj['numero_cnj'],
                'detalhe' => trim(($pj['tribunal'] ?? '') . ' ' . ($pj['orgao_julgador'] ?? '')),
            ];
        }
    }
    if (isset($dados['extrajudiciais_ids'])) {
        $repoPe = new ProcessoExtrajudicialRepository();
        $extrajudiciaisSelecionados = itens_vinculo_extrajudicial(array_filter(array_map(
            static fn(int $pe): ?array => $repoPe->buscarPorId($pe),
            $dados['extrajudiciais_ids']
        )));
    }
}

$titulo      = 'Editar Processo Administrativo';
$menuAtivo   = 'pa-lista';
$breadcrumbs = [
    'Processos Administrativos' => 'processos-administrativos.php',
    $registro['protocolo']      => 'processo-administrativo.php?id=' . $id,
    'Editar'                    => null,
];
$scripts = ['extracao-ui.js', 'vinculos.js', 'form-processo-administrativo.js'];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<form method="post" id="formProcessoAdministrativo" novalidate>
    <?= Csrf::campo() ?>
    <input type="hidden" name="origem" value="<?= e($dados['origem']) ?>">
    <input type="hidden" name="data_extracao" value="<?= e($dados['data_extracao']) ?>">
    <input type="hidden" name="url_origem" value="<?= e($dados['url_origem']) ?>">
    <input type="hidden" name="extrator_versao" value="<?= e($dados['extrator_versao']) ?>">

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Identificação</h2>
                <p class="card__subtitulo">Cadastrado em <?= e(datahora_br($registro['created_at'])) ?> ·
                    Última atualização em <?= e(datahora_br($registro['updated_at'])) ?></p>
            </div>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="protocolo">Protocolo <span class="obrigatorio">*</span></label>
                    <input class="input mono <?= isset($erros['protocolo']) ? 'is-invalido' : '' ?>"
                           type="text" id="protocolo" name="protocolo" required
                           value="<?= e($dados['protocolo']) ?>" autocomplete="off">
                    <?php if (isset($erros['protocolo'])): ?>
                        <span class="campo__erro"><?= e($erros['protocolo']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="extracao mt-16">
                <p class="extracao__titulo">Atualizar pelo SIGADOC</p>
                <p class="extracao__descricao">
                    Reexecuta a extração e substitui os campos pelos valores da página oficial. A revisão continua sendo obrigatória.
                </p>
                <button type="button" class="btn btn--extracao" id="btnCompletarSigadoc">⬇ Completar cadastro pelo SIGADOC</button>
                <div class="extracao__status" id="statusSigadoc" role="status" aria-live="polite"></div>
                <div class="proveniencia" data-proveniencia>
                    <?php if ($registro['origem'] !== 'MANUAL'): ?>
                        <span class="badge badge--azul">Origem: <?= e($registro['origem']) ?></span>
                        <span>Extraído em: <?= e(datahora_br($registro['data_extracao'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card__cabecalho"><h2 class="card__titulo">Dados do processo</h2></div>
        <div class="card__corpo">
            <div class="form-grid">
                <div class="campo campo--3">
                    <label class="campo__rotulo" for="data_abertura">Data de Abertura</label>
                    <input class="input <?= isset($erros['data_abertura']) ? 'is-invalido' : '' ?>"
                           type="date" id="data_abertura" name="data_abertura" value="<?= e($dados['data_abertura']) ?>">
                    <?php if (isset($erros['data_abertura'])): ?>
                        <span class="campo__erro"><?= e($erros['data_abertura']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="campo campo--5">
                    <label class="campo__rotulo" for="procedimento">Procedimento</label>
                    <input class="input <?= isset($erros['procedimento']) ? 'is-invalido' : '' ?>"
                           type="text" id="procedimento" name="procedimento" list="listaProcedimentos"
                           maxlength="160" value="<?= e($dados['procedimento']) ?>"
                           placeholder="Ex.: Fornecimento de Produto de Saúde" autocomplete="off">
                    <datalist id="listaProcedimentos">
                        <?php foreach ($procedimentosSugeridos as $sugestao): ?>
                            <option value="<?= e($sugestao) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <?php if (isset($erros['procedimento'])): ?>
                        <span class="campo__erro"><?= e($erros['procedimento']) ?></span>
                    <?php else: ?>
                        <span class="campo__ajuda">Selecione uma sugestão ou digite outro procedimento.</span>
                    <?php endif; ?>
                </div>

                <?php require APP_ROOT . '/Views/partials/campos-prazo-pa.php'; ?>

                <div class="campo">
                    <label class="campo__rotulo" for="objeto">Objeto</label>
                    <textarea class="textarea" id="objeto" name="objeto" rows="4"><?= e($dados['objeto']) ?></textarea>
                </div>
            </div>
        </div>
    </section>

    <?php require APP_ROOT . '/Views/partials/form-pa-situacao.php'; ?>

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Processos Judiciais Relacionados</h2>
                <p class="card__subtitulo">Remover um vínculo não exclui nenhum processo</p>
            </div>
        </div>
        <div class="card__corpo">
            <div class="alert alert--info" id="sugestaoVinculo" hidden></div>
            <?php
            $tipoAlvo = 'judicial';
            $campoNome = 'judiciais_ids[]';
            $selecionados = $judiciaisSelecionados;
            require APP_ROOT . '/Views/partials/vinculos-widget.php';
            ?>
        </div>
    </section>

    <section class="card" id="extrajudiciais">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Processos Extrajudiciais Relacionados</h2>
                <p class="card__subtitulo">Procedimentos do Ministério Público, Defensoria, Tribunal de Contas… · remover um vínculo não exclui nenhum processo</p>
            </div>
        </div>
        <div class="card__corpo">
            <?php if (isset($erros['extrajudiciais'])): ?>
                <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erros['extrajudiciais']) ?></span></div>
            <?php endif; ?>
            <input type="hidden" name="extrajudiciais_enviados" value="1">
            <?php
            $tipoAlvo = 'extrajudicial';
            $campoNome = 'extrajudiciais_ids[]';
            $selecionados = $extrajudiciaisSelecionados;
            require APP_ROOT . '/Views/partials/vinculos-widget.php';
            ?>
        </div>
    </section>

    <div class="card__rodape" style="border-radius:var(--raio);border:1px solid var(--cinza-200)">
        <button type="submit" class="btn btn--primario">💾 Salvar alterações</button>
        <a class="btn btn--ghost" href="<?= e(url('processo-administrativo.php?id=' . $id)) ?>">Cancelar</a>
    </div>
</form>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
