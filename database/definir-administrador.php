<?php
declare(strict_types=1);

/**
 * Define um usuario ja cadastrado como ADMINISTRADOR e grava a senha dele.
 * Uso na primeira instalacao (ninguem consegue entrar ainda) ou para
 * recuperar o acesso de administrador.
 *
 *   php database/definir-administrador.php email@ses.mt.gov.br
 *
 * A senha e pedida no terminal (nunca como argumento, para nao ficar no
 * historico do shell). Somente linha de comando.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Core\Perfil;
use App\Repositories\CredencialRepository;
use App\Services\AuditoriaService;
use App\Validators\SenhaValidator;
use App\Validators\UsuarioValidator;

/** Le uma linha do terminal, sem eco quando possivel. */
function ler_senha(string $rotulo): string
{
    fwrite(STDOUT, $rotulo);
    $semEco = DIRECTORY_SEPARATOR === '/' && shell_exec('stty -echo 2>/dev/null') !== null;
    $linha = fgets(STDIN);
    if ($semEco) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
    return rtrim((string)$linha, "\r\n");
}

[$email, $erro] = UsuarioValidator::email($argv[1] ?? '');
if ($erro !== null) {
    fwrite(STDERR, "Uso: php database/definir-administrador.php email@ses.mt.gov.br\n");
    exit(1);
}

$db = Database::conexao();
$stmt = $db->prepare('SELECT id, nome, ativo, perfil FROM usuarios WHERE email = :email');
$stmt->execute([':email' => $email]);
$usuario = $stmt->fetch();
if (!$usuario) {
    fwrite(STDERR, "Nenhum usuário cadastrado com o e-mail {$email}.\n");
    exit(1);
}

fwrite(STDOUT, "Usuário: {$usuario['nome']} (perfil atual: " . Perfil::rotulo($usuario['perfil']) . ")\n");
if (DIRECTORY_SEPARATOR !== '/') {
    fwrite(STDOUT, "Atenção: no Windows a senha aparece enquanto é digitada.\n");
}

$senha = ler_senha('Nova senha: ');
$confirmacao = ler_senha('Confirme a senha: ');
$erro = SenhaValidator::validar($senha, $confirmacao);
if ($erro !== null) {
    fwrite(STDERR, $erro . "\n");
    exit(1);
}

Database::transacao(function () use ($db, $usuario, $senha): void {
    $id = (int)$usuario['id'];
    $db->prepare("UPDATE usuarios SET perfil = 'ADMINISTRADOR', ativo = 1, inativado_em = NULL WHERE id = :id")
       ->execute([':id' => $id]);
    (new CredencialRepository())->definirSenha($id, password_hash($senha, PASSWORD_DEFAULT), false);

    $auditoria = new AuditoriaService();
    if ($usuario['perfil'] !== Perfil::ADMINISTRADOR) {
        $auditoria->registrar(AuditoriaService::USUARIO_EDITADO, 'usuarios', $id,
            ['perfil' => $usuario['perfil']], ['perfil' => Perfil::ADMINISTRADOR]);
    }
    if ((int)$usuario['ativo'] !== 1) {
        $auditoria->registrar(AuditoriaService::USUARIO_ATIVADO, 'usuarios', $id, ['ativo' => 0], ['ativo' => 1]);
    }
    $auditoria->registrar(AuditoriaService::SENHA_DEFINIDA, 'usuarios', $id, null, ['origem' => 'linha de comando']);
});

fwrite(STDOUT, "Pronto: {$usuario['nome']} agora é Administrador e pode entrar com {$email}.\n");
