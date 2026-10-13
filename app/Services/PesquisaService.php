<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Validators\CnjValidator;
use App\Validators\ProtocoloValidator;
use PDO;

/**
 * Pesquisa geral do SESJUR (campo da barra superior): um termo procurado
 * nos processos administrativos, judiciais e extrajudiciais, nas partes, nas acoes, nas
 * solicitacoes, nas anotacoes e, para
 * administradores, nos usuarios e agentes publicos. Cada grupo traz os primeiros resultados e
 * o total encontrado.
 *
 * Nomes de tabela e coluna vem das definicoes fixas abaixo, nunca da entrada.
 */
final class PesquisaService
{
    public const TERMO_MINIMO = 2;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::conexao();
    }

    /**
     * Identificador exato (protocolo ou CNJ, com ou sem mascara), para abrir
     * a ficha direto em vez da lista de resultados.
     * @return array{tipo:string,id:int}|null
     */
    public function identificadorExato(string $termo): ?array
    {
        $cnj = CnjValidator::normalizar($termo);
        if ($cnj !== null) {
            $stmt = $this->db->prepare('SELECT id FROM processos_judiciais WHERE numero_cnj = :n');
            $stmt->execute([':n' => $cnj]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return ['tipo' => 'judicial', 'id' => (int)$id];
            }
        }
        $protocolo = ProtocoloValidator::normalizar($termo);
        if ($protocolo !== null && ProtocoloValidator::valido($protocolo)) {
            $stmt = $this->db->prepare('SELECT id FROM processos_administrativos WHERE protocolo = :p');
            $stmt->execute([':p' => $protocolo]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return ['tipo' => 'administrativo', 'id' => (int)$id];
            }
        }
        return null;
    }

    /**
     * @return array<string, array{titulo:string, total:int, itens:list<array{rotulo:string,detalhe:string,url:string,marca:?string}>, verTodos:?string}>
     */
    public function pesquisar(string $termo, int $limite = 8, bool $incluirUsuarios = false): array
    {
        $termo = trim($termo);
        if (mb_strlen($termo) < self::TERMO_MINIMO) {
            return [];
        }
        // CNJ digitado sem mascara tambem encontra o numero gravado com mascara.
        $busca = CnjValidator::normalizar($termo) ?? $termo;
        $like = '%' . addcslashes($busca, '%_\\') . '%';

        $grupos = [
            'administrativos' => $this->grupo(
                'Processos Administrativos',
                'processos_administrativos pa',
                ['pa.protocolo', 'pa.objeto', 'pa.procedimento', 'pa.responsavel_nome'],
                'pa.id, pa.protocolo, pa.objeto, pa.procedimento, pa.ativo',
                'pa.protocolo',
                $like,
                $limite,
                static fn(array $r): array => [
                    'rotulo'  => $r['protocolo'],
                    'detalhe' => self::resumo($r['procedimento'] ? $r['procedimento'] . ' · ' . $r['objeto'] : $r['objeto']),
                    'url'     => url('processo-administrativo.php?id=' . $r['id']),
                    'marca'   => (int)$r['ativo'] === 1 ? 'Ativo' : null,
                ],
                'processos-administrativos.php?busca=' . rawurlencode($termo)
            ),
            'judiciais' => $this->grupo(
                'Processos Judiciais',
                'processos_judiciais pj',
                ['pj.numero_cnj', 'pj.classe_judicial', 'pj.assunto_principal', 'pj.resumo_partes', 'pj.orgao_julgador', 'pj.jurisdicao'],
                'pj.id, pj.numero_cnj, pj.classe_judicial, pj.resumo_partes, pj.orgao_julgador',
                'pj.numero_cnj',
                $like,
                $limite,
                static fn(array $r): array => [
                    'rotulo'  => $r['numero_cnj'],
                    'detalhe' => self::resumo(implode(' · ', array_filter([$r['resumo_partes'], $r['classe_judicial'], $r['orgao_julgador']]))),
                    'url'     => url('processo-judicial.php?id=' . $r['id']),
                    'marca'   => null,
                ],
                'processos-judiciais.php?busca=' . rawurlencode($termo)
            ),
            'extrajudiciais' => $this->grupo(
                'Processos Extrajudiciais',
                'processos_extrajudiciais pe JOIN instituicoes_externas i ON i.id = pe.instituicao_externa_id',
                ['pe.protocolo', 'pe.assunto', 'pe.descricao', 'i.sigla', 'i.nome'],
                'pe.id, pe.protocolo, pe.assunto, pe.situacao, i.sigla, i.nome AS instituicao',
                'pe.data_expediente DESC',
                $like,
                $limite,
                static fn(array $r): array => [
                    'rotulo'  => $r['protocolo'],
                    'detalhe' => self::resumo(($r['sigla'] ?: $r['instituicao']) . ' · ' . $r['assunto']),
                    'url'     => url('processo-extrajudicial.php?id=' . $r['id']),
                    'marca'   => ProcessoExtrajudicialService::SITUACOES[$r['situacao']] ?? null,
                ],
                'processos-extrajudiciais.php?busca=' . rawurlencode($termo)
            ),
            'partes' => $this->grupo(
                'Partes processuais',
                'partes_processuais pp JOIN processos_judiciais pj ON pj.id = pp.processo_judicial_id',
                ['pp.nome', 'pp.documento'],
                'pp.nome, pp.documento, pp.polo, pj.id AS processo_id, pj.numero_cnj',
                'pp.nome, pj.numero_cnj',
                $like,
                $limite,
                static fn(array $r): array => [
                    'rotulo'  => $r['nome'],
                    'detalhe' => self::resumo(implode(' · ', array_filter([$r['documento'], 'Polo ' . strtolower($r['polo']), $r['numero_cnj']]))),
                    'url'     => url('processo-judicial.php?id=' . $r['processo_id']),
                    'marca'   => null,
                ],
                null
            ),
            'acoes' => $this->grupo(
                'Ações',
                'processo_administrativo_acoes a JOIN processos_administrativos pa ON pa.id = a.processo_administrativo_id',
                ['a.descricao', 'a.observacao'],
                'a.id, a.descricao, a.status, a.prazo, pa.id AS processo_id, pa.protocolo',
                "a.status IN ('CONCLUIDA', 'CANCELADA'), a.prazo IS NULL, a.prazo",
                $like,
                $limite,
                static fn(array $r): array => [
                    'rotulo'  => self::resumo($r['descricao'], 90),
                    'detalhe' => $r['protocolo'] . ($r['prazo'] ? ' · prazo ' . data_br($r['prazo']) : ''),
                    'url'     => url('processo-administrativo.php?id=' . $r['processo_id']) . '#acao-' . $r['id'],
                    'marca'   => AcaoService::STATUS[$r['status']] ?? null,
                ],
                'acoes.php?busca=' . rawurlencode($termo)
            ),
            'solicitacoes' => $this->grupo(
                'Solicitações',
                'processo_administrativo_solicitacoes s JOIN processos_administrativos pa ON pa.id = s.processo_administrativo_id',
                ['s.assunto', 's.expediente'],
                's.id, s.assunto, s.expediente, s.status, pa.id AS processo_id, pa.protocolo',
                "s.status <> 'AGUARDANDO', s.data_solicitacao DESC",
                $like,
                $limite,
                static fn(array $r): array => [
                    'rotulo'  => self::resumo($r['assunto'], 90),
                    'detalhe' => $r['expediente'] . ' · ' . $r['protocolo'],
                    'url'     => url('processo-administrativo.php?id=' . $r['processo_id']) . '#solicitacao-' . $r['id'],
                    'marca'   => SolicitacaoService::STATUS[$r['status']] ?? null,
                ],
                'solicitacoes.php?busca=' . rawurlencode($termo)
            ),
            'anotacoes' => $this->grupo(
                'Anotações',
                'processo_administrativo_anotacoes n JOIN processos_administrativos pa ON pa.id = n.processo_administrativo_id',
                ['n.texto'],
                'n.id, n.texto, n.created_at, pa.id AS processo_id, pa.protocolo',
                'n.created_at DESC',
                $like,
                $limite,
                static fn(array $r): array => [
                    'rotulo'  => self::resumo($r['texto'], 90),
                    'detalhe' => $r['protocolo'] . ' · ' . datahora_br($r['created_at']),
                    'url'     => url('processo-administrativo.php?id=' . $r['processo_id']) . '#anotacao-' . $r['id'],
                    'marca'   => null,
                ],
                'anotacoes.php?busca=' . rawurlencode($termo)
            ),
        ];

        if ($incluirUsuarios) {
            $grupos['usuarios'] = $this->grupo(
                'Usuários',
                'usuarios u',
                ['u.nome', 'u.email', 'u.matricula'],
                'u.id, u.nome, u.email, u.matricula, u.ativo',
                'u.nome',
                $like,
                $limite,
                static fn(array $r): array => [
                    'rotulo'  => $r['nome'],
                    'detalhe' => $r['email'] . ' · ' . $r['matricula'],
                    'url'     => url('usuario.php?id=' . $r['id']),
                    'marca'   => (int)$r['ativo'] === 1 ? null : 'Inativo',
                ],
                'usuarios.php?busca=' . rawurlencode($termo)
            );
            $grupos['agentes'] = $this->grupo(
                'Agentes Públicos',
                'agentes_publicos a JOIN lotacoes l ON l.id = a.lotacao_id',
                ['a.nome', 'a.nome_social', 'a.matricula', 'a.email_institucional'],
                'a.id, a.nome, a.matricula, a.situacao, l.nome AS lotacao',
                'a.nome',
                $like,
                $limite,
                static fn(array $r): array => [
                    'rotulo'  => $r['nome'],
                    'detalhe' => self::resumo(implode(' · ', array_filter([$r['matricula'], $r['lotacao']]))),
                    'url'     => url('agente-publico.php?id=' . $r['id']),
                    'marca'   => $r['situacao'] === 'ATIVO' ? null : (AgentePublicoService::SITUACOES[$r['situacao']] ?? null),
                ],
                'agentes-publicos.php?busca=' . rawurlencode($termo)
            );
        }

        return array_filter($grupos, static fn(array $g): bool => $g['total'] > 0);
    }

    /**
     * @param list<string> $colunas colunas pesquisadas por LIKE
     * @param callable(array):array $formatar
     */
    private function grupo(
        string $titulo,
        string $origem,
        array $colunas,
        string $selecao,
        string $ordem,
        string $like,
        int $limite,
        callable $formatar,
        ?string $verTodos
    ): array {
        // Um marcador por coluna: prepared statements nativos nao repetem nomes.
        $condicoes = [];
        $params = [];
        foreach ($colunas as $i => $coluna) {
            $condicoes[] = "{$coluna} LIKE :t{$i}";
            $params[":t{$i}"] = $like;
        }
        $where = '(' . implode(' OR ', $condicoes) . ')';

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$origem} WHERE {$where}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $itens = [];
        if ($total > 0) {
            $stmt = $this->db->prepare("SELECT {$selecao} FROM {$origem} WHERE {$where} ORDER BY {$ordem} LIMIT :limite");
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmt->execute();
            $itens = array_map($formatar, $stmt->fetchAll());
        }

        return [
            'titulo'   => $titulo,
            'total'    => $total,
            'itens'    => $itens,
            'verTodos' => $verTodos !== null && $total > count($itens) ? url($verTodos) : null,
        ];
    }

    private static function resumo(?string $texto, int $max = 140): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', (string)$texto) ?? '');
        return mb_strimwidth($texto, 0, $max, '…');
    }
}
