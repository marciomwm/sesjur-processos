<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\PrioridadeRepository;
use App\Services\PrioridadeService;

$id = entrada_int('id');
$repo = new PrioridadeRepository();
$registro = $id !== null ? $repo->buscarPorId($id) : null;
if ($registro === null) {
    flash('erro', 'Prioridade não encontrada.');
    redirecionar('prioridades.php');
}

$erros = [];
$dados = $registro;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = ['nome' => entrada('nome'), 'nivel' => entrada('nivel'), 'descricao' => (string)($_POST['descricao'] ?? '')];
    try {
        (new PrioridadeService())->atualizar($id, $dados);
        flash('sucesso', 'Prioridade atualizada com sucesso.');
        redirecionar('prioridades.php');
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'prioridade.atualizar');
        $erros['_geral'] = "Não foi possível salvar. Se o problema persistir, informe o código {$codigo} ao suporte.";
    }
}

$totalProcessos = $repo->totalProcessos($id);
$historico = (new AuditoriaRepository())->listarPorEntidade('prioridades', $id);
$rotulosCampo = ['nome' => 'Nome', 'nivel' => 'Nível', 'descricao' => 'Descrição', 'ativo' => 'Status'];
$valorAuditoria = static fn(string $campo, mixed $valor): string => match (true) {
    $valor === null || $valor === '' => '—',
    $campo === 'nivel'               => PrioridadeService::rotuloNivel($valor),
    default                          => (string)$valor,
};
$historicoVazio = 'Nenhum evento registrado. Prioridades criadas pela instalação do banco não têm evento de cadastro.';
$ativa = (int)$registro['ativo'] === 1;

$titulo      = 'Editar prioridade';
$menuAtivo   = 'prioridades';
$breadcrumbs = ['Sistema' => null, 'Prioridades' => 'prioridades.php', $registro['nome'] => null];

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
                <h2 class="card__titulo">Editar prioridade</h2>
                <p class="card__subtitulo">
                    <?= $totalProcessos > 0
                        ? 'A alteração vale para os ' . $totalProcessos . ' processo(s) que já a possuem.'
                        : 'Nenhum processo possui esta prioridade.' ?>
                </p>
            </div>
            <span class="badge <?= $ativa ? 'badge--verde' : 'badge--neutro' ?>"><?= $ativa ? 'Ativa' : 'Inativa' ?></span>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <div class="campo campo--8">
                    <label class="campo__rotulo" for="nome">Nome <span class="obrigatorio">*</span></label>
                    <input class="input <?= isset($erros['nome']) ? 'is-invalido' : '' ?>" type="text" id="nome" name="nome" required
                           maxlength="<?= PrioridadeService::NOME_MAX ?>" value="<?= e((string)$dados['nome']) ?>">
                    <?php if (isset($erros['nome'])): ?><span class="campo__erro"><?= e($erros['nome']) ?></span><?php endif; ?>
                </div>
                <div class="campo campo--4">
                    <label class="campo__rotulo" for="nivel">Nível <span class="obrigatorio">*</span></label>
                    <select class="select <?= isset($erros['nivel']) ? 'is-invalido' : '' ?>" id="nivel" name="nivel" required>
                        <?php foreach (PrioridadeService::NIVEIS as $n => $rotulo): ?>
                            <option value="<?= $n ?>" <?= (string)$dados['nivel'] === (string)$n ? 'selected' : '' ?>><?= $n ?> · <?= e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($erros['nivel'])): ?><span class="campo__erro"><?= e($erros['nivel']) ?></span><?php endif; ?>
                </div>
                <div class="campo">
                    <label class="campo__rotulo" for="descricao">Descrição</label>
                    <textarea class="textarea <?= isset($erros['descricao']) ? 'is-invalido' : '' ?>" id="descricao" name="descricao" rows="3"
                              maxlength="<?= PrioridadeService::DESCRICAO_MAX ?>"><?= e((string)($dados['descricao'] ?? '')) ?></textarea>
                    <?php if (isset($erros['descricao'])): ?><span class="campo__erro"><?= e($erros['descricao']) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card__rodape form-usuario__acoes">
            <a class="btn btn--ghost" href="<?= e(url('prioridades.php')) ?>">Cancelar</a>
            <button type="submit" class="btn btn--primario">Salvar alterações</button>
        </div>
    </section>
</form>

<section class="card form-usuario">
    <div class="card__cabecalho"><h2 class="card__titulo">Histórico de alterações</h2></div>
    <?php require APP_ROOT . '/Views/partials/historico-auditoria.php'; ?>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
