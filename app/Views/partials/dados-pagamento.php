<?php
/**
 * Guia Dados do Pagamento da ficha do processo administrativo (procedimento
 * Pagamento Indenizatorio): interessados (Demandante e Credor), Objeto,
 * Data de Execucao, Competencia (automatica) e Valor.
 *
 * Variaveis:
 *   $id                 id do processo
 *   $pagamento          ?array PagamentoIndenizatorioRepository::doProcesso
 *   $pagamentoEditavel  bool   procedimento atual e Pagamento Indenizatorio
 *   $demandantes        list   unidades da SES-MT (ativas e a gravada)
 *   $credores           list   entregadores
 *   $dadosPagamento     array  valores do formulario
 *   $errosPagamento     array  erros por campo
 */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Dinheiro;
use App\Services\EntregadorService;
use App\Services\PagamentoIndenizatorioService;

$erroPg = static fn(string $campo): string => isset($errosPagamento[$campo]) ? 'is-invalido' : '';
$msgPg = static function (string $campo, string $ajuda = '') use ($errosPagamento): string {
    if (isset($errosPagamento[$campo])) {
        return '<span class="campo__erro">' . e($errosPagamento[$campo]) . '</span>';
    }
    return $ajuda !== '' ? '<span class="campo__ajuda">' . $ajuda . '</span>' : '';
};
$competenciaAtual = PagamentoIndenizatorioService::competencia($dadosPagamento['data_execucao'] ?? null);
$urlNovoEntregador = url('entregadores.php?processo_id=' . (int)$id);
?>
<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Dados do Pagamento</h2>
            <p class="card__subtitulo">
                <?php if ($pagamento !== null): ?>
                    Registrado por <?= e($pagamento['criado_por_nome'] ?? 'usuário não identificado') ?> em <?= e(datahora_br($pagamento['created_at'])) ?>
                    <?php if ($pagamento['updated_at'] !== $pagamento['created_at']): ?>
                        · atualizado por <?= e($pagamento['atualizado_por_nome'] ?? 'usuário não identificado') ?> em <?= e(datahora_br($pagamento['updated_at'])) ?>
                    <?php endif; ?>
                <?php else: ?>
                    <?= e(PagamentoIndenizatorioService::PROCEDIMENTO) ?> · interessados, objeto, execução e valor
                <?php endif; ?>
            </p>
        </div>
        <?php if ($pagamento !== null): ?>
            <span class="badge badge--azul" title="Competência (ano-mês da data de execução)">Competência <?= e($pagamento['competencia']) ?></span>
        <?php endif; ?>
    </div>

    <?php if (!$pagamentoEditavel): ?>
        <div class="card__corpo">
            <div class="alert alert--info"><span class="alert__icone">ℹ</span>
                <span>O procedimento deste processo não é mais “<?= e(PagamentoIndenizatorioService::PROCEDIMENTO) ?>”. Os dados registrados são mantidos, mas não podem ser alterados.</span></div>
            <div class="definicoes">
                <div class="definicao"><span class="definicao__rotulo">Demandante</span><span class="definicao__valor"><?= e($pagamento['demandante_nome']) ?></span></div>
                <div class="definicao"><span class="definicao__rotulo">Credor</span><span class="definicao__valor"><?= e($pagamento['credor_nome']) ?><br><span class="texto-secundario mono"><?= e(EntregadorService::formatarDocumento($pagamento['credor_documento'])) ?></span></span></div>
                <div class="definicao"><span class="definicao__rotulo">Data de Execução</span><span class="definicao__valor"><?= e(data_br($pagamento['data_execucao'])) ?></span></div>
                <div class="definicao"><span class="definicao__rotulo">Competência</span><span class="definicao__valor mono"><?= e($pagamento['competencia']) ?></span></div>
                <div class="definicao"><span class="definicao__rotulo">Valor</span><span class="definicao__valor"><?= e(Dinheiro::formatar(Dinheiro::deDecimal($pagamento['valor']))) ?></span></div>
                <div class="definicao definicao--larga"><span class="definicao__rotulo">Objeto</span><span class="definicao__valor"><?= nl2br(e($pagamento['objeto'])) ?></span></div>
            </div>
        </div>
    <?php else: ?>
        <form method="post" action="<?= e(url('salvar-dados-pagamento.php')) ?>" novalidate data-dados-pagamento>
            <?= Csrf::campo() ?>
            <input type="hidden" name="processo_id" value="<?= (int)$id ?>">
            <div class="card__corpo">
                <fieldset class="grupo-campos">
                    <legend>Interessados</legend>
                    <div class="form-grid">
                        <div class="campo campo--6">
                            <label class="campo__rotulo" for="pagamento_demandante">Demandante <span class="obrigatorio">*</span></label>
                            <select class="select <?= $erroPg('demandante_lotacao_id') ?>" id="pagamento_demandante" name="demandante_lotacao_id" required
                                    <?= count($demandantes) > 10 ? 'data-select-pesquisavel' : '' ?>>
                                <option value=""><?= $demandantes === [] ? 'Nenhuma unidade da ' . e(PagamentoIndenizatorioService::INSTITUICAO_DEMANDANTE) . ' cadastrada' : 'Selecione a unidade' ?></option>
                                <?php foreach ($demandantes as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>" <?= (string)($dadosPagamento['demandante_lotacao_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>>
                                        <?= e($u['nome']) ?><?= $u['sigla'] !== null ? ' (' . e($u['sigla']) . ')' : '' ?><?= (int)$u['ativo'] === 1 ? '' : ' (inativa)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= $msgPg('demandante_lotacao_id', 'Unidade administrativa da ' . e(PagamentoIndenizatorioService::INSTITUICAO_DEMANDANTE) . ' que demandou o objeto.'
                                . (Auth::ehAdministrador() ? ' <a href="' . e(url('unidades-administrativas.php')) . '">Gerenciar unidades</a>' : '')) ?>
                        </div>
                        <div class="campo campo--6">
                            <label class="campo__rotulo" for="pagamento_credor">Credor <span class="obrigatorio">*</span></label>
                            <select class="select <?= $erroPg('entregador_id') ?>" id="pagamento_credor" name="entregador_id" required
                                    <?= count($credores) > 10 ? 'data-select-pesquisavel' : '' ?>>
                                <option value=""><?= $credores === [] ? 'Nenhum entregador cadastrado' : 'Selecione o entregador' ?></option>
                                <?php foreach ($credores as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= (string)($dadosPagamento['entregador_id'] ?? '') === (string)$c['id'] ? 'selected' : '' ?>>
                                        <?= e(EntregadorService::rotulo($c)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= $msgPg('entregador_id', 'Pessoa física ou jurídica que entregou o objeto. Não está na lista? <a href="'
                                . e($urlNovoEntregador) . '">Cadastrar entregador</a>') ?>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="grupo-campos mb-0">
                    <legend>Objeto, execução e valor</legend>
                    <div class="form-grid">
                        <div class="campo">
                            <label class="campo__rotulo" for="pagamento_objeto">Objeto <span class="obrigatorio">*</span></label>
                            <textarea class="textarea textarea--curta <?= $erroPg('objeto') ?>" id="pagamento_objeto" name="objeto" rows="3" required
                                      maxlength="<?= PagamentoIndenizatorioService::OBJETO_MAX ?>"
                                      placeholder="Bem fornecido ou serviço prestado pelo entregador"><?= e((string)($dadosPagamento['objeto'] ?? '')) ?></textarea>
                            <?= $msgPg('objeto') ?>
                        </div>
                        <div class="campo campo--4">
                            <label class="campo__rotulo" for="pagamento_data_execucao">Data de Execução <span class="obrigatorio">*</span></label>
                            <input class="input <?= $erroPg('data_execucao') ?>" type="date" id="pagamento_data_execucao" name="data_execucao" required
                                   max="<?= e(date('Y-m-d')) ?>" value="<?= e((string)($dadosPagamento['data_execucao'] ?? '')) ?>" data-pagamento-data>
                            <?= $msgPg('data_execucao', 'Data da entrega do objeto.') ?>
                        </div>
                        <div class="campo campo--4">
                            <span class="campo__rotulo" id="pagamento_competencia_rotulo">Competência</span>
                            <output class="input input--leitura mono" id="pagamento_competencia" for="pagamento_data_execucao"
                                    aria-labelledby="pagamento_competencia_rotulo" data-pagamento-competencia><?= e($competenciaAtual ?? '—') ?></output>
                            <span class="campo__ajuda">Automática: ano e mês da Data de Execução.</span>
                        </div>
                        <div class="campo campo--4">
                            <label class="campo__rotulo" for="pagamento_valor">Valor (R$) <span class="obrigatorio">*</span></label>
                            <input class="input input--valor <?= $erroPg('valor') ?>" type="text" id="pagamento_valor" name="valor" required
                                   inputmode="decimal" placeholder="0,00" autocomplete="off" maxlength="22"
                                   value="<?= e((string)($dadosPagamento['valor'] ?? '')) ?>" data-pagamento-valor>
                            <?= $msgPg('valor', 'Preço pago pelo objeto.') ?>
                        </div>
                    </div>
                </fieldset>
            </div>
            <div class="card__rodape">
                <button type="submit" class="btn btn--primario">💾 <?= $pagamento === null ? 'Registrar dados do pagamento' : 'Salvar alterações' ?></button>
            </div>
        </form>
    <?php endif; ?>
</section>
