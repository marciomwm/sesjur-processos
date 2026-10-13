<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\ProcessoAdministrativoRepository;
use App\Validators\ProtocoloValidator;

exigir_metodo('GET');

$termo = trim((string)($_GET['termo'] ?? ''));
if (mb_strlen($termo) < 3) {
    responder_json(['success' => true, 'itens' => []]);
}

$normalizado = ProtocoloValidator::normalizar($termo);
$busca = ($normalizado !== null && ProtocoloValidator::valido($normalizado)) ? $normalizado : $termo;

try {
    $resultados = (new ProcessoAdministrativoRepository())->pesquisar($busca, 10);
} catch (Throwable $e) {
    responder_json([
        'success' => false,
        'error'   => ['code' => 'DB_ERROR', 'message' => 'Falha ao consultar o banco de dados.'],
    ], 500);
}

$itens = array_map(static fn(array $p): array => [
    'id'      => (int)$p['id'],
    'rotulo'  => $p['protocolo'],
    'detalhe' => trim(data_br($p['data_abertura']) . ' · ' . mb_substr((string)($p['objeto'] ?? ''), 0, 90)),
], $resultados);

responder_json(['success' => true, 'itens' => $itens]);
