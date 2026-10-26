<?php
declare(strict_types=1);

/**
 * GET /api/v1/vinculos.php?tipo=judicial|extrajudicial
 *   &limite=&apos_id=&atualizado_desde=   listagem por cursor (atualizado_desde = criados desde)
 *   &somente_ids=1                        todos os ids atuais do tipo
 *
 * Vinculo N:N entre um Processo Administrativo e um Processo Judicial ou
 * Extrajudicial. Vinculos nao sao editados: removidos, somem da lista de ids.
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\ExportacaoRepository;

api_autenticar('vinculos');
$geradoEm = api_agora();
$repo = new ExportacaoRepository();

$tipo = strtoupper((string)($_GET['tipo'] ?? 'judicial'));
if (!in_array($tipo, ['JUDICIAL', 'EXTRAJUDICIAL'], true)) {
    api_erro('PARAMETRO_INVALIDO', 'tipo deve ser judicial ou extrajudicial.', 422);
}

if (($_GET['somente_ids'] ?? '') === '1') {
    api_responder_ids($repo->ids($tipo === 'JUDICIAL' ? 'processo_administrativo_judicial' : 'processo_administrativo_extrajudicial'), $geradoEm);
}

$params = api_parametros_lista();
$r = $repo->vinculos($tipo, $params);

$itens = array_map(static fn(array $v): array => api_formatar(['tipo' => $tipo] + $v), $r['itens']);

if ($params['id'] !== null) {
    api_responder_item($itens[0] ?? null, $geradoEm);
}
api_responder_lista($itens, $r['tem_mais'], $params, $geradoEm);
