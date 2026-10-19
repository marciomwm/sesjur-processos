<?php
declare(strict_types=1);

/**
 * Testes da area Configuracoes do Sistema: acesso exclusivo de
 * administradores, catalogo de Procedimentos e seu uso no processo
 * administrativo, configuracoes da extensao (auditadas) e paginas.
 *
 * @var \Tests\Runner $t
 */

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\ProcedimentoRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Services\ConfiguracaoService;
use App\Services\PrioridadeService;
use App\Services\ProcedimentoService;
use App\Services\ProcessoAdministrativoService;
use App\Services\UsuarioService;

$bancoPronto = $GLOBALS['gj_banco_teste_pronto'] ?? false;
$exigirBanco = static function ($t) use ($bancoPronto): void {
    if (!$bancoPronto) {
        $t->ignorar('banco de testes indisponível');
    }
};

$entrarComo = static function (?int $id): void {
    if ($id === null) {
        unset($_SESSION['auth']);
    } else {
        $_SESSION['auth'] = ['usuario_id' => $id, 'ultimo_acesso' => time(), 'exigir_troca' => false];
    }
    Auth::esquecer();
};

/** Usuario criado em contexto de sistema (sem sessao). */
$novoUsuario = static function (string $perfil) use ($entrarComo): int {
    static $seq = 0;
    $seq++;
    $entrarComo(null);
    $db = Database::conexao();
    return (new UsuarioService())->criar([
        'nome'            => "Pessoa Configuração {$seq}",
        'email'           => "config{$seq}@ses.mt.gov.br",
        'matricula'       => 'CF-' . $seq,
        'cargo_id'        => (string)$db->query("SELECT id FROM cargos WHERE nome = 'Analista'")->fetchColumn(),
        'lotacao_id'      => (string)$db->query("SELECT id FROM lotacoes WHERE nome = 'Unidade Jurídica'")->fetchColumn(),
        'perfil'          => $perfil,
        'data_nascimento' => '',
    ]);
};

$renderizar = static function (string $pagina, array $get): string {
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    try {
        require PROJECT_ROOT . '/public/' . $pagina;
    } finally {
        $html = (string)ob_get_clean();
    }
    return $html;
};

/** Deixa o catalogo sem itens ativos (os demais testes criam processos sem procedimento). */
$inativarCatalogo = static function (): void {
    Database::conexao()->exec('UPDATE procedimentos SET ativo = 0');
};

$t->grupo('Configurações — Procedimentos (catálogo)');

$t->teste('nome obrigatório, espaços normalizados e duplicidade sem caixa nem acentos', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $inativarCatalogo) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::ADMINISTRADOR));
    $servico = new ProcedimentoService();

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['nome' => '   ']), 'nome vazio');
    $t->afirmar(isset($e->erros()['nome']), 'erro no campo nome');

    $id = $servico->criar(['nome' => '  Análise   Prévia de   Legalidade ', 'descricao' => 'Controle prévio']);
    $item = (new ProcedimentoRepository())->buscarPorId($id);
    $t->igual($item['nome'], 'Análise Prévia de Legalidade', 'espaços normalizados');
    $t->igual((int)$item['ativo'], 1, 'nasce ativo');

    foreach (['análise prévia de legalidade', 'ANALISE PREVIA DE LEGALIDADE', "Análise  Prévia de Legalidade"] as $duplicado) {
        $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['nome' => $duplicado]), 'duplicado: ' . $duplicado);
        $t->igual($e->erros()['nome'], ProcedimentoService::MSG_NOME_DUPLICADO, 'mensagem de duplicidade');
    }

    $outro = $servico->criar(['nome' => 'Consulta Jurídica']);
    $t->lanca(ValidationException::class, fn() => $servico->atualizar($outro, ['nome' => 'análise prévia de legalidade']), 'edição para nome existente');
    $servico->atualizar($outro, ['nome' => 'Consulta Jurídica Formal', 'descricao' => '']);
    $t->igual((new ProcedimentoRepository())->buscarPorId($outro)['nome'], 'Consulta Jurídica Formal', 'edição grava');

    $acoes = array_column((new AuditoriaRepository())->listarPorEntidade('procedimentos', $outro), 'acao');
    $t->igual($acoes, ['PROCEDIMENTO_EDITADO', 'PROCEDIMENTO_CRIADO'], 'criação e edição auditadas');
    $inativarCatalogo();
});

