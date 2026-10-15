<?php
declare(strict_types=1);

/**
 * Funcoes utilitarias de uso global.
 */

/** Retorna um valor da configuracao usando notacao por pontos. */
function config(string $chave, mixed $padrao = null): mixed
{
    $atual = $GLOBALS['gj_config'] ?? [];
    foreach (explode('.', $chave) as $parte) {
        if (!is_array($atual) || !array_key_exists($parte, $atual)) {
            return $padrao;
        }
        $atual = $atual[$parte];
    }
    return $atual;
}

/** Escapa saida para HTML (protecao contra XSS). */
function e(mixed $valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Monta uma URL a partir da base configurada. */
function url(string $caminho = ''): string
{
    $base = rtrim((string)config('app.base_url', ''), '/');
    return $base . '/' . ltrim($caminho, '/');
}

/** Formata uma data ISO (Y-m-d) para o padrao brasileiro. */
function data_br(?string $iso): string
{
    if ($iso === null || $iso === '' || str_starts_with($iso, '0000')) {
        return '—';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', substr($iso, 0, 10));
    return $dt ? $dt->format('d/m/Y') : '—';
}

/** Formata data/hora para o padrao brasileiro. */
function datahora_br(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($iso))->format('d/m/Y H:i');
    } catch (Throwable) {
        return '—';
    }
}

