<?php
declare(strict_types=1);

/**
 * Registra (sem id) ou altera (com id) um lancamento de Bloqueio ou
 * Desbloqueio. Enviado pelo modal da ficha do processo judicial ou do painel
 * de bloqueios: com "Accept: application/json" responde JSON; sem
 * JavaScript, volta para a origem com a mensagem.
 */

if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
    define('GJ_API', true);
}
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\BloqueioJudicialRepository;
use App\Services\BloqueioJudicialService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('bloqueios-judiciais.php');
}
Csrf::exigir();

$id = entrada_int('id', $_POST);
$processoId = entrada_int('processo_judicial_id', $_POST);
if ($id !== null) {
    // Na edicao o processo e o do proprio lancamento.
    $processoId = (int)((new BloqueioJudicialRepository())->buscarPorId($id)['processo_judicial_id'] ?? 0) ?: $processoId;
}
$destino = destino_bloqueios(entrada('retorno'), $processoId);
$entrada = [
    'tipo'            => entrada('tipo'),
    'data_lancamento' => entrada('data_lancamento'),
    'valor'           => entrada('valor'),
    'identificador'   => entrada('identificador'),
    'observacao'      => (string)($_POST['observacao'] ?? ''),
];

$servico = new BloqueioJudicialService();
try {
    if ($id === null) {
        $servico->criar($processoId, $entrada);
        $rotulo = BloqueioJudicialService::TIPOS[strtoupper($entrada['tipo'])] ?? 'Lançamento';
        responder_formulario(true, $rotulo . ' registrado.', $destino);
    }
    $servico->atualizar($id, $entrada);
    responder_formulario(true, 'Lançamento atualizado.', $destino);
} catch (ValidationException $e) {
    responder_formulario(false, $e->erros() === [] ? $e->getMessage() : 'Corrija os campos destacados.', $destino, $e->erros());
} catch (AcessoNegadoException $e) {
    responder_formulario(false, $e->getMessage(), $destino, [], 403);
} catch (Throwable $e) {
    $codigo = log_erro($e, 'bloqueio.salvar');
    responder_formulario(false, "Não foi possível salvar o lançamento (código {$codigo}).", $destino, [], 500);
}