$t->teste('somente administradores cadastram, editam, ativam e inativam', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $inativarCatalogo) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::ADMINISTRADOR));
    $id = (new ProcedimentoService())->criar(['nome' => 'Apuração Administrativa']);

    foreach ([Perfil::GESTOR, Perfil::USUARIO] as $perfil) {
        $entrarComo($novoUsuario($perfil));
        $servico = new ProcedimentoService();
        $t->lanca(AcessoNegadoException::class, fn() => $servico->criar(['nome' => 'Indevido ' . $perfil]), $perfil . ' não cria');
        $t->lanca(AcessoNegadoException::class, fn() => $servico->atualizar($id, ['nome' => 'Indevido']), $perfil . ' não edita');
        $t->lanca(AcessoNegadoException::class, fn() => $servico->alterarStatus($id, false), $perfil . ' não inativa');
        $t->lanca(AcessoNegadoException::class, fn() => (new PrioridadeService())->criar(['nome' => 'Indevida', 'nivel' => '3']), $perfil . ' não cria prioridade');
        $t->lanca(AcessoNegadoException::class, fn() => ConfiguracaoService::salvarIntegracao(['sigadoc_hosts' => 'evil.example']), $perfil . ' não altera a extensão');
    }
    $t->igual((int)(new ProcedimentoRepository())->buscarPorId($id)['ativo'], 1, 'nada mudou');
    $inativarCatalogo();
});

$t->teste('inativação preserva o histórico e reativação devolve às opções', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $inativarCatalogo) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::ADMINISTRADOR));
    $servico = new ProcedimentoService();
    $repo = new ProcedimentoRepository();
    $id = $servico->criar(['nome' => 'Cumprimento de Determinação Judicial']);
    $pa = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/81001', 'procedimento_id' => (string)$id]);

    $t->afirmar($servico->alterarStatus($id, false), 'inativou');
    $t->afirmar(!$servico->alterarStatus($id, false), 'já estava inativo');
    $t->afirmar(!in_array($id, array_map('intval', array_column($repo->opcoesAtivas(), 'id')), true), 'fora das opções');
    $processo = (new ProcessoAdministrativoRepository())->buscarPorId($pa);
    $t->igual((int)$processo['procedimento_id'], $id, 'processo mantém o vínculo');
    $t->igual($processo['procedimento_nome'], 'Cumprimento de Determinação Judicial', 'nome disponível para consulta');
    $t->igual($repo->totalProcessos($id), 1, 'contagem de processos vinculados');

    $t->afirmar($servico->alterarStatus($id, true), 'reativou');
    $t->afirmar(in_array($id, array_map('intval', array_column($repo->opcoesAtivas(), 'id')), true), 'volta às opções');
    $t->lanca(\PDOException::class, fn() => Database::conexao()->exec('DELETE FROM procedimentos WHERE id = ' . $id), 'FK impede exclusão física');
    $inativarCatalogo();
});

$t->grupo('Configurações — Procedimento no processo administrativo');

