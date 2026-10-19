<?php
/**
 * Formulario de usuario (reutilizado em novo/editar).
 * Variaveis: $dados, $erros, $modo ('novo'|'editar'), $registro, $cargos, $lotacoes,
 *            $agentesDisponiveis e $agente (so no modo novo: a conta nasce de um Agente Publico)
 */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Perfil;
use App\Validators\UsuarioValidator;

$erros    = $erros    ?? [];
$modo     = $modo     ?? 'novo';
$registro = $registro ?? null;
$agente   = $agente   ?? null;
// Perfil e status: somente o administrador altera (a regra e aplicada tambem no UsuarioService).
$podeAdministrar = Auth::ehAdministrador();
$perfilAtual = (string)($dados['perfil'] ?? $registro['perfil'] ?? Perfil::USUARIO);

$erro = static fn(string $campo): string => isset($erros[$campo]) ? 'is-invalido' : '';
$val  = static fn(string $campo): string => e((string)($dados[$campo] ?? ''));
$mensagem = static function (string $campo, string $ajuda = '') use ($erros): string {
    if (isset($erros[$campo])) {
        return '<span class="campo__erro" id="erro-' . e($campo) . '">' . e($erros[$campo]) . '</span>';
    }
    return '<span class="campo__erro" id="erro-' . e($campo) . '" hidden></span>'
        . ($ajuda !== '' ? '<span class="campo__ajuda">' . e($ajuda) . '</span>' : '');
};
$dataObrigatoria = (bool)config('usuarios.data_nascimento_obrigatoria', false);
$urlCancelar = $modo === 'novo'
    ? url($agente !== null ? 'agente-publico.php?id=' . (int)$agente['id'] : 'configuracoes.php?aba=usuarios')
    : url('usuario.php?id=' . (int)($registro['id'] ?? 0));
