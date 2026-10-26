<?php
declare(strict_types=1);

/**
 * Testes da API de Integracao (api/v1/): token, IPs permitidos,
 * autenticacao, validacao do cliente e consultas de exportacao.
 *
 * @var \Tests\Runner $t
 */

use App\Core\Database;
use App\Core\ValidationException;
use App\Repositories\ApiClienteRepository;
use App\Repositories\ExportacaoRepository;
use App\Services\ApiClienteService;
use App\Services\ProcessoAdministrativoService;
use App\Services\VinculoService;

$bancoPronto = $GLOBALS['gj_banco_teste_pronto'] ?? false;
$exigirBanco = static function ($t) use ($bancoPronto): void {
    if (!$bancoPronto) {
        $t->ignorar('banco de testes indisponível');
    }
};

$t->grupo('API de Integração — token e IPs');

$t->teste('gera token com prefixo público e hash SHA-256', function ($t) {
    [$token, $prefixo, $hash] = ApiClienteService::gerarToken();
    $t->afirmar(preg_match('/^sjr_[0-9a-f]{12}\.[A-Za-z0-9_-]{43}$/', $token) === 1, 'formato do token');
    $t->igual(substr($token, 4, 12), $prefixo, 'prefixo embutido no token');
    $t->igual($hash, hash('sha256', $token), 'hash do token inteiro');
    $t->afirmar(ApiClienteService::gerarToken()[0] !== $token, 'tokens distintos a cada geração');
});

$t->teste('confere IPs exatos e faixas IPv4 CIDR', function ($t) {
    $t->afirmar(ApiClienteService::ipPermitido('10.1.2.3', null), 'sem lista: qualquer IP');
    $t->afirmar(ApiClienteService::ipPermitido('10.1.2.3', '10.1.2.3'), 'IP exato');
    $t->afirmar(!ApiClienteService::ipPermitido('10.1.2.4', '10.1.2.3'), 'outro IP');
    $t->afirmar(ApiClienteService::ipPermitido('10.1.2.200', '192.168.0.1, 10.1.2.0/24'), 'dentro da faixa');
    $t->afirmar(!ApiClienteService::ipPermitido('10.1.3.1', '10.1.2.0/24'), 'fora da faixa');
    $t->afirmar(ApiClienteService::ipPermitido('::1', '::1'), 'IPv6 exato');
    $t->afirmar(!ApiClienteService::ipPermitido(null, '10.1.2.3'), 'IP desconhecido com lista');
});

$t->grupo('API de Integração — clientes e autenticação');

$t->teste('cria cliente, guarda só o hash e autentica o token', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $servico = new ApiClienteService();
    $r = $servico->criar(['nome' => 'Sistema Teste', 'escopos' => ['processos_judiciais']]);

    $gravado = (new ApiClienteRepository())->buscarPorId($r['id']);
    $t->igual($gravado['token_hash'], hash('sha256', $r['token']), 'hash gravado');
    $t->afirmar(!str_contains(json_encode($gravado), $r['token']), 'token em claro nunca gravado');

    $a = $servico->autenticar($r['token'], '127.0.0.1');
    $t->igual($a['motivo'], null, 'token válido');
    $t->igual($a['cliente']['escopos'], ['processos_judiciais'], 'escopos decodificados');
    $t->afirmar(!isset($a['cliente']['token_hash']), 'hash não sai da autenticação');

    $t->igual($servico->autenticar($r['token'] . 'x', null)['motivo'], 'TOKEN_INVALIDO', 'token adulterado');
    $t->igual($servico->autenticar(null, null)['motivo'], 'TOKEN_INVALIDO', 'sem token');
    $outro = substr($r['token'], 0, 17) . str_repeat('A', 43);
    $t->igual($servico->autenticar($outro, null)['motivo'], 'TOKEN_INVALIDO', 'prefixo certo, segredo errado');
});

