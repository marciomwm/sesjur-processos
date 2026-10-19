<?php
declare(strict_types=1);

/**
 * Criacao de usuario do sistema a partir de um Agente Publico ja
 * cadastrado (a pessoa). Aqui so se informa o que e da conta: e-mail de
 * acesso e perfil.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AgentePublicoRepository;
use App\Services\UsuarioService;

$repoAgentes = new AgentePublicoRepository();
$erros = [];
$agenteId = entrada_int('agente_id', $_SERVER['REQUEST_METHOD'] === 'POST' ? [] : $_GET);
$agente = $agenteId !== null ? $repoAgentes->buscarPorId($agenteId) : null;
if ($agente !== null && $agente['usuario_id'] !== null) {
    flash('info', $agente['nome'] . ' já possui usuário do sistema.');
    redirecionar('usuario.php?id=' . (int)$agente['usuario_id']);
}
$dados = [
    'agente_publico_id' => $agente !== null ? (string)$agente['id'] : '',
    'email'             => (string)($agente['email_institucional'] ?? ''),
    'perfil'            => Perfil::USUARIO,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::exigir();
    $dados = [
        'agente_publico_id' => entrada('agente_publico_id'),
        'email'             => entrada('email'),
        'perfil'            => entrada('perfil'),
    ];
    $agente = ctype_digit($dados['agente_publico_id']) ? $repoAgentes->buscarPorId((int)$dados['agente_publico_id']) : null;

    try {
        if ($agente === null) {
            throw new ValidationException(['agente_publico_id' => 'Selecione o agente público titular da conta.']);
        }
        $id = (new UsuarioService())->criarParaAgente((int)$agente['id'], $dados);
        flash('sucesso', 'Usuário criado para ' . $agente['nome'] . '. Defina a senha provisória para liberar o acesso.');
        redirecionar('usuario.php?id=' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
        // Pendencias nos dados do agente (nome, cargo, unidade...) nao tem campo aqui.
        $doAgente = array_diff_key($erros, array_flip(['agente_publico_id', 'email', 'perfil']));
        if ($doAgente !== []) {
            $erros = array_diff_key($erros, $doAgente);
            $erros['_geral'] = 'Corrija o cadastro do agente público antes de criar o usuário: ' . implode(' ', $doAgente);
        }
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'usuario.criar');
        $erros['_geral'] = "Não foi possível criar o usuário. Tente novamente; se persistir, informe o código {$codigo} ao suporte.";
    }
}

// Agentes que podem receber conta; o ja escolhido continua na lista.
$agentesDisponiveis = $repoAgentes->semUsuario();
if ($agente !== null && !in_array((int)$agente['id'], array_map('intval', array_column($agentesDisponiveis, 'id')), true)) {
    $agentesDisponiveis[] = array_intersect_key($agente, array_flip(['id', 'nome', 'matricula', 'email_institucional', 'lotacao']));
}

$titulo      = 'Criar Usuário';
$menuAtivo   = 'config';
$breadcrumbs = ['Configurações' => 'configuracoes.php', 'Usuários' => 'configuracoes.php?aba=usuarios', 'Criar' => null];
$scripts     = ['form-usuario.js'];
$modo        = 'novo';
$registro    = null;
$cargos = $lotacoes = [];

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert">
        <span class="alert__icone">✕</span>
        <span><?= e($erros['_geral']) ?>
            <?php if ($agente !== null): ?> <a href="<?= e(url('editar-agente-publico.php?id=' . (int)$agente['id'])) ?>">Editar o agente</a><?php endif; ?></span>
    </div>
<?php endif; ?>

<?php if ($agentesDisponiveis === []): ?>
    <div class="alert alert--info" role="status">
        <span class="alert__icone">ℹ</span>
        <span>Todos os agentes públicos ativos já têm usuário. <a href="<?= e(url('novo-agente-publico.php')) ?>">Cadastre o agente público</a> antes de criar a conta.</span>
    </div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-usuario.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
