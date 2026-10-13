<?php
declare(strict_types=1);

define('GJ_ROTA_PUBLICA', true);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\CredencialRepository;
use App\Services\AuthService;

$retorno = Auth::retornoSeguro($_GET['retorno'] ?? $_POST['retorno'] ?? '');

// Ja autenticado: segue direto.
if (usuario_logado_id() !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    redirecionar($retorno);
}

$erro  = null;
$email = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::exigir();
    $email = mb_substr(entrada('email'), 0, 254);

    try {
        $resultado = (new AuthService())->autenticar($email, $_POST['senha'] ?? '');
        Auth::iniciarSessao($resultado['id'], $resultado['exigir_troca']);
        if ($resultado['exigir_troca']) {
            redirecionar('alterar-senha.php');
        }
        redirecionar($retorno);
    } catch (ValidationException $e) {
        $erro = $e->getMessage();
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'login');
        $erro = "Não foi possível entrar agora. Tente novamente; se persistir, informe o código {$codigo} ao suporte.";
    }
}

// Primeira instalacao: ninguem tem senha ainda.
$semAcessoConfigurado = false;
try {
    $semAcessoConfigurado = !(new CredencialRepository())->existeAlguma();
} catch (Throwable) {
    // tabela ausente (migracao pendente) ou banco fora do ar: a tela continua
}

$mensagens = flash_consumir();
?>
<!DOCTYPE html>
<html lang="pt-BR" data-density="<?= e(densidade_interface()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · <?= e(config('app.nome')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='26' font-size='26'>⚖️</text></svg>">
</head>
<body class="pagina-login">
<main class="login">
    <div class="login__marca">
        <span class="sidebar__simbolo"><?= e(mb_substr((string)config('app.nome'), 0, 1)) ?></span>
        <span class="login__titulo">
            <strong><?= e(config('app.nome')) ?></strong>
            <small><?= e(config('app.unidade')) ?></small>
        </span>
    </div>

    <section class="card login__card">
        <div class="card__corpo">
            <h1 class="login__cabecalho">Entrar no sistema</h1>

            <?php foreach ($mensagens as $msg): ?>
                <div class="alert alert--<?= e($msg['tipo']) ?>" role="status"><span><?= e($msg['mensagem']) ?></span></div>
            <?php endforeach; ?>

            <?php if ($erro !== null): ?>
                <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erro) ?></span></div>
            <?php endif; ?>

            <form method="post" class="login__form" novalidate>
                <?= Csrf::campo() ?>
                <input type="hidden" name="retorno" value="<?= e($retorno) ?>">
                <div class="campo">
                    <label class="campo__rotulo" for="email">E-mail institucional</label>
                    <input class="input" type="email" id="email" name="email" required autocomplete="username"
                           maxlength="254" value="<?= e($email) ?>" <?= $email === '' ? 'autofocus' : '' ?>>
                </div>
                <div class="campo">
                    <label class="campo__rotulo" for="senha">Senha</label>
                    <input class="input" type="password" id="senha" name="senha" required autocomplete="current-password"
                           <?= $email !== '' ? 'autofocus' : '' ?>>
                </div>
                <button type="submit" class="btn btn--primario btn--bloco">Entrar</button>
            </form>

            <p class="login__ajuda">Esqueceu a senha? Peça a um administrador do sistema para redefini-la.</p>

            <?php if ($semAcessoConfigurado): ?>
                <div class="alert alert--aviso mb-0" role="note">
                    <span>
                        <strong>Primeiro acesso:</strong> nenhum usuário tem senha definida. No servidor, execute
                        <code>php database/definir-administrador.php seu.email@ses.mt.gov.br</code>
                        para criar o primeiro administrador.
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>
</body>
</html>