$t->teste('revoga, renova e respeita IPs e validade', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $servico = new ApiClienteService();
    $r = $servico->criar(['nome' => 'Sistema Restrito', 'escopos' => ['vinculos'], 'ips_permitidos' => '10.0.0.0/8']);

    $t->igual($servico->autenticar($r['token'], '10.9.9.9')['motivo'], null, 'IP da faixa');
    $t->igual($servico->autenticar($r['token'], '192.168.1.1')['motivo'], 'IP_NAO_PERMITIDO', 'IP fora da faixa');

    $t->afirmar($servico->revogar($r['id']), 'revogado');
    $t->igual($servico->autenticar($r['token'], '10.9.9.9')['motivo'], 'INATIVO', 'token revogado');

    $novo = $servico->renovarToken($r['id']);
    $t->igual($servico->autenticar($r['token'], '10.9.9.9')['motivo'], 'TOKEN_INVALIDO', 'token antigo não vale');
    $t->igual($servico->autenticar($novo, '10.9.9.9')['motivo'], null, 'token novo vale e reativa');

    Database::conexao()->prepare('UPDATE api_clientes SET expira_em = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?')
        ->execute([$r['id']]);
    $t->igual($servico->autenticar($novo, '10.9.9.9')['motivo'], 'EXPIRADO', 'token expirado');
});

$t->teste('valida nome, escopos, IPs e validade', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $e = $t->lanca(ValidationException::class, fn() => (new ApiClienteService())->criar([
        'nome' => '', 'escopos' => ['usuarios'], 'ips_permitidos' => '10.0.0.0/40', 'expira_em' => '2020-01-01',
    ]), 'dados inválidos');
    $erros = $e->erros();
    foreach (['nome', 'escopos', 'ips_permitidos', 'expira_em'] as $campo) {
        $t->afirmar(isset($erros[$campo]), "erro em {$campo}");
    }
});

$t->grupo('API de Integração — exportação');

$t->teste('lista por cursor, filtra por atualização e traz filhos', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $db = Database::conexao();
    $servico = new ProcessoAdministrativoService();
    $ids = [];
    foreach (['SES-PRO-2026/90001', 'SES-PRO-2026/90002', 'SES-PRO-2026/90003'] as $protocolo) {
        $ids[] = $servico->criar(['protocolo' => $protocolo, 'data_abertura' => '01/10/2026', 'objeto' => 'Exportação']);
    }
    $db->exec("INSERT INTO processos_judiciais (numero_cnj) VALUES ('0900001-11.2026.8.11.0041')");
    $judicialId = (int)$db->lastInsertId();

    // Processos "antigos": nada mudou desde ontem, salvo o que for gravado abaixo.
    $db->exec("UPDATE processos_administrativos SET updated_at = DATE_SUB(NOW(), INTERVAL 2 DAY)");
    (new VinculoService())->vincular($ids[2], $judicialId);

    $repo = new ExportacaoRepository();
    $base = ['apos_id' => $ids[0] - 1, 'limite' => 2, 'atualizado_desde' => null, 'id' => null, 'protocolo' => null];

    $p1 = $repo->processosAdministrativos($base);
    $t->igual(count($p1['itens']), 2, 'primeira página com o limite');
    $t->afirmar($p1['tem_mais'], 'há próxima página');
    $p2 = $repo->processosAdministrativos(['apos_id' => (int)end($p1['itens'])['id']] + $base);
    $t->afirmar(!$p2['tem_mais'], 'última página');
    $t->afirmar(in_array($ids[2], array_column($p2['itens'], 'id'), true), 'cursor continua da página anterior');

    $desde = (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
    $alterados = $repo->processosAdministrativos(['atualizado_desde' => $desde, 'limite' => 100] + $base);
    $t->igual(array_values(array_intersect($ids, array_column($alterados['itens'], 'id'))), [$ids[2]], 'só o processo com vínculo novo');

    $judiciais = $repo->judiciaisDosAdministrativos([$ids[2]]);
    $t->igual($judiciais[$ids[2]][0]['numero_cnj'], '0900001-11.2026.8.11.0041', 'vínculo carregado em lote');

    $porProtocolo = $repo->processosAdministrativos(['protocolo' => 'SES-PRO-2026/90002'] + $base);
    $t->igual((int)$porProtocolo['itens'][0]['id'], $ids[1], 'busca pelo protocolo');

    $t->afirmar(in_array($ids[0], $repo->ids('processos_administrativos'), true), 'lista de ids atuais');
    $t->lanca(InvalidArgumentException::class, fn() => $repo->ids('usuario_credenciais'), 'tabela fora da allowlist');
});
