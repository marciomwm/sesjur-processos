<?php
declare(strict_types=1);

/**
 * Etapa 2 do fluxo de extracao: REVALIDACAO NO SERVIDOR.
 *
 * Recebe o payload produzido pela extensao, confere o identificador
 * (protocolo / CNJ) e devolve os dados normalizados para preenchimento do
 * formulario. Nada e persistido aqui: a gravacao so ocorre apos a
 * confirmacao humana no formulario.
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\ExtracaoService;

exigir_metodo('POST');
exigir_csrf_api();

$corpo      = corpo_json();
$origem     = strtoupper(trim((string)($corpo['origem'] ?? '')));
$solicitado = trim((string)($corpo['solicitado'] ?? ''));
$payload    = is_array($corpo['payload'] ?? null) ? $corpo['payload'] : [];

if ($solicitado === '') {
    responder_json(
        ExtracaoService::falha($origem ?: 'DESCONHECIDA', 'MISSING_IDENTIFIER', 'Identificador solicitado ausente.'),
        422
    );
}

$resposta = match ($origem) {
    'SIGADOC' => ExtracaoService::conferirRetornoSigadoc($payload, $solicitado),
    'PJE'     => ExtracaoService::conferirRetornoPje($payload, $solicitado),
    default   => ExtracaoService::falha('DESCONHECIDA', 'UNKNOWN_SOURCE', 'Origem de extração desconhecida.'),
};

responder_json($resposta, $resposta['success'] ? 200 : 422);
