<?php
declare(strict_types=1);

/**
 * Bootstrap da API de Integracao v1: exportacao somente leitura para
 * sistemas externos, autenticada por token (sem sessao, sem CSRF).
 *
 *   Authorization: Bearer sjr_xxxxxxxxxxxx.yyyy...   (ou X-API-Key: sjr_...)
 *
 * O token nunca e aceito na query string (ficaria em logs e historicos).
 * Toda chamada fica registrada em api_requisicoes.
 */

define('GJ_ROTA_PUBLICA', true);
define('GJ_SEM_SESSAO', true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\RequisicaoApi;
use App\Repositories\ApiClienteRepository;
use App\Services\ApiClienteService;

const API_VERSAO = '1.0';
const API_LIMITE_MAXIMO = 500;
const API_LIMITE_PADRAO = 100;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-API-Version: ' . API_VERSAO);

$GLOBALS['api_inicio'] = microtime(true);
$GLOBALS['api_cliente_id'] = null;

// Registra a chamada ao final, qualquer que seja a resposta.
register_shutdown_function(static function (): void {
    try {
        (new ApiClienteRepository())->registrarRequisicao([
            'cliente_id'  => $GLOBALS['api_cliente_id'],
            'metodo'      => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'recurso'     => basename((string)($_SERVER['SCRIPT_NAME'] ?? ''), '.php'),
            'parametros'  => ($_SERVER['QUERY_STRING'] ?? '') !== '' ? (string)$_SERVER['QUERY_STRING'] : null,
            'status_http' => (int)(http_response_code() ?: 200),
            'ip'          => api_ip_cliente(),
            'user_agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : null,
            'duracao_ms'  => (int)round((microtime(true) - $GLOBALS['api_inicio']) * 1000),
        ]);
    } catch (Throwable $e) {
        log_erro($e, 'api-v1-registro');
    }
});

set_exception_handler(static function (Throwable $e): void {
    $codigo = log_erro($e, 'api-v1');
    api_erro('INTERNAL_ERROR', 'Erro interno. Informe ao suporte o código ' . $codigo . '.', 500);
});

/** @return string[] IPs dos proxies reversos confiaveis (api.proxies_confiaveis). */
function api_proxies(): array
{
    return array_values(array_filter(
        array_map('trim', (array)config('api.proxies_confiaveis', [])),
        static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
    ));
}

/** IP do cliente, considerando o proxy reverso confiavel. */
function api_ip_cliente(): ?string
{
    return RequisicaoApi::ip($_SERVER, api_proxies());
}

/** Resposta de erro padronizada. */
function api_erro(string $codigo, string $mensagem, int $status, array $extra = []): never
{
    responder_json(['success' => false, 'error' => ['code' => $codigo, 'message' => $mensagem] + $extra], $status);
}

/** Token enviado no cabecalho Authorization (Bearer) ou X-API-Key. */
function api_token_da_requisicao(): ?string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if ($auth === null && function_exists('getallheaders')) {
        foreach (getallheaders() as $nome => $valor) {
            if (strcasecmp($nome, 'Authorization') === 0) {
                $auth = $valor;
            }
        }
    }
    if (is_string($auth) && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m) === 1) {
        return $m[1];
    }
    $chave = $_SERVER['HTTP_X_API_KEY'] ?? null;
    return is_string($chave) && trim($chave) !== '' ? trim($chave) : null;
}

/**
 * Autentica a chamada e confere o escopo. Devolve o cliente.
 * Sem escopo ($escopo = null): basta um token valido.
 */
