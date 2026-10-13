<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use InvalidArgumentException;
use PDO;

/**
 * Designacoes de processos administrativos a usuarios (o processo judicial
 * nao tem designacao).
 * Nomes de tabela/coluna vem do mapa fixo TIPOS, nunca da entrada.
 */
final class DesignacaoRepository
{
    public const TIPOS = [
        'administrativo' => [
            'tabela'    => 'processo_administrativo_designacoes',
            'coluna'    => 'processo_administrativo_id',
            'processos' => 'processos_administrativos',
        ],
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    public static function tipoValido(mixed $tipo): bool
    {
        return is_string($tipo) && isset(self::TIPOS[$tipo]);
    }

    /** @return array{tabela:string,coluna:string,processos:string} */
    public static function meta(string $tipo): array
    {
        if (!self::tipoValido($tipo)) {
            throw new InvalidArgumentException('Tipo de processo inválido.');
        }
        return self::TIPOS[$tipo];
    }

    /** Designados de um processo, com dados do usuario e de quem designou. */
    public function listarPorProcesso(string $tipo, int $processoId): array
    {
        ['tabela' => $tabela, 'coluna' => $coluna] = self::meta($tipo);
        $stmt = $this->db->prepare(
            "SELECT d.id, d.usuario_id, d.observacao, d.created_at,
                    u.nome, u.perfil, u.ativo, c.nome AS cargo, l.nome AS lotacao,
                    dp.nome AS designado_por_nome
               FROM {$tabela} d
               JOIN usuarios u  ON u.id = d.usuario_id
               JOIN cargos c    ON c.id = u.cargo_id
               JOIN lotacoes l  ON l.id = u.lotacao_id
               LEFT JOIN usuarios dp ON dp.id = d.designado_por
              WHERE d.{$coluna} = :processo
              ORDER BY u.nome, d.id"
        );
        $stmt->execute([':processo' => $processoId]);
        return $stmt->fetchAll();
    }

    /** Designacao (com o nome do usuario), bloqueada para remocao. */
    public function buscarParaRemover(string $tipo, int $id): ?array
    {
        ['tabela' => $tabela, 'coluna' => $coluna] = self::meta($tipo);
        $stmt = $this->db->prepare(
            "SELECT d.id, d.{$coluna} AS processo_id, d.usuario_id, d.observacao, u.nome
               FROM {$tabela} d
               JOIN usuarios u ON u.id = d.usuario_id
              WHERE d.id = :id
              FOR UPDATE"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function existe(string $tipo, int $processoId, int $usuarioId): bool
    {
        ['tabela' => $tabela, 'coluna' => $coluna] = self::meta($tipo);
        $stmt = $this->db->prepare("SELECT 1 FROM {$tabela} WHERE {$coluna} = :p AND usuario_id = :u LIMIT 1");
        $stmt->execute([':p' => $processoId, ':u' => $usuarioId]);
        return (bool)$stmt->fetchColumn();
    }

    public function processoExiste(string $tipo, int $processoId): bool
    {
        $processos = self::meta($tipo)['processos'];
        $stmt = $this->db->prepare("SELECT 1 FROM {$processos} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $processoId]);
        return (bool)$stmt->fetchColumn();
    }

    public function inserir(string $tipo, int $processoId, int $usuarioId, ?int $designadoPor, ?string $observacao): int
    {
        ['tabela' => $tabela, 'coluna' => $coluna] = self::meta($tipo);
        $stmt = $this->db->prepare(
            "INSERT INTO {$tabela} ({$coluna}, usuario_id, designado_por, observacao)
             VALUES (:processo, :usuario, :por, :observacao)"
        );
        $stmt->execute([
            ':processo'   => $processoId,
            ':usuario'    => $usuarioId,
            ':por'        => $designadoPor,
            ':observacao' => $observacao,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function remover(string $tipo, int $id): void
    {
        $tabela = self::meta($tipo)['tabela'];
        $stmt = $this->db->prepare("DELETE FROM {$tabela} WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /** Processos designados a um usuario, mais recentes primeiro. */
    public function doUsuario(int $usuarioId, int $limite = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT 'administrativo' AS tipo, pa.id, pa.protocolo AS numero,
                    pa.objeto AS descricao, pa.situacao, d.created_at AS designado_em
               FROM processo_administrativo_designacoes d
               JOIN processos_administrativos pa ON pa.id = d.processo_administrativo_id
              WHERE d.usuario_id = :u
              ORDER BY designado_em DESC, numero
              LIMIT :limite"
        );
        $stmt->bindValue(':u', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function contarDoUsuario(int $usuarioId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM processo_administrativo_designacoes WHERE usuario_id = :u');
        $stmt->execute([':u' => $usuarioId]);
        return (int)$stmt->fetchColumn();
    }
}
