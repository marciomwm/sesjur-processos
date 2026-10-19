<?php
declare(strict_types=1);

/** Edição de um entregador, com o histórico de alterações. */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EntregadorRepository;
use App\Services\EntregadorService;

$id = entrada_int('id');
$repo = new EntregadorRepository();
$registro = $id !== null ? $repo->buscarPorId($id) : null;
if ($registro === null) {
    flash('erro', 'Entregador não encontrado.');
    redirecionar('entregadores.php');
}

$erros = [];
$dados = $registro;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = ['documento' => entrada('documento'), 'nome' => entrada('nome')];
    try {
        (new EntregadorService())->atualizar($id, $dados);
        flash('sucesso', 'Entregador atualizado com sucesso.');
        redirecionar('entregadores.php');
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'entregador.atualizar');
        $erros['_geral'] = "Não foi possível salvar. Se o problema persistir, informe o código {$codigo} ao suporte.";
    }
}

$totalPagamentos = $repo->totalPagamentos($id);
$historico = (new AuditoriaRepository())->listarPorEntidade('entregadores', $id);
$rotulosCampo = ['documento' => 'CPF/CNPJ', 'nome' => 'Nome', 'tipo_pessoa' => 'Tipo'];
$valorAuditoria = static fn(string $campo, mixed $valor): string => match (true) {
    $valor === null || $valor === '' => '—',
    $campo === 'documento'           => EntregadorService::formatarDocumento((string)$valor),
    $campo === 'tipo_pessoa'         => EntregadorService::TIPOS[$valor] ?? (string)$valor,
    default                          => (string)$valor,
};
$urlCancelar = url('entregadores.php');

$titulo      = 'Editar entregador';
$menuAtivo   = 'entregadores';
$breadcrumbs = ['Cadastros' => null, 'Entregadores' => 'entregadores.php', $registro['nome'] => null];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<section class="card">
    <div class="card__cabecalho">
        <div>
            <h2 class="card__titulo">Editar entregador</h2>
            <p class="card__subtitulo">
                <?= $totalPagamentos > 0
                    ? 'A alteração passa a aparecer nos ' . $totalPagamentos . ' pagamento(s) em que é o credor.'
                    : 'Não é credor em nenhum pagamento.' ?>
            </p>
        </div>
        <span class="badge <?= $registro['tipo_pessoa'] === 'JURIDICA' ? 'badge--azul' : 'badge--neutro' ?>"><?= e(EntregadorService::TIPOS[$registro['tipo_pessoa']] ?? '') ?></span>
    </div>
    <div class="card__corpo">
        <?php require APP_ROOT . '/Views/partials/form-entregador.php'; ?>
    </div>
</section>

<section class="card">
    <div class="card__cabecalho"><h2 class="card__titulo">Histórico de alterações</h2></div>
    <?php require APP_ROOT . '/Views/partials/historico-auditoria.php'; ?>
</section>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
