<?php
declare(strict_types=1);

/** Edição de um procedimento do catálogo, com o histórico de alterações. */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\ProcedimentoRepository;
use App\Services\ProcedimentoService;

$id = entrada_int('id');
$repo = new ProcedimentoRepository();
$registro = $id !== null ? $repo->buscarPorId($id) : null;
if ($registro === null) {
    flash('erro', 'Procedimento não encontrado.');
    redirecionar('configuracoes.php?aba=procedimentos');
}

$erros = [];
$dados = $registro;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = ['nome' => entrada('nome'), 'descricao' => (string)($_POST['descricao'] ?? '')];
    try {
        (new ProcedimentoService())->atualizar($id, $dados);
        flash('sucesso', 'Procedimento atualizado com sucesso.');
        redirecionar('configuracoes.php?aba=procedimentos');
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'procedimento.atualizar');
        $erros['_geral'] = "Não foi possível salvar. Se o problema persistir, informe o código {$codigo} ao suporte.";
    }
}

$totalProcessos = $repo->totalProcessos($id);
$historico = (new AuditoriaRepository())->listarPorEntidade('procedimentos', $id);
$rotulosCampo = ['nome' => 'Nome', 'descricao' => 'Descrição', 'ativo' => 'Status'];
$historicoVazio = 'Nenhum evento registrado.';
$ativo = (int)$registro['ativo'] === 1;

$titulo      = 'Editar procedimento';
$menuAtivo   = 'config';
$breadcrumbs = ['Sistema' => null, 'Configurações' => 'configuracoes.php', 'Procedimentos' => 'configuracoes.php?aba=procedimentos', $registro['nome'] => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<form method="post" class="form-usuario" novalidate>
    <?= Csrf::campo() ?>
    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Editar procedimento</h2>
                <p class="card__subtitulo">
                    <?= $totalProcessos > 0
                        ? 'O novo nome passa a aparecer nos ' . $totalProcessos . ' processo(s) que já possuem este procedimento.'
                        : 'Nenhum processo possui este procedimento.' ?>
                </p>
            </div>
            <span class="badge <?= $ativo ? 'badge--verde' : 'badge--neutro' ?>"><?= $ativo ? 'Ativo' : 'Inativo' ?></span>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <div class="campo">
                    <label class="campo__rotulo" for="nome">Nome do procedimento <span class="obrigatorio">*</span></label>
                    <input class="input <?= isset($erros['nome']) ? 'is-invalido' : '' ?>" type="text" id="nome" name="nome" required
                           maxlength="<?= ProcedimentoService::NOME_MAX ?>" value="<?= e((string)$dados['nome']) ?>">
                    <?php if (isset($erros['nome'])): ?><span class="campo__erro"><?= e($erros['nome']) ?></span><?php endif; ?>
                </div>
                <div class="campo">
                    <label class="campo__rotulo" for="descricao">Descrição</label>
                    <textarea class="textarea <?= isset($erros['descricao']) ? 'is-invalido' : '' ?>" id="descricao" name="descricao" rows="3"
                              maxlength="<?= ProcedimentoService::DESCRICAO_MAX ?>"><?= e((string)($dados['descricao'] ?? '')) ?></textarea>
                    <?php if (isset($erros['descricao'])): ?><span class="campo__erro"><?= e($erros['descricao']) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card__rodape form-usuario__acoes">
            <a class="btn btn--ghost" href="<?= e(url('configuracoes.php?aba=procedimentos')) ?>">Cancelar</a>
            <button type="submit" class="btn btn--primario">Salvar alterações</button>
        </div>
    </section>
</form>

<section class="card form-usuario">
    <div class="card__cabecalho"><h2 class="card__titulo">Histórico de alterações</h2></div>
    <?php require APP_ROOT . '/Views/partials/historico-auditoria.php'; ?>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
