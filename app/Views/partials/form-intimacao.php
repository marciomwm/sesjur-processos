<?php
/**
 * Formulario de Intimacao Pessoal (nova e editar).
 * Variaveis: $dados (valores do formulario), $erros, $rotulos
 *            (IntimacaoPessoalService::rotulosFormulario), $prazoOriginal
 *            (?string - so na edicao: mudar o prazo exige justificativa),
 *            $meuAgente (?array - agente do usuario logado, para "Recebi eu"),
 *            $retorno, $rotuloSalvar
 */
use App\Core\Csrf;
use App\Services\IntimacaoPessoalService;

$hoje = date('Y-m-d');
$erro = static fn(string $campo): string => isset($erros[$campo]) ? 'is-invalido' : '';
$mensagem = static function (string $campo, string $ajuda = '') use ($erros): void {
    if (isset($erros[$campo])) {
        echo '<span class="campo__erro">' . e($erros[$campo]) . '</span>';
    } elseif ($ajuda !== '') {
        echo '<span class="campo__ajuda">' . e($ajuda) . '</span>';
    }
};
$cumprida = ($dados['status'] ?? '') === 'CUMPRIDA';
$prazoMudou = $prazoOriginal !== null && ($dados['data_prazo'] ?? '') !== $prazoOriginal;

$botoesRecebedor = '<span class="campo-pesquisa__atalhos">'
    . '<button type="button" class="btn btn--ghost btn--xs" data-copiar-de="agente-intimado" data-copiar-para="agente-recebedor">= Pessoa intimada</button>'
    . ($meuAgente !== null
        ? '<button type="button" class="btn btn--ghost btn--xs" data-preencher-pesquisa="agente-recebedor" data-item="' . e(json_encode($meuAgente, JSON_UNESCAPED_UNICODE)) . '">Recebi eu</button>'
        : '')
    . '</span>';
