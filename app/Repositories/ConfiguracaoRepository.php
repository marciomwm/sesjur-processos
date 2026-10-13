<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Acesso a dados da tabela configuracoes (chave/valor).
 */
final class ConfiguracaoRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public function todas(): array
    {
        $linhas = $this->db->query('SELECT chave, valor, descricao FROM configuracoes')->fetchAll();
        $mapa = [];
        foreach ($linhas as $linha) {
            $mapa[$linha['chave']] = $linha;
        }
        return $mapa;
    }

    public function obter(string $chave, ?string $padrao = null): ?string
    {
        $stmt = $this->db->prepare('SELECT valor FROM configuracoes WHERE chave = :c');
        $stmt->execute([':c' => $chave]);
        $valor = $stmt->fetchColumn();
        return $valor === false ? $padrao : (string)$valor;
    }

    public function definir(string $chave, ?string $valor): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO configuracoes (chave, valor) VALUES (:c, :v)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        );
        $stmt->execute([':c' => $chave, ':v' => $valor]);
    }
}
