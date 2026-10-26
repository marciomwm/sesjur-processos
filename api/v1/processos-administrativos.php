<?php
declare(strict_types=1);

/**
 * GET /api/v1/processos-administrativos.php
 *   ?limite=&apos_id=&atualizado_desde=   listagem por cursor
 *   ?id=N | ?protocolo=XXX                um processo
 *   ?somente_ids=1                        todos os ids atuais
 *
 * Cada processo vem com procedimento, eventos (SIGADOC), prioridades,
 * produtos de saude e os processos judiciais e extrajudiciais vinculados.
 */

require_once __DIR__ . '/_bootstrap.php';

use App\Repositories\ExportacaoRepository;
use App\Validators\ProtocoloValidator;

api_autenticar('processos_administrativos');
$geradoEm = api_agora();
$repo = new ExportacaoRepository();

if (($_GET['somente_ids'] ?? '') === '1') {
    api_responder_ids($repo->ids('processos_administrativos'), $geradoEm);
}

$params = api_parametros_lista();
$params['protocolo'] = null;
if (isset($_GET['protocolo'])) {
    $params['protocolo'] = ProtocoloValidator::normalizar((string)$_GET['protocolo'])
        ?? api_erro('PARAMETRO_INVALIDO', 'protocolo inválido.', 422);
}

$r = $repo->processosAdministrativos($params);
$ids = array_column($r['itens'], 'id');

$eventos     = $repo->eventosAdministrativos($ids);
$prioridades = $repo->prioridadesAdministrativas($ids);
$produtos    = $repo->produtosSaude($ids);
$judiciais   = $repo->judiciaisDosAdministrativos($ids);
$extrajud    = $repo->extrajudiciaisDosAdministrativos($ids);

$itens = array_map(static function (array $pa) use ($eventos, $prioridades, $produtos, $judiciais, $extrajud): array {
    $id = (int)$pa['id'];
    $saida = api_formatar([
        'id'                       => $id,
        'protocolo'                => $pa['protocolo'],
        'data_abertura'            => $pa['data_abertura'],
        'objeto'                   => $pa['objeto'],
        'procedimento'             => $pa['procedimento_id'] !== null
            ? ['id' => (int)$pa['procedimento_id'], 'nome' => $pa['procedimento_nome']]
            : null,
        'classificacao_anterior'   => $pa['procedimento'],
        'situacao'                 => $pa['situacao'],
        'situacao_descricao'       => $pa['situacao_descricao'],
        'responsavel'              => [
            'nome'         => $pa['responsavel_nome'],
            'matricula'    => $pa['responsavel_matricula'],
            'lotacao'      => $pa['responsavel_lotacao'],
            'lotacao_nome' => $pa['responsavel_lotacao_nome'],
        ],
        'prazo'                    => $pa['prazo'],
        'prazo_hora'               => $pa['prazo_hora'],
        'prazo_vigente'            => $pa['prazo_vigente'],
        'prazo_vigente_hora'       => $pa['prazo_vigente_hora'],
        'ativo'                    => $pa['ativo'],
        'origem'                   => $pa['origem'],
        'url_origem'               => $pa['url_origem'],
        'extrator_versao'          => $pa['extrator_versao'],
        'data_extracao'            => $pa['data_extracao'],
        'created_at'               => $pa['created_at'],
        'updated_at'               => $pa['updated_at'],
    ], ['ativo']);

    $saida['eventos']                  = array_map('api_formatar', $eventos[$id] ?? []);
    $saida['prioridades']              = array_map('api_formatar', $prioridades[$id] ?? []);
    $saida['produtos_saude']           = array_map('api_formatar', $produtos[$id] ?? []);
    $saida['processos_judiciais']      = $judiciais[$id] ?? [];
    $saida['processos_extrajudiciais'] = $extrajud[$id] ?? [];
    return $saida;
}, $r['itens']);

if ($params['id'] !== null || $params['protocolo'] !== null) {
    api_responder_item($itens[0] ?? null, $geradoEm);
}
api_responder_lista($itens, $r['tem_mais'], $params, $geradoEm);
