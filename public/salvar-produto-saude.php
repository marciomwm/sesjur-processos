<?php
declare(strict_types=1);

/**
 * Inclui (sem id) ou altera (com id) um produto de saude do processo
 * administrativo. Enviado pelo modal da guia Produtos de Saude: com
 * "Accept: application/json" responde JSON; sem JavaScript, volta para a
 * ficha com a mensagem.
 */

if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
    define('GJ_API', true);
}
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\ProdutoSaudeService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('processos-administrativos.php');
}
Csrf::exigir();

$processoId = entrada_int('processo_id', $_POST);
if ($processoId === null) {
    responder_formulario(false, 'Processo administrativo não informado.', 'processos-administrativos.php');
}
$ficha = 'processo-administrativo.php?id=' . $processoId;
$id = entrada_int('id', $_POST);

$entrada = ['processo_id' => (string)$processoId];
foreach (['categoria', 'descricao', 'sigtap_codigo', 'sigtap_nome', 'sigtap_grupo_nome',
          'sigtap_subgrupo_nome', 'sigtap_forma_organizacao_nome'] as $campo) {
    $entrada[$campo] = entrada($campo);
}

$servico = new ProdutoSaudeService();
try {
    if ($id === null) {
        $id = $servico->criar($entrada);
        responder_formulario(true, 'Produto de saúde incluído no processo.', $ficha . '#produto-' . $id);
    }
    $servico->atualizar($id, $entrada);
    responder_formulario(true, 'Produto de saúde atualizado.', $ficha . '#produto-' . $id);
} catch (ValidationException $e) {
    responder_formulario(false, $e->erros() === [] ? $e->getMessage() : 'Corrija os campos destacados.', $ficha . '#produtos-saude', $e->erros());
} catch (AcessoNegadoException $e) {
    responder_formulario(false, $e->getMessage(), $ficha . '#produtos-saude', [], 403);
} catch (Throwable $e) {
    $codigo = log_erro($e, 'produto-saude.salvar');
    responder_formulario(false, "Não foi possível salvar o produto (código {$codigo}).", $ficha . '#produtos-saude', [], 500);
}
