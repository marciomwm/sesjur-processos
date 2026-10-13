<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\AuthService;
use App\Validators\SenhaValidator;

$id = (int)usuario_logado_id();
$obrigatoria = Auth::trocaSenhaPendente();
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    try {
        (new AuthService())->alterarPropriaSenha(
            $id,
            $_POST['senha_atual'] ?? '',
            $_POST['senha'] ?? '',
            $_POST['senha_confirmacao'] ?? ''
        );
        Auth::concluirTrocaSenha();
        flash('sucesso', 'Senha alterada com sucesso.');
        redirecionar('index.php');
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'senha.alterar');
        $erros['_geral'] = "Não foi possível alterar a senha. Tente novamente; se persistir, informe o código {$codigo} ao suporte.";
    }
}

$titulo      = 'Alterar senha';
$menuAtivo   = 'senha';
$breadcrumbs = ['Minha conta' => null, 'Alterar senha' => null];

require APP_ROOT . '/Views/layout/cabecalho.php';

$mensagem = static function (string $campo, string $ajuda = '') use ($erros): string {
    if (isset($erros[$campo])) {
        return '<span class="campo__erro">' . e($erros[$campo]) . '</span>';
    }
    return $ajuda !== '' ? '<span class="campo__ajuda">' . e($ajuda) . '</span>' : '';
};
?>

<?php if ($obrigatoria): ?>
    <div class="alert alert--aviso" role="status">
        <span class="alert__icone">ℹ</span>
        <span>Sua senha foi definida por um administrador e é provisória. Crie uma senha pessoal para continuar usando o sistema.</span>
    </div>
<?php endif; ?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php endif; ?>

<form method="post" class="form-usuario form-senha" novalidate>
    <?= Csrf::campo() ?>
    <section class="card">
        <div class="card__cabecalho">
            <div>
                <h2 class="card__titulo">Alterar minha senha</h2>
                <p class="card__subtitulo">
                    Mínimo de <?= SenhaValidator::MIN_CARACTERES ?> caracteres, com letras e números.
                </p>
            </div>
        </div>
        <div class="card__corpo">
            <div class="form-grid">
                <div class="campo">
                    <label class="campo__rotulo" for="senha_atual">Senha atual <span class="obrigatorio">*</span></label>
                    <input class="input <?= isset($erros['senha_atual']) ? 'is-invalido' : '' ?>" type="password" id="senha_atual"
                           name="senha_atual" required autocomplete="current-password" autofocus>
                    <?= $mensagem('senha_atual', $obrigatoria ? 'A senha provisória que você recebeu.' : '') ?>
                </div>
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="senha">Nova senha <span class="obrigatorio">*</span></label>
                    <input class="input <?= isset($erros['senha']) ? 'is-invalido' : '' ?>" type="password" id="senha" name="senha"
                           required minlength="<?= SenhaValidator::MIN_CARACTERES ?>" autocomplete="new-password">
                    <?= $mensagem('senha') ?>
                </div>
                <div class="campo campo--6">
                    <label class="campo__rotulo" for="senha_confirmacao">Confirme a nova senha <span class="obrigatorio">*</span></label>
                    <input class="input" type="password" id="senha_confirmacao" name="senha_confirmacao"
                           required minlength="<?= SenhaValidator::MIN_CARACTERES ?>" autocomplete="new-password">
                </div>
            </div>
        </div>
        <div class="card__rodape form-usuario__acoes">
            <?php if (!$obrigatoria): ?>
                <a class="btn btn--ghost" href="<?= e(url('index.php')) ?>">Cancelar</a>
            <?php endif; ?>
            <button type="submit" class="btn btn--primario">Salvar nova senha</button>
        </div>
    </section>
</form>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
