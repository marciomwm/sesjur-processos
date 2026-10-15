<?php
declare(strict_types=1);

/**
 * Autocomplete de Agentes Publicos (nome, nome social ou matricula) para
 * administradores e gestores (Intimacoes Pessoais). Devolve apenas identificacao funcional:
 * nome, matricula, cargo, unidade e se esta desligado.
 */

require_once __DIR__ . '/_bootstrap.php';

// Somente administradores e gestores.
App\Services\IntimacaoPessoalService::exigirAcesso();

use App\Repositories\AgentePublicoRepository;
use App\Services\IntimacaoPessoalService;

exigir_metodo('GET');

$termo = trim((string)($_GET['termo'] ?? ''));
if (mb_strlen($termo) < 2) {
    responder_json(['success' => true, 'itens' => []]);
}

try {
    $resultados = (new AgentePublicoRepository())->pesquisar(mb_substr($termo, 0, 100), 12);
} catch (Throwable $e) {
    log_erro($e, 'api.agentes');
    responder_json([
        'success' => false,
        'error'   => ['code' => 'DB_ERROR', 'message' => 'Falha ao consultar o banco de dados.'],
    ], 500);
}

responder_json(['success' => true, 'itens' => array_map([IntimacaoPessoalService::class, 'descreverAgente'], $resultados)]);
