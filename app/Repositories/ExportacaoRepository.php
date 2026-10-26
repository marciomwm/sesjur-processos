<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Consultas somente leitura da API de Integracao (api/v1/).
 *
 * Listagens paginadas por cursor (id > apos_id, em ordem de id) e com filtro
 * opcional "atualizado desde": um processo entra quando ele mesmo ou algum
 * registro filho (eventos, partes, movimentacoes, vinculos...) foi gravado a
 * partir da data. Os filhos sao carregados em lote, sem N+1.
 */
final class ExportacaoRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    // -----------------------------------------------------------------
    // Processos Administrativos
    // -----------------------------------------------------------------

    /** @param array{apos_id:int, limite:int, atualizado_desde:?string, id:?int, protocolo:?string} $f */
    public function processosAdministrativos(array $f): array
    {
        $alteracoes = [
            'pa.updated_at >= :d0',
            'EXISTS (SELECT 1 FROM processo_administrativo_eventos x WHERE x.processo_administrativo_id = pa.id AND x.created_at >= :d1)',
            'EXISTS (SELECT 1 FROM processo_administrativo_prioridades x WHERE x.processo_administrativo_id = pa.id AND x.created_at >= :d2)',
            'EXISTS (SELECT 1 FROM processo_administrativo_produtos_saude x WHERE x.processo_administrativo_id = pa.id AND x.updated_at >= :d3)',
            'EXISTS (SELECT 1 FROM processo_administrativo_judicial x WHERE x.processo_administrativo_id = pa.id AND x.created_at >= :d4)',
            'EXISTS (SELECT 1 FROM processo_administrativo_extrajudicial x WHERE x.processo_administrativo_id = pa.id AND x.created_at >= :d5)',
        ];
        $chave = $f['protocolo'] !== null ? ['pa.protocolo = :chave', $f['protocolo']] : null;

        return $this->paginar(
            'SELECT pa.*, pr.nome AS procedimento_nome
               FROM processos_administrativos pa
               LEFT JOIN procedimentos pr ON pr.id = pa.procedimento_id',
            'pa', $alteracoes, $chave, $f
        );
    }

    /** Eventos (movimentacao do SIGADOC), do mais recente ao mais antigo. */
    public function eventosAdministrativos(array $ids): array
    {
        return $this->filhos(
            'SELECT id, processo_administrativo_id, data_evento, tipo, tipo_descricao, lotacao, responsavel,
                    atendente_lotacao, atendente, documento, descricao, ordem
               FROM processo_administrativo_eventos
              WHERE processo_administrativo_id IN (%s)
              ORDER BY processo_administrativo_id, ordem, id',
            'processo_administrativo_id', $ids
        );
    }

    public function prioridadesAdministrativas(array $ids): array
    {
        return $this->filhos(
            'SELECT pp.processo_administrativo_id, p.id, p.nome, p.nivel, pp.observacao, pp.created_at AS atribuida_em
               FROM processo_administrativo_prioridades pp
               JOIN prioridades p ON p.id = pp.prioridade_id
              WHERE pp.processo_administrativo_id IN (%s)
              ORDER BY pp.processo_administrativo_id, p.nivel DESC, p.nome',
            'processo_administrativo_id', $ids
        );
    }

    public function produtosSaude(array $ids): array
    {
        return $this->filhos(
            'SELECT ps.processo_administrativo_id, ps.id, ps.categoria, ps.descricao,
                    s.codigo AS sigtap_codigo, s.nome AS sigtap_nome, ps.created_at, ps.updated_at
               FROM processo_administrativo_produtos_saude ps
               LEFT JOIN sigtap_procedimentos s ON s.id = ps.sigtap_procedimento_id
              WHERE ps.processo_administrativo_id IN (%s)
              ORDER BY ps.processo_administrativo_id, ps.id',
            'processo_administrativo_id', $ids
        );
    }

    /** Processos judiciais vinculados a cada administrativo. */
    public function judiciaisDosAdministrativos(array $ids): array
    {
        return $this->filhos(
            'SELECT v.processo_administrativo_id, pj.id, pj.numero_cnj, v.data_vinculo, v.observacao
               FROM processo_administrativo_judicial v
               JOIN processos_judiciais pj ON pj.id = v.processo_judicial_id
              WHERE v.processo_administrativo_id IN (%s)
              ORDER BY v.processo_administrativo_id, v.id',
            'processo_administrativo_id', $ids
        );
    }

    /** Processos extrajudiciais vinculados a cada administrativo. */
    public function extrajudiciaisDosAdministrativos(array $ids): array
    {
        return $this->filhos(
            'SELECT v.processo_administrativo_id, pe.id, pe.protocolo, ie.sigla AS instituicao_sigla,
                    v.data_vinculo, v.observacao
               FROM processo_administrativo_extrajudicial v
               JOIN processos_extrajudiciais pe ON pe.id = v.processo_extrajudicial_id
               JOIN instituicoes_externas ie ON ie.id = pe.instituicao_externa_id
              WHERE v.processo_administrativo_id IN (%s)
              ORDER BY v.processo_administrativo_id, v.id',
            'processo_administrativo_id', $ids
        );
    }

    // -----------------------------------------------------------------
    // Processos Judiciais
    // -----------------------------------------------------------------

    /** @param array{apos_id:int, limite:int, atualizado_desde:?string, id:?int, numero_cnj:?string} $f */
    public function processosJudiciais(array $f): array
    {
        $alteracoes = [
            'pj.updated_at >= :d0',
            'EXISTS (SELECT 1 FROM partes_processuais x WHERE x.processo_judicial_id = pj.id AND x.updated_at >= :d1)',
            'EXISTS (SELECT 1 FROM assuntos_processuais x WHERE x.processo_judicial_id = pj.id AND x.created_at >= :d2)',
            'EXISTS (SELECT 1 FROM processo_judicial_movimentacoes x WHERE x.processo_judicial_id = pj.id AND x.created_at >= :d3)',
            'EXISTS (SELECT 1 FROM processo_judicial_documentos x WHERE x.processo_judicial_id = pj.id AND x.updated_at >= :d4)',
            'EXISTS (SELECT 1 FROM processo_administrativo_judicial x WHERE x.processo_judicial_id = pj.id AND x.created_at >= :d5)',
        ];
        $chave = $f['numero_cnj'] !== null ? ['pj.numero_cnj = :chave', $f['numero_cnj']] : null;

        return $this->paginar('SELECT pj.* FROM processos_judiciais pj', 'pj', $alteracoes, $chave, $f);
    }

    public function partes(array $ids): array
    {
        return $this->filhos(
            'SELECT processo_judicial_id, id, nome, documento, polo, tipo, representacao, observacoes, ordem
               FROM partes_processuais
              WHERE processo_judicial_id IN (%s)
              ORDER BY processo_judicial_id, ordem, id',
            'processo_judicial_id', $ids
        );
    }

    public function assuntos(array $ids): array
    {
        return $this->filhos(
            'SELECT processo_judicial_id, id, descricao, codigo, principal, ordem
               FROM assuntos_processuais
              WHERE processo_judicial_id IN (%s)
              ORDER BY processo_judicial_id, principal DESC, ordem, id',
            'processo_judicial_id', $ids
        );
    }

    /** Cronologia do PJe, da mais recente a mais antiga. */
    public function movimentacoes(array $ids): array
    {
        return $this->filhos(
            'SELECT processo_judicial_id, id, data_movimentacao, hora, tipo, descricao, documento_id,
                    documento_tipo, natureza, origem, ordem
               FROM processo_judicial_movimentacoes
              WHERE processo_judicial_id IN (%s)
              ORDER BY processo_judicial_id, ordem, id',
            'processo_judicial_id', $ids
        );
    }

    /** Documentos juntados; a integra (texto_integral) so quando pedida, pois pode ser grande. */
    public function documentos(array $ids, bool $comIntegra): array
    {
        $integra = $comIntegra ? 'texto_integral' : 'NULL AS texto_integral';
        return $this->filhos(
            "SELECT processo_judicial_id, id, documento_id, tipo, classificacao, data_documento, hora,
                    juntado_por, data_juntada, metodo_extracao, integra_extraida_em,
                    (texto_integral IS NOT NULL AND texto_integral <> '') AS possui_integra, {$integra},
                    ordem, updated_at
               FROM processo_judicial_documentos
              WHERE processo_judicial_id IN (%s)
              ORDER BY processo_judicial_id, ordem, id",
            'processo_judicial_id', $ids
        );
    }

    /** Processos administrativos vinculados a cada judicial. */
    public function administrativosDosJudiciais(array $ids): array
    {
        return $this->filhos(
            'SELECT v.processo_judicial_id, pa.id, pa.protocolo, v.data_vinculo, v.observacao
               FROM processo_administrativo_judicial v
               JOIN processos_administrativos pa ON pa.id = v.processo_administrativo_id
              WHERE v.processo_judicial_id IN (%s)
              ORDER BY v.processo_judicial_id, v.id',
            'processo_judicial_id', $ids
        );
    }

    // -----------------------------------------------------------------
    // Processos Extrajudiciais
    // -----------------------------------------------------------------

    /** @param array{apos_id:int, limite:int, atualizado_desde:?string, id:?int} $f */
    public function processosExtrajudiciais(array $f): array
    {
        $alteracoes = [
            'pe.updated_at >= :d0',
            'EXISTS (SELECT 1 FROM processo_administrativo_extrajudicial x WHERE x.processo_extrajudicial_id = pe.id AND x.created_at >= :d1)',
        ];

        return $this->paginar(
            'SELECT pe.id, pe.protocolo, pe.data_expediente, pe.assunto, pe.descricao, pe.situacao, pe.observacoes,
                    pe.instituicao_externa_id, ie.nome AS instituicao_nome, ie.sigla AS instituicao_sigla,
                    pe.orgao_externo_id, oe.nome AS orgao_nome, pe.created_at, pe.updated_at
               FROM processos_extrajudiciais pe
               JOIN instituicoes_externas ie ON ie.id = pe.instituicao_externa_id
               LEFT JOIN orgaos_externos oe ON oe.id = pe.orgao_externo_id',
            'pe', $alteracoes, null, $f
        );
    }

    public function administrativosDosExtrajudiciais(array $ids): array
    {
        return $this->filhos(
            'SELECT v.processo_extrajudicial_id, pa.id, pa.protocolo, v.data_vinculo, v.observacao
               FROM processo_administrativo_extrajudicial v
               JOIN processos_administrativos pa ON pa.id = v.processo_administrativo_id
              WHERE v.processo_extrajudicial_id IN (%s)
              ORDER BY v.processo_extrajudicial_id, v.id',
            'processo_extrajudicial_id', $ids
        );
    }

    // -----------------------------------------------------------------
    // Vinculos
    // -----------------------------------------------------------------

    /**
     * Vinculos de um tipo (JUDICIAL ou EXTRAJUDICIAL), por cursor.
     * @param array{apos_id:int, limite:int, atualizado_desde:?string, id:?int} $f
     */
    public function vinculos(string $tipo, array $f): array
    {
        $sql = $tipo === 'EXTRAJUDICIAL'
            ? 'SELECT v.id, v.processo_administrativo_id, pa.protocolo AS processo_administrativo_protocolo,
                      v.processo_extrajudicial_id AS processo_id, pe.protocolo AS processo_identificador,
                      v.data_vinculo, v.observacao, v.created_at
                 FROM processo_administrativo_extrajudicial v
                 JOIN processos_administrativos pa ON pa.id = v.processo_administrativo_id
                 JOIN processos_extrajudiciais pe ON pe.id = v.processo_extrajudicial_id'
            : 'SELECT v.id, v.processo_administrativo_id, pa.protocolo AS processo_administrativo_protocolo,
                      v.processo_judicial_id AS processo_id, pj.numero_cnj AS processo_identificador,
                      v.data_vinculo, v.observacao, v.created_at
                 FROM processo_administrativo_judicial v
                 JOIN processos_administrativos pa ON pa.id = v.processo_administrativo_id
                 JOIN processos_judiciais pj ON pj.id = v.processo_judicial_id';

        return $this->paginar($sql, 'v', ['v.created_at >= :d0'], null, $f);
    }

    /**
     * Todos os ids atuais de um recurso, para o consumidor detectar o que
     * foi excluido no SESJUR desde a ultima importacao.
     * @return int[]
     */
    public function ids(string $tabela): array
    {
        $permitidas = [
            'processos_administrativos', 'processos_judiciais', 'processos_extrajudiciais',
            'processo_administrativo_judicial', 'processo_administrativo_extrajudicial',
        ];
        if (!in_array($tabela, $permitidas, true)) {
            throw new \InvalidArgumentException('Tabela não exportável: ' . $tabela);
        }
        return array_map('intval', $this->db->query("SELECT id FROM `{$tabela}` ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    }

    // -----------------------------------------------------------------

    /**
     * Executa a listagem por cursor. Busca um registro a mais que o limite
     * para saber se ha proxima pagina.
     * @param string[] $alteracoes condicoes OR com os parametros :d0..:dN
     * @param array{0:string,1:string}|null $chave filtro pelo identificador de negocio
     * @return array{itens:array, tem_mais:bool}
     */
    private function paginar(string $select, string $alias, array $alteracoes, ?array $chave, array $f): array
    {
        $where = [];
        $params = [];

        if (($f['id'] ?? null) !== null) {
            $where[] = "{$alias}.id = :id";
            $params[':id'] = $f['id'];
        } elseif ($chave !== null) {
            $where[] = $chave[0];
            $params[':chave'] = $chave[1];
        } else {
            $where[] = "{$alias}.id > :apos";
            $params[':apos'] = $f['apos_id'];
            if ($f['atualizado_desde'] !== null) {
                $where[] = '(' . implode(' OR ', $alteracoes) . ')';
                foreach (array_keys($alteracoes) as $i) {
                    $params[":d{$i}"] = $f['atualizado_desde'];
                }
            }
        }

        $stmt = $this->db->prepare(
            $select . ' WHERE ' . implode(' AND ', $where) . " ORDER BY {$alias}.id LIMIT :limite"
        );
        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limite', $f['limite'] + 1, PDO::PARAM_INT);
        $stmt->execute();

        $itens = $stmt->fetchAll();
        $temMais = count($itens) > $f['limite'];
        return ['itens' => array_slice($itens, 0, $f['limite']), 'tem_mais' => $temMais];
    }

    /**
     * Registros filhos dos ids informados, agrupados pelo id do pai (a coluna
     * de agrupamento sai de cada item).
     * @param int[] $ids
     * @return array<int, array<int, array>>
     */
    private function filhos(string $sql, string $colunaPai, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->prepare(sprintf($sql, implode(',', array_fill(0, count($ids), '?'))));
        $stmt->execute($ids);

        $grupos = [];
        foreach ($stmt->fetchAll() as $linha) {
            $pai = (int)$linha[$colunaPai];
            unset($linha[$colunaPai]);
            $grupos[$pai][] = $linha;
        }
        return $grupos;
    }
}
