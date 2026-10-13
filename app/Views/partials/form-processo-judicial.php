<?php
/**
 * Formulário de Processo Judicial (reutilizado em novo/editar).
 *
 * Organizado como a página de detalhe do PJe, de onde vem quase tudo:
 *   Importar do PJe → Detalhes → Partes (polo ativo, passivo, outros
 *   interessados) → Cronologia → Documentos → Processos administrativos.
 *
 * Variáveis: $dados, $erros, $partes, $movimentacoes, $movimentacoesSalvas,
 *            $documentos, $documentosSalvos, $documentosPorMovimentacao,
 *            $administrativosSelecionados, $modo ('novo'|'editar'), $registro
 */
use App\Core\Csrf;

$erros   = $erros   ?? [];
$partes  = $partes  ?? [];
$modo    = $modo    ?? 'novo';
$registro = $registro ?? null;
$administrativosSelecionados = $administrativosSelecionados ?? [];
$movimentacoes = $movimentacoes ?? [];
$movimentacoesSalvas = $movimentacoesSalvas ?? false;
$documentos = $documentos ?? [];
$documentosSalvos = $documentosSalvos ?? false;
$documentosPorMovimentacao = $documentosPorMovimentacao ?? [];

$erro = static fn(string $campo): string => isset($erros[$campo]) ? 'is-invalido' : '';
$val  = static fn(string $campo): string => e((string)($dados[$campo] ?? ''));

/** Select tri-estado (Sim / Não / Não informado), usado nos sinalizadores do PJe. */
$triEstado = static function (string $campo, string $rotulo, array $dados, string $classe = 'campo--3'): void {
    $atual = (string)($dados[$campo] ?? '');
    echo '<div class="campo ' . e($classe) . '">';
    echo '<label class="campo__rotulo" for="' . e($campo) . '">' . e($rotulo) . '</label>';
    echo '<select class="select" id="' . e($campo) . '" name="' . e($campo) . '">';
    echo '<option value="">Não informado</option>';
    echo '<option value="1"' . ($atual === '1' ? ' selected' : '') . '>Sim</option>';
    echo '<option value="0"' . ($atual === '0' ? ' selected' : '') . '>Não</option>';
    echo '</select>';
    echo '</div>';
};

/** Polos como o PJe os separa em abas. OUTRO (cadastro manual antigo) fica com os interessados. */
$gruposPartes = [
    'ATIVO'    => ['titulo' => 'Polo ativo',          'polos' => ['ATIVO']],
    'PASSIVO'  => ['titulo' => 'Polo passivo',        'polos' => ['PASSIVO']],
    'TERCEIRO' => ['titulo' => 'Outros interessados', 'polos' => ['TERCEIRO', 'OUTRO']],
];

/** Linha de parte: índice global (partes[i]) e polo em campo oculto. */
$linhaParte = static function (string $indice, array $parte, string $polo): void {
    $v = static fn(string $c): string => e((string)($parte[$c] ?? ''));
    ?>
    <tr>
        <td>
            <input type="hidden" name="partes[<?= $indice ?>][id]" value="<?= $v('id') ?>">
            <input type="hidden" data-campo="polo" name="partes[<?= $indice ?>][polo]" value="<?= e($polo) ?>">
            <input class="input" type="text" data-campo="nome" name="partes[<?= $indice ?>][nome]"
                   value="<?= $v('nome') ?>" placeholder="Nome da parte" aria-label="Nome da parte">
        </td>
        <td><input class="input mono" type="text" data-campo="documento" name="partes[<?= $indice ?>][documento]"
                   value="<?= $v('documento') ?>" placeholder="CPF/CNPJ" aria-label="CPF/CNPJ"></td>
        <td><input class="input" type="text" data-campo="tipo" name="partes[<?= $indice ?>][tipo]"
                   value="<?= $v('tipo') ?>" placeholder="Autor, Réu…" aria-label="Papel processual"></td>
        <td><input class="input" type="text" data-campo="representacao" name="partes[<?= $indice ?>][representacao]"
                   value="<?= $v('representacao') ?>" placeholder="Advogado, Defensoria…" aria-label="Representação"></td>
        <td class="texto-direita">
            <button type="button" class="partes__remover" data-remover-parte aria-label="Remover parte">×</button>
        </td>
    </tr>
    <?php
};