?>
<form method="post" class="form-intimacao" data-form-intimacao novalidate>
    <?= Csrf::campo() ?>
    <input type="hidden" name="retorno" value="<?= e($retorno) ?>">

    <?php if (isset($erros['confirmar_duplicidade'])): ?>
        <div class="alert alert--aviso" role="alert">
            <span class="alert__icone">!</span>
            <span>
                <?= e($erros['confirmar_duplicidade']) ?>
                <label class="checkbox-linha mt-8">
                    <input type="checkbox" name="confirmar_duplicidade" value="1">
                    <strong>Confirmo que é uma nova intimação, distinta da já registrada.</strong>
                </label>
            </span>
        </div>
    <?php endif; ?>

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">1. Processo vinculado</h2>
                <p class="card__subtitulo">Somente processos já cadastrados no SESJUR — judiciais, extrajudiciais ou administrativos.</p>
            </div>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <?php
                $ac = [
                    'id' => 'processo-busca', 'nome' => 'processo_id', 'nomeTipo' => 'processo_tipo',
                    'rotulo' => 'Processo judicial ou extrajudicial', 'obrigatorio' => true,
                    'endpoint' => 'api/pesquisar-processos.php', 'minimo' => 3,
                    'placeholder' => 'Número CNJ, protocolo SIGADOC ou protocolo do órgão externo (mín. 3 caracteres)…',
                    'ajuda' => 'Pesquise pelo número ou protocolo e escolha o processo na lista.',
                    'vazio' => 'Nenhum processo encontrado. Cadastre o processo antes de registrar a intimação.',
                    'valor' => $rotulos['processo'], 'erro' => $erros['processo_id'] ?? null,
                ];
                require __DIR__ . '/campo-pesquisa.php';
                ?>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">2. Agentes públicos</h2>
                <p class="card__subtitulo">Do cadastro de Agentes Públicos. Quem foi intimado e quem recebeu podem ser pessoas diferentes;
                    o registro no sistema fica em seu nome, à parte.</p>
            </div>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <?php
                $ac = [
                    'id' => 'agente-intimado', 'nome' => 'agente_intimado_id', 'classe' => 'campo--6',
                    'rotulo' => 'Pessoa intimada', 'obrigatorio' => true,
                    'endpoint' => 'api/pesquisar-agentes-publicos.php', 'minimo' => 2,
                    'placeholder' => 'Nome ou matrícula…', 'ajuda' => 'Pesquise pelo nome ou pela matrícula.',
                    'vazio' => 'Nenhum agente público encontrado.',
                    'valor' => $rotulos['intimado'], 'erro' => $erros['agente_intimado_id'] ?? null,
                ];
                require __DIR__ . '/campo-pesquisa.php';

                $ac = [
                    'id' => 'agente-recebedor', 'nome' => 'agente_recebedor_id', 'classe' => 'campo--6',
                    'rotulo' => 'Recebido por', 'obrigatorio' => true,
                    'endpoint' => 'api/pesquisar-agentes-publicos.php', 'minimo' => 2,
                    'placeholder' => 'Nome ou matrícula…', 'ajuda' => 'Agente que efetivamente recebeu a comunicação.',
                    'vazio' => 'Nenhum agente público encontrado.', 'extra' => $botoesRecebedor,
                    'valor' => $rotulos['recebedor'], 'erro' => $erros['agente_recebedor_id'] ?? null,
                ];
                require __DIR__ . '/campo-pesquisa.php';
                ?>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">3. Datas e cumprimento</h2>
                <p class="card__subtitulo">O prazo é a data-limite informada — o sistema não calcula prazos processuais. A situação
                    (vencida, próxima do vencimento, cumprida fora do prazo…) é calculada a partir destas datas.</p>
            </div>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <div class="campo campo--4">
                    <label class="campo__rotulo" for="data_recebimento">Data do recebimento <span class="obrigatorio">*</span></label>
                    <input class="input <?= $erro('data_recebimento') ?>" type="date" id="data_recebimento" name="data_recebimento" required
                           max="<?= e($hoje) ?>" value="<?= e($dados['data_recebimento'] ?? '') ?>" data-recebimento>
                    <?php $mensagem('data_recebimento'); ?>
                </div>
                <div class="campo campo--4">
                    <label class="campo__rotulo" for="data_prazo">Data do prazo <span class="obrigatorio">*</span></label>
                    <input class="input <?= $erro('data_prazo') ?>" type="date" id="data_prazo" name="data_prazo" required
                           min="<?= e($dados['data_recebimento'] ?? '') ?>" value="<?= e($dados['data_prazo'] ?? '') ?>"
                           data-prazo <?= $prazoOriginal !== null ? 'data-original="' . e($prazoOriginal) . '"' : '' ?>>
                    <?php $mensagem('data_prazo', 'Data-limite para o cumprimento.'); ?>
                </div>
                <div class="campo campo--4">
                    <label class="campo__rotulo" for="status">Status <span class="obrigatorio">*</span></label>
                    <select class="select <?= $erro('status') ?>" id="status" name="status" data-status>
                        <?php foreach (IntimacaoPessoalService::STATUS as $codigo => $rotulo): ?>
                            <option value="<?= e($codigo) ?>" <?= ($dados['status'] ?? 'PENDENTE') === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php $mensagem('status'); ?>
                </div>
                <div class="campo campo--4" data-bloco-cumprimento <?= $cumprida || isset($erros['data_cumprimento']) ? '' : 'hidden' ?>>
                    <label class="campo__rotulo" for="data_cumprimento">Data do cumprimento <span class="obrigatorio">*</span></label>
                    <input class="input <?= $erro('data_cumprimento') ?>" type="date" id="data_cumprimento" name="data_cumprimento"
                           max="<?= e($hoje) ?>" value="<?= e($dados['data_cumprimento'] ?? '') ?>" <?= $cumprida ? 'required' : '' ?>>
                    <?php $mensagem('data_cumprimento', 'Data efetiva da providência, não a data deste registro.'); ?>
                </div>
                <?php if ($prazoOriginal !== null): ?>
                    <div class="campo" data-bloco-justificativa <?= $prazoMudou || isset($erros['justificativa']) ? '' : 'hidden' ?>>
                        <label class="campo__rotulo" for="justificativa">Justificativa da retificação do prazo <span class="obrigatorio">*</span></label>
                        <textarea class="textarea textarea--curta <?= $erro('justificativa') ?>" id="justificativa" name="justificativa"
                                  maxlength="<?= IntimacaoPessoalService::JUSTIFICATIVA_MAX ?>"
                                  placeholder="Ex.: prazo registrado com erro de digitação; a intimação fixa 20 dias."><?= e($dados['justificativa'] ?? '') ?></textarea>
                        <?php $mensagem('justificativa', 'Prazo anterior: ' . data_br($prazoOriginal) . '. Fica registrada no histórico.'); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card__cabecalho">
            <div><h2 class="card__titulo">4. Informações complementares</h2></div>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <div class="campo">
                    <label class="campo__rotulo" for="documento_referencia">Documento comprobatório</label>
                    <input class="input <?= $erro('documento_referencia') ?>" type="text" id="documento_referencia" name="documento_referencia"
                           maxlength="<?= IntimacaoPessoalService::DOCUMENTO_MAX ?>" autocomplete="off"
                           placeholder="Expediente no SIGADOC (ex.: SES-OFI-2026/12345) ou outra referência" value="<?= e($dados['documento_referencia'] ?? '') ?>">
                    <?php $mensagem('documento_referencia'); ?>
                </div>
                <div class="campo">
                    <label class="campo__rotulo" for="observacoes">Observações</label>
                    <textarea class="textarea <?= $erro('observacoes') ?>" id="observacoes" name="observacoes"
                              maxlength="<?= IntimacaoPessoalService::OBSERVACOES_MAX ?>"><?= e($dados['observacoes'] ?? '') ?></textarea>
                    <?php $mensagem('observacoes'); ?>
                </div>
            </div>
        </div>
        <div class="card__rodape">
            <button type="submit" class="btn btn--primario">💾 <?= e($rotuloSalvar) ?></button>
            <a class="btn btn--ghost" href="<?= e(url($retorno)) ?>">Cancelar</a>
        </div>
    </section>
</form>
