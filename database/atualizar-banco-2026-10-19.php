<?php
declare(strict_types=1);

/**
 * Atualizacao do banco no ambiente real apos o pull da versao
 * "Configuracoes do Sistema / Catalogo de Procedimentos" (2026-10-19).
 *
 * Faz, nesta ordem:
 *   1. confere a conexao e os pre-requisitos (tabelas e tipos existentes);
 *   2. gera backup completo com mysqldump (obrigatorio, salvo --sem-backup);
 *   3. aplica a estrutura que faltar, passo a passo e de forma idempotente:
 *        - tabela `procedimentos` (catalogo);
 *        - coluna `processos_administrativos.procedimento_id` + indice;
 *        - chave estrangeira `fk_pa_procedimento`;
 *        - comentario da coluna `procedimento` (texto livre vira historico);
 *   4. confere que nenhum dado existente mudou e que a estrutura ficou correta.
 *
 * Nada e apagado, nenhum item e cadastrado no catalogo e nenhum processo e
 * vinculado: o texto livre antigo fica preservado para a conciliacao
 * (database/conciliar-procedimentos.php), feita depois do cadastro do
 * catalogo pelos administradores.
 *
 * Uso (na raiz do projeto, com o PHP de linha de comando do servidor):
 *   php database/atualizar-banco-2026-10-19.php --verificar   (so diagnostico, nao altera nada)
 *   php database/atualizar-banco-2026-10-19.php               (backup + atualizacao, pede confirmacao)
 *
 * Opcoes:
 *   --verificar          apenas mostra o que falta; nao faz backup nem altera
 *   --sim                nao pede confirmacao (execucao automatizada)
 *   --mysqldump=CAMINHO  caminho do mysqldump, se nao for encontrado sozinho
 *   --backup-dir=PASTA   onde gravar o backup (padrao: database/)
 *   --sem-backup         pula o backup (somente se ja houver um backup recente!)
 *   --banco=NOME         outro banco que nao o de app/config/config.php (homologacao)
 *
 * Codigos de saida: 0 = banco atualizado/ja atualizado; 1 = erro; 2 = pendente (--verificar).
 * Pode ser executado quantas vezes for preciso: o que ja existe e mantido.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

// ---------------------------------------------------------------------
// Opcoes e saida
// ---------------------------------------------------------------------
$opcoes = getopt('', ['verificar', 'sim', 'sem-backup', 'mysqldump:', 'backup-dir:', 'banco:', 'help']);
if (isset($opcoes['help'])) {
    echo "Consulte o cabeçalho deste arquivo para as opções.\n";
    exit(0);
}
$soVerificar = isset($opcoes['verificar']);
if (isset($opcoes['banco'])) {
    $GLOBALS['gj_config']['db']['database'] = (string)$opcoes['banco'];
}
$cfg = $GLOBALS['gj_config']['db'];
$banco = (string)$cfg['database'];

function linha(string $texto = ''): void { echo $texto . "\n"; }
function ok(string $texto): void { echo "  \u{2713} {$texto}\n"; }
function aviso(string $texto): void { echo "  ! {$texto}\n"; }
function falhar(string $texto, int $codigo = 1): never
{
    fwrite(STDERR, "\n  \u{2715} {$texto}\n\n");
    exit($codigo);
}

linha();
linha('Atualização do banco — Catálogo de Procedimentos (2026-10-19)');
linha(str_repeat('─', 66));

// ---------------------------------------------------------------------
// 1. Conexao e pre-requisitos
// ---------------------------------------------------------------------
try {
    $db = Database::conexao();
} catch (Throwable $e) {
    falhar('Sem conexão com o banco: ' . $e->getMessage());
}
$versao = (string)$db->query('SELECT VERSION()')->fetchColumn();
linha("Banco: {$banco} em {$cfg['host']}:{$cfg['port']} · servidor {$versao}");
linha();
linha('1. Pré-requisitos');

$coluna = static function (string $tabela, string $nome) use ($db): ?array {
    $stmt = $db->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_COMMENT FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
    $stmt->execute([':t' => $tabela, ':c' => $nome]);
    return $stmt->fetch() ?: null;
};
$tabelaExiste = static function (string $tabela) use ($db): bool {
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
    $stmt->execute([':t' => $tabela]);
    return (bool)$stmt->fetchColumn();
};
$restricaoExiste = static function (string $tabela, string $nome) use ($db): bool {
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                           WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = :n');
    $stmt->execute([':t' => $tabela, ':n' => $nome]);
    return (bool)$stmt->fetchColumn();
};
$indiceExiste = static function (string $tabela, string $nome) use ($db): bool {
    $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :n LIMIT 1');
    $stmt->execute([':t' => $tabela, ':n' => $nome]);
    return (bool)$stmt->fetchColumn();
};

foreach (['processos_administrativos', 'usuarios', 'auditoria', 'configuracoes', 'prioridades'] as $t) {
    if (!$tabelaExiste($t)) {
        falhar("Tabela `{$t}` não encontrada. Aplique antes as migrações anteriores (database/migracao_*.sql, em ordem de data).");
    }
}
ok('tabelas base presentes');
$idUsuarios = $coluna('usuarios', 'id');
if ($idUsuarios === null || !str_contains(strtolower($idUsuarios['COLUMN_TYPE']), 'int') || !str_contains(strtolower($idUsuarios['COLUMN_TYPE']), 'unsigned')) {
    falhar('usuarios.id não é INT UNSIGNED: estrutura inesperada, atualização interrompida.');
}
if ($coluna('processos_administrativos', 'procedimento') === null) {
    falhar('Coluna processos_administrativos.procedimento ausente. Aplique antes database/migracao_2026-09-25_procedimento.sql.');
}
ok('tipos e colunas esperados');

// Retrato dos dados, para provar ao final que nada mudou.
$retrato = static fn(): array => $db->query(
    "SELECT COUNT(*) AS processos,
            COUNT(NULLIF(TRIM(procedimento), '')) AS com_texto,
            COALESCE(SUM(CRC32(COALESCE(procedimento, ''))), 0) AS assinatura_texto
       FROM processos_administrativos"
)->fetch();
$antes = $retrato();
ok("{$antes['processos']} processo(s) administrativo(s), {$antes['com_texto']} com procedimento em texto livre");

// ---------------------------------------------------------------------
// Diagnostico do que falta
// ---------------------------------------------------------------------
$colunaId = $coluna('processos_administrativos', 'procedimento_id');
$pendencias = [
    'tabela'     => !$tabelaExiste('procedimentos'),
    'coluna'     => $colunaId === null,
    'indice'     => !$indiceExiste('processos_administrativos', 'ix_pa_procedimento_id'),
    'fk'         => !$restricaoExiste('processos_administrativos', 'fk_pa_procedimento'),
    'comentario' => !str_starts_with((string)($coluna('processos_administrativos', 'procedimento')['COLUMN_COMMENT'] ?? ''), 'Legado'),
];
$descricoes = [
    'tabela'     => 'criar a tabela `procedimentos`',
    'coluna'     => 'criar a coluna `processos_administrativos.procedimento_id`',
    'indice'     => 'criar o índice `ix_pa_procedimento_id`',
    'fk'         => 'criar a chave estrangeira `fk_pa_procedimento`',
    'comentario' => 'marcar a coluna `procedimento` como histórico (só o comentário)',
];

linha();
linha('2. Diagnóstico');
$faltando = array_keys(array_filter($pendencias));
if ($faltando === []) {
    ok('estrutura já atualizada — nada a fazer');
    linha();
    exit(0);
}
foreach ($faltando as $p) {
    aviso('pendente: ' . $descricoes[$p]);
}
if ($soVerificar) {
    linha();
    linha('Modo --verificar: nada foi alterado. Rode sem a opção para atualizar.');
    linha();
    exit(2);
}

// ---------------------------------------------------------------------
// 3. Confirmacao e backup
// ---------------------------------------------------------------------
if (!isset($opcoes['sim'])) {
    linha();
    echo "  Atualizar o banco `{$banco}`? As alterações de estrutura (DDL) não podem ser desfeitas por rollback.\n";
    echo "  Digite SIM para continuar: ";
    $resposta = trim((string)fgets(STDIN));
    if ($resposta !== 'SIM') {
        falhar('Cancelado pelo operador. Nada foi alterado.');
    }
}

linha();
linha('3. Backup');
if (isset($opcoes['sem-backup'])) {
    aviso('backup ignorado (--sem-backup): garanta que existe um backup recente deste banco');
} else {
    $candidatos = array_filter([
        $opcoes['mysqldump'] ?? null,
        ...glob('C:/laragon/bin/mysql/*/bin/mysqldump.exe') ?: [],
        'C:/xampp/mysql/bin/mysqldump.exe',
        '/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/opt/lampp/bin/mysqldump',
    ]);
    $mysqldump = null;
    foreach ($candidatos as $c) {
        if (is_file((string)$c)) {
            $mysqldump = (string)$c;
            break;
        }
    }
    if ($mysqldump === null) {
        // No PATH do sistema?
        $where = stripos(PHP_OS, 'WIN') === 0 ? 'where mysqldump 2>NUL' : 'command -v mysqldump 2>/dev/null';
        $achado = trim((string)shell_exec($where));
        $mysqldump = $achado !== '' ? strtok($achado, "\r\n") : null;
    }
    if ($mysqldump === null) {
        falhar('mysqldump não encontrado. Informe --mysqldump=CAMINHO (ou faça o backup manualmente e use --sem-backup). Nada foi alterado.');
    }

    $pasta = rtrim((string)($opcoes['backup-dir'] ?? __DIR__), '/\\');
    if (!is_dir($pasta) || !is_writable($pasta)) {
        falhar("Pasta de backup inacessível: {$pasta}. Nada foi alterado.");
    }
    $arquivo = $pasta . DIRECTORY_SEPARATOR . 'backup_antes_procedimentos_' . $banco . '_' . date('Ymd_His') . '.sql';

    // Senha num arquivo temporario de opcoes: nunca na linha de comando.
    $opcoesMysql = tempnam(sys_get_temp_dir(), 'gjdump');
    file_put_contents($opcoesMysql, sprintf(
        "[client]\nuser=\"%s\"\npassword=\"%s\"\nhost=\"%s\"\nport=%d\n",
        addcslashes((string)$cfg['username'], "\"\\"),
        addcslashes((string)($cfg['password'] ?? ''), "\"\\"),
        addcslashes((string)$cfg['host'], "\"\\"),
        (int)$cfg['port']
    ));
    @chmod($opcoesMysql, 0600);

    $comando = escapeshellarg($mysqldump)
        . ' --defaults-extra-file=' . escapeshellarg($opcoesMysql)
        . ' --single-transaction --routines --triggers --default-character-set=utf8mb4'
        . ' --result-file=' . escapeshellarg($arquivo) . ' ' . escapeshellarg($banco) . ' 2>&1';
    exec($comando, $saida, $retorno);
    @unlink($opcoesMysql);

    if ($retorno !== 0 || !is_file($arquivo) || filesize($arquivo) < 1024
        || !str_contains((string)file_get_contents($arquivo, false, null, max(0, filesize($arquivo) - 200)), 'Dump completed')) {
        falhar("Backup falhou (código {$retorno}): " . implode(' ', $saida) . "\n    Nada foi alterado.");
    }
    ok('backup gravado: ' . $arquivo . ' (' . number_format(filesize($arquivo) / 1024, 0, ',', '.') . ' KB)');
}

