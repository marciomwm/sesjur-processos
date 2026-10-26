<?php
declare(strict_types=1);

/**
 * Administracao dos clientes da API de Integracao (api/v1/).
 *
 * Uso (na raiz do projeto, com o PHP de linha de comando do servidor):
 *   php database/api-clientes.php listar
 *   php database/api-clientes.php criar --nome="Sistema X" --responsavel=email@dominio
 *        [--escopos=processos_administrativos,processos_judiciais,processos_extrajudiciais,vinculos]
 *        [--ips=10.0.0.5,10.0.1.0/24] [--expira=AAAA-MM-DD] [--observacao="..."]
 *   php database/api-clientes.php renovar --id=N    (novo token; o anterior deixa de valer)
 *   php database/api-clientes.php revogar --id=N
 *
 * Opcoes gerais:
 *   --banco=NOME   outro banco que nao o de app/config/config.php (homologacao)
 *
 * O token e exibido UMA unica vez (criar/renovar): entregue-o ao responsavel
 * por meio seguro. O SESJUR guarda apenas o hash e nao consegue recupera-lo.
 * Codigos de saida: 0 = ok; 1 = erro.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\ValidationException;
use App\Repositories\ApiClienteRepository;
use App\Repositories\UsuarioRepository;
use App\Services\ApiClienteService;

$comando = $argv[1] ?? 'ajuda';
$opcoes = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $opcoes[$m[1]] = $m[2] ?? '1';
    }
}
if (isset($opcoes['banco'])) {
    $GLOBALS['gj_config']['db']['database'] = $opcoes['banco'];
}

$servico = new ApiClienteService();
$repo = new ApiClienteRepository();

$mostrarToken = static function (string $token): void {
    echo "\n  TOKEN (exibido somente agora — copie e entregue por meio seguro):\n\n";
    echo "  {$token}\n\n";
    echo "  Uso: Authorization: Bearer <token>\n";
    $url = (string)config('api.url_publica', '');
    echo $url !== '' ? "  Endereço: {$url}\n\n" : "\n";
    echo "  O token vale somente no banco em que foi criado: crie-o no servidor que o consumidor vai acessar.\n\n";
};

try {
    switch ($comando) {
        case 'listar':
            $clientes = $repo->listar();
            if ($clientes === []) {
                echo "Nenhum cliente da API cadastrado.\n";
                break;
            }
            foreach ($clientes as $c) {
                $situacao = (int)$c['ativo'] === 1 ? 'ATIVO' : 'REVOGADO';
                if ((int)$c['ativo'] === 1 && $c['expira_em'] !== null && strtotime($c['expira_em']) <= time()) {
                    $situacao = 'EXPIRADO';
                }
                printf(
                    "#%d  %s  [%s]\n    responsável: %s\n    token: sjr_%s.…  escopos: %s\n    IPs: %s  validade: %s  último uso: %s%s\n\n",
                    $c['id'], $c['nome'], $situacao, $c['responsavel_nome'] ?? '—', $c['token_prefixo'],
                    implode(', ', json_decode($c['escopos'], true) ?: []),
                    $c['ips_permitidos'] ?? 'qualquer', $c['expira_em'] ?? 'sem validade',
                    $c['ultimo_uso_em'] ?? 'nunca', $c['ultimo_ip'] ? " ({$c['ultimo_ip']})" : ''
                );
            }
            break;

        case 'criar':
            $responsavelId = null;
            if (isset($opcoes['responsavel'])) {
                $usuario = (new UsuarioRepository())->buscarPorEmail(mb_strtolower(trim($opcoes['responsavel'])));
                if ($usuario === null) {
                    throw new ValidationException(['responsavel' => 'Nenhum usuário com o e-mail ' . $opcoes['responsavel'] . '.']);
                }
                $responsavelId = (int)$usuario['id'];
            }
            $r = $servico->criar([
                'nome'                   => $opcoes['nome'] ?? '',
                'responsavel_usuario_id' => $responsavelId,
                'escopos'                => isset($opcoes['escopos'])
                    ? array_map('trim', explode(',', $opcoes['escopos']))
                    : array_keys(ApiClienteService::ESCOPOS),
                'ips_permitidos'         => $opcoes['ips'] ?? null,
                'expira_em'              => $opcoes['expira'] ?? null,
                'observacao'             => $opcoes['observacao'] ?? null,
            ]);
            echo "Cliente da API #{$r['id']} criado.\n";
            $mostrarToken($r['token']);
            break;

        case 'renovar':
            $token = $servico->renovarToken((int)($opcoes['id'] ?? 0));
            echo "Token do cliente #{$opcoes['id']} renovado; o anterior não vale mais.\n";
            $mostrarToken($token);
            break;

        case 'revogar':
            $ok = $servico->revogar((int)($opcoes['id'] ?? 0));
            echo $ok ? "Acesso do cliente #{$opcoes['id']} revogado.\n" : "Cliente não encontrado ou já revogado.\n";
            break;

        default:
            $ajuda = file_get_contents(__FILE__);
            preg_match('#/\*\*(.*?)\*/#s', $ajuda, $m);
            echo preg_replace('/^ ?\* ?/m', '', trim($m[1] ?? '')) . "\n\nEscopos: " . implode(', ', array_keys(ApiClienteService::ESCOPOS)) . "\n";
    }
} catch (ValidationException $e) {
    fwrite(STDERR, "Não foi possível concluir:\n");
    foreach ($e->erros() as $campo => $msg) {
        fwrite(STDERR, "  - {$campo}: {$msg}\n");
    }
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
    exit(1);
}
exit(0);
