<?php
declare(strict_types=1);

/**
 * Bloqueios judiciais (lançamentos de Bloqueio/Desbloqueio e Saldo retido)
 * e Guias de Depósito Judicial.
 *
 * @var \Tests\Runner $t
 */

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Dinheiro;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\BloqueioJudicialRepository;
use App\Repositories\GuiaDepositoRepository;
use App\Services\BloqueioJudicialService;
use App\Services\GuiaDepositoService;
use App\Services\ProcessoJudicialService;
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

$novoUsuario = static function (string $perfil) use ($entrarComo): int {
    static $seq = 0;
    $seq++;
    $entrarComo(null);
    $db = Database::conexao();
    return (new UsuarioService())->criar([
        'nome'       => "Pessoa Bloqueios {$seq}",
        'email'      => "bloqueios{$seq}@ses.mt.gov.br",
        'matricula'  => 'BLQ-' . $seq,
        'cargo_id'   => (string)$db->query("SELECT id FROM cargos WHERE nome = 'Analista'")->fetchColumn(),
        'lotacao_id' => (string)$db->query("SELECT id FROM lotacoes WHERE nome = 'Unidade Jurídica'")->fetchColumn(),
        'perfil'     => $perfil,
        'data_nascimento' => '',
    ]);
};

/** Processo judicial novo, com número CNJ de dígito verificador válido. */
$novoProcesso = static function (): int {
    static $seq = 0;
    $seq++;
    $n = str_pad((string)(5300000 + $seq), 7, '0', STR_PAD_LEFT);
    $base = $n . '2026' . '8' . '11' . '0041' . '00';
    $resto = 0;
    foreach (str_split($base) as $d) {
        $resto = ($resto * 10 + (int)$d) % 97;
    }
    $dv = str_pad((string)(98 - $resto), 2, '0', STR_PAD_LEFT);
    return (new ProcessoJudicialService())->criar(['numero_cnj' => "{$n}-{$dv}.2026.8.11.0041"]);
};

$t->grupo('Bloqueios — valores e regras');

$t->teste('valor em reais: formato brasileiro, ponto decimal e milhar', function ($t) {
    $t->igual(Dinheiro::centavos('1.234,56'), 123456, 'milhar e vírgula');
    $t->igual(Dinheiro::centavos('R$ 1.234.567,8'), 123456780, 'com R$ e uma casa');
    $t->igual(Dinheiro::centavos('1234,5'), 123450, 'sem milhar');
    $t->igual(Dinheiro::centavos('1234.56'), 123456, 'ponto decimal');
    $t->igual(Dinheiro::centavos('1.234'), 123400, '"1.234" é milhar');
    $t->igual(Dinheiro::centavos('0,01'), 1, 'um centavo');
    $t->igual(Dinheiro::centavos('0'), null, 'zero recusado');
    $t->igual(Dinheiro::centavos('-10'), null, 'negativo recusado');
    $t->igual(Dinheiro::centavos('10,999'), null, 'três casas decimais');
    $t->igual(Dinheiro::centavos('1,234.56'), null, 'formato americano recusado');
    $t->igual(Dinheiro::centavos('abc'), null, 'texto');
    $t->igual(Dinheiro::centavos('99999999999999'), null, 'acima do teto da coluna');
    $t->igual(Dinheiro::centavos('9999999999999,99'), Dinheiro::MAX_CENTAVOS, 'no teto');
});

$t->teste('conversões de centavos: banco, campo e exibição', function ($t) {
    $t->igual(Dinheiro::paraDecimal(123456), '1234.56', 'para DECIMAL');
    $t->igual(Dinheiro::paraDecimal(5), '0.05', 'centavos com zero à esquerda');
    $t->igual(Dinheiro::deDecimal('1234.50'), 123450, 'do DECIMAL');
    $t->igual(Dinheiro::deDecimal('-0.05'), -5, 'negativo');
    $t->igual(Dinheiro::formatar(123456789), 'R$ 1.234.567,89', 'exibição');
    $t->igual(Dinheiro::formatar(-1500), '-R$ 15,00', 'exibição negativa');
    $t->igual(Dinheiro::paraCampo('1234.5'), '1.234,50', 'campo do formulário');
    $t->igual(Dinheiro::centavos(Dinheiro::paraCampo('98765.43')), 9876543, 'campo volta ao mesmo valor');
});

