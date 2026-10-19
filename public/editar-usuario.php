<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\CargoRepository;
use App\Repositories\LotacaoRepository;
use App\Repositories\UsuarioRepository;
use App\Services\UsuarioService;

$id = entrada_int('id');
if ($id === null) {
    flash('erro', 'Usuário não informado.');
    redirecionar('configuracoes.php?aba=usuarios');
}

// Administrador edita qualquer usuario; os demais, apenas o proprio cadastro.
if (!UsuarioService::podeEditar($id)) {
    throw new AcessoNegadoException('Você só pode editar o seu próprio cadastro.');
}
$proprio = usuario_logado_id() === $id;

$registro = (new UsuarioRepository())->buscarPorId($id);
if ($registro === null) {
    flash('erro', 'Usuário não encontrado.');
    redirecionar('configuracoes.php?aba=usuarios');
}

$erros = [];
$dados = $registro;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = entrada_usuario(true);

    try {
        (new UsuarioService())->atualizar($id, $dados);
        flash('sucesso', 'Dados do usuário atualizados com sucesso.');
        redirecionar('usuario.php?id=' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (AcessoNegadoException $e) {
        $erros['_geral'] = $e->getMessage();
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'usuario.atualizar');
        $erros['_geral'] = "Não foi possível salvar as alterações. Tente novamente; se persistir, informe o código {$codigo} ao suporte.";
    }
}

// Mantem no select o cargo/lotacao gravados, mesmo que inativados depois.
$cargos   = (new CargoRepository())->opcoes((int)$registro['cargo_id']);
$lotacoes = (new LotacaoRepository())->opcoes((int)$registro['lotacao_id']);

if ($proprio && !Auth::ehAdministrador()) {
    $titulo      = 'Editar meu cadastro';
    $menuAtivo   = 'meu-cadastro';
    $breadcrumbs = ['Minha conta' => null, 'Meu cadastro' => 'usuario.php?id=' . $id, 'Editar' => null];
} else {
    $titulo      = 'Editar Usuário';
    $menuAtivo   = $proprio ? 'meu-cadastro' : 'config';
    $breadcrumbs = [
        'Usuários'        => 'configuracoes.php?aba=usuarios',
        $registro['nome'] => 'usuario.php?id=' . $id,
        'Editar'          => null,
    ];
}
$scripts = ['form-usuario.js'];
$modo    = 'editar';

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-usuario.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
