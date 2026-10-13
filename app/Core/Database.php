<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexao PDO unica (singleton) com MariaDB/MySQL.
 * Toda consulta na aplicacao usa prepared statements.
 */
final class Database
{
    private static ?PDO $pdo = null;

    private function __construct()
    {
    }

    public static function conexao(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = $GLOBALS['gj_config']['db'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'] ?? '127.0.0.1',
            (int)($cfg['port'] ?? 3306),
            $cfg['database'] ?? 'gestao_juridica',
            $cfg['charset'] ?? 'utf8mb4'
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['username'] ?? 'root', $cfg['password'] ?? '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Nao foi possivel conectar ao banco de dados: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }

        return self::$pdo;
    }

    /** Executa um callback dentro de uma transacao, com rollback automatico. */
    public static function transacao(callable $callback): mixed
    {
        $pdo = self::conexao();
        $jaEmTransacao = $pdo->inTransaction();

        if (!$jaEmTransacao) {
            $pdo->beginTransaction();
        }

        try {
            $resultado = $callback($pdo);
            if (!$jaEmTransacao) {
                $pdo->commit();
            }
            return $resultado;
        } catch (\Throwable $e) {
            if (!$jaEmTransacao && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Verifica a conexao. Usado pela tela de Configuracoes. */
    public static function testar(): array
    {
        try {
            $versao = self::conexao()->query('SELECT VERSION()')->fetchColumn();
            return ['ok' => true, 'versao' => (string)$versao];
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => $e->getMessage()];
        }
    }
}