$t->teste('saldo retido em ordem cronológica e detecção do primeiro saldo negativo', function ($t) {
    $l = static fn(int $id, string $tipo, string $data, string $valor): array =>
        ['id' => $id, 'tipo' => $tipo, 'data_lancamento' => $data, 'valor' => $valor];
    $s = BloqueioJudicialService::saldos([
        $l(1, 'BLOQUEIO', '2026-01-10', '1000.00'),
        $l(2, 'DESBLOQUEIO', '2026-02-01', '250.50'),
        $l(3, 'BLOQUEIO', '2026-03-01', '0.50'),
    ]);
    $t->igual([$s['bloqueado'], $s['desbloqueado'], $s['saldo']], [100050, 25050, 75000], 'totais em centavos');
    $t->igual($s['saldo_apos'], [1 => 100000, 2 => 74950, 3 => 75000], 'saldo após cada lançamento');
    $t->igual($s['negativo'], null, 'nunca negativo');

    $s = BloqueioJudicialService::saldos([
        $l(1, 'DESBLOQUEIO', '2026-01-05', '10.00'),
        $l(2, 'BLOQUEIO', '2026-01-10', '100.00'),
    ]);
    $t->igual($s['saldo'], 9000, 'saldo final positivo');
    $t->igual($s['negativo'], ['data' => '2026-01-05', 'saldo' => -1000], 'mas desbloqueou antes de bloquear');
});

$t->teste('situação da guia: Vencida é Emitida com vencimento passado', function ($t) {
    $t->igual(GuiaDepositoService::situacao(['status' => 'EMITIDA', 'vencimento' => '2026-10-01'], '2026-10-13'), 'VENCIDA', 'vencida');
    $t->igual(GuiaDepositoService::situacao(['status' => 'EMITIDA', 'vencimento' => '2026-10-13'], '2026-10-13'), 'EMITIDA', 'vence hoje: ainda não');
    $t->igual(GuiaDepositoService::situacao(['status' => 'EMITIDA', 'vencimento' => null], '2026-10-13'), 'EMITIDA', 'sem vencimento');
    $t->igual(GuiaDepositoService::situacao(['status' => 'PAGA', 'vencimento' => '2026-01-01'], '2026-10-13'), 'PAGA', 'paga nunca vence');
    $t->igual(GuiaDepositoService::situacao(['status' => 'CANCELADA', 'vencimento' => '2026-01-01'], '2026-10-13'), 'CANCELADA', 'cancelada');
});

$t->teste('destino depois de gravar: lista fechada', function ($t) {
    $t->igual(destino_bloqueios('processo', 7), 'processo-judicial.php?id=7#secao-bloqueios', 'ficha');
    $t->igual(destino_bloqueios('painel', 7), 'bloqueios-judiciais.php', 'painel');
    $t->igual(destino_bloqueios('https://evil.example', 7), 'bloqueios-judiciais.php', 'valor desconhecido vai ao painel');
    $t->igual(destino_bloqueios('processo', null), 'bloqueios-judiciais.php', 'sem processo vai ao painel');
});

$t->grupo('Bloqueios — lançamentos');

$t->teste('valida campos e exige o processo', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoProcesso) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::USUARIO));
    $servico = new BloqueioJudicialService();
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(null, ['tipo' => 'x', 'data_lancamento' => '', 'valor' => '']), 'tudo vazio');
    $t->igual(array_keys($e->erros()), ['processo_judicial_id', 'tipo', 'data_lancamento', 'valor'], 'erros reunidos de uma vez');

    $pid = $novoProcesso();
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($pid, [
        'tipo' => 'BLOQUEIO', 'data_lancamento' => date('Y-m-d', strtotime('+1 day')), 'valor' => '10,999',
        'identificador' => str_repeat('9', 61),
    ]), 'data futura, valor e identificador');
    $t->afirmar(isset($e->erros()['data_lancamento'], $e->erros()['valor'], $e->erros()['identificador']), 'três erros');
    $t->lanca(ValidationException::class, fn() => $servico->criar(999999, ['tipo' => 'BLOQUEIO', 'data_lancamento' => '2026-01-01', 'valor' => '1']), 'processo inexistente');
    $entrarComo(null);
});