$t->teste('novo processo: só aceita item ativo do catálogo, nunca texto livre', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $inativarCatalogo) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::ADMINISTRADOR));
    $servico = new ProcedimentoService();
    $ativo = $servico->criar(['nome' => 'Fornecimento de Produto de Saúde (teste)']);
    $inativo = $servico->criar(['nome' => 'Procedimento Desativado']);
    $servico->alterarStatus($inativo, false);

    $entrarComo($novoUsuario(Perfil::USUARIO));
    $processos = new ProcessoAdministrativoService();
    $e = $t->lanca(ValidationException::class, fn() => $processos->criar(['protocolo' => 'SES-PRO-2026/81101']), 'obrigatório com catálogo ativo');
    $t->igual($e->erros()['procedimento_id'], 'Selecione o procedimento.', 'mensagem de obrigatoriedade');
    foreach (['999999', 'abc', '-1', 'Consulta inventada'] as $arbitrario) {
        $e = $t->lanca(ValidationException::class, fn() => $processos->criar(['protocolo' => 'SES-PRO-2026/81102', 'procedimento_id' => $arbitrario]), 'rejeita ' . $arbitrario);
        $t->afirmar(isset($e->erros()['procedimento_id']), 'erro no procedimento: ' . $arbitrario);
    }
    $e = $t->lanca(ValidationException::class, fn() => $processos->criar(['protocolo' => 'SES-PRO-2026/81103', 'procedimento_id' => (string)$inativo]), 'rejeita inativo');
    $t->contem($e->erros()['procedimento_id'], 'inativo', 'mensagem de inativo');

    $id = $processos->criar(['protocolo' => 'SES-PRO-2026/81104', 'procedimento_id' => (string)$ativo, 'procedimento' => 'texto livre ignorado']);
    $gravado = (new ProcessoAdministrativoRepository())->buscarPorId($id);
    $t->igual((int)$gravado['procedimento_id'], $ativo, 'referência persistida');
    $t->igual($gravado['procedimento'], null, 'texto livre não é gravado');

    $inativarCatalogo();
    $semCatalogo = $processos->criar(['protocolo' => 'SES-PRO-2026/81105']);
    $t->igual((new ProcessoAdministrativoRepository())->buscarPorId($semCatalogo)['procedimento_id'], null, 'sem catálogo ativo, opcional');
});

$t->teste('edição: mantém procedimento inativo e texto antigo; trocar exige item ativo', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $inativarCatalogo) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::ADMINISTRADOR));
    $servico = new ProcedimentoService();
    $antigo = $servico->criar(['nome' => 'Procedimento Antigo']);
    $outroInativo = $servico->criar(['nome' => 'Outro Inativo']);
    $novo = $servico->criar(['nome' => 'Procedimento Novo']);
    $repoPa = new ProcessoAdministrativoRepository();
    $processos = new ProcessoAdministrativoService();
    $pa = $processos->criar(['protocolo' => 'SES-PRO-2026/81201', 'procedimento_id' => (string)$antigo]);
    $servico->alterarStatus($antigo, false);
    $servico->alterarStatus($outroInativo, false);

    $processos->atualizar($pa, ['protocolo' => 'SES-PRO-2026/81201', 'objeto' => 'Editado', 'procedimento_id' => (string)$antigo]);
    $t->igual((int)$repoPa->buscarPorId($pa)['procedimento_id'], $antigo, 'inativo atual é mantido');
    $processos->atualizar($pa, ['protocolo' => 'SES-PRO-2026/81201', 'objeto' => 'Sem o campo']);
    $t->igual((int)$repoPa->buscarPorId($pa)['procedimento_id'], $antigo, 'chamada sem o campo não limpa');

    $e = $t->lanca(ValidationException::class, fn() => $processos->atualizar($pa, ['protocolo' => 'SES-PRO-2026/81201', 'procedimento_id' => (string)$outroInativo]), 'trocar por inativo');
    $t->contem($e->erros()['procedimento_id'], 'inativo', 'mensagem');
    $t->lanca(ValidationException::class, fn() => $processos->atualizar($pa, ['protocolo' => 'SES-PRO-2026/81201', 'procedimento_id' => '']), 'não remove a classificação');
    $processos->atualizar($pa, ['protocolo' => 'SES-PRO-2026/81201', 'procedimento_id' => (string)$novo]);
    $t->igual((int)$repoPa->buscarPorId($pa)['procedimento_id'], $novo, 'troca por ativo');

    // Processo antigo, só com texto livre: o texto fica preservado e a classificação é opcional.
    $legado = $processos->criar(['protocolo' => 'SES-PRO-2026/81202', 'procedimento_id' => (string)$novo]);
    Database::conexao()->exec("UPDATE processos_administrativos SET procedimento = 'HOME CARE', procedimento_id = NULL WHERE id = {$legado}");
    $processos->atualizar($legado, ['protocolo' => 'SES-PRO-2026/81202', 'objeto' => 'Atualizado', 'procedimento_id' => '']);
    $registro = $repoPa->buscarPorId($legado);
    $t->igual($registro['procedimento'], 'HOME CARE', 'texto antigo preservado');
    $t->igual($registro['procedimento_id'], null, 'nenhum valor artificial imposto');
    $inativarCatalogo();
});

