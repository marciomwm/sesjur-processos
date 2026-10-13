<?php
/**
 * Formulario de Processo Extrajudicial (novo e editar).
 * Variaveis: $dados, $erros, $modo ('novo'|'editar'), $instituicoes (opcoes), $orgaos
 *            (OrgaoExternoRepository::opcoesComInstituicao), $administrativosSelecionados, $urlCancelar
 */
use App\Core\Auth;
use App\Core\Csrf;
use App\Services\ProcessoExtrajudicialService;

$erro = static fn(string $campo): string => isset($erros[$campo]) ? 'is-invalido' : '';
$mensagem = static function (string $campo, string $ajuda = '') use ($erros): string {
    if (isset($erros[$campo])) {
        return '<span class="campo__erro">' . e($erros[$campo]) . '</span>';
    }
    return $ajuda !== '' ? '<span class="campo__ajuda">' . e($ajuda) . '</span>' : '';
};
$orgaoEscolhido = (string)($dados['orgao_externo_id'] ?? '');
$novoOrgao = ($dados['orgao_novo'] ?? '') !== '' ? '__novo' : $orgaoEscolhido;
$errosCampos = array_diff_key($erros, ['_geral' => true]);
?>
<form method="post" class="form-unidade" id="formExtrajudicial" novalidate>
    <?= Csrf::campo() ?>

    <?php if ($errosCampos !== []): ?>
        <div class="alert alert--erro" role="alert">
            <span class="alert__icone">✕</span>
            <span>Não foi possível salvar. Corrija <?= count($errosCampos) === 1 ? 'o campo destacado' : 'os ' . count($errosCampos) . ' campos destacados' ?> abaixo.</span>
        </div>
    <?php endif; ?>

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo"><?= $modo === 'novo' ? 'Cadastrar Processo Extrajudicial' : 'Editar Processo Extrajudicial' ?></h2>
                <p class="card__subtitulo">Procedimento originado em instituição externa ao Poder Executivo estadual (Ministério Público, Defensoria, Tribunal de Contas, conselhos…).</p>
            </div>
        </div>
        <div class="card__corpo">
            <fieldset class="grupo-campos">
                <legend>Origem</legend>
                <div class="form-grid">
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="instituicao_externa_id">Instituição <span class="obrigatorio">*</span></label>
                        <select class="select <?= $erro('instituicao_externa_id') ?>" id="instituicao_externa_id" name="instituicao_externa_id" required data-instituicao-extrajudicial>
                            <option value="">Selecione a instituição</option>
                            <?php foreach ($instituicoes as $i): ?>
                                <option value="<?= (int)$i['id'] ?>" <?= (string)($dados['instituicao_externa_id'] ?? '') === (string)$i['id'] ? 'selected' : '' ?>>
                                    <?= e($i['nome']) ?><?= !empty($i['sigla']) ? ' — ' . e($i['sigla']) : '' ?><?= (int)$i['ativo'] === 1 ? '' : ' (inativa)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= $mensagem('instituicao_externa_id') ?>
                        <?php if (Auth::ehAdministrador()): ?>
                            <a class="campo__link" href="<?= e(url('tabela-apoio.php?tipo=instituicoes_externas')) ?>">Gerenciar instituições externas</a>
                        <?php endif; ?>
                    </div>
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="orgao_externo_id">Órgão / Unidade</label>
                        <select class="select <?= $erro('orgao_externo_id') ?>" id="orgao_externo_id" name="orgao_externo_id" data-orgao-extrajudicial>
                            <option value="">— Não informado —</option>
                            <?php foreach ($orgaos as $o): ?>
                                <option value="<?= (int)$o['id'] ?>" data-instituicao="<?= (int)$o['instituicao_externa_id'] ?>"
                                    <?= $novoOrgao === (string)$o['id'] ? 'selected' : '' ?>><?= e($o['nome']) ?><?= (int)$o['ativo'] === 1 ? '' : ' (inativo)' ?></option>
                            <?php endforeach; ?>
                            <option value="__novo" <?= $novoOrgao === '__novo' ? 'selected' : '' ?>>＋ Outro órgão (cadastrar)</option>
                        </select>
                        <?= $mensagem('orgao_externo_id', 'Ex.: 1ª Promotoria de Justiça de Cuiabá') ?>
                        <div class="form-unidade__nivel-novo" data-orgao-novo <?= $novoOrgao === '__novo' ? '' : 'hidden' ?>>
                            <label class="campo__rotulo" for="orgao_novo">Nome do novo órgão</label>
                            <input class="input <?= $erro('orgao_novo') ?>" type="text" id="orgao_novo" name="orgao_novo"
                                   maxlength="<?= ProcessoExtrajudicialService::ORGAO_MAX ?>" value="<?= e($dados['orgao_novo'] ?? '') ?>">
                            <?= $mensagem('orgao_novo', 'Será cadastrado na instituição escolhida ao salvar.') ?>
                        </div>
                    </div>
                </div>
            </fieldset>

            <fieldset class="grupo-campos mb-0">
                <legend>Procedimento</legend>
                <div class="form-grid">
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="protocolo">Protocolo <span class="obrigatorio">*</span></label>
                        <input class="input mono <?= $erro('protocolo') ?>" type="text" id="protocolo" name="protocolo" required
                               maxlength="<?= ProcessoExtrajudicialService::PROTOCOLO_MAX ?>" value="<?= e($dados['protocolo'] ?? '') ?>"
                               placeholder="Ex.: SIMP 000123-001/2026" autocomplete="off">
                        <?= $mensagem('protocolo', 'Número do procedimento na instituição de origem.') ?>
                    </div>
                    <div class="campo campo--3">
                        <label class="campo__rotulo" for="data_expediente">Data <span class="obrigatorio">*</span></label>
                        <input class="input <?= $erro('data_expediente') ?>" type="date" id="data_expediente" name="data_expediente" required
                               max="<?= e(date('Y-m-d')) ?>" value="<?= e($dados['data_expediente'] ?? '') ?>">
                        <?= $mensagem('data_expediente') ?>
                    </div>
                    <div class="campo campo--3">
                        <label class="campo__rotulo" for="situacao">Situação <span class="obrigatorio">*</span></label>
                        <select class="select <?= $erro('situacao') ?>" id="situacao" name="situacao" required>
                            <?php foreach (ProcessoExtrajudicialService::SITUACOES as $codigo => $rotulo): ?>
                                <option value="<?= e($codigo) ?>" <?= ($dados['situacao'] ?? 'EM_ANDAMENTO') === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $mensagem('situacao') ?>
                    </div>
                    <div class="campo">
                        <label class="campo__rotulo" for="assunto">Assunto <span class="obrigatorio">*</span></label>
                        <input class="input <?= $erro('assunto') ?>" type="text" id="assunto" name="assunto" required
                               maxlength="<?= ProcessoExtrajudicialService::ASSUNTO_MAX ?>" value="<?= e($dados['assunto'] ?? '') ?>"
                               placeholder="Ex.: Fornecimento de medicamento oncológico">
                        <?= $mensagem('assunto') ?>
                    </div>
                    <div class="campo">
                        <label class="campo__rotulo" for="descricao">Descrição</label>
                        <textarea class="textarea <?= $erro('descricao') ?>" id="descricao" name="descricao" rows="4"
                                  maxlength="<?= ProcessoExtrajudicialService::TEXTO_MAX ?>"><?= e($dados['descricao'] ?? '') ?></textarea>
                        <?= $mensagem('descricao') ?>
                    </div>
                    <div class="campo">
                        <label class="campo__rotulo" for="observacoes">Observações</label>
                        <textarea class="textarea <?= $erro('observacoes') ?>" id="observacoes" name="observacoes" rows="3"
                                  maxlength="<?= ProcessoExtrajudicialService::TEXTO_MAX ?>"><?= e($dados['observacoes'] ?? '') ?></textarea>
                        <?= $mensagem('observacoes') ?>
                    </div>
                </div>
            </fieldset>
        </div>
    </section>

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Processos Administrativos vinculados</h2>
                <p class="card__subtitulo">Um processo extrajudicial pode repercutir em vários processos administrativos. Remover o vínculo não exclui nenhum processo.</p>
            </div>
        </div>
        <div class="card__corpo">
            <?php if (isset($erros['administrativos'])): ?>
                <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erros['administrativos']) ?></span></div>
            <?php endif; ?>
            <input type="hidden" name="administrativos_enviados" value="1">
            <?php
            $tipoAlvo = 'administrativo';
            $selecionados = $administrativosSelecionados;
            $campoNome = 'administrativos_ids[]';
            require __DIR__ . '/vinculos-widget.php';
            ?>
        </div>
        <div class="card__rodape form-usuario__acoes">
            <a class="btn btn--ghost" href="<?= e($urlCancelar) ?>">Cancelar</a>
            <button type="submit" class="btn btn--primario"><?= $modo === 'novo' ? 'Cadastrar processo extrajudicial' : 'Salvar alterações' ?></button>
        </div>
    </section>
</form>
