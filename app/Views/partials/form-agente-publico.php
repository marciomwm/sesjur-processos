<?php
/**
 * Formulario de Agente Publico (novo e editar).
 * Variaveis: $dados, $erros, $modo ('novo'|'editar'), $cargos, $lotacoes (com instituicao_id),
 *            $instituicoes, $temUsuario, $urlCancelar
 */
use App\Core\Csrf;
use App\Services\AgentePublicoService;
use App\Validators\CpfValidator;
use App\Validators\UsuarioValidator;

$erro = static fn(string $campo): string => isset($erros[$campo]) ? 'is-invalido' : '';
$val  = static fn(string $campo): string => e((string)($dados[$campo] ?? ''));
$mensagem = static function (string $campo, string $ajuda = '') use ($erros): string {
    if (isset($erros[$campo])) {
        return '<span class="campo__erro">' . e($erros[$campo]) . '</span>';
    }
    return $ajuda !== '' ? '<span class="campo__ajuda">' . e($ajuda) . '</span>' : '';
};
// Orgao/Entidade: so filtra as unidades; o que se grava e a unidade.
$lotacaoAtual = (string)($dados['lotacao_id'] ?? '');
$instituicaoAtual = '';
foreach ($lotacoes as $l) {
    if ((string)$l['id'] === $lotacaoAtual) {
        $instituicaoAtual = (string)($l['instituicao_id'] ?? '');
    }
}
$cpfExibido = strlen((string)($dados['cpf'] ?? '')) === 11 ? CpfValidator::formatar($dados['cpf']) : (string)($dados['cpf'] ?? '');
$errosCampos = array_diff_key($erros, ['_geral' => true]);
?>
<form method="post" class="form-usuario" id="formAgente" novalidate>
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
                <h2 class="card__titulo"><?= $modo === 'novo' ? 'Cadastrar Agente Público' : 'Editar Agente Público' ?></h2>
                <p class="card__subtitulo">Pessoa com vínculo funcional ou profissional com a Administração. Campos com <span class="obrigatorio">*</span> são obrigatórios.</p>
            </div>
        </div>
        <div class="card__corpo">
            <?php if ($temUsuario): ?>
                <div class="alert alert--info"><span class="alert__icone">ℹ</span>
                    <span>Este agente tem usuário do sistema: nome, matrícula, cargo, unidade e nascimento alterados aqui valem também para a conta. Desligar o agente inativa o acesso.</span></div>
            <?php endif; ?>

            <fieldset class="grupo-campos">
                <legend>Dados pessoais</legend>
                <div class="form-grid">
                    <div class="campo campo--8">
                        <label class="campo__rotulo" for="nome">Nome completo <span class="obrigatorio">*</span></label>
                        <input class="input <?= $erro('nome') ?>" type="text" id="nome" name="nome" required
                               maxlength="<?= UsuarioValidator::NOME_MAX ?>" autocomplete="off" value="<?= $val('nome') ?>">
                        <?= $mensagem('nome') ?>
                    </div>
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="nome_social">Nome social</label>
                        <input class="input <?= $erro('nome_social') ?>" type="text" id="nome_social" name="nome_social"
                               maxlength="<?= UsuarioValidator::NOME_MAX ?>" autocomplete="off" value="<?= $val('nome_social') ?>">
                        <?= $mensagem('nome_social', 'Quando aplicável.') ?>
                    </div>
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="cpf">CPF</label>
                        <input class="input mono <?= $erro('cpf') ?>" type="text" id="cpf" name="cpf" inputmode="numeric"
                               maxlength="14" placeholder="000.000.000-00" autocomplete="off" value="<?= e($cpfExibido) ?>" data-mascara="cpf">
                        <?= $mensagem('cpf') ?>
                    </div>
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="data_nascimento">Data de nascimento</label>
                        <input class="input <?= $erro('data_nascimento') ?>" type="date" id="data_nascimento" name="data_nascimento"
                               min="<?= e(UsuarioValidator::DATA_MINIMA) ?>" max="<?= e(date('Y-m-d')) ?>" value="<?= $val('data_nascimento') ?>">
                        <?= $mensagem('data_nascimento') ?>
                    </div>
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="telefone">Telefone</label>
                        <input class="input <?= $erro('telefone') ?>" type="tel" id="telefone" name="telefone" inputmode="tel"
                               maxlength="16" placeholder="(65) 99999-9999" value="<?= e(telefone_br($dados['telefone'] ?? '')) ?>" data-mascara="telefone">
                        <?= $mensagem('telefone') ?>
                    </div>
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="email_institucional">E-mail institucional</label>
                        <input class="input <?= $erro('email_institucional') ?>" type="email" id="email_institucional" name="email_institucional"
                               maxlength="<?= UsuarioValidator::EMAIL_MAX ?>" placeholder="nome@ses.mt.gov.br" value="<?= $val('email_institucional') ?>">
                        <?= $mensagem('email_institucional', 'Sugerido como login ao criar o usuário do sistema.') ?>
                    </div>
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="email_alternativo">E-mail alternativo</label>
                        <input class="input <?= $erro('email_alternativo') ?>" type="email" id="email_alternativo" name="email_alternativo"
                               maxlength="<?= UsuarioValidator::EMAIL_MAX ?>" value="<?= $val('email_alternativo') ?>">
                        <?= $mensagem('email_alternativo') ?>
                    </div>
                </div>
            </fieldset>

            <fieldset class="grupo-campos">
                <legend>Dados funcionais</legend>
                <div class="form-grid">
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="matricula">Matrícula <?= $temUsuario ? '<span class="obrigatorio">*</span>' : '' ?></label>
                        <input class="input mono <?= $erro('matricula') ?>" type="text" id="matricula" name="matricula"
                               maxlength="<?= UsuarioValidator::MATRICULA_MAX ?>" autocomplete="off" value="<?= $val('matricula') ?>">
                        <?= $mensagem('matricula', $temUsuario ? '' : 'Obrigatória para criar usuário do sistema.') ?>
                    </div>
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="tipo_vinculo">Tipo de vínculo <span class="obrigatorio">*</span></label>
                        <select class="select <?= $erro('tipo_vinculo') ?>" id="tipo_vinculo" name="tipo_vinculo" required>
                            <option value="">Selecione</option>
                            <?php foreach (AgentePublicoService::TIPOS_VINCULO as $codigo => $rotulo): ?>
                                <option value="<?= e($codigo) ?>" <?= ($dados['tipo_vinculo'] ?? '') === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $mensagem('tipo_vinculo') ?>
                    </div>
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="situacao">Situação <span class="obrigatorio">*</span></label>
                        <select class="select <?= $erro('situacao') ?>" id="situacao" name="situacao" required>
                            <?php foreach (AgentePublicoService::SITUACOES as $codigo => $rotulo): ?>
                                <option value="<?= e($codigo) ?>" <?= ($dados['situacao'] ?? 'ATIVO') === $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $mensagem('situacao') ?>
                    </div>
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="cargo_id">Cargo <?= $temUsuario ? '<span class="obrigatorio">*</span>' : '' ?></label>
                        <select class="select <?= $erro('cargo_id') ?>" id="cargo_id" name="cargo_id">
                            <option value="">— Sem cargo —</option>
                            <?php foreach ($cargos as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= (string)($dados['cargo_id'] ?? '') === (string)$c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['nome']) ?><?= (int)$c['ativo'] === 1 ? '' : ' (inativo)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= $mensagem('cargo_id') ?>
                        <a class="campo__link" href="<?= e(url('tabela-apoio.php?tipo=cargos')) ?>">Gerenciar cargos</a>
                    </div>
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="funcao">Função</label>
                        <input class="input <?= $erro('funcao') ?>" type="text" id="funcao" name="funcao"
                               maxlength="<?= AgentePublicoService::FUNCAO_MAX ?>" placeholder="Ex.: Coordenador" value="<?= $val('funcao') ?>">
                        <?= $mensagem('funcao') ?>
                    </div>
                    <div class="campo campo--3">
                        <label class="campo__rotulo" for="data_ingresso">Data de ingresso</label>
                        <input class="input <?= $erro('data_ingresso') ?>" type="date" id="data_ingresso" name="data_ingresso" value="<?= $val('data_ingresso') ?>">
                        <?= $mensagem('data_ingresso') ?>
                    </div>
                    <div class="campo campo--3">
                        <label class="campo__rotulo" for="data_desligamento">Data de desligamento</label>
                        <input class="input <?= $erro('data_desligamento') ?>" type="date" id="data_desligamento" name="data_desligamento" value="<?= $val('data_desligamento') ?>">
                        <?= $mensagem('data_desligamento') ?>
                    </div>
                </div>
            </fieldset>

            <fieldset class="grupo-campos mb-0">
                <legend>Lotação</legend>
                <div class="form-grid">
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="instituicao_filtro">Órgão ou Entidade</label>
                        <select class="select" id="instituicao_filtro" data-filtro-instituicao="lotacao_id">
                            <option value="">Todos</option>
                            <?php foreach ($instituicoes as $i): ?>
                                <option value="<?= (int)$i['id'] ?>" <?= $instituicaoAtual === (string)$i['id'] ? 'selected' : '' ?>><?= e($i['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="campo__ajuda">É o órgão da unidade escolhida: não é gravado à parte.</span>
                    </div>
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="lotacao_id">Unidade Administrativa <span class="obrigatorio">*</span></label>
                        <select class="select <?= $erro('lotacao_id') ?>" id="lotacao_id" name="lotacao_id" required>
                            <option value="">Selecione a unidade</option>
                            <?php foreach ($lotacoes as $l): ?>
                                <option value="<?= (int)$l['id'] ?>" data-instituicao="<?= (int)($l['instituicao_id'] ?? 0) ?>" <?= $lotacaoAtual === (string)$l['id'] ? 'selected' : '' ?>>
                                    <?= e($l['rotulo'] ?? $l['nome']) ?><?= (int)$l['ativo'] === 1 ? '' : ' (inativa)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= $mensagem('lotacao_id') ?>
                        <a class="campo__link" href="<?= e(url('unidades-administrativas.php')) ?>">Gerenciar unidades</a>
                    </div>
                    <div class="campo">
                        <label class="campo__rotulo" for="observacoes">Observações</label>
                        <textarea class="textarea textarea--curta <?= $erro('observacoes') ?>" id="observacoes" name="observacoes" rows="2"
                                  maxlength="<?= AgentePublicoService::OBSERVACOES_MAX ?>"><?= $val('observacoes') ?></textarea>
                        <?= $mensagem('observacoes') ?>
                    </div>
                </div>
            </fieldset>
        </div>
        <div class="card__rodape form-usuario__acoes">
            <a class="btn btn--ghost" href="<?= e($urlCancelar) ?>">Cancelar</a>
            <button type="submit" class="btn btn--primario"><?= $modo === 'novo' ? 'Cadastrar agente público' : 'Salvar alterações' ?></button>
        </div>
    </section>
</form>
