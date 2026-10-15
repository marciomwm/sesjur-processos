<?php
declare(strict_types=1);

/**
 * Exclusao logica de uma Intimacao Pessoal, com motivo obrigatorio
 * (administrador, gestor ou quem registrou). O registro e o historico
 * permanecem; a intimacao so sai do acompanhamento.
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
$destino = destino_intimacao(entrada('retorno'));
// Da propria ficha da intimacao, volta para a listagem.
if (str_starts_with($destino, 'intimacao.php')) {
    $destino = 'intimacoes-pessoais.php';
}
if ($id === null) {
    responder_formulario(false, 'Intimação não informada.', $destino);
}

try {
    $excluida = (new IntimacaoPessoalService())->excluir($id, (string)($_POST['motivo'] ?? ''));
    responder_formulario(true, 'Intimação nº ' . $id . ' (' . $excluida['intimado_nome'] . ') excluída. O registro continua disponível para consulta.', $destino);
} catch (ValidationException $e) {
    responder_formulario(false, $e->erros() === [] ? $e->getMessage() : 'Corrija os campos destacados.', $destino, $e->erros());
} catch (AcessoNegadoException $e) {
    responder_formulario(false, $e->getMessage(), $destino, [], 403);
} catch (Throwable $e) {
    $codigo = log_erro($e, 'intimacao.excluir');
    responder_formulario(false, "Não foi possível excluir a intimação (código {$codigo}).", $destino, [], 500);
}
