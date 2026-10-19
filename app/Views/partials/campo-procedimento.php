<?php
/**
 * Campo Procedimento do processo administrativo: escolha controlada no
 * catalogo mantido pelos administradores (Configuracoes > Procedimentos).
 *
 * Variaveis:
 *   $procedimentosAtivos      list<array{id,nome,descricao}> opcoes ativas
 *   $dados['procedimento_id'] valor escolhido (string)
 *   $erros                    array de erros do formulario
 *   $procedimentoObrigatorio  bool
 *   $procedimentoAtual        ?array{id,nome,ativo} item ja gravado (edicao)
 *   $procedimentoLegado       ?string texto livre anterior ao catalogo
 */
$procedimentoAtual  = $procedimentoAtual ?? null;
$procedimentoLegado = $procedimentoLegado ?? null;
$idsAtivos = array_map(static fn(array $p): int => (int)$p['id'], $procedimentosAtivos);
// Item ja gravado e hoje inativo: aparece como opcao propria, para nunca ser
// substituido sem que o usuario escolha outro.
$atualInativo = $procedimentoAtual !== null && !in_array((int)$procedimentoAtual['id'], $idsAtivos, true);
$escolhido = (string)($dados['procedimento_id'] ?? '');
$semOpcoes = $procedimentosAtivos === [] && !$atualInativo;
?>
<div class="campo campo--5">
    <label class="campo__rotulo" for="procedimento_id">
        Procedimento<?php if ($procedimentoObrigatorio): ?> <span class="obrigatorio">*</span><?php endif; ?>
    </label>
    <select class="select <?= isset($erros['procedimento_id']) ? 'is-invalido' : '' ?>" id="procedimento_id" name="procedimento_id"
            <?= $procedimentoObrigatorio ? 'required' : '' ?> <?= count($procedimentosAtivos) > 10 ? 'data-select-pesquisavel' : '' ?>
            aria-describedby="procedimento_ajuda">
        <option value=""><?= $semOpcoes ? 'Nenhum procedimento cadastrado' : 'Selecione' ?></option>
        <?php if ($atualInativo): ?>
            <option value="<?= (int)$procedimentoAtual['id'] ?>" <?= $escolhido === (string)$procedimentoAtual['id'] ? 'selected' : '' ?>>
                <?= e($procedimentoAtual['nome']) ?> (inativo)
            </option>
        <?php endif; ?>
        <?php foreach ($procedimentosAtivos as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $escolhido === (string)$p['id'] ? 'selected' : '' ?>
                    <?= !empty($p['descricao']) ? 'title="' . e($p['descricao']) . '"' : '' ?>><?= e($p['nome']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (isset($erros['procedimento_id'])): ?>
        <span class="campo__erro" id="procedimento_ajuda"><?= e($erros['procedimento_id']) ?></span>
    <?php elseif ($atualInativo): ?>
        <span class="campo__ajuda" id="procedimento_ajuda">O procedimento atual foi inativado e é mantido. Para alterá-lo, escolha um procedimento ativo.</span>
    <?php elseif ($semOpcoes): ?>
        <span class="campo__ajuda" id="procedimento_ajuda">
            <?= App\Core\Auth::ehAdministrador()
                ? 'Cadastre os procedimentos em <a href="' . e(url('configuracoes.php?aba=procedimentos')) . '">Configurações › Procedimentos</a>.'
                : 'Os procedimentos ainda não foram cadastrados pelos administradores.' ?>
        </span>
    <?php else: ?>
        <span class="campo__ajuda" id="procedimento_ajuda">Lista mantida pelos administradores em Configurações.</span>
    <?php endif; ?>
    <?php if ($procedimentoLegado !== null && $procedimentoLegado !== '' && $procedimentoAtual === null): ?>
        <span class="campo__ajuda">Classificação anterior (texto livre, preservada): <em><?= e($procedimentoLegado) ?></em></span>
    <?php endif; ?>
</div>
