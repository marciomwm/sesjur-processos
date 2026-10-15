<?php
/**
 * Modais de providencia rapida da ficha do processo administrativo: Nova
 * Acao, Nova Solicitacao e Nova Anotacao, com os mesmos campos das paginas
 * de cadastro (campos-*.php). Processo, autor, data/hora e tipo nao sao
 * digitados: vem da ficha, da sessao e do banco.
 * Variaveis: $registro (processo), $lotacoes (LotacaoRepository::opcoes)
 */
use App\Core\Auth;
use App\Core\Csrf;

$autor = Auth::usuario();
$modaisProvidencia = [
    'acao' => [
        'id'        => 'modalNovaAcao',
        'titulo'    => 'Nova tarefa',
        'descricao' => 'Atividade que deverá ou poderá ser executada: elaborar manifestação, encaminhar o processo, revisar documentos…',
        'campos'    => 'campos-acao.php',
        'dados'     => ['status' => 'PENDENTE'],
        'salvar'    => 'Salvar tarefa',
    ],
    'solicitacao' => [
        'id'        => 'modalNovaSolicitacao',
        'titulo'    => 'Nova solicitação',
        'descricao' => 'Demanda dirigida a uma unidade por expediente do SIGADOC: documentos, informações, manifestação técnica…',
        'campos'    => 'campos-solicitacao.php',
        'dados'     => ['status' => 'AGUARDANDO', 'data_solicitacao' => date('Y-m-d')],
        'salvar'    => 'Salvar solicitação',
    ],
    'anotacao' => [
        'id'        => 'modalNovaAnotacao',
        'titulo'    => 'Nova anotação',
        'descricao' => 'Informação livre no histórico do processo: observação interna, registro de contato, memória de acompanhamento…',
        'campos'    => 'campos-anotacao.php',
        'dados'     => [],
        'salvar'    => 'Salvar anotação',
    ],
];
?>
<?php foreach ($modaisProvidencia as $tipoProvidencia => $m): ?>
    <?php
    $dados = $m['dados'];
    $erros = [];
    $prefixoId = $tipoProvidencia . '-';
    ?>
    <dialog class="modal" id="<?= e($m['id']) ?>" aria-labelledby="<?= e($m['id']) ?>Titulo">
        <form method="post" action="<?= e(url('providencia-rapida.php')) ?>" class="modal__form" data-modal-form novalidate>
            <?= Csrf::campo() ?>
            <input type="hidden" name="tipo" value="<?= e($tipoProvidencia) ?>">
            <input type="hidden" name="processo_id" value="<?= (int)$registro['id'] ?>">
            <header class="modal__cabecalho">
                <div>
                    <h2 class="modal__titulo" id="<?= e($m['id']) ?>Titulo" data-modal-titulo><?= e($m['titulo']) ?></h2>
                    <p class="modal__contexto">
                        <span>Processo <strong class="mono"><?= e($registro['protocolo']) ?></strong></span>
                        <span>Registrado por <strong><?= e($autor['nome'] ?? 'usuário da sessão') ?></strong></span>
                        <span>Data e hora gravadas ao salvar</span>
                    </p>
                </div>
                <button type="button" class="modal__fechar" data-modal-fechar aria-label="Fechar">×</button>
            </header>
            <div class="modal__corpo">
                <div class="alert alert--erro" data-modal-erro tabindex="-1" role="alert" hidden></div>
                <p class="modal__descricao"><?= e($m['descricao']) ?></p>
                <div class="form-grid">
                    <?php require __DIR__ . '/' . $m['campos']; ?>
                </div>
            </div>
            <footer class="modal__rodape">
                <button type="button" class="btn btn--ghost" data-modal-fechar>Cancelar</button>
                <button type="submit" class="btn btn--primario">💾 <?= e($m['salvar']) ?></button>
            </footer>
        </form>
    </dialog>
<?php endforeach; ?>