function api_autenticar(?string $escopo): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        api_erro('METHOD_NOT_ALLOWED', 'A API de Integração é somente leitura: use GET.', 405);
    }

    // Padrao seguro: sem a secao 'api' no config.php (como no servidor da VPN), exige HTTPS.
    if ((bool)config('api.exigir_https', true) && !RequisicaoApi::https($_SERVER, api_proxies())) {
        $url = (string)config('api.url_publica', '');
        api_erro('HTTPS_REQUIRED', 'Use HTTPS para acessar a API' . ($url !== '' ? ": {$url}" : '') . '.', 403);
    }

    $repo = new ApiClienteRepository();
    $ip = api_ip_cliente();

    $maxFalhas = max(1, (int)config('api.max_falhas_por_ip', 20));
    if ($ip !== null && $repo->contarFalhasDoIp($ip, 600) >= $maxFalhas) {
        header('Retry-After: 600');
        api_erro('TOO_MANY_FAILURES', 'Muitas tentativas com token inválido. Aguarde 10 minutos.', 429);
    }

    $token = api_token_da_requisicao();
    if (isset($_GET['token']) || isset($_GET['api_key'])) {
        api_erro('TOKEN_IN_URL', 'Envie o token no cabeçalho Authorization, nunca na URL. Considere este token exposto e peça um novo.', 400);
    }

    $r = (new ApiClienteService())->autenticar($token, $ip);
    $cliente = $r['cliente'];

    if ($r['motivo'] === 'TOKEN_INVALIDO') {
        header('WWW-Authenticate: Bearer realm="SESJUR"');
        api_erro('NAO_AUTENTICADO', $token === null
            ? 'Informe o token no cabeçalho Authorization: Bearer <token>.'
            : 'Token inválido.', 401);
    }

    $GLOBALS['api_cliente_id'] = (int)$cliente['id'];

    match ($r['motivo']) {
        'INATIVO'          => api_erro('ACESSO_REVOGADO', 'O acesso deste token foi revogado.', 403),
        'EXPIRADO'         => api_erro('TOKEN_EXPIRADO', 'O token expirou. Solicite a renovação ao administrador do SESJUR.', 403),
        'IP_NAO_PERMITIDO' => api_erro('IP_NAO_PERMITIDO', 'O endereço IP de origem não está autorizado para este token.', 403),
        default            => null,
    };

    $limite = max(1, (int)config('api.limite_por_minuto', 120));
    if ($repo->contarRecentes((int)$cliente['id'], 60) >= $limite) {
        header('Retry-After: 60');
        api_erro('RATE_LIMIT', "Limite de {$limite} requisições por minuto atingido.", 429);
    }

    if ($escopo !== null && !in_array($escopo, $cliente['escopos'], true)) {
        api_erro('ESCOPO_NEGADO', "Este token não tem acesso ao recurso {$escopo}.", 403);
    }

    $repo->registrarUso((int)$cliente['id'], $ip);
    return $cliente;
}

/**
 * Parametros comuns das listagens:
 *   limite (1..500, padrao 100), apos_id (cursor), atualizado_desde (data ou data/hora ISO 8601), id.
 */
function api_parametros_lista(): array
{
    $limite = $_GET['limite'] ?? (string)API_LIMITE_PADRAO;
    if (!ctype_digit((string)$limite) || (int)$limite < 1 || (int)$limite > API_LIMITE_MAXIMO) {
        api_erro('PARAMETRO_INVALIDO', 'limite deve ser um inteiro de 1 a ' . API_LIMITE_MAXIMO . '.', 422);
    }

    $aposId = $_GET['apos_id'] ?? '0';
    if (!ctype_digit((string)$aposId)) {
        api_erro('PARAMETRO_INVALIDO', 'apos_id deve ser um inteiro não negativo.', 422);
    }

    $id = $_GET['id'] ?? null;
    if ($id !== null && (!ctype_digit((string)$id) || (int)$id < 1)) {
        api_erro('PARAMETRO_INVALIDO', 'id deve ser um inteiro positivo.', 422);
    }

    $desde = null;
    if (isset($_GET['atualizado_desde']) && $_GET['atualizado_desde'] !== '') {
        $desde = api_data_hora_local((string)$_GET['atualizado_desde']);
        if ($desde === null) {
            api_erro('PARAMETRO_INVALIDO', 'atualizado_desde deve estar em ISO 8601, ex.: 2026-10-01 ou 2026-10-01T08:00:00-04:00.', 422);
        }
    }

    return [
        'limite'           => (int)$limite,
        'apos_id'          => (int)$aposId,
        'id'               => $id !== null ? (int)$id : null,
        'atualizado_desde' => $desde,
    ];
}