// ---------------------------------------------------------------------
// 4. Aplicacao (cada passo so roda se ainda faltar)
// ---------------------------------------------------------------------
linha();
linha('4. Atualização');
$passos = [
    'tabela' => "CREATE TABLE IF NOT EXISTS `procedimentos` (
        `id`             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `nome`           VARCHAR(160)      NOT NULL,
        `descricao`      VARCHAR(500)      NULL,
        `ativo`          TINYINT(1)        NOT NULL DEFAULT 1,
        `criado_por`     INT UNSIGNED      NULL,
        `atualizado_por` INT UNSIGNED      NULL,
        `created_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_procedimentos_nome` (`nome`),
        KEY `ix_procedimentos_ativo` (`ativo`, `nome`),
        CONSTRAINT `ck_procedimentos_ativo` CHECK (`ativo` IN (0, 1)),
        CONSTRAINT `fk_procedimentos_criado_por`
            FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT `fk_procedimentos_atualizado_por`
            FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'coluna' => "ALTER TABLE `processos_administrativos`
        ADD COLUMN `procedimento_id` SMALLINT UNSIGNED NULL COMMENT 'Procedimento do catalogo' AFTER `procedimento`",
    'indice' => 'ALTER TABLE `processos_administrativos` ADD KEY `ix_pa_procedimento_id` (`procedimento_id`)',
    'fk' => 'ALTER TABLE `processos_administrativos`
        ADD CONSTRAINT `fk_pa_procedimento` FOREIGN KEY (`procedimento_id`) REFERENCES `procedimentos` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE',
    'comentario' => "ALTER TABLE `processos_administrativos` MODIFY COLUMN `procedimento` VARCHAR(160) NULL
        COMMENT 'Legado: texto livre anterior ao catalogo (procedimento_id). Preservado, nao editado'",
];

