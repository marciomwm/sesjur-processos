<?php
declare(strict_types=1);

/**
 * Cadastra (sem id) ou altera (com id) um recurso. Enviado pelo modal da
 * Central de Recursos: com "Accept: application/json" responde JSON; sem
 * JavaScript, volta para a página com a mensagem.
 */

if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
    define('GJ_API', true);
}
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\RecursoService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('recursos.php');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
$entrada = [
    'titulo'     => entrada('titulo'),
    'descricao'  => (string)($_POST['descricao'] ?? ''),
    'url'        => entrada('url'),
    'tipo_id'    => entrada('tipo_id'),
    'categorias' => is_array($_POST['categorias'] ?? null) ? $_POST['categorias'] : [],
];

$servico = new RecursoService();
try {
    if ($id === null) {
        $servico->criar($entrada);
        responder_formulario(true, 'Recurso cadastrado.', 'recursos.php');
    }
    $servico->atualizar($id, $entrada);
    responder_formulario(true, 'Recurso atualizado.', 'recursos.php');
} catch (ValidationException $e) {
    responder_formulario(false, $e->erros() === [] ? $e->getMessage() : 'Corrija os campos destacados.', 'recursos.php', $e->erros());
} catch (AcessoNegadoException $e) {
    responder_formulario(false, $e->getMessage(), 'recursos.php', [], 403);
} catch (Throwable $e) {
    $codigo = log_erro($e, 'recurso.salvar');
    responder_formulario(false, "Não foi possível salvar o recurso (código {$codigo}).", 'recursos.php', [], 500);
}
