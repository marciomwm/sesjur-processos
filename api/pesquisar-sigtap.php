<?php
declare(strict_types=1);

/** Sugestoes de procedimentos SIGTAP ja cadastrados, por codigo ou descricao. */

require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\SigtapRepository;
use App\Services\ProdutoSaudeService;

exigir_metodo('GET');

$termo = trim((string)($_GET['termo'] ?? ''));
if (mb_strlen($termo) < 2) {
    responder_json(['success' => true, 'itens' => []]);
}

try {
    $resultados = (new SigtapRepository())->pesquisar(mb_substr($termo, 0, 100), 10);
} catch (Throwable $e) {
    log_erro($e, 'api.sigtap');
    responder_json([
        'success' => false,
        'error'   => ['code' => 'DB_ERROR', 'message' => 'Falha ao consultar o banco de dados.'],
    ], 500);
}

$itens = array_map(static fn(array $s): array => [
    'id'      => (int)$s['id'],
    'codigo'  => ProdutoSaudeService::formatarSigtap($s['codigo']),
    'nome'    => $s['nome'],
    'grupo_nome'             => $s['grupo_nome'],
    'subgrupo_nome'          => $s['subgrupo_nome'],
    'forma_organizacao_nome' => $s['forma_organizacao_nome'],
], $resultados);

responder_json(['success' => true, 'itens' => $itens]);