$t->teste('conciliação: só correspondência exata (caixa, acentos e espaços), texto original mantido', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $inativarCatalogo) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::ADMINISTRADOR));
    $servico = new ProcedimentoService();
    $item = $servico->criar(['nome' => 'Transferência Hospitalar']);
    $processos = new ProcessoAdministrativoService();
    $db = Database::conexao();
    $ids = [];
    foreach (['TRANSFERENCIA  HOSPITALAR', 'transferência hospitalar', 'TRANSFERÊNCIA HOSPITALAR E AVALIAÇÃO'] as $i => $texto) {
        $ids[$i] = $processos->criar(['protocolo' => 'SES-PRO-2026/8130' . $i, 'procedimento_id' => (string)$item]);
        $db->prepare('UPDATE processos_administrativos SET procedimento = :t, procedimento_id = NULL WHERE id = :id')
           ->execute([':t' => $texto, ':id' => $ids[$i]]);
    }

    $previa = $servico->conciliarTextosLegados(false);
    $t->igual($previa['vinculados'], 0, 'prévia não grava');
    $t->igual(count($previa['conciliados']), 2, 'duas grafias conciliáveis');
    $t->afirmar(in_array('TRANSFERÊNCIA HOSPITALAR E AVALIAÇÃO', array_column($previa['pendentes'], 'texto'), true), 'aproximação fica pendente');
    $t->igual((new ProcessoAdministrativoRepository())->buscarPorId($ids[0])['procedimento_id'], null, 'nada vinculado na prévia');

    $aplicado = $servico->conciliarTextosLegados(true);
    $t->igual($aplicado['vinculados'], 2, 'vincula as duas');
    $repo = new ProcessoAdministrativoRepository();
    $t->igual((int)$repo->buscarPorId($ids[0])['procedimento_id'], $item, 'vinculado');
    $t->igual($repo->buscarPorId($ids[0])['procedimento'], 'TRANSFERENCIA  HOSPITALAR', 'texto original mantido');
    $t->igual($repo->buscarPorId($ids[2])['procedimento_id'], null, 'pendente não vinculado');
    $inativarCatalogo();
});

$t->grupo('Configurações — Extensão');

$t->teste('parâmetros validados, gravados em transação e auditados com antes/depois', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::ADMINISTRADOR));

    $e = $t->lanca(ValidationException::class, fn() => ConfiguracaoService::salvarIntegracao([
        'exigir_https' => true, 'sigadoc_url_template' => 'http://sigadoc.mt.gov.br/x?s={protocolo}',
    ]), 'http com HTTPS exigido');
    $t->afirmar(isset($e->erros()['sigadoc_url_template']), 'erro no template');
    $t->lanca(ValidationException::class, fn() => ConfiguracaoService::salvarIntegracao([
        'exigir_https' => true, 'sigadoc_url_template' => 'https://sigadoc.mt.gov.br/x',
    ]), 'template sem {protocolo}');

    $entrada = [
        'sigadoc_hosts' => "https://www.sigadoc.mt.gov.br/sigaex\nsigadoc.mt.gov.br",
        'pje_hosts' => 'pje.tjmt.jus.br; PJE.TJMT.JUS.BR',
        'exigir_https' => true,
        'sigadoc_url_template' => 'https://{host}/sigaex/app/expediente/doc/exibir?sigla={protocolo}',
    ];
    $t->afirmar(ConfiguracaoService::salvarIntegracao($entrada), 'gravou');
    $t->igual(ConfiguracaoService::hostsSigadoc(), ['www.sigadoc.mt.gov.br', 'sigadoc.mt.gov.br'], 'hosts normalizados');
    $t->igual(ConfiguracaoService::hostsPje(), ['pje.tjmt.jus.br'], 'sem repetição');
    $t->afirmar(!ConfiguracaoService::salvarIntegracao($entrada), 'sem alteração não grava nem audita');

    $historico = (new AuditoriaRepository())->listarPorEntidadeSemId(ConfiguracaoService::ENTIDADE);
    $t->igual($historico[0]['acao'], 'CONFIGURACAO_ALTERADA', 'evento registrado');
    $depois = json_decode((string)$historico[0]['dados_novos'], true);
    $t->igual($depois['pje_hosts'], 'pje.tjmt.jus.br', 'valor novo');
    $t->afirmar(array_key_exists('pje_hosts', json_decode((string)$historico[0]['dados_anteriores'], true)), 'valor anterior');
    $t->afirmar(count($historico) === 1, 'um evento por gravação efetiva');
    $t->afirmar(ConfiguracaoService::versaoExtensaoDistribuida() !== null, 'versão lida do manifest');
});

