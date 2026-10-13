<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\ProcessoJudicialRepository;
use App\Validators\CnjValidator;

exigir_metodo('GET');

$termo = trim((string)($_GET['termo'] ?? ''));
if (mb_strlen($termo) < 3) {
    responder_json(['success' => true, 'itens' => []]);
}

// Se o usuario digitou um CNJ (com ou sem mascara), pesquisa pelo formato canonico.
$normalizado = CnjValidator::normalizar($termo);
$busca = $normalizado ?? $termo;

try {
    $resultados = (new ProcessoJudicialRepository())->pesquisar($busca, 10);
} catch (Throwable $e) {
    responder_json([
        'success' => false,
        'error'   => ['code' => 'DB_ERROR', 'message' => 'Falha ao consultar o banco de dados.'],
    ], 500);
}

$itens = array_map(static fn(array $p): array => [
    'id'      => (int)$p['id'],
    'rotulo'  => $p['numero_cnj'],
    'detalhe' => implode(' · ', array_filter([
        $p['tribunal'] ?? null,
        $p['orgao_julgador'] ?? null,
        $p['classe_judicial'] ?? null,
    ])),
], $resultados);

responder_json(['success' => true, 'itens' => $itens]);
