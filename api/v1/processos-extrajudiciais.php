<?php
declare(strict_types=1);

/**
 * GET /api/v1/processos-extrajudiciais.php
 *   ?limite=&apos_id=&atualizado_desde=   listagem por cursor
 *   ?id=N                                 um processo
 *   ?somente_ids=1                        todos os ids atuais
 *
 * Cada processo vem com a instituicao e o orgao externos e os processos
 * administrativos vinculados.
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\ExportacaoRepository;

api_autenticar('processos_extrajudiciais');
$geradoEm = api_agora();
$repo = new ExportacaoRepository();

if (($_GET['somente_ids'] ?? '') === '1') {
    api_responder_ids($repo->ids('processos_extrajudiciais'), $geradoEm);
}

$params = api_parametros_lista();
$r = $repo->processosExtrajudiciais($params);
$administrativos = $repo->administrativosDosExtrajudiciais(array_column($r['itens'], 'id'));

$itens = array_map(static function (array $pe) use ($administrativos): array {
    $id = (int)$pe['id'];
    return api_formatar([
        'id'              => $id,
        'protocolo'       => $pe['protocolo'],
        'data_expediente' => $pe['data_expediente'],
        'instituicao'     => [
            'id'    => (int)$pe['instituicao_externa_id'],
            'nome'  => $pe['instituicao_nome'],
            'sigla' => $pe['instituicao_sigla'],
        ],
        'orgao'           => $pe['orgao_externo_id'] !== null
            ? ['id' => (int)$pe['orgao_externo_id'], 'nome' => $pe['orgao_nome']]
            : null,
        'assunto'         => $pe['assunto'],
        'descricao'       => $pe['descricao'],
        'situacao'        => $pe['situacao'],
        'observacoes'     => $pe['observacoes'],
        'created_at'      => $pe['created_at'],
        'updated_at'      => $pe['updated_at'],
    ]) + ['processos_administrativos' => $administrativos[$id] ?? []];
}, $r['itens']);

if ($params['id'] !== null) {
    api_responder_item($itens[0] ?? null, $geradoEm);
}
api_responder_lista($itens, $r['tem_mais'], $params, $geradoEm);