?>
<form method="post" id="formUsuario" class="form-usuario" novalidate>
    <?= Csrf::campo() ?>

    <?php $errosCampos = array_diff_key($erros, ['_geral' => true]); ?>
    <?php if ($errosCampos !== []): ?>
        <div class="alert alert--erro" role="alert">
            <span class="alert__icone">✕</span>
            <span>Não foi possível salvar. Corrija <?= count($errosCampos) === 1 ? 'o campo destacado' : 'os ' . count($errosCampos) . ' campos destacados' ?> abaixo.</span>
        </div>
    <?php endif; ?>

    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo"><?= $modo === 'novo' ? 'Criar usuário do sistema' : 'Editar Usuário' ?></h2>
                <p class="card__subtitulo">Campos marcados com <span class="obrigatorio">*</span> são obrigatórios</p>
            </div>
        </div>
        <div class="card__corpo">
            <?php if ($modo === 'novo'): ?>
                <fieldset class="grupo-campos">
                    <legend>Agente público</legend>
                    <div class="form-grid">
                        <div class="campo">
                            <label class="campo__rotulo" for="agente_publico_id">Agente público <span class="obrigatorio">*</span></label>
                            <select class="select <?= $erro('agente_publico_id') ?>" id="agente_publico_id" name="agente_publico_id" required
                                    aria-describedby="erro-agente_publico_id" data-agente-usuario>
                                <option value="">Selecione o agente público</option>
                                <?php foreach ($agentesDisponiveis as $a): ?>
                                    <option value="<?= (int)$a['id'] ?>" data-email="<?= e((string)($a['email_institucional'] ?? '')) ?>"
                                        <?= (string)($dados['agente_publico_id'] ?? '') === (string)$a['id'] ? 'selected' : '' ?>>
                                        <?= e($a['nome']) ?><?= $a['matricula'] !== null ? ' — ' . e($a['matricula']) : '' ?> · <?= e($a['lotacao']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= $mensagem('agente_publico_id', 'A conta aproveita nome, matrícula, cargo, unidade e nascimento do cadastro do agente.') ?>
                            <a class="campo__link" href="<?= e(url('novo-agente-publico.php')) ?>">Cadastrar novo agente público</a>
                        </div>
                    </div>
                    <?php if ($agente !== null): ?>
                        <div class="definicoes mt-16" data-resumo-agente="<?= (int)$agente['id'] ?>">
                            <div class="definicao"><span class="definicao__rotulo">Nome</span><span class="definicao__valor"><?= e($agente['nome']) ?></span></div>
                            <div class="definicao"><span class="definicao__rotulo">Matrícula</span><span class="definicao__valor mono"><?= ou_traco($agente['matricula']) ?></span></div>
                            <div class="definicao"><span class="definicao__rotulo">Cargo</span><span class="definicao__valor"><?= ou_traco($agente['cargo']) ?></span></div>
                            <div class="definicao"><span class="definicao__rotulo">Unidade</span><span class="definicao__valor"><?= e($agente['lotacao']) ?></span></div>
                        </div>
                    <?php endif; ?>
                </fieldset>

                <fieldset class="grupo-campos mb-0">
                    <legend>Acesso ao sistema</legend>
                    <div class="form-grid">
                        <div class="campo campo--6">
                            <label class="campo__rotulo" for="email">E-mail de acesso (login) <span class="obrigatorio">*</span></label>
                            <input class="input <?= $erro('email') ?>" type="email" id="email" name="email"
                                   required maxlength="<?= UsuarioValidator::EMAIL_MAX ?>" autocomplete="off"
                                   placeholder="usuario@ses.mt.gov.br" aria-describedby="erro-email" value="<?= $val('email') ?>">
                            <?= $mensagem('email', 'Sugerido a partir do e-mail institucional do agente.') ?>
                        </div>
                        <div class="campo campo--6">
                            <label class="campo__rotulo" for="perfil">Perfil de acesso <span class="obrigatorio">*</span></label>
                            <select class="select <?= $erro('perfil') ?>" id="perfil" name="perfil" required aria-describedby="erro-perfil"
                                    data-descricoes="<?= e(json_encode(Perfil::DESCRICOES, JSON_UNESCAPED_UNICODE)) ?>">
                                <?php foreach (Perfil::ROTULOS as $valor => $rotulo): ?>
                                    <option value="<?= e($valor) ?>" <?= $perfilAtual === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $mensagem('perfil', Perfil::DESCRICOES[$perfilAtual] ?? '') ?>
                        </div>
                    </div>
                    <p class="campo__ajuda mt-16 mb-0">Depois de criar, defina a senha provisória na ficha do usuário para liberar o acesso.</p>
                </fieldset>
            <?php else: ?>
            <?php if (!empty($registro['agente_publico_id'])): ?>
                <div class="alert alert--info"><span class="alert__icone">ℹ</span>
                    <span>Os dados pessoais e funcionais são os do Agente Público titular da conta e são atualizados nos dois cadastros.
                    <?php if ($podeAdministrar): ?><a href="<?= e(url('agente-publico.php?id=' . (int)$registro['agente_publico_id'])) ?>">Abrir o cadastro do agente</a><?php endif; ?></span></div>
            <?php endif; ?>
            <fieldset class="grupo-campos">
                <legend>Identificação</legend>
                <div class="form-grid">
                    <div class="campo">
                        <label class="campo__rotulo" for="nome">Nome completo <span class="obrigatorio">*</span></label>
                        <input class="input <?= $erro('nome') ?>" type="text" id="nome" name="nome"
                               required minlength="<?= UsuarioValidator::NOME_MIN ?>" maxlength="<?= UsuarioValidator::NOME_MAX ?>"
                               autocomplete="name" placeholder="Ex.: Marcio Jean da Silva"
                               aria-describedby="erro-nome" value="<?= $val('nome') ?>">
                        <?= $mensagem('nome') ?>
                    </div>

                    <div class="campo campo--8">
                        <label class="campo__rotulo" for="email">E-mail de acesso (login) <span class="obrigatorio">*</span></label>
                        <input class="input <?= $erro('email') ?>" type="email" id="email" name="email"
                               required maxlength="<?= UsuarioValidator::EMAIL_MAX ?>" autocomplete="email"
                               placeholder="usuario@ses.mt.gov.br" aria-describedby="erro-email" value="<?= $val('email') ?>">
                        <?= $mensagem('email', 'Usado como login.') ?>
                    </div>

                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="matricula">Matrícula <span class="obrigatorio">*</span></label>
                        <input class="input mono <?= $erro('matricula') ?>" type="text" id="matricula" name="matricula"
                               required maxlength="<?= UsuarioValidator::MATRICULA_MAX ?>" autocomplete="off"
                               placeholder="123456" aria-describedby="erro-matricula" value="<?= $val('matricula') ?>">
                        <?= $mensagem('matricula', 'Zeros à esquerda são preservados.') ?>
                    </div>

                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="data_nascimento">
                            Data de nascimento <?= $dataObrigatoria ? '<span class="obrigatorio">*</span>' : '' ?>
                        </label>
                        <input class="input <?= $erro('data_nascimento') ?>" type="date" id="data_nascimento" name="data_nascimento"
                               <?= $dataObrigatoria ? 'required' : '' ?>
                               min="<?= e(UsuarioValidator::DATA_MINIMA) ?>" max="<?= e(date('Y-m-d')) ?>"
                               aria-describedby="erro-data_nascimento" value="<?= $val('data_nascimento') ?>">
                        <?= $mensagem('data_nascimento', $dataObrigatoria ? '' : 'Opcional.') ?>
                    </div>
                </div>
            </fieldset>

            <fieldset class="grupo-campos">
                <legend>Vínculo funcional</legend>
                <div class="form-grid">
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="cargo_id">Cargo <span class="obrigatorio">*</span></label>
                        <select class="select <?= $erro('cargo_id') ?>" id="cargo_id" name="cargo_id" required aria-describedby="erro-cargo_id">
                            <option value="">Selecione o cargo</option>
                            <?php foreach ($cargos as $c): ?>
                                <option value="<?= e((string)$c['id']) ?>" <?= (string)($dados['cargo_id'] ?? '') === (string)$c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['nome']) ?><?= (int)$c['ativo'] === 1 ? '' : ' (inativo)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= $mensagem('cargo_id') ?>
                        <?php if ($podeAdministrar): ?><a class="campo__link" href="<?= e(url('tabela-apoio.php?tipo=cargos')) ?>">Gerenciar cargos</a><?php endif; ?>
                    </div>

                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="lotacao_id">Unidade Administrativa <span class="obrigatorio">*</span></label>
                        <select class="select <?= $erro('lotacao_id') ?>" id="lotacao_id" name="lotacao_id" required aria-describedby="erro-lotacao_id">
                            <option value="">Selecione a unidade</option>
                            <?php foreach ($lotacoes as $l): ?>
                                <option value="<?= e((string)$l['id']) ?>" <?= (string)($dados['lotacao_id'] ?? '') === (string)$l['id'] ? 'selected' : '' ?>>
                                    <?= e($l['rotulo'] ?? $l['nome']) ?><?= (int)$l['ativo'] === 1 ? '' : ' (inativa)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= $mensagem('lotacao_id') ?>
                        <?php if ($podeAdministrar): ?><a class="campo__link" href="<?= e(url('unidades-administrativas.php')) ?>">Gerenciar unidades</a><?php endif; ?>
                    </div>

                </div>
            </fieldset>

            <fieldset class="grupo-campos mb-0">
                <legend>Acesso ao sistema</legend>
                <div class="form-grid">
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="perfil">Perfil de acesso <?= $podeAdministrar ? '<span class="obrigatorio">*</span>' : '' ?></label>
                        <?php if ($podeAdministrar): ?>
                            <select class="select <?= $erro('perfil') ?>" id="perfil" name="perfil" required aria-describedby="erro-perfil"
                                    data-descricoes="<?= e(json_encode(Perfil::DESCRICOES, JSON_UNESCAPED_UNICODE)) ?>">
                                <?php foreach (Perfil::ROTULOS as $valor => $rotulo): ?>
                                    <option value="<?= e($valor) ?>" <?= $perfilAtual === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $mensagem('perfil', Perfil::DESCRICOES[$perfilAtual] ?? '') ?>
                        <?php else: ?>
                            <!-- Sem name: o perfil nao e enviado nem pode ser alterado por aqui. -->
                            <select class="select" id="perfil" disabled aria-describedby="ajuda-perfil">
                                <option selected><?= e(Perfil::rotulo($perfilAtual)) ?></option>
                            </select>
                            <span class="campo__ajuda" id="ajuda-perfil">Somente administradores podem alterar o perfil de acesso.</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($podeAdministrar): ?>
                        <div class="campo campo--6">
                            <label class="campo__rotulo" for="ativo">Status <span class="obrigatorio">*</span></label>
                            <select class="select <?= $erro('ativo') ?>" id="ativo" name="ativo" required aria-describedby="erro-ativo">
                                <option value="1" <?= (string)($dados['ativo'] ?? '1') === '1' ? 'selected' : '' ?>>Ativo</option>
                                <option value="0" <?= (string)($dados['ativo'] ?? '1') === '0' ? 'selected' : '' ?>>Inativo</option>
                            </select>
                            <?= $mensagem('ativo', 'Usuários inativos são mantidos para fins históricos e não entram no sistema.') ?>
                        </div>
                    <?php endif; ?>
                </div>
            </fieldset>
            <?php endif; ?>
        </div>

        <div class="card__rodape form-usuario__acoes">
            <a class="btn btn--ghost" href="<?= e($urlCancelar) ?>">Cancelar</a>
            <button type="submit" class="btn btn--primario">
                <?= $modo === 'novo' ? 'Criar usuário' : 'Salvar alterações' ?>
            </button>
        </div>
    </section>
</form>
