<?php
declare(strict_types=1);

/**
 * Executa toda a suíte de testes PHP.
 *
 *   /Applications/XAMPP/bin/php tests/run-tests.php
 *
 * Os testes de integração rodam sobre um banco isolado
 * (gestao_juridica_teste), recriado a partir de database/database.sql.
 * O banco de produção nunca é alterado. Se o MariaDB não estiver disponível,
 * esses testes são ignorados e os demais continuam válidos.
 */

if (PHP_SAPI !== 'cli') {
    exit('Execute pela linha de comando.');
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/Runner.php';

use Tests\Runner;

$nomeBancoTeste = getenv('GJ_TEST_DB') ?: 'gestao_juridica_teste';

/** Prepara um banco isolado para os testes de integração. */
function prepararBancoDeTeste(string $nome): bool
{
    $cfg = $GLOBALS['gj_config']['db'];

    try {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], (int)$cfg['port'], $cfg['charset']);
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (Throwable $e) {
        fwrite(STDERR, "  ! MariaDB indisponível: " . $e->getMessage() . "\n");
        return false;
    }

    $sql = file_get_contents(PROJECT_ROOT . '/database/database.sql');
    if ($sql === false) {
        fwrite(STDERR, "  ! database/database.sql não encontrado\n");
        return false;
    }

    // Aponta o script para o banco de testes e remove os dados de demonstração.
    $sql = str_replace('`gestao_juridica`', '`' . $nome . '`', $sql);
    $corte = strpos($sql, '-- Dados de demonstracao');
    if ($corte !== false) {
        $inicioBloco = strrpos(substr($sql, 0, $corte), '-- ====');
        $sql = substr($sql, 0, $inicioBloco !== false ? $inicioBloco : $corte);
    }

    try {
        $pdo->exec('DROP DATABASE IF EXISTS `' . $nome . '`');
        $pdo->exec($sql);
    } catch (Throwable $e) {
        fwrite(STDERR, "  ! Falha ao preparar o banco de testes: " . $e->getMessage() . "\n");
        return false;
    }

    // Redireciona a conexão da aplicação para o banco de testes.
    $GLOBALS['gj_config']['db']['database'] = $nome;
    return true;
}

echo "\n\033[1mSuíte de testes — Gestão Jurídica\033[0m\n";
echo str_repeat('─', 66) . "\n";
echo "PHP " . PHP_VERSION . " · banco de testes: {$nomeBancoTeste}\n";

$GLOBALS['gj_banco_teste_pronto'] = prepararBancoDeTeste($nomeBancoTeste);
echo $GLOBALS['gj_banco_teste_pronto']
    ? "\033[32m✓\033[0m banco de testes preparado\n"
    : "\033[33m○\033[0m testes de integração serão ignorados\n";

$t = new Runner();

foreach (['validadores.php', 'interface.php', 'extracao.php', 'integracao.php', 'usuarios.php', 'acesso.php', 'acoes.php', 'solicitacoes.php', 'anotacoes.php', 'unidades.php', 'processo-relacionados.php', 'agentes.php', 'expediente.php', 'recursos.php', 'bloqueios.php', 'intimacoes.php', 'modelos-expediente.php', 'configuracoes.php', 'pagamentos.php'] as $arquivo) {
    require __DIR__ . '/php/' . $arquivo;
}

$codigo = $t->relatorio();

// Limpeza: remove o banco de testes.
if ($GLOBALS['gj_banco_teste_pronto'] && getenv('GJ_KEEP_TEST_DB') !== '1') {
    try {
        App\Core\Database::conexao()->exec('DROP DATABASE IF EXISTS `' . $nomeBancoTeste . '`');
    } catch (Throwable) {
        // banco de testes permanece; sem impacto na aplicação
    }
}

echo "\nTestes dos extratores (JavaScript): abra tests/extractors/runner.html\n";
echo "por HTTP, por exemplo com:  php -S localhost:8080 -t .\n\n";

exit($codigo);
