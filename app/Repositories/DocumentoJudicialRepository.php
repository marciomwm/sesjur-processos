<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Acesso a dados de processo_judicial_documentos.
 *
 * Diferente da cronologia (espelho substituido a cada extracao), o registro de
 * documentos e ACUMULATIVO: cada extracao atualiza os documentos que cita e
 * acrescenta os novos, sem apagar os demais - uma leitura parcial da timeline
 * nao faz documentos "sumirem". A integra ja obtida so e trocada por outra
 * leitura bem-sucedida.
 */
final class DocumentoJudicialRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    /**
     * Documentos do processo, do mais recente para o mais antigo, SEM o texto
     * integral (que pode ter centenas de KB): traz apenas o tamanho dele.
     */
    public function listarPorProcesso(int $processoJudicialId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, processo_judicial_id, movimentacao_id, documento_id, tipo, classificacao,
                    data_documento, hora, juntado_por, data_juntada, metodo_extracao,
                    integra_extraida_em, ordem,
                    CHAR_LENGTH(texto_integral) AS tamanho_integra
               FROM processo_judicial_documentos
              WHERE processo_judicial_id = :id
              ORDER BY ordem, id'
        );
        $stmt->execute([':id' => $processoJudicialId]);
        return $stmt->fetchAll();
    }

    /** Um documento com a integra. */
    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM processo_judicial_documentos WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function contarPorProcesso(int $processoJudicialId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM processo_judicial_documentos WHERE processo_judicial_id = :id');
        $stmt->execute([':id' => $processoJudicialId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Grava os documentos de uma extracao (upsert por numero do documento).
     * Deve ser chamado dentro de uma transacao (ver ProcessoJudicialService).
     *
     * @param list<array<string, mixed>> $documentos ja normalizados
     *        (Normalizador::documentosJudiciais)
     * @param list<int>|null $idsMovimentacoes ids da cronologia gravada na
     *        mesma operacao (indice = ordem); null = cronologia nao regravada
     */
    public function sincronizar(int $processoJudicialId, array $documentos, ?array $idsMovimentacoes = null): void
    {
        if ($documentos === []) {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT id, documento_id, texto_integral IS NOT NULL AS tem_integra
               FROM processo_judicial_documentos WHERE processo_judicial_id = :id'
        );
        $stmt->execute([':id' => $processoJudicialId]);
        $existentes = [];
        foreach ($stmt->fetchAll() as $linha) {
            $existentes[$linha['documento_id']] = $linha;
        }

        $inserir = $this->db->prepare(
            'INSERT INTO processo_judicial_documentos
                (processo_judicial_id, movimentacao_id, documento_id, tipo, classificacao,
                 data_documento, hora, juntado_por, data_juntada, texto_integral,
                 metodo_extracao, integra_extraida_em, ordem)
             VALUES (:pid, :mov, :documento_id, :tipo, :classificacao,
                     :data_documento, :hora, :juntado_por, :data_juntada, :texto,
                     :metodo, :extraida_em, :ordem)'
        );

        // Metadados: o valor novo so substitui quando existe (COALESCE).
        $atualizarMetadados = $this->db->prepare(
            'UPDATE processo_judicial_documentos SET
                movimentacao_id = COALESCE(:mov, movimentacao_id),
                tipo            = COALESCE(:tipo, tipo),
                classificacao   = COALESCE(:classificacao, classificacao),
                data_documento  = COALESCE(:data_documento, data_documento),
                hora            = COALESCE(:hora, hora),
                juntado_por     = COALESCE(:juntado_por, juntado_por),
                data_juntada    = COALESCE(:data_juntada, data_juntada),
                ordem           = :ordem
             WHERE id = :id'
        );
        $atualizarIntegra = $this->db->prepare(
            'UPDATE processo_judicial_documentos
                SET texto_integral = :texto, metodo_extracao = :metodo, integra_extraida_em = NOW()
              WHERE id = :id'
        );
        $registrarTentativa = $this->db->prepare(
            'UPDATE processo_judicial_documentos SET metodo_extracao = :metodo WHERE id = :id'
        );

        foreach (array_values($documentos) as $ordem => $doc) {
            $ordemMov = $doc['ordem_movimentacao'] ?? null;
            $movId = ($idsMovimentacoes !== null && $ordemMov !== null) ? ($idsMovimentacoes[$ordemMov] ?? null) : null;
            $texto = $doc['texto_integral'] ?? null;
            $metodo = $doc['metodo_extracao'] ?? null;

            $metadados = [
                ':mov'            => $movId,
                ':tipo'           => $doc['tipo'] ?? null,
                ':classificacao'  => $doc['classificacao'] ?? null,
                ':data_documento' => $doc['data_documento'] ?? null,
                ':hora'           => $doc['hora'] ?? null,
                ':juntado_por'    => $doc['juntado_por'] ?? null,
                ':data_juntada'   => $doc['data_juntada'] ?? null,
                ':ordem'          => $ordem,
            ];

            $existente = $existentes[$doc['documento_id']] ?? null;
            if ($existente === null) {
                $inserir->execute($metadados + [
                    ':pid'          => $processoJudicialId,
                    ':documento_id' => $doc['documento_id'],
                    ':texto'        => $texto,
                    ':metodo'       => $metodo,
                    ':extraida_em'  => $texto !== null ? date('Y-m-d H:i:s') : null,
                ]);
                continue;
            }

            $id = (int)$existente['id'];
            $atualizarMetadados->execute($metadados + [':id' => $id]);

            if ($texto !== null) {
                $atualizarIntegra->execute([':texto' => $texto, ':metodo' => $metodo, ':id' => $id]);
            } elseif ($metodo !== null && !(bool)$existente['tem_integra']) {
                // Tentativa sem texto (PDF, falha): registra, sem apagar nada.
                $registrarTentativa->execute([':metodo' => $metodo, ':id' => $id]);
            }
        }
    }
}