/** Formata valor monetario. */
function moeda_br(mixed $valor): string
{
    if ($valor === null || $valor === '') {
        return '—';
    }
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

/** Telefone gravado so com digitos -> "(65) 99999-9999"; outro formato volta como veio. */
function telefone_br(?string $digitos): string
{
    $d = preg_replace('/\D+/', '', (string)$digitos) ?? '';
    return match (strlen($d)) {
        10      => '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6),
        11      => '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7),
        default => (string)$digitos,
    };
}

/** Valor exibivel, com travessao quando vazio. */
function ou_traco(mixed $valor): string
{
    $valor = is_string($valor) ? trim($valor) : $valor;
    return ($valor === null || $valor === '') ? '—' : e($valor);
}

/** Redireciona e encerra a execucao. */
function redirecionar(string $caminho): never
{
    header('Location: ' . (str_starts_with($caminho, 'http') ? $caminho : url($caminho)));
    exit;
}

/** Registra uma mensagem flash para a proxima requisicao. */
function flash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

/** Consome as mensagens flash pendentes. */
function flash_consumir(): array
{
    $itens = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $itens;
}

/** Resposta JSON padronizada e encerramento. */
function responder_json(array $dados, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** A requisicao espera JSON (formulario enviado por um modal via fetch). */
function requisicao_json(): bool
{
    return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

/**
 * Resposta de um formulario que pode vir de um modal (JSON, com os erros
 * por campo) ou de um envio comum sem JavaScript (mensagem flash e volta
 * para $destino). Em caso de sucesso a mensagem aparece como flash na
 * pagina de destino nos dois casos.
 * @param array<string,string> $erros
 */
function responder_formulario(bool $ok, string $mensagem, string $destino, array $erros = [], int $status = 422): never
{
    if (requisicao_json()) {
        if ($ok) {
            flash('sucesso', $mensagem);
            responder_json(['success' => true, 'mensagem' => $mensagem, 'redirecionar' => url($destino)]);
        }
        responder_json([
            'success' => false,
            'error'   => ['code' => 'VALIDATION_ERROR', 'message' => $mensagem, 'details' => $erros],
        ], $status);
    }
    flash($ok ? 'sucesso' : 'erro', $ok ? $mensagem : trim($mensagem . ' ' . implode(' ', $erros)));
    redirecionar($destino);
}

/** Le um parametro de entrada como string limpa. */
function entrada(string $chave, array $fonte = null): string
{
    $fonte ??= $_POST;
    $valor = $fonte[$chave] ?? '';
    if (is_array($valor)) {
        return '';
    }
    return trim((string)$valor);
}

/**
 * Campos de situacao, responsavel e movimentacoes (SIGADOC) do formulario
 * de processo administrativo.
 */
function entrada_situacao_pa(): array
{
    $campos = ['situacao', 'situacao_descricao', 'responsavel_nome', 'responsavel_matricula',
               'responsavel_lotacao', 'responsavel_lotacao_nome', 'eventos_json'];
    $dados = [];
    foreach ($campos as $campo) {
        $dados[$campo] = entrada($campo);
    }
    return $dados;
}

/**
 * Campos do formulario de Unidade Administrativa, com o lotacionograma
 * (servidores[i][nome|matricula|cargo]) na ordem enviada.
 */
function entrada_unidade_administrativa(): array
{
    $dados = [];
    foreach (['instituicao_id', 'nivel_id', 'nivel_novo', 'unidade_superior_id', 'nome', 'sigla',
              'gestor_nome', 'gestor_matricula', 'competencias'] as $campo) {
        $dados[$campo] = entrada($campo);
    }
    $servidores = [];
    foreach (is_array($_POST['servidores'] ?? null) ? $_POST['servidores'] : [] as $linha) {
        if (is_array($linha)) {
            $servidores[] = [
                'nome'      => entrada('nome', $linha),
                'matricula' => entrada('matricula', $linha),
                'cargo'     => entrada('cargo', $linha),
            ];
        }
    }
    // Linhas vazias nao voltam ao formulario (ele ja acrescenta linhas em branco).
    $dados['servidores'] = array_values(array_filter(
        $servidores,
        static fn(array $s): bool => $s['nome'] !== '' || $s['matricula'] !== '' || $s['cargo'] !== ''
    ));
    return $dados;
}

/**
 * Campos do formulario de Processo Extrajudicial. O seletor de orgao usa
 * "__novo" para "outro orgao": so entao o nome digitado (orgao_novo) vale.
 * Os vinculos so entram quando o formulario os enviou (marcador oculto).
 */
function entrada_processo_extrajudicial(): array
{
    $dados = [];
    foreach (['protocolo', 'data_expediente', 'instituicao_externa_id', 'orgao_externo_id', 'orgao_novo', 'assunto', 'situacao'] as $campo) {
        $dados[$campo] = entrada($campo);
    }
    // Sem entrada(): o trim por linha fica com o servico.
    foreach (['descricao', 'observacoes'] as $campo) {
        $dados[$campo] = is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '';
    }
    if ($dados['orgao_externo_id'] === '__novo') {
        $dados['orgao_externo_id'] = '';
    } else {
        $dados['orgao_novo'] = '';
    }
    if (entrada('administrativos_enviados') === '1') {
        $dados['administrativos_ids'] = array_map('intval', is_array($_POST['administrativos_ids'] ?? null) ? $_POST['administrativos_ids'] : []);
    }
    return $dados;
}

/** Campos do formulario de Agente Publico. */
function entrada_agente_publico(): array
{
    $dados = [];
    foreach (['nome', 'nome_social', 'cpf', 'data_nascimento', 'email_institucional', 'email_alternativo', 'telefone',
              'matricula', 'tipo_vinculo', 'cargo_id', 'funcao', 'lotacao_id', 'data_ingresso', 'data_desligamento',
              'situacao'] as $campo) {
        $dados[$campo] = entrada($campo);
    }
    $dados['observacoes'] = is_string($_POST['observacoes'] ?? null) ? $_POST['observacoes'] : '';
    return $dados;
}

/**
 * Itens do widget de vinculos a partir de processos administrativos
 * (id, protocolo, objeto).
 * @return list<array{id:int,rotulo:string,detalhe:string}>
 */
function itens_vinculo_administrativo(array $processos): array
{
    return array_values(array_map(static fn(array $p): array => [
        'id'      => (int)$p['id'],
        'rotulo'  => $p['protocolo'],
        'detalhe' => mb_strimwidth((string)($p['objeto'] ?? ''), 0, 120, '…'),
    ], $processos));
}

/**
 * Itens do widget de vinculos a partir de processos extrajudiciais
 * (id, protocolo, instituicao_sigla/nome, assunto).
 * @return list<array{id:int,rotulo:string,detalhe:string}>
 */
function itens_vinculo_extrajudicial(array $processos): array
{
    return array_values(array_map(static fn(array $p): array => [
        'id'      => (int)$p['id'],
        'rotulo'  => $p['protocolo'],
        'detalhe' => implode(' · ', array_filter([
            $p['instituicao_sigla'] ?: ($p['instituicao_nome'] ?? null),
            mb_strimwidth((string)($p['assunto'] ?? ''), 0, 100, '…'),
        ])),
    ], $processos));
}

/**
 * Documentos gravados do processo judicial agrupados pelo evento da
 * cronologia que os juntou (movimentacao_id => lista), para exibir os N
 * documentos de um mesmo evento.
 */
function agrupar_por_movimentacao(array $documentos): array
{
    $grupos = [];
    foreach ($documentos as $doc) {
        if (!empty($doc['movimentacao_id'])) {
            $grupos[(int)$doc['movimentacao_id']][] = $doc;
        }
    }
    return $grupos;
}

/** Le um parametro de entrada como inteiro positivo ou null. */
function entrada_int(string $chave, array $fonte = null): ?int
{
    $fonte ??= $_GET;
    $valor = $fonte[$chave] ?? null;
    if ($valor === null || $valor === '' || !is_numeric($valor)) {
        return null;
    }
    $int = (int)$valor;
    return $int > 0 ? $int : null;
}

/**
 * Id do usuario autenticado (gravado na sessao pelo login), ou null.
 * A auditoria usa este valor como autor de cada acao.
 */
function usuario_logado_id(): ?int
{
    $id = $_SESSION['auth']['usuario_id'] ?? null;
    return is_int($id) && $id > 0 ? $id : null;
}

/** IP do cliente (REMOTE_ADDR; cabecalhos de proxy nao sao confiaveis). */
function ip_cliente(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
}

/**
 * Registra o erro tecnico no log do servidor e devolve um codigo curto
 * para o usuario informar ao suporte, sem expor detalhes internos.
 */
function log_erro(Throwable $e, string $contexto): string
{
    $codigo = strtoupper(bin2hex(random_bytes(4)));
    error_log(sprintf(
        '[SESJUR][%s][%s] %s: %s em %s:%d',
        $codigo,
        $contexto,
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    return $codigo;
}

/**
 * Campos do formulario de usuario (cadastro e edicao). Perfil e status so
 * entram quando enviados: o formulario os omite para quem nao e
 * administrador, e o servico entao mantem os valores gravados.
 */
function entrada_usuario(bool $incluirStatus = false): array
{
    $dados = [];
    foreach (['nome', 'email', 'matricula', 'cargo_id', 'lotacao_id', 'data_nascimento'] as $campo) {
        $dados[$campo] = entrada($campo);
    }
    $opcionais = $incluirStatus ? ['perfil', 'ativo'] : ['perfil'];
    foreach ($opcionais as $campo) {
        if (array_key_exists($campo, $_POST)) {
            $dados[$campo] = entrada($campo);
        }
    }
    return $dados;
}

/**
 * Filtro "designado a" das listagens de processos: '' (todos), 'eu',
 * 'nenhum' ou o id de um usuario. Devolve [valor para a URL, valor para o repositorio].
 * @return array{0:string,1:int|string|null}
 */
function filtro_designado(): array
{
    $valor = entrada('designado', $_GET);
    return match (true) {
        $valor === 'eu'     => ['eu', usuario_logado_id()],
        $valor === 'nenhum' => ['nenhum', 'nenhum'],
        ctype_digit($valor) && (int)$valor > 0 => [$valor, (int)$valor],
        default             => ['', null],
    };
}

/** Iniciais do primeiro e do ultimo nome ("Marcio Jean da Silva" -> "MS"). */
function iniciais(string $nome): string
{
    $todas = array_values(array_filter(preg_split('/\s+/u', trim($nome)) ?: [], 'strlen'));
    if ($todas === []) {
        return '?';
    }
    // Ignora preposicoes (da, de, dos...) quando houver outras palavras.
    $partes = array_values(array_filter($todas, static fn($p) => mb_strlen($p) > 2)) ?: $todas;
    $primeira = mb_substr($partes[0], 0, 1);
    $ultima   = count($partes) > 1 ? mb_substr($partes[count($partes) - 1], 0, 1) : '';
    return mb_strtoupper($primeira . $ultima);
}

/**
 * URL de um arquivo estatico de public/ com versao pela data de modificacao,
 * para o navegador baixar de novo CSS/JS alterados em vez de usar o cache.
 */
function asset(string $caminho): string
{
    $caminho = ltrim($caminho, '/');
    $arquivo = PROJECT_ROOT . '/public/' . $caminho;
    $versao = is_file($arquivo) ? (string)filemtime($arquivo) : '';
    return url($caminho) . ($versao !== '' ? '?v=' . $versao : '');
}

/**
 * Densidade da interface escolhida pelo usuario ("compact" ou "comfortable").
 * Fica num cookie do navegador (gravado por app.js) para o servidor ja
 * renderizar o <html data-density> certo, sem o layout "pular" ao carregar.
 * O padrao e o modo compacto.
 */
function densidade_interface(?string $valor = null): string
{
    $valor ??= $_COOKIE['sesjur_densidade'] ?? null;
    return $valor === 'comfortable' ? 'comfortable' : 'compact';
}

/** Campos do formulario de Intimacao Pessoal (cadastro e edicao). */
function entrada_intimacao(): array
{
    $dados = [];
    foreach (['processo_tipo', 'processo_id', 'agente_intimado_id', 'agente_recebedor_id', 'data_recebimento', 'data_prazo',
              'data_cumprimento', 'status', 'documento_referencia', 'confirmar_duplicidade'] as $campo) {
        $dados[$campo] = entrada($campo);
    }
    // Sem entrada(): o trim por linha fica com o servico.
    foreach (['observacoes', 'justificativa'] as $campo) {
        $dados[$campo] = is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '';
    }
    return $dados;
}

/**
 * Para onde voltar depois de gravar ou excluir uma Intimacao Pessoal: uma
 * pagina interna ("pagina.php?query", sem redirecionamento aberto) ou, sem
 * retorno valido, a listagem de intimacoes.
 */
function destino_intimacao(string $retorno, string $ancora = ''): string
{
    $seguro = \App\Core\Auth::retornoSeguro($retorno);
    return ($seguro === $retorno ? $seguro : 'intimacoes-pessoais.php') . $ancora;
}

/**
 * Para onde voltar depois de gravar um lancamento de bloqueio ou uma guia de
 * deposito: o painel ou a ficha do processo. Lista fechada, nunca uma URL
 * vinda do formulario.
 */
function destino_bloqueios(string $retorno, ?int $processoId): string
{
    return $retorno === 'processo' && $processoId !== null
        ? 'processo-judicial.php?id=' . $processoId . '#secao-bloqueios'
        : 'bloqueios-judiciais.php';
}