/** Data/hora ISO 8601 convertida para o fuso da aplicacao (formato do banco), ou null se invalida. */
function api_data_hora_local(string $valor): ?string
{
    $valor = trim($valor);
    $formatos = ['!Y-m-d', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:iP', 'Y-m-d\TH:i'];
    foreach ($formatos as $formato) {
        $d = DateTimeImmutable::createFromFormat($formato, $valor);
        $avisos = DateTimeImmutable::getLastErrors();
        if ($d !== false && ($avisos === false || $avisos['warning_count'] + $avisos['error_count'] === 0)) {
            return $d->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        }
    }
    return null;
}

/** Inclusoes opcionais pedidas em ?incluir=a,b (somente as permitidas). */
function api_incluir(array $permitidas): array
{
    $pedidas = array_filter(array_map('trim', explode(',', (string)($_GET['incluir'] ?? ''))));
    $invalidas = array_diff($pedidas, $permitidas);
    if ($invalidas !== []) {
        api_erro('PARAMETRO_INVALIDO', 'incluir aceita: ' . implode(', ', $permitidas) . '.', 422);
    }
    return array_values($pedidas);
}

/**
 * Normaliza um registro para a saida: DATETIME vira ISO 8601 com fuso,
 * colunas booleanas viram true/false (NULL e preservado: "nao informado").
 */
function api_formatar(array $linha, array $booleanos = []): array
{
    static $fuso = null;
    $fuso ??= new DateTimeZone(date_default_timezone_get());

    foreach ($linha as $campo => $valor) {
        if (is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $valor) === 1) {
            $linha[$campo] = (new DateTimeImmutable($valor, $fuso))->format(DATE_ATOM);
        } elseif (in_array($campo, $booleanos, true) && $valor !== null) {
            $linha[$campo] = (bool)$valor;
        }
    }
    return $linha;
}

/**
 * URL de origem sem parametros de acesso da fonte (o "ca=" do PJe e uma
 * chave de acesso do usuario que fez a extracao; nunca sai do SESJUR).
 */
function api_url_sem_credencial(?string $url): ?string
{
    if ($url === null || !str_contains($url, '?')) {
        return $url;
    }
    [$base, $query] = explode('?', $url, 2);
    parse_str($query, $params);
    $params = array_diff_key($params, array_flip(['ca', 'token', 'jsessionid', 'cid']));
    return $params === [] ? $base : $base . '?' . http_build_query($params);
}

/** Resposta de listagem paginada por cursor. */
function api_responder_lista(array $itens, bool $temMais, array $params, string $geradoEm): never
{
    $ultimo = $itens === [] ? null : (int)end($itens)['id'];
    responder_json([
        'success'    => true,
        'gerado_em'  => $geradoEm,
        'dados'      => $itens,
        'paginacao'  => [
            'limite'          => $params['limite'],
            'apos_id'         => $params['apos_id'],
            'quantidade'      => count($itens),
            'tem_mais'        => $temMais,
            'proximo_apos_id' => $temMais ? $ultimo : null,
        ],
    ]);
}

/** Resposta de um unico registro (consulta por id ou identificador de negocio). */
function api_responder_item(?array $item, string $geradoEm): never
{
    if ($item === null) {
        api_erro('NAO_ENCONTRADO', 'Registro não encontrado.', 404);
    }
    responder_json(['success' => true, 'gerado_em' => $geradoEm, 'dados' => $item]);
}

/** Lista de todos os ids atuais (?somente_ids=1), para conciliar exclusoes. */
function api_responder_ids(array $ids, string $geradoEm): never
{
    responder_json(['success' => true, 'gerado_em' => $geradoEm, 'quantidade' => count($ids), 'ids' => $ids]);
}

/** Momento da consulta, a usar como proximo atualizado_desde. Tomado antes de ler os dados. */
function api_agora(): string
{
    return (new DateTimeImmutable('now'))->format(DATE_ATOM);
}