$t->teste('saldo nunca negativo: criação, edição e exclusão são desfeitas', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoProcesso) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::USUARIO));
    $servico = new BloqueioJudicialService();
    $repo = new BloqueioJudicialRepository();
    $pid = $novoProcesso();

    $bloqueio = $servico->criar($pid, ['tipo' => 'bloqueio', 'data_lancamento' => '10/03/2026', 'valor' => 'R$ 5.000,00', 'identificador' => ' SISBAJUD  123 ']);
    $gravado = $repo->buscarPorId($bloqueio);
    $t->igual([$gravado['tipo'], $gravado['data_lancamento'], (string)$gravado['valor'], $gravado['identificador']],
        ['BLOQUEIO', '2026-03-10', '5000.00', 'SISBAJUD 123'], 'normalizado e gravado');

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($pid, ['tipo' => 'DESBLOQUEIO', 'data_lancamento' => '2026-03-20', 'valor' => '5000,01']), 'desbloqueio maior que o saldo');
    $t->contem($e->erros()['valor'] ?? '', 'superariam', 'mensagem no campo valor');
    $t->lanca(ValidationException::class, fn() => $servico->criar($pid, ['tipo' => 'DESBLOQUEIO', 'data_lancamento' => '2026-03-01', 'valor' => '100']), 'desbloqueio antes do bloqueio');
    $t->igual($repo->contarDoProcesso($pid), 1, 'nada gravado nas tentativas recusadas');

    $desbloqueio = $servico->criar($pid, ['tipo' => 'DESBLOQUEIO', 'data_lancamento' => '2026-03-10', 'valor' => '5000']);
    $t->igual(BloqueioJudicialService::saldos($repo->listarPorProcesso($pid))['saldo'], 0, 'no mesmo dia, desbloqueio depois do bloqueio');

    $t->lanca(ValidationException::class, fn() => $servico->atualizar($bloqueio, ['tipo' => 'BLOQUEIO', 'data_lancamento' => '2026-03-10', 'valor' => '4000']), 'reduzir o bloqueio');
    $t->igual((string)$repo->buscarPorId($bloqueio)['valor'], '5000.00', 'edição recusada não grava');
    $e = $t->lanca(ValidationException::class, fn() => $servico->excluir($bloqueio), 'excluir o bloqueio');
    $t->contem($e->getMessage(), 'superariam', 'mensagem geral na exclusão');
    $t->afirmar($repo->buscarPorId($bloqueio) !== null, 'exclusão recusada não apaga');

    $servico->excluir($desbloqueio);
    $servico->excluir($bloqueio);
    $t->igual($repo->contarDoProcesso($pid), 0, 'na ordem certa, ambos saem');
    $acoes = array_column((new AuditoriaRepository())->listarPorEntidade('bloqueios_judiciais', $bloqueio), 'acao');
    $t->afirmar(in_array('BLOQUEIO_JUDICIAL_CRIADO', $acoes, true) && in_array('BLOQUEIO_JUDICIAL_EXCLUIDO', $acoes, true), 'auditoria do cadastro e da exclusão');
    $entrarComo(null);
});

$t->teste('só administrador, gestor ou o autor altera e exclui', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoProcesso) {
    $exigirBanco($t);
    $autor = $novoUsuario(Perfil::USUARIO);
    $outro = $novoUsuario(Perfil::USUARIO);
    $gestor = $novoUsuario(Perfil::GESTOR);
    $pid = $novoProcesso();
    $entrarComo($autor);
    $servico = new BloqueioJudicialService();
    $id = $servico->criar($pid, ['tipo' => 'BLOQUEIO', 'data_lancamento' => '2026-04-01', 'valor' => '300']);
    $dados = ['tipo' => 'BLOQUEIO', 'data_lancamento' => '2026-04-01', 'valor' => '350'];

    $entrarComo($outro);
    $t->lanca(AcessoNegadoException::class, fn() => $servico->atualizar($id, $dados), 'outro usuário não altera');
    $t->lanca(AcessoNegadoException::class, fn() => $servico->excluir($id), 'outro usuário não exclui');
    $t->afirmar(!BloqueioJudicialService::podeAlterar((new BloqueioJudicialRepository())->buscarPorId($id)), 'botões ocultos para ele');

    $entrarComo($gestor);
    $servico->atualizar($id, $dados);
    $t->igual((string)(new BloqueioJudicialRepository())->buscarPorId($id)['valor'], '350.00', 'gestor altera');
    $historico = (new AuditoriaRepository())->listarPorEntidade('bloqueios_judiciais', $id);
    $edicao = array_values(array_filter($historico, static fn(array $h): bool => $h['acao'] === 'BLOQUEIO_JUDICIAL_EDITADO'));
    $t->contem((string)($edicao[0]['dados_novos'] ?? ''), '350.00', 'auditoria só com o que mudou');
    $entrarComo(null);
});

$t->grupo('Guias de depósito judicial');

