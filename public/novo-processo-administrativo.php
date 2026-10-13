<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Services\ProcessoAdministrativoService;
use App\Validators\Normalizador;
use App\Validators\ProtocoloValidator;

$servico = new ProcessoAdministrativoService();
$erros = [];
$dados = [
    'protocolo'     => entrada('protocolo', $_GET),
    'data_abertura' => '',
    'objeto'        => '',
    'procedimento'  => '',
    'origem'        => 'MANUAL',
    'data_extracao' => '',
    'url_origem'    => '',
    'extrator_versao' => '',
];
$judiciaisSelecionados = [];
$eventos = [];
$procedimentosSugeridos = array_unique(array_merge(
    Normalizador::PROCEDIMENTOS_SUGERIDOS,
    (new ProcessoAdministrativoRepository())->procedimentosDistintos()
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
        'origem'        => entrada('origem') ?: 'MANUAL',
        'data_extracao' => entrada('data_extracao'),
        'url_origem'    => entrada('url_origem'),
        'extrator_versao' => entrada('extrator_versao'),
        'judiciais_ids' => array_map('intval', (array)($_POST['judiciais_ids'] ?? [])),
    ] + entrada_situacao_pa();

    try {
        // Reexibe as movimentações extraídas caso a validação falhe.
        $eventos = ProcessoAdministrativoService::eventosDaEntrada($dados) ?? [];
        $id = $servico->criar($dados);
        flash('sucesso', 'Processo administrativo cadastrado com sucesso.');
        redirecionar('processo-administrativo.php?id=' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros();
        if ($erros === []) {
            $erros['_geral'] = $e->getMessage();
        }
    } catch (Throwable $e) {
        $erros['_geral'] = 'Erro ao salvar: ' . $e->getMessage();
    }

    // Recompoe os vinculos escolhidos para reexibir o formulario.
    $repoJud = new ProcessoJudicialRepository();
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
}

$titulo      = 'Novo Processo Administrativo';
$menuAtivo   = 'pa-novo';
$breadcrumbs = ['Processos Administrativos' => 'processos-administrativos.php', 'Novo' => null];
$scripts     = ['extracao-ui.js', 'vinculos.js', 'form-processo-administrativo.js', 'criacao-extensao.js'];

// Protocolo vindo da extensão (ou de um link) que já está cadastrado.
$existente = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $dados['protocolo'] !== '') {
    $protocoloGet = ProtocoloValidator::normalizar($dados['protocolo']);
    $existente = $protocoloGet !== null ? (new ProcessoAdministrativoRepository())->buscarPorProtocolo($protocoloGet) : null;
}

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if ($existente !== null): ?>
    <div class="alert alert--info" data-processo-existente>
        <span class="alert__icone">ℹ</span>
        <span>O processo <strong class="mono"><?= e($existente['protocolo']) ?></strong> já está cadastrado.
            <a href="<?= e(url('processo-administrativo.php?id=' . $existente['id'])) ?>">Abrir a ficha</a> ou
            <a href="<?= e(url('editar-processo-administrativo.php?id=' . $existente['id'])) ?>">atualizar pelo SIGADOC</a>.</span>
    </div>
<?php endif; ?>

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
                <p class="card__subtitulo">Processo administrativo proveniente do SIGADOC</p>
            </div>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="protocolo">Protocolo <span class="obrigatorio">*</span></label>
                    <input class="input mono <?= isset($erros['protocolo']) ? 'is-invalido' : '' ?>"
                           type="text" id="protocolo" name="protocolo" required
                           value="<?= e($dados['protocolo']) ?>"
                           placeholder="SEPLAG-PRO-2026/16696" autocomplete="off">
                    <?php if (isset($erros['protocolo'])): ?>
                        <span class="campo__erro"><?= e($erros['protocolo']) ?></span>
                    <?php else: ?>
                        <span class="campo__ajuda">Formato: SIGLA-ESPÉCIE-ANO/NÚMERO. Campo obrigatório e único.</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="extracao mt-16">
                <p class="extracao__titulo">Preenchimento automático pelo SIGADOC</p>
                <p class="extracao__descricao">
                    Autentique-se no SIGADOC no seu navegador e mantenha a página do processo aberta.
                    A extensão lê o DOM da página que você já tem permissão para acessar, valida o protocolo
                    e devolve os dados para revisão. <strong>Nada é salvo automaticamente.</strong>
                </p>
                <button type="button" class="btn btn--extracao" id="btnCompletarSigadoc">
                    ⬇ Completar cadastro pelo SIGADOC
                </button>
                <div class="extracao__status" id="statusSigadoc" role="status" aria-live="polite"></div>
                <div class="proveniencia" data-proveniencia></div>
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
                           type="date" id="data_abertura" name="data_abertura"
                           value="<?= e($dados['data_abertura']) ?>">
                    <?php if (isset($erros['data_abertura'])): ?>
                        <span class="campo__erro"><?= e($erros['data_abertura']) ?></span>
                    <?php else: ?>
                        <span class="campo__ajuda">Exibição: 17/09/2026 · Armazenamento: 2026-09-17</span>
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
                    <textarea class="textarea <?= isset($erros['objeto']) ? 'is-invalido' : '' ?>"
                              id="objeto" name="objeto" rows="4"
                              placeholder="Descrição do objeto do processo administrativo"><?= e($dados['objeto']) ?></textarea>
                    <?php if (isset($erros['objeto'])): ?>
                        <span class="campo__erro"><?= e($erros['objeto']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <?php
    $eventosSalvos = false;
    require APP_ROOT . '/Views/partials/form-pa-situacao.php';
    ?>

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Processos Judiciais Relacionados</h2>
                <p class="card__subtitulo">Relacionamento N:N — um processo administrativo pode ter nenhum, um ou vários processos judiciais</p>
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

    <div class="card__rodape" style="border-radius:var(--raio);border:1px solid var(--cinza-200)">
        <button type="submit" class="btn btn--primario">💾 Salvar Processo</button>
        <a class="btn btn--ghost" href="<?= e(url('processos-administrativos.php')) ?>">Cancelar</a>
        <span class="texto-secundario">Revise os dados antes de salvar.</span>
    </div>
</form>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
