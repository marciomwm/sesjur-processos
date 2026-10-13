<?php
declare(strict_types=1);

/**
 * Sugestões da pesquisa geral da barra superior (poucos itens por grupo).
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Core\Auth;
use App\Services\PesquisaService;

exigir_metodo('GET');

$termo = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 150);
if (mb_strlen($termo) < PesquisaService::TERMO_MINIMO) {
    responder_json(['success' => true, 'grupos' => []]);
}

try {
    $grupos = (new PesquisaService())->pesquisar($termo, 4, Auth::ehAdministrador());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'api.pesquisa-geral');
    responder_json([
        'success' => false,
        'error'   => ['code' => 'DB_ERROR', 'message' => "Falha ao pesquisar (código {$codigo})."],
    ], 500);
}

responder_json([
    'success'  => true,
    'grupos'   => array_values(array_map(static fn(array $g): array => [
        'titulo' => $g['titulo'],
        'total'  => $g['total'],
        'itens'  => $g['itens'],
    ], $grupos)),
    'todosUrl' => url('pesquisa.php?todos=1&q=' . rawurlencode($termo)),
]);
