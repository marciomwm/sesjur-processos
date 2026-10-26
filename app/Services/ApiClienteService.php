<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\ValidationException;
use App\Repositories\ApiClienteRepository;
use App\Repositories\UsuarioRepository;

/**
 * Clientes da API de Integracao: criacao, troca e revogacao do token e
 * autenticacao das chamadas.
 *
 * Formato do token: "sjr_" + prefixo (12 hex, publico, localiza o cliente)
 * + "." + segredo (43 caracteres base64url, 256 bits). Somente o SHA-256 do
 * token inteiro e gravado; o token em claro existe apenas no retorno de
 * criar() e renovarToken().
 */
final class ApiClienteService
{
    /** Recursos que um cliente pode receber, com o rotulo exibido. */
    public const ESCOPOS = [
        'processos_administrativos' => 'Processos Administrativos (eventos, prioridades, produtos de saúde)',
        'processos_judiciais'       => 'Processos Judiciais (partes, assuntos, movimentações, documentos)',
        'processos_extrajudiciais'  => 'Processos Extrajudiciais',
        'vinculos'                  => 'Vínculos entre processos',
    ];

    private const PADRAO_TOKEN = '/^sjr_([0-9a-f]{12})\.([A-Za-z0-9_-]{43})$/';

    public function __construct(
        private readonly ApiClienteRepository $repo = new ApiClienteRepository(),
        private readonly AuditoriaService $auditoria = new AuditoriaService(),
    ) {
    }

    /**
     * Cria o cliente e devolve o token em claro (unica vez em que ele existe).
     * @return array{id:int, token:string}
     */
    public function criar(array $dados, ?int $autorId = null): array
    {
        $limpo = $this->validar($dados);
        [$token, $prefixo, $hash] = self::gerarToken();

        $id = Database::transacao(function () use ($limpo, $prefixo, $hash, $autorId): int {
            $id = $this->repo->inserir($limpo + ['token_prefixo' => $prefixo, 'token_hash' => $hash]);
            $this->auditoria->registrar(AuditoriaService::API_CLIENTE_CRIADO, 'api_clientes', $id, null, [
                'nome'                   => $limpo['nome'],
                'responsavel_usuario_id' => $limpo['responsavel_usuario_id'],
                'escopos'                => $limpo['escopos'],
                'ips_permitidos'         => $limpo['ips_permitidos'],
                'expira_em'              => $limpo['expira_em'],
                'token_prefixo'          => $prefixo,
            ], $autorId);
            return $id;
        });

        return ['id' => $id, 'token' => $token];
    }

    /** Gera um novo token para o cliente; o anterior deixa de valer imediatamente. */
    public function renovarToken(int $id, ?int $autorId = null): string
    {
        if ($this->repo->buscarPorId($id) === null) {
            throw new ValidationException(['id' => 'Cliente da API não encontrado.']);
        }
        [$token, $prefixo, $hash] = self::gerarToken();

        Database::transacao(function () use ($id, $prefixo, $hash, $autorId): void {
            $this->repo->trocarToken($id, $prefixo, $hash);
            $this->auditoria->registrar(AuditoriaService::API_TOKEN_RENOVADO, 'api_clientes', $id, null,
                ['token_prefixo' => $prefixo], $autorId);
        });

        return $token;
    }

    public function revogar(int $id, ?int $autorId = null): bool
    {
        return Database::transacao(function () use ($id, $autorId): bool {
            $revogado = $this->repo->revogar($id);
            if ($revogado) {
                $this->auditoria->registrar(AuditoriaService::API_CLIENTE_REVOGADO, 'api_clientes', $id, null, null, $autorId);
            }
            return $revogado;
        });
    }

    /**
     * Cliente dono do token, se ele for valido para o IP informado.
     * @return array{cliente:?array, motivo:?string} motivo: TOKEN_INVALIDO | INATIVO | EXPIRADO | IP_NAO_PERMITIDO
     */
    public function autenticar(?string $token, ?string $ip): array
    {
        if ($token === null || preg_match(self::PADRAO_TOKEN, $token, $m) !== 1) {
            return ['cliente' => null, 'motivo' => 'TOKEN_INVALIDO'];
        }

        $cliente = $this->repo->buscarPorPrefixo($m[1]);
        // Compara mesmo sem cliente, para nao revelar pelo tempo de resposta que o prefixo existe.
        $hashGravado = $cliente['token_hash'] ?? str_repeat('0', 64);
        if (!hash_equals($hashGravado, hash('sha256', $token)) || $cliente === null) {
            return ['cliente' => null, 'motivo' => 'TOKEN_INVALIDO'];
        }

        unset($cliente['token_hash']);
        $cliente['escopos'] = json_decode((string)$cliente['escopos'], true) ?: [];

        if ((int)$cliente['ativo'] !== 1) {
            return ['cliente' => $cliente, 'motivo' => 'INATIVO'];
        }
        if ($cliente['expira_em'] !== null && strtotime((string)$cliente['expira_em']) <= time()) {
            return ['cliente' => $cliente, 'motivo' => 'EXPIRADO'];
        }
        if (!self::ipPermitido($ip, $cliente['ips_permitidos'])) {
            return ['cliente' => $cliente, 'motivo' => 'IP_NAO_PERMITIDO'];
        }
        return ['cliente' => $cliente, 'motivo' => null];
    }

