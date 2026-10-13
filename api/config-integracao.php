<?php
declare(strict_types=1);

/**
 * Configuracao publica da integracao, consumida pela aplicacao e pela
 * pagina de opcoes da extensao (allowlist de dominios autorizados).
 * Nao expoe credencial alguma.
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\ConfiguracaoService;

exigir_metodo('GET');

responder_json([
    'success' => true,
    'config'  => [
        'sigadocHosts' => ConfiguracaoService::hostsSigadoc(),
        'pjeHosts'     => ConfiguracaoService::hostsPje(),
        'exigirHttps'  => ConfiguracaoService::exigirHttps(),
        'sigadocUrlTemplate' => ConfiguracaoService::urlTemplateSigadoc(),
        'timeoutMs'    => (int)config('integracao.timeout_ms', 45000),
    ],
]);