$t->teste('cadastro, pagamento e regras de data', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoProcesso) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::USUARIO));
    $servico = new GuiaDepositoService();
    $repo = new GuiaDepositoRepository();
    $pid = $novoProcesso();

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($pid, [
        'data_emissao' => '2026-05-10', 'vencimento' => '2026-05-01', 'valor' => '', 'status' => 'PAGA', 'data_pagamento' => '',
    ]), 'vencimento antes da emissão, sem valor, paga sem data');
    $t->afirmar(isset($e->erros()['vencimento'], $e->erros()['valor'], $e->erros()['data_pagamento']), 'três erros');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($pid, [
        'data_emissao' => '2026-05-10', 'valor' => '10', 'status' => 'PAGA', 'data_pagamento' => '2026-05-01',
    ]), 'pagamento antes da emissão');
    $t->afirmar(isset($e->erros()['data_pagamento']), 'erro no pagamento');
    $t->lanca(ValidationException::class, fn() => $servico->criar($pid, ['data_emissao' => '2026-05-10', 'valor' => '10', 'status' => 'OUTRO']), 'situação inválida');

    $id = $servico->criar($pid, [
        'numero' => ' 2026.0001 ', 'data_emissao' => '2026-05-10', 'vencimento' => '2026-05-20', 'valor' => '12.345,67',
        'finalidade' => 'Custeio do tratamento', 'status' => 'EMITIDA', 'data_pagamento' => '2026-05-15',
    ]);
    $g = $repo->buscarPorId($id);
    $t->igual([$g['numero'], (string)$g['valor'], $g['status'], $g['data_pagamento']], ['2026.0001', '12345.67', 'EMITIDA', null], 'pagamento descartado se não paga');
    $t->igual(GuiaDepositoService::situacao($g, '2026-06-01'), 'VENCIDA', 'vencida depois do vencimento');

    $servico->atualizar($id, ['data_emissao' => '2026-05-10', 'vencimento' => '2026-05-20', 'valor' => '12345,67',
        'numero' => '2026.0001', 'finalidade' => 'Custeio do tratamento', 'status' => 'PAGA', 'data_pagamento' => '2026-05-18']);
    $g = $repo->buscarPorId($id);
    $t->igual([$g['status'], $g['data_pagamento']], ['PAGA', '2026-05-18'], 'marcada como paga');
    $t->igual($repo->listarPorProcesso($pid)[0]['criado_por_nome'], 'Pessoa Bloqueios 6', 'listagem traz o autor');
    $entrarComo(null);
});

$t->teste('processo com lançamentos ou guias não pode ser excluído', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoProcesso) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::ADMINISTRADOR));
    $pid = $novoProcesso();
    $guia = (new GuiaDepositoService())->criar($pid, ['data_emissao' => '2026-06-01', 'valor' => '1', 'status' => 'EMITIDA']);
    $e = $t->lanca(ValidationException::class, fn() => (new ProcessoJudicialService())->excluir($pid), 'exclusão recusada');
    $t->contem($e->getMessage(), '1 guia(s)', 'mensagem conta os registros');

    (new GuiaDepositoService())->excluir($guia);
    (new ProcessoJudicialService())->excluir($pid);
    $t->afirmar(true, 'sem registros financeiros, o processo sai');
    $entrarComo(null);
});

$t->grupo('Bloqueios — páginas');

$t->teste('painel e ficha renderizam com os lançamentos', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $novoProcesso) {
    $exigirBanco($t);
    $entrarComo($novoUsuario(Perfil::GESTOR));
    $pid = $novoProcesso();
    (new BloqueioJudicialService())->criar($pid, ['tipo' => 'BLOQUEIO', 'data_lancamento' => '2026-07-01', 'valor' => '2.500,00']);
    (new GuiaDepositoService())->criar($pid, ['data_emissao' => '2026-07-02', 'valor' => '800', 'status' => 'EMITIDA', 'vencimento' => '2026-07-05']);

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

    $painel = $renderizar('bloqueios-judiciais.php', ['processo' => (string)$pid]);
    $dadosGrafico = preg_match('#<script type="application/json" id="dadosBloqueios">(.*?)</script>#s', $painel, $m)
        ? json_decode($m[1], true) : null;
    $t->afirmar(is_array($dadosGrafico), 'dados do gráfico embutidos');
    $t->afirmar(in_array([$pid, '2026-07-01', 'B', 250000], $dadosGrafico['lancamentos'] ?? [], true), 'lançamento em centavos');
    $t->afirmar(in_array([$pid, '2026-07-02', 'EMITIDA', 80000, null, '2026-07-05'], $dadosGrafico['guias'] ?? [], true), 'guia em centavos');
    $t->contem($painel, 'value="' . $pid . '" selected', 'processo da URL pré-selecionado');
    $t->contem($painel, 'id="modalBloqueio"', 'modal de lançamento');
    $t->contem($painel, 'data-processo-busca', 'busca de processo no painel');

    $ficha = $renderizar('processo-judicial.php', ['id' => (string)$pid]);
    $t->contem($ficha, 'id="secao-bloqueios"', 'card na ficha');
    $t->contem($ficha, 'R$ 2.500,00', 'saldo retido na ficha');
    $t->contem($ficha, 'Vencida', 'guia vencida sinalizada');
    $t->afirmar(!str_contains($ficha, 'data-processo-busca'), 'na ficha o processo é fixo');
    $_GET = [];
    $entrarComo(null);
});
