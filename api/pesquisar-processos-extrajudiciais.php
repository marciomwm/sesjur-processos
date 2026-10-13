<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\ProcessoExtrajudicialRepository;

exigir_metodo('GET');

$termo = trim((string)($_GET['termo'] ?? ''));
if (mb_strlen($termo) < 3) {
    responder_json(['success' => true, 'itens' => []]);
}

try {
    $resultados = (new ProcessoExtrajudicialRepository())->pesquisar(mb_substr($termo, 0, 100), 10);
} catch (Throwable $e) {
    log_erro($e, 'api.extrajudiciais');
    responder_json([
        'success' => false,
        'error'   => ['code' => 'DB_ERROR', 'message' => 'Falha ao consultar o banco de dados.'],
    ], 500);
}

$itens = array_map(static fn(array $p): array => [
    'id'      => (int)$p['id'],
    'rotulo'  => $p['protocolo'],
    'detalhe' => implode(' · ', array_filter([
        $p['instituicao_sigla'] ?: $p['instituicao_nome'],
        $p['orgao_nome'] ?? null,
        data_br($p['data_expediente']),
        mb_strimwidth((string)$p['assunto'], 0, 80, '…'),
    ])),
], $resultados);

responder_json(['success' => true, 'itens' => $itens]);