    /** @return array{0:string,1:string,2:string} token, prefixo e hash */
    public static function gerarToken(): array
    {
        $prefixo = bin2hex(random_bytes(6));
        $segredo = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = 'sjr_' . $prefixo . '.' . $segredo;
        return [$token, $prefixo, hash('sha256', $token)];
    }

    /**
     * O IP esta na lista (IPs exatos ou faixas IPv4 CIDR, separados por
     * virgula). Lista vazia libera qualquer IP.
     */
    public static function ipPermitido(?string $ip, ?string $lista): bool
    {
        $itens = array_filter(array_map('trim', explode(',', (string)$lista)), static fn(string $i): bool => $i !== '');
        if ($itens === []) {
            return true;
        }
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        foreach ($itens as $item) {
            if (!str_contains($item, '/')) {
                if (inet_pton($item) !== false && inet_pton($item) === inet_pton($ip)) {
                    return true;
                }
                continue;
            }
            [$rede, $bits] = explode('/', $item, 2);
            if (filter_var($rede, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || !ctype_digit($bits) || (int)$bits > 32) {
                continue;
            }
            $mascara = (int)$bits === 0 ? 0 : (~0 << (32 - (int)$bits)) & 0xFFFFFFFF;
            if ((ip2long($ip) & $mascara) === (ip2long($rede) & $mascara)) {
                return true;
            }
        }
        return false;
    }

    private function validar(array $dados): array
    {
        $erros = [];

        $nome = trim((string)($dados['nome'] ?? ''));
        if ($nome === '' || mb_strlen($nome) > 150) {
            $erros['nome'] = 'Informe o nome do sistema consumidor (até 150 caracteres).';
        }

        $responsavel = $dados['responsavel_usuario_id'] ?? null;
        if ($responsavel !== null) {
            $responsavel = (int)$responsavel;
            if ((new UsuarioRepository())->buscarPorId($responsavel) === null) {
                $erros['responsavel_usuario_id'] = 'Usuário responsável não encontrado.';
            }
        }

        $escopos = array_values(array_unique((array)($dados['escopos'] ?? [])));
        if ($escopos === [] || array_diff($escopos, array_keys(self::ESCOPOS)) !== []) {
            $erros['escopos'] = 'Escolha ao menos um escopo válido: ' . implode(', ', array_keys(self::ESCOPOS)) . '.';
        }

        $ips = trim((string)($dados['ips_permitidos'] ?? ''));
        foreach (array_filter(array_map('trim', explode(',', $ips))) as $item) {
            [$endereco, $bits] = array_pad(explode('/', $item, 2), 2, null);
            $valido = $bits === null
                ? filter_var($endereco, FILTER_VALIDATE_IP) !== false
                : filter_var($endereco, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && ctype_digit($bits) && (int)$bits <= 32;
            if (!$valido) {
                $erros['ips_permitidos'] = "IP ou faixa inválida: {$item}.";
                break;
            }
        }

        $expira = trim((string)($dados['expira_em'] ?? ''));
        if ($expira !== '') {
            $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $expira)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $expira);
            if ($data === false || $data->getTimestamp() <= time()) {
                $erros['expira_em'] = 'A validade deve ser uma data futura (AAAA-MM-DD).';
            } else {
                $expira = $data->format('H:i:s') === '00:00:00' ? $data->format('Y-m-d 23:59:59') : $data->format('Y-m-d H:i:s');
            }
        }

        if ($erros !== []) {
            throw new ValidationException($erros);
        }

        return [
            'nome'                   => $nome,
            'responsavel_usuario_id' => $responsavel,
            'escopos'                => $escopos,
            'ips_permitidos'         => $ips !== '' ? $ips : null,
            'expira_em'              => $expira !== '' ? $expira : null,
            'observacao'             => trim((string)($dados['observacao'] ?? '')) ?: null,
        ];
    }
}
