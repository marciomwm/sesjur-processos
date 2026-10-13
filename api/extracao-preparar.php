<?php
declare(strict_types=1);

/**
 * Etapa 1 do fluxo de extracao: AUTORIZACAO.
 *
 * Valida o identificador informado e devolve a allowlist de dominios que a
 * extensao pode acessar. O PHP nunca acessa SIGADOC/PJe - apenas autoriza.
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\ExtracaoService;

exigir_metodo('POST');
exigir_csrf_api();

$corpo  = corpo_json();
$origem = strtoupper(trim((string)($corpo['origem'] ?? '')));

$resposta = match ($origem) {
    'SIGADOC' => ExtracaoService::autorizarSigadoc($corpo['protocolo'] ?? null),
    'PJE'     => ExtracaoService::autorizarPje($corpo['numero_cnj'] ?? null, $corpo['url'] ?? null),
    default   => ExtracaoService::falha('DESCONHECIDA', 'UNKNOWN_SOURCE', 'Origem de extração desconhecida.'),
};

responder_json($resposta, $resposta['success'] ? 200 : 422);