foreach ($faltando as $p) {
    if ($p === 'fk') {
        // Valores sem item correspondente impediriam a FK: nunca sao "corrigidos" aqui.
        $orfaos = (int)$db->query('SELECT COUNT(*) FROM processos_administrativos pa
                                     LEFT JOIN procedimentos pr ON pr.id = pa.procedimento_id
                                    WHERE pa.procedimento_id IS NOT NULL AND pr.id IS NULL')->fetchColumn();
        if ($orfaos > 0) {
            falhar("{$orfaos} processo(s) com procedimento_id sem item no catálogo. Revise manualmente antes de criar a chave estrangeira.");
        }
    }
    try {
        $db->exec($passos[$p]);
        ok($descricoes[$p]);
    } catch (Throwable $e) {
        falhar('Falha ao ' . $descricoes[$p] . ': ' . $e->getMessage()
            . "\n    Os passos anteriores ficaram aplicados; corrija a causa e rode o script de novo (ele continua de onde parou)."
            . (isset($arquivo) ? "\n    Backup disponível em: {$arquivo}" : ''));
    }
}

// ---------------------------------------------------------------------
// 5. Conferencia final
// ---------------------------------------------------------------------
linha();
linha('5. Conferência');
$colunaId = $coluna('processos_administrativos', 'procedimento_id');
$idCatalogo = $coluna('procedimentos', 'id');
$estruturaOk = $tabelaExiste('procedimentos')
    && $colunaId !== null && $colunaId['IS_NULLABLE'] === 'YES'
    && strtolower(preg_replace('/\(\d+\)/', '', $colunaId['COLUMN_TYPE'])) === strtolower(preg_replace('/\(\d+\)/', '', (string)$idCatalogo['COLUMN_TYPE']))
    && $indiceExiste('processos_administrativos', 'ix_pa_procedimento_id')
    && $restricaoExiste('processos_administrativos', 'fk_pa_procedimento');
if (!$estruturaOk) {
    falhar('A estrutura final não confere. Verifique manualmente (SHOW CREATE TABLE processos_administrativos).');
}
ok('estrutura conferida (tabela, coluna, índice e chave estrangeira)');

$depois = $retrato();
if ($depois != $antes) {
    falhar('Os dados de processos mudaram durante a atualização (antes: ' . json_encode($antes) . ', depois: ' . json_encode($depois)
        . '). Verifique imediatamente' . (isset($arquivo) ? "; backup em {$arquivo}" : '') . '.');
}
ok("dados preservados: {$depois['processos']} processo(s), {$depois['com_texto']} texto(s) livre(s) intactos");
$vinculados = (int)$db->query('SELECT COUNT(*) FROM processos_administrativos WHERE procedimento_id IS NOT NULL')->fetchColumn();
ok("{$vinculados} processo(s) vinculado(s) ao catálogo");

linha();
linha('Banco atualizado. Próximos passos:');
linha('  1. Administradores cadastram os procedimentos em Sistema › Configurações › Procedimentos.');
linha('  2. Prévia da conciliação dos textos antigos:  php database/conciliar-procedimentos.php --csv=conciliacao.csv');
linha('  3. Após revisar, vincular as correspondências exatas:  php database/conciliar-procedimentos.php --aplicar');
linha();
exit(0);
