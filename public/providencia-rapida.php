<?php
declare(strict_types=1);

/**
 * Providencia rapida: cadastra uma Acao, Solicitacao ou Anotacao a partir
 * dos modais da ficha do processo administrativo, sem sair dela.
 *
 * POST tipo=acao|solicitacao|anotacao, processo_id e os campos do tipo.
 * Com "Accept: application/json" (modal) responde JSON com os erros por
 * campo; sem JavaScript, volta para a ficha com a mensagem.
 * O processo, o autor (sessao), a data/hora (banco) e o tipo sao definidos
 * aqui, nunca digitados.
 */

if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
    define('GJ_API', true);
}
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\AcaoService;
use App\Services\AnotacaoService;
use App\Services\SolicitacaoService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('processos-administrativos.php');
}
Csrf::exigir();

$processoId = entrada_int('processo_id', $_POST);
if ($processoId === null) {
    responder_formulario(false, 'Processo administrativo não informado.', 'processos-administrativos.php');
}
$ficha = 'processo-administrativo.php?id=' . $processoId;
$tipo  = entrada('tipo');

try {
    switch ($tipo) {
        case 'acao':
            $id = (new AcaoService())->criar([
                'processo_id' => (string)$processoId,
                'descricao'   => entrada('descricao'),
                'prazo'       => entrada('prazo'),
                'prazo_hora'  => entrada('prazo_hora'),
                'status'      => entrada('status'),
                'observacao'  => entrada('observacao'),
            ]);
            responder_formulario(true, 'Tarefa cadastrada.', $ficha . '#acao-' . $id);
        case 'solicitacao':
            $id = (new SolicitacaoService())->criar([
                'processo_id'             => (string)$processoId,
                'data_solicitacao'        => entrada('data_solicitacao'),
                'expediente'              => entrada('expediente'),
                'destinatario_lotacao_id' => entrada('destinatario_lotacao_id'),
                'assunto'                 => entrada('assunto'),
                'prazo'                   => entrada('prazo'),
                'status'                  => entrada('status'),
            ]);
            responder_formulario(true, 'Solicitação cadastrada.', $ficha . '#solicitacao-' . $id);
        case 'anotacao':
            $id = (new AnotacaoService())->criar([
                'processo_id' => (string)$processoId,
                // Sem entrada(): o trim por linha fica com o AnotacaoService.
                'texto'       => is_string($_POST['texto'] ?? null) ? $_POST['texto'] : '',
            ]);
            responder_formulario(true, 'Anotação cadastrada.', $ficha . '#anotacao-' . $id);
        default:
            responder_formulario(false, 'Tipo de providência inválido.', $ficha . '#providencias');
    }
} catch (ValidationException $e) {
    responder_formulario(false, $e->erros() === [] ? $e->getMessage() : 'Corrija os campos destacados.', $ficha . '#providencias', $e->erros());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'providencia.' . $tipo);
    responder_formulario(false, "Não foi possível salvar a providência (código {$codigo}).", $ficha . '#providencias', [], 500);
}