$totalPartes = count($partes);
$completo = (string)($dados['historico_completo'] ?? '') === '1';
?>
<form method="post" id="formProcessoJudicial" class="form-pj" novalidate>
    <?= Csrf::campo() ?>
    <input type="hidden" name="origem" value="<?= $val('origem') ?>">
    <input type="hidden" name="data_extracao" value="<?= $val('data_extracao') ?>">
    <input type="hidden" name="extrator_versao" value="<?= $val('extrator_versao') ?>">
    <?php /* Códigos e cabeçalho do PJe: só a extração os fornece. */ ?>
    <input type="hidden" name="classe_codigo" value="<?= $val('classe_codigo') ?>">
    <input type="hidden" name="assunto_principal_codigo" value="<?= $val('assunto_principal_codigo') ?>">
    <input type="hidden" name="historico_completo" value="<?= $val('historico_completo') ?>">
    <?php /* Vazios = manter o que já está gravado; a extração os preenche.
             Preservam o valor submetido para não perder a extração quando o
             formulário volta com erro de validação. */ ?>
    <input type="hidden" name="movimentacoes_json" value="<?= $val('movimentacoes_json') ?>">
    <input type="hidden" name="documentos_json" value="<?= $val('documentos_json') ?>">

    <!-- ============ 1. Importar do PJe ============ -->
    <section class="card importacao-pje">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Importar do PJe</h2>
                <p class="card__subtitulo">Número CNJ obrigatório e único · a importação preenche o formulário, e nada é salvo sem a sua revisão</p>
            </div>
            <div class="proveniencia" data-proveniencia>
                <?php if ($registro !== null && $registro['origem'] !== 'MANUAL'): ?>
                    <span class="badge badge--azul">Origem: <?= e($registro['origem']) ?></span>
                    <span>Extraído em: <?= e(datahora_br($registro['data_extracao'])) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <div class="campo campo--4">
                    <label class="campo__rotulo" for="numero_cnj">Número do processo <span class="obrigatorio">*</span></label>
                    <input class="input mono <?= $erro('numero_cnj') ?>" type="text" id="numero_cnj" name="numero_cnj"
                           required data-mascara="cnj" autocomplete="off"
                           placeholder="1008695-17.2026.8.11.0006" value="<?= $val('numero_cnj') ?>">
                    <?php if (isset($erros['numero_cnj'])): ?>
                        <span class="campo__erro"><?= e($erros['numero_cnj']) ?></span>
                    <?php else: ?>
                        <span class="campo__ajuda">Padrão CNJ: NNNNNNN-DD.AAAA.J.TR.OOOO</span>
                    <?php endif; ?>
                </div>
                <div class="campo campo--8">
                    <label class="campo__rotulo" for="url_origem">Endereço do processo no PJe</label>
                    <input class="input <?= $erro('url_origem') ?>" type="url" id="url_origem" name="url_origem"
                           placeholder="https://pje.tjmt.jus.br/pje/Processo/ConsultaProcesso/Detalhe/…" value="<?= $val('url_origem') ?>" autocomplete="off">
                    <?php if (isset($erros['url_origem'])): ?>
                        <span class="campo__erro"><?= e($erros['url_origem']) ?></span>
                    <?php else: ?>
                        <span class="campo__ajuda">Abra o processo no PJe já autenticado e copie o endereço da página de detalhe. Somente domínios autorizados são aceitos.</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="importacao-pje__opcoes" role="group" aria-label="O que importar além dos detalhes, partes e cronologia exibida">
                <label class="opcao-importacao">
                    <input type="checkbox" id="optCronologiaCompleta" checked>
                    <span>
                        <strong>Carregar a cronologia completa</strong>
                        <small>Rola a linha do tempo no PJe até a última página antes de ler. Sem isso, só a primeira página entra.</small>
                    </span>
                </label>
                <label class="opcao-importacao">
                    <input type="checkbox" id="optIntegra">
                    <span>
                        <strong>Extrair a íntegra das peças decisórias</strong>
                        <small>Abre no PJe cada sentença, decisão, acórdão, despacho e embargos e copia o texto. Mais lento. Processos em segredo de justiça ficam só com os metadados.</small>
                    </span>
                </label>
            </div>

            <div class="importacao-pje__acao">
                <button type="button" class="btn btn--extracao" id="btnCompletarPje">⬇ Importar do PJe</button>
                <span class="texto-secundario">O número é conferido antes de qualquer leitura: se a página for de outro processo, nada é preenchido.</span>
            </div>
            <div class="extracao__status" id="statusPje" role="status" aria-live="polite"></div>

            <div class="resumo-importacao" data-resumo-importacao hidden></div>
        </div>
    </section>

    <!-- Navegação por seções, na ordem das abas do PJe -->
    <nav class="secoes-pj" aria-label="Seções do cadastro">
        <a href="#secao-detalhes">Detalhes</a>
        <a href="#secao-partes">Partes <span class="secoes-pj__contador" data-contador="partes"><?= $totalPartes ?: '' ?></span></a>
        <a href="#secao-cronologia">Cronologia <span class="secoes-pj__contador" data-contador="cronologia"><?= count($movimentacoes) ?: '' ?></span></a>
        <a href="#secao-documentos">Documentos <span class="secoes-pj__contador" data-contador="documentos"><?= count($documentos) ?: '' ?></span></a>
        <a href="#secao-vinculos">Processos administrativos <span class="secoes-pj__contador" data-contador="vinculos"><?= count($administrativosSelecionados) ?: '' ?></span></a>
    </nav>

    <!-- ============ 2. Detalhes ============ -->
    <section class="card" id="secao-detalhes">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Detalhes do processo</h2>
                <p class="card__subtitulo">Aba “Detalhes” e cabeçalho do PJe · campos em verde vieram da importação</p>
            </div>
        </div>
        <div class="card__corpo">
            <fieldset class="grupo-campos">
                <legend>Classificação</legend>
                <div class="form-grid">
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="classe_judicial">Classe judicial</label>
                        <input class="input" type="text" id="classe_judicial" name="classe_judicial" value="<?= $val('classe_judicial') ?>">
                    </div>
                    <div class="campo campo--2">
                        <label class="campo__rotulo" for="classe_abreviada">Sigla da classe</label>
                        <input class="input mono" type="text" id="classe_abreviada" name="classe_abreviada" maxlength="30"
                               placeholder="ProceComCiv" value="<?= $val('classe_abreviada') ?>">
                    </div>
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="situacao">Situação processual</label>
                        <input class="input" type="text" id="situacao" name="situacao" value="<?= $val('situacao') ?>">
                    </div>
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="assunto_principal">Assunto principal</label>
                        <input class="input" type="text" id="assunto_principal" name="assunto_principal" value="<?= $val('assunto_principal') ?>">
                    </div>
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="assuntos_secundarios">Assuntos secundários</label>
                        <textarea class="textarea textarea--curta" id="assuntos_secundarios" name="assuntos_secundarios" rows="2"
                                  placeholder="Um assunto por linha"><?= $val('assuntos_secundarios') ?></textarea>
                    </div>
                    <div class="campo">
                        <label class="campo__rotulo" for="resumo_partes">Resumo das partes</label>
                        <input class="input" type="text" id="resumo_partes" name="resumo_partes" maxlength="500"
                               placeholder="FULANO DE TAL e outros X MUNICÍPIO DE EXEMPLO" value="<?= $val('resumo_partes') ?>">
                        <span class="campo__ajuda">Linha “polo ativo X polo passivo” do cabeçalho do PJe. As partes completas ficam na seção Partes.</span>
                    </div>
                </div>
            </fieldset>

            <fieldset class="grupo-campos">
                <legend>Órgão jurisdicional</legend>
                <div class="form-grid">
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="tribunal">Tribunal</label>
                        <input class="input" type="text" id="tribunal" name="tribunal" value="<?= $val('tribunal') ?>">
                    </div>
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="orgao_julgador">Órgão julgador</label>
                        <input class="input" type="text" id="orgao_julgador" name="orgao_julgador" value="<?= $val('orgao_julgador') ?>">
                    </div>
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="jurisdicao">Jurisdição</label>
                        <input class="input" type="text" id="jurisdicao" name="jurisdicao" value="<?= $val('jurisdicao') ?>">
                    </div>
                    <div class="campo campo--4">
                        <label class="campo__rotulo" for="cargo_judicial">Cargo judicial</label>
                        <input class="input" type="text" id="cargo_judicial" name="cargo_judicial"
                               placeholder="Juiz Titular" value="<?= $val('cargo_judicial') ?>">
                    </div>
                    <div class="campo campo--8">
                        <label class="campo__rotulo" for="competencia">Competência</label>
                        <input class="input" type="text" id="competencia" name="competencia" value="<?= $val('competencia') ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset class="grupo-campos">
                <legend>Datas e valor</legend>
                <div class="form-grid">
                    <div class="campo campo--3">
                        <label class="campo__rotulo" for="data_autuacao">Autuação</label>
                        <input class="input <?= $erro('data_autuacao') ?>" type="date" id="data_autuacao"
                               name="data_autuacao" value="<?= $val('data_autuacao') ?>">
                        <?php if (isset($erros['data_autuacao'])): ?>
                            <span class="campo__erro"><?= e($erros['data_autuacao']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="campo campo--3">
                        <label class="campo__rotulo" for="data_distribuicao">Última distribuição</label>
                        <input class="input <?= $erro('data_distribuicao') ?>" type="date" id="data_distribuicao"
                               name="data_distribuicao" value="<?= $val('data_distribuicao') ?>">
                        <?php if (isset($erros['data_distribuicao'])): ?>
                            <span class="campo__erro"><?= e($erros['data_distribuicao']) ?></span>
                        <?php else: ?>
                            <span class="campo__ajuda">Difere da autuação em processos redistribuídos.</span>
                        <?php endif; ?>
                    </div>
                    <div class="campo campo--3">
                        <label class="campo__rotulo" for="valor_causa">Valor da causa</label>
                        <input class="input <?= $erro('valor_causa') ?>" type="text" id="valor_causa" name="valor_causa"
                               inputmode="decimal" placeholder="0,00" value="<?= $val('valor_causa') ?>">
                        <?php if (isset($erros['valor_causa'])): ?>
                            <span class="campo__erro"><?= e($erros['valor_causa']) ?></span>
                        <?php else: ?>
                            <span class="campo__ajuda">Aceita 1.234,56 ou 1234.56</span>
                        <?php endif; ?>
                    </div>
                    <div class="campo campo--3">
                        <label class="campo__rotulo" for="data_ultima_atualizacao">Última atualização (fonte)</label>
                        <input class="input <?= $erro('data_ultima_atualizacao') ?>" type="date"
                               id="data_ultima_atualizacao" name="data_ultima_atualizacao" value="<?= $val('data_ultima_atualizacao') ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset class="grupo-campos">
                <legend>Sinalizadores</legend>
                <p class="texto-secundario mt-0">
                    Preenchidos apenas quando o PJe os informa — <strong>“Não informado” não equivale a “Não”</strong>.
                </p>
                <div class="form-grid">
                    <?php
                    $triEstado('segredo_justica', 'Segredo de justiça', $dados);
                    $triEstado('justica_gratuita', 'Justiça gratuita', $dados);
                    $triEstado('tutela_liminar', 'Tutela / liminar', $dados);
                    $triEstado('prioridade', 'Prioridade de tramitação', $dados);
                    ?>
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="prioridade_descricao">Motivo da prioridade</label>
                        <input class="input" type="text" id="prioridade_descricao" name="prioridade_descricao" maxlength="100"
                               placeholder="Idoso, doença grave…" value="<?= $val('prioridade_descricao') ?>">
                        <span class="campo__ajuda">O PJe às vezes mostra o motivo no lugar de “Sim”. Ignorado quando a prioridade é “Não”.</span>
                    </div>
                </div>
            </fieldset>

            <fieldset class="grupo-campos mb-0">
                <legend>Resumo e observações</legend>
                <div class="form-grid">
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="resumo">Resumo / objeto</label>
                        <textarea class="textarea" id="resumo" name="resumo" rows="3"><?= $val('resumo') ?></textarea>
                    </div>
                    <div class="campo campo--6">
                        <label class="campo__rotulo" for="observacoes">Observações internas</label>
                        <textarea class="textarea" id="observacoes" name="observacoes" rows="3"><?= $val('observacoes') ?></textarea>
                    </div>
                </div>
            </fieldset>
        </div>
    </section>

    <!-- ============ 3. Partes ============ -->
    <section class="card" id="secao-partes">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Partes processuais</h2>
                <p class="card__subtitulo">Abas “Polo ativo”, “Polo passivo” e “Outros interessados” do PJe</p>
            </div>
        </div>
        <div class="card__corpo">
            <?php $indiceParte = 0; ?>
            <?php foreach ($gruposPartes as $poloGrupo => $grupo): ?>
                <?php $doGrupo = array_values(array_filter($partes, static fn($p) => in_array($p['polo'] ?? 'OUTRO', $grupo['polos'], true))); ?>
                <div class="polo-bloco" data-polo-bloco="<?= e($poloGrupo) ?>">
                    <div class="polo-bloco__cabecalho">
                        <h3 class="polo-bloco__titulo">
                            <span class="polo-bloco__marca polo-bloco__marca--<?= e(strtolower($poloGrupo)) ?>"></span>
                            <?= e($grupo['titulo']) ?>
                            <span class="badge" data-contador-polo><?= count($doGrupo) ?></span>
                        </h3>
                        <button type="button" class="btn btn--ghost btn--sm" data-adicionar-parte="<?= e($poloGrupo) ?>">＋ Adicionar</button>
                    </div>
                    <div class="tabela-wrap">
                        <table class="partes__tabela">
                            <thead>
                                <tr>
                                    <th style="width:30%">Nome</th>
                                    <th style="width:16%">Documento</th>
                                    <th style="width:16%">Papel</th>
                                    <th style="width:32%">Representação</th>
                                    <th style="width:6%"></th>
                                </tr>
                            </thead>
                            <tbody data-partes-corpo="<?= e($poloGrupo) ?>">
                                <?php foreach ($doGrupo as $parte): ?>
                                    <?php $linhaParte((string)$indiceParte++, $parte, (string)($parte['polo'] ?? $poloGrupo)); ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="texto-secundario polo-bloco__vazio" data-partes-vazio <?= $doGrupo !== [] ? 'hidden' : '' ?>>Nenhuma parte neste polo.</p>
                </div>
            <?php endforeach; ?>

            <template id="modeloParte">
                <?php $linhaParte('__INDICE__', [], '__POLO__'); ?>
            </template>
        </div>
    </section>

    <!-- ============ 4. Cronologia ============ -->
    <section class="card" id="secao-cronologia">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Cronologia</h2>
                <p class="card__subtitulo" data-movimentacoes-resumo>
                    <?php if ($movimentacoes !== []): ?>
                        <?= count($movimentacoes) ?> evento(s) · do mais recente para o mais antigo<?= $movimentacoesSalvas && !$completo ? ' · histórico parcial' : '' ?>
                    <?php else: ?>
                        Linha do tempo do PJe · do mais recente para o mais antigo
                    <?php endif; ?>
                </p>
            </div>
            <div class="filtro-chips" role="group" aria-label="Filtrar cronologia" data-filtro-cronologia>
                <button type="button" class="filtro-chips__item is-ativo" data-filtro="">Todos</button>
                <button type="button" class="filtro-chips__item" data-filtro="MOVIMENTO">Movimentos</button>
                <button type="button" class="filtro-chips__item" data-filtro="DOCUMENTO">Com documento</button>
            </div>
        </div>
        <div class="card__corpo">
            <?php if (isset($erros['movimentacoes'])): ?>
                <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erros['movimentacoes']) ?></span></div>
            <?php endif; ?>

            <div data-movimentacoes>
                <?php
                $movimentacoesVazio = 'Nenhuma movimentação registrada. Use “Importar do PJe” para trazer a cronologia.';
                require APP_ROOT . '/Views/partials/movimentacoes-judiciais.php';
                ?>
            </div>

            <?php if ($movimentacoesSalvas && $movimentacoes !== []): ?>
                <p class="texto-secundario mt-16 mb-0" data-movimentacoes-nota>
                    Cronologia já gravada. Uma nova importação substitui a lista inteira.
                </p>
            <?php endif; ?>
        </div>
    </section>

    <!-- ============ 5. Documentos ============ -->
    <section class="card" id="secao-documentos">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Documentos</h2>
                <p class="card__subtitulo" data-documentos-resumo>
                    Documentos juntados, com a íntegra das peças decisórias quando importada
                </p>
            </div>
            <div class="filtro-chips" role="group" aria-label="Filtrar documentos" data-filtro-documentos>
                <button type="button" class="filtro-chips__item is-ativo" data-filtro="">Todos</button>
                <button type="button" class="filtro-chips__item" data-filtro="1">Peças decisórias</button>
            </div>
        </div>
        <div class="card__corpo">
            <?php if (isset($erros['documentos'])): ?>
                <div class="alert alert--erro"><span class="alert__icone">✕</span><span><?= e($erros['documentos']) ?></span></div>
            <?php endif; ?>

            <div data-documentos>
                <?php
                $documentosVazio = 'Nenhum documento registrado. Os documentos citados na cronologia entram com a importação do PJe.';
                require APP_ROOT . '/Views/partials/documentos-judiciais.php';
                ?>
            </div>

            <?php if ($documentosSalvos && $documentos !== []): ?>
                <p class="texto-secundario mt-16 mb-0" data-documentos-nota>
                    Uma nova importação acrescenta documentos e atualiza os existentes — nenhuma íntegra já obtida é apagada.
                </p>
            <?php endif; ?>
        </div>
    </section>

    <!-- ============ 6. Processos administrativos ============ -->
    <section class="card" id="secao-vinculos">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Processos administrativos relacionados</h2>
                <p class="card__subtitulo">Relacionamento N:N navegável nos dois sentidos</p>
            </div>
        </div>
        <div class="card__corpo">
            <?php
            $tipoAlvo = 'administrativo';
            $campoNome = 'administrativos_ids[]';
            $selecionados = $administrativosSelecionados;
            require APP_ROOT . '/Views/partials/vinculos-widget.php';
            ?>
        </div>
    </section>

    <div class="form-pj__rodape">
        <button type="submit" class="btn btn--primario">
            💾 <?= $modo === 'novo' ? 'Salvar processo' : 'Salvar alterações' ?>
        </button>
        <a class="btn btn--ghost" href="<?= e($modo === 'novo' ? url('processos-judiciais.php') : url('processo-judicial.php?id=' . (int)($registro['id'] ?? 0))) ?>">Cancelar</a>
        <span class="texto-secundario">Revise os dados importados antes de salvar.</span>
    </div>
</form>