$t->grupo('Configurações — páginas e acesso');

$t->teste('administrador vê as quatro guias; demais perfis recebem acesso negado', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $renderizar, $inativarCatalogo) {
    $exigirBanco($t);
    $admin = $novoUsuario(Perfil::ADMINISTRADOR);
    $entrarComo($admin);
    $id = (new ProcedimentoService())->criar(['nome' => 'Consulta <b>Jurídica</b> Página']);

    $html = $renderizar('configuracoes.php', ['aba' => 'procedimentos']);
    foreach (['Configurações do Sistema', 'Gerencie usuários, integrações, procedimentos e critérios de prioridade do SESJUR.',
              'aba=usuarios', 'aba=extensao', 'aba=procedimentos', 'aba=prioridades', 'aria-current="page"'] as $trecho) {
        $t->contem($html, $trecho, 'cabeçalho e guias');
    }
    $t->contem($html, 'Consulta &lt;b&gt;Jurídica&lt;/b&gt; Página', 'nome escapado na lista');
    $t->contem($html, 'editar-procedimento.php?id=' . $id, 'ação de editar');
    $t->contem($renderizar('configuracoes.php', ['aba' => 'usuarios']), 'novo-usuario.php', 'guia Usuários');
    $t->contem($renderizar('configuracoes.php', ['aba' => 'extensao']), 'btnSincronizarExtensao', 'guia Extensão');
    $t->contem($renderizar('configuracoes.php', ['aba' => 'prioridades']), 'alterar-status-prioridade.php', 'guia Prioridades');
    $t->contem($renderizar('configuracoes.php', ['aba' => 'invalida']), 'Pesquisar usuário', 'guia desconhecida cai em Usuários');
    $t->contem($renderizar('editar-procedimento.php', ['id' => (string)$id]), 'Histórico de alterações', 'edição do procedimento');

    $form = $renderizar('novo-processo-administrativo.php', []);
    $t->contem($form, 'name="procedimento_id"', 'formulário usa seleção controlada');
    $t->afirmar(!str_contains($form, 'list="listaProcedimentos"'), 'sem texto livre no formulário');

    foreach ([Perfil::GESTOR, Perfil::USUARIO] as $perfil) {
        $entrarComo($novoUsuario($perfil));
        foreach (['configuracoes.php', 'editar-procedimento.php'] as $pagina) {
            $t->lanca(AcessoNegadoException::class, fn() => $renderizar($pagina, ['aba' => 'procedimentos', 'id' => (string)$id]), $perfil . ' em ' . $pagina);
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        try {
            require APP_ROOT . '/Views/layout/cabecalho.php';
        } finally {
            $menu = (string)ob_get_clean();
        }
        $t->afirmar(!str_contains($menu, 'configuracoes.php'), $perfil . ' não vê o link de Configurações');
    }
    $entrarComo($admin);
    $inativarCatalogo();
});
