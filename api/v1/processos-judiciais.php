<?php
declare(strict_types=1);

/**
 * GET /api/v1/processos-judiciais.php
 *   ?limite=&apos_id=&atualizado_desde=   listagem por cursor
 *   ?id=N | ?numero_cnj=XXX               um processo
 *   ?somente_ids=1                        todos os ids atuais
 *   &incluir=integra                      inclui o texto integral dos documentos
 *
 * Cada processo vem com partes, assuntos, movimentacoes (cronologia do PJe),
 * documentos e os processos administrativos vinculados.
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\ExportacaoRepository;
use App\Validators\CnjValidator;

api_autenticar('processos_judiciais');
$geradoEm = api_agora();
$repo = new ExportacaoRepository();

if (($_GET['somente_ids'] ?? '') === '1') {
    api_responder_ids($repo->ids('processos_judiciais'), $geradoEm);
}

$params = api_parametros_lista();
$comIntegra = in_array('integra', api_incluir(['integra']), true);
$params['numero_cnj'] = null;
if (isset($_GET['numero_cnj'])) {
    $params['numero_cnj'] = CnjValidator::normalizar((string)$_GET['numero_cnj'])
        ?? api_erro('PARAMETRO_INVALIDO', 'numero_cnj inválido.', 422);
}

$r = $repo->processosJudiciais($params);
$ids = array_column($r['itens'], 'id');

$partes         = $repo->partes($ids);
$assuntos       = $repo->assuntos($ids);
$movimentacoes  = $repo->movimentacoes($ids);
$documentos     = $repo->documentos($ids, $comIntegra);
$administrativos = $repo->administrativosDosJudiciais($ids);

$booleanos = ['segredo_justica', 'justica_gratuita', 'tutela_liminar', 'prioridade', 'historico_completo'];

$itens = array_map(static function (array $pj) use ($partes, $assuntos, $movimentacoes, $documentos, $administrativos, $booleanos, $comIntegra): array {
    $id = (int)$pj['id'];
    $saida = api_formatar($pj, $booleanos);
    $saida['url_origem'] = api_url_sem_credencial($pj['url_origem']);

    $saida['partes']   = $partes[$id] ?? [];
    $saida['assuntos'] = array_map(static fn(array $a): array => api_formatar($a, ['principal']), $assuntos[$id] ?? []);
    $saida['movimentacoes'] = $movimentacoes[$id] ?? [];
    $saida['documentos'] = array_map(static function (array $d) use ($comIntegra): array {
        $d = api_formatar($d, ['possui_integra']);
        if (!$comIntegra) {
            unset($d['texto_integral']);
        }
        return $d;
    }, $documentos[$id] ?? []);
    $saida['processos_administrativos'] = $administrativos[$id] ?? [];
    return $saida;
}, $r['itens']);

if ($params['id'] !== null || $params['numero_cnj'] !== null) {
    api_responder_item($itens[0] ?? null, $geradoEm);
}
api_responder_lista($itens, $r['tem_mais'], $params, $geradoEm);
