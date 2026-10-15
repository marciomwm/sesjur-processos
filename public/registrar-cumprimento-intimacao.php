<?php
declare(strict_types=1);

/**
 * Registra o cumprimento de uma Intimacao Pessoal (status Cumprida e data
 * efetiva). Enviado pelo modal: com "Accept: application/json" responde
 * JSON; sem JavaScript, volta a origem com a mensagem.
 */

if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
    define('GJ_API', true);
}
require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores e gestores.
App\Services\IntimacaoPessoalService::exigirAcesso();

use App\Core\AcessoNegadoException;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\IntimacaoPessoalService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('intimacoes-pessoais.php');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
$destino = destino_intimacao(entrada('retorno'), $id !== null ? '#intimacao-' . $id : '');
if ($id === null) {
    responder_formulario(false, 'Intimação não informada.', $destino);
}

try {
    (new IntimacaoPessoalService())->registrarCumprimento($id, entrada('data_cumprimento'));
    responder_formulario(true, 'Cumprimento registrado.', $destino);
} catch (ValidationException $e) {
    responder_formulario(false, $e->erros() === [] ? $e->getMessage() : 'Corrija os campos destacados.', $destino, $e->erros());
} catch (AcessoNegadoException $e) {
    responder_formulario(false, $e->getMessage(), $destino, [], 403);
} catch (Throwable $e) {
    $codigo = log_erro($e, 'intimacao.cumprimento');
    responder_formulario(false, "Não foi possível registrar o cumprimento (código {$codigo}).", $destino, [], 500);
}
