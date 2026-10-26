<?php
declare(strict_types=1);

/**
 * GET /api/v1/  -  identificacao do cliente e recursos disponiveis.
 * Serve para o consumidor testar o token antes da importacao.
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Services\ApiClienteService;

$cliente = api_autenticar(null);

$recursos = [
    'processos_administrativos' => 'processos-administrativos.php',
    'processos_judiciais'       => 'processos-judiciais.php',
    'processos_extrajudiciais'  => 'processos-extrajudiciais.php',
    'vinculos'                  => 'vinculos.php',
];

responder_json([
    'success'   => true,
    'api'       => 'SESJUR — API de Integração',
    'versao'    => API_VERSAO,
    'gerado_em' => api_agora(),
    'cliente'   => api_formatar([
        'nome'        => $cliente['nome'],
        'responsavel' => $cliente['responsavel_nome'],
        'expira_em'   => $cliente['expira_em'],
    ]),
    'recursos'  => array_map(
        static fn(string $escopo): array => [
            'escopo'    => $escopo,
            'descricao' => ApiClienteService::ESCOPOS[$escopo],
            'endpoint'  => $recursos[$escopo],
        ],
        array_values(array_intersect(array_keys($recursos), $cliente['escopos']))
    ),
    'limites'   => [
        'itens_por_pagina_max' => API_LIMITE_MAXIMO,
        'requisicoes_por_minuto' => max(1, (int)config('api.limite_por_minuto', 120)),
    ],
]);
