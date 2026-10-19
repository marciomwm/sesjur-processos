<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Prazo;
use App\Repositories\AssuntoProcessualRepository;
use App\Repositories\DesignacaoRepository;
use App\Repositories\ParteProcessualRepository;
use App\Repositories\PrioridadeRepository;
use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\ProcessoExtrajudicialRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Repositories\ProdutoSaudeRepository;
use App\Repositories\VinculoExtrajudicialRepository;
use App\Repositories\VinculoRepository;
use App\Validators\Normalizador;

/**
 * Gerador de Expedientes: catalogo de propriedades e valores de um
 * Processo Administrativo.
 *
 * O catalogo e a unica fonte das propriedades "do sistema" que o gerador
 * oferece ({{TOKEN}} no texto): atributos do Processo Administrativo e dos
 * Processos Judiciais e Extrajudiciais vinculados a ele. A pagina entrega o
 * catalogo ao JavaScript e o endpoint api/expediente-dados.php entrega os
 * valores — nada e gravado por aqui.
 *
 * Valor ausente no banco vira string vazia, nunca um texto fabricado
 * (ADR 0003): no documento a propriedade aparece como pendente.
 */
final class ExpedienteService
{
    /** Grupos do menu "Do catalogo", na ordem de exibicao. */
    public const GRUPOS = [
        'administrativo' => 'Processo Administrativo',
        'judicial'       => 'Processo Judicial relacionado',
        'extrajudicial'  => 'Processo Extrajudicial relacionado',
    ];

    /**
     * token => [chave, grupo, rotulo, tipo, ajuda].
     * `chave` e o nome do valor no pacote de dados; `tipo` e texto, textarea
     * ou data. O token nunca muda depois de publicado: os modelos salvos
     * pelos usuarios o citam no texto.
     */
    private const CATALOGO = [
        'PROCESSO_SIGADOC'    => ['processo_administrativo', 'administrativo', 'Protocolo do processo administrativo (SIGADOC)', 'texto', 'Protocolo do Processo Administrativo selecionado.'],
        'PA_DATA_ABERTURA'    => ['pa_data_abertura', 'administrativo', 'Data de abertura', 'data', 'Data de abertura do processo no SIGADOC.'],
        'PA_OBJETO'           => ['pa_objeto', 'administrativo', 'Objeto', 'textarea', 'Objeto do processo administrativo.'],
        'PA_PROCEDIMENTO'     => ['pa_procedimento', 'administrativo', 'Procedimento', 'texto', 'Classificação do tipo de trâmite do processo.'],
        'PA_SITUACAO'         => ['pa_situacao', 'administrativo', 'Situação', 'texto', 'Situação do processo no SIGADOC.'],
        'PA_SITUACAO_SIGADOC' => ['pa_situacao_sigadoc', 'administrativo', 'Situação (rótulo do SIGADOC)', 'texto', 'Rótulo oficial da situação, como exibido no SIGADOC.'],
        'PA_RESPONSAVEL'      => ['pa_responsavel', 'administrativo', 'Servidor responsável', 'texto', 'Agente público que detém o processo no SIGADOC.'],
        'PA_RESPONSAVEL_MATRICULA' => ['pa_responsavel_matricula', 'administrativo', 'Matrícula do responsável', 'texto', 'Matrícula do servidor responsável.'],
        'PA_LOTACAO'          => ['pa_lotacao', 'administrativo', 'Lotação (sigla)', 'texto', 'Sigla da lotação em que o processo se encontra.'],
        'PA_LOTACAO_NOME'     => ['pa_lotacao_nome', 'administrativo', 'Lotação (nome)', 'texto', 'Nome da lotação em que o processo se encontra.'],
        'PA_PRAZO'            => ['pa_prazo', 'administrativo', 'Prazo do processo', 'texto', 'Prazo próprio do processo.'],
        'PA_PRAZO_VIGENTE'    => ['pa_prazo_vigente', 'administrativo', 'Prazo vigente', 'texto', 'O prazo mais próximo entre o do processo e o das tarefas em aberto.'],
        'PA_DESIGNADOS'       => ['pa_designados', 'administrativo', 'Designados', 'texto', 'Usuários designados para acompanhar o processo.'],
        'PA_PRIORIDADES'      => ['pa_prioridades', 'administrativo', 'Prioridades', 'texto', 'Prioridades atribuídas ao processo.'],
        'PA_PRODUTOS_SAUDE'   => ['pa_produtos_saude', 'administrativo', 'Produtos de Saúde', 'textarea', 'Produtos de saúde relacionados ao processo.'],

        'PROCESSO_JUDICIAL'   => ['processo_judicial', 'judicial', 'Número CNJ do processo judicial', 'texto', 'Número CNJ do processo judicial escolhido.'],
        'PJ_TRIBUNAL'         => ['pj_tribunal', 'judicial', 'Tribunal', 'texto', 'Tribunal do processo judicial.'],
        'PJ_ORGAO_JULGADOR'   => ['pj_orgao_julgador', 'judicial', 'Órgão julgador', 'texto', 'Juízo ou vara do processo judicial.'],
        'PJ_COMPETENCIA'      => ['pj_competencia', 'judicial', 'Competência', 'texto', 'Competência do órgão julgador.'],
        'PJ_JURISDICAO'       => ['pj_jurisdicao', 'judicial', 'Jurisdição', 'texto', 'Jurisdição do processo judicial.'],
        'PJ_CLASSE'           => ['pj_classe', 'judicial', 'Classe judicial', 'texto', 'Classe do processo judicial.'],
        'PJ_ASSUNTO_PRINCIPAL' => ['pj_assunto_principal', 'judicial', 'Assunto principal', 'texto', 'Assunto processual principal.'],
        'PJ_ASSUNTOS'         => ['pj_assuntos', 'judicial', 'Assuntos (todos)', 'textarea', 'Todos os assuntos processuais, o principal primeiro.'],
        'PJ_POLO_ATIVO'       => ['pj_polo_ativo', 'judicial', 'Polo ativo', 'textarea', 'Partes do polo ativo.'],
        'PJ_POLO_PASSIVO'     => ['pj_polo_passivo', 'judicial', 'Polo passivo', 'textarea', 'Partes do polo passivo.'],
        'PJ_DATA_AUTUACAO'    => ['pj_data_autuacao', 'judicial', 'Data da autuação', 'data', 'Data da autuação original.'],
        'PJ_DATA_DISTRIBUICAO' => ['pj_data_distribuicao', 'judicial', 'Data da distribuição', 'data', 'Última distribuição informada pela fonte.'],
        'PJ_VALOR_CAUSA'      => ['pj_valor_causa', 'judicial', 'Valor da causa', 'texto', 'Valor da causa, em reais.'],
        'PJ_SITUACAO'         => ['pj_situacao', 'judicial', 'Situação do processo judicial', 'texto', 'Situação do processo judicial.'],
        'PJ_TODOS'            => ['pj_todos', 'judicial', 'Todos os processos judiciais vinculados', 'textarea', 'Números CNJ de todos os processos judiciais vinculados ao processo administrativo.'],

        'PROCESSO_EXTRAJUDICIAL' => ['processo_extrajudicial', 'extrajudicial', 'Protocolo do processo extrajudicial', 'texto', 'Protocolo na instituição de origem.'],
        'PE_DATA_EXPEDIENTE'  => ['pe_data_expediente', 'extrajudicial', 'Data do expediente', 'data', 'Data do expediente da instituição externa.'],
        'PE_INSTITUICAO'      => ['pe_instituicao', 'extrajudicial', 'Instituição externa', 'texto', 'Instituição de origem do processo extrajudicial.'],
        'PE_INSTITUICAO_SIGLA' => ['pe_instituicao_sigla', 'extrajudicial', 'Instituição externa (sigla)', 'texto', 'Sigla da instituição de origem.'],
        'PE_ORGAO'            => ['pe_orgao', 'extrajudicial', 'Órgão externo', 'texto', 'Órgão ou unidade da instituição externa.'],
        'PE_ASSUNTO'          => ['pe_assunto', 'extrajudicial', 'Assunto do processo extrajudicial', 'texto', 'Assunto do processo extrajudicial.'],
        'PE_DESCRICAO'        => ['pe_descricao', 'extrajudicial', 'Descrição do processo extrajudicial', 'textarea', 'Descrição do processo extrajudicial.'],
        'PE_SITUACAO'         => ['pe_situacao', 'extrajudicial', 'Situação do processo extrajudicial', 'texto', 'Situação do processo extrajudicial.'],
        'PE_TODOS'            => ['pe_todos', 'extrajudicial', 'Todos os processos extrajudiciais vinculados', 'textarea', 'Protocolos de todos os processos extrajudiciais vinculados ao processo administrativo.'],
    ];

    public function __construct(
        private readonly ProcessoAdministrativoRepository $administrativos = new ProcessoAdministrativoRepository(),
        private readonly ProcessoJudicialRepository $judiciais = new ProcessoJudicialRepository(),
        private readonly VinculoRepository $vinculos = new VinculoRepository(),
        private readonly VinculoExtrajudicialRepository $vinculosExtrajudiciais = new VinculoExtrajudicialRepository(),
        private readonly ProcessoExtrajudicialRepository $extrajudiciais = new ProcessoExtrajudicialRepository(),
        private readonly ParteProcessualRepository $partes = new ParteProcessualRepository(),
        private readonly AssuntoProcessualRepository $assuntos = new AssuntoProcessualRepository(),
        private readonly DesignacaoRepository $designacoes = new DesignacaoRepository(),
        private readonly PrioridadeRepository $prioridades = new PrioridadeRepository(),
        private readonly ProdutoSaudeRepository $produtos = new ProdutoSaudeRepository(),
    ) {
    }

    /**
     * Catalogo no formato consumido pelo gerador (assets/expediente/templates.js).
     * @return list<array{token:string,chave:string,grupo:string,label:string,tipo:string,ajuda:string}>
     */
    public static function catalogo(): array
    {
        $lista = [];
        foreach (self::CATALOGO as $token => [$chave, $grupo, $rotulo, $tipo, $ajuda]) {
            $lista[] = ['token' => $token, 'chave' => $chave, 'grupo' => $grupo, 'label' => $rotulo, 'tipo' => $tipo, 'ajuda' => $ajuda];
        }
        return $lista;
    }

    /**
     * Valores do catalogo para um Processo Administrativo: os dele e os de
     * cada Processo Judicial e Extrajudicial vinculado (o usuario escolhe, no
     * gerador, qual vinculado alimenta as propriedades PJ_ e PE_).
     *
     * @return array{processo:array,administrativo:array<string,string>,judiciais:list<array>,extrajudiciais:list<array>}|null
     */
    public function pacote(int $administrativoId): ?array
    {
        $pa = $this->administrativos->buscarPorId($administrativoId);
        if ($pa === null) {
            return null;
        }

        $judiciais = [];
        foreach ($this->vinculos->judiciaisDoAdministrativo($administrativoId) as $vinculo) {
            $pj = $this->judiciais->buscarPorId((int)$vinculo['id']);
            if ($pj !== null) {
                $judiciais[] = [
                    'id'      => (int)$pj['id'],
                    'rotulo'  => $pj['numero_cnj'],
                    'detalhe' => implode(' · ', array_filter([$pj['tribunal'], $pj['orgao_julgador'], $pj['classe_judicial']])),
                    'dados'   => $this->dadosJudicial($pj),
                ];
            }
        }

        $extrajudiciais = [];
        foreach ($this->vinculosExtrajudiciais->extrajudiciaisDoAdministrativo($administrativoId) as $vinculo) {
            $pe = $this->extrajudiciais->buscarPorId((int)$vinculo['id']);
            if ($pe !== null) {
                $extrajudiciais[] = [
                    'id'      => (int)$pe['id'],
                    'rotulo'  => $pe['protocolo'],
                    'detalhe' => implode(' · ', array_filter([$pe['instituicao_sigla'] ?: $pe['instituicao_nome'], $pe['orgao_nome'], $pe['assunto']])),
                    'dados'   => $this->dadosExtrajudicial($pe),
                ];
            }
        }

        return [
            'processo' => [
                'id'        => (int)$pa['id'],
                'protocolo' => $pa['protocolo'],
                'resumo'    => mb_strimwidth(self::texto($pa['objeto']), 0, 160, '…'),
            ],
            'administrativo' => $this->dadosAdministrativo($pa) + [
                'pj_todos' => self::enumerar(array_column($judiciais, 'rotulo')),
                'pe_todos' => self::enumerar(array_column($extrajudiciais, 'rotulo')),
            ],
            'judiciais'      => $judiciais,
            'extrajudiciais' => $extrajudiciais,
        ];
    }

    /** @return array<string,string> */
    private function dadosAdministrativo(array $pa): array
    {
        $id = (int)$pa['id'];
        $situacao = self::texto($pa['situacao']);

        return [
            'processo_administrativo'  => self::texto($pa['protocolo']),
            'pa_data_abertura'         => self::data($pa['data_abertura']),
            'pa_objeto'                => self::texto($pa['objeto']),
            // Catalogo; sem item vinculado, a classificacao anterior (texto livre).
            'pa_procedimento'          => self::texto($pa['procedimento_nome'] ?? $pa['procedimento']),
            'pa_situacao'              => Normalizador::SITUACOES[$situacao] ?? $situacao,
            'pa_situacao_sigadoc'      => self::texto($pa['situacao_descricao']),
            'pa_responsavel'           => self::texto($pa['responsavel_nome']),
            'pa_responsavel_matricula' => self::texto($pa['responsavel_matricula']),
            'pa_lotacao'               => self::texto($pa['responsavel_lotacao']),
            'pa_lotacao_nome'          => self::texto($pa['responsavel_lotacao_nome']),
            'pa_prazo'                 => self::prazo($pa['prazo'], $pa['prazo_hora']),
            'pa_prazo_vigente'         => self::prazo($pa['prazo_vigente'], $pa['prazo_vigente_hora']),
            'pa_designados'            => self::enumerar(array_column($this->designacoes->listarPorProcesso('administrativo', $id), 'nome')),
            'pa_prioridades'           => self::enumerar(array_column($this->prioridades->doProcesso($id), 'nome')),
            'pa_produtos_saude'        => self::enumerar(array_column($this->produtos->listarPorProcesso($id), 'descricao')),
        ];
    }

    /** @return array<string,string> */
    private function dadosJudicial(array $pj): array
    {
        $id = (int)$pj['id'];
        $assuntos = array_column($this->assuntos->listarPorProcesso($id), 'descricao');
        $porPolo = ['ATIVO' => [], 'PASSIVO' => []];
        foreach ($this->partes->listarPorProcesso($id) as $parte) {
            if (isset($porPolo[$parte['polo']])) {
                $porPolo[$parte['polo']][] = $parte['nome'];
            }
        }

        return [
            'processo_judicial'    => self::texto($pj['numero_cnj']),
            'pj_tribunal'          => self::texto($pj['tribunal']),
            'pj_orgao_julgador'    => self::texto($pj['orgao_julgador']),
            'pj_competencia'       => self::texto($pj['competencia']),
            'pj_jurisdicao'        => self::texto($pj['jurisdicao']),
            'pj_classe'            => self::texto($pj['classe_judicial']),
            // Listados com o principal primeiro (ver AssuntoProcessualRepository).
            'pj_assunto_principal' => self::texto($pj['assunto_principal']) ?: self::texto($assuntos[0] ?? null),
            'pj_assuntos'          => self::enumerar($assuntos),
            'pj_polo_ativo'        => self::enumerar($porPolo['ATIVO']),
            'pj_polo_passivo'      => self::enumerar($porPolo['PASSIVO']),
            'pj_data_autuacao'     => self::data($pj['data_autuacao']),
            'pj_data_distribuicao' => self::data($pj['data_distribuicao']),
            'pj_valor_causa'       => $pj['valor_causa'] === null ? '' : moeda_br($pj['valor_causa']),
            'pj_situacao'          => self::texto($pj['situacao']),
        ];
    }

    /** @return array<string,string> */
    private function dadosExtrajudicial(array $pe): array
    {
        $situacao = self::texto($pe['situacao']);

        return [
            'processo_extrajudicial' => self::texto($pe['protocolo']),
            'pe_data_expediente'     => self::data($pe['data_expediente']),
            'pe_instituicao'         => self::texto($pe['instituicao_nome']),
            'pe_instituicao_sigla'   => self::texto($pe['instituicao_sigla']),
            'pe_orgao'               => self::texto($pe['orgao_nome']),
            'pe_assunto'             => self::texto($pe['assunto']),
            'pe_descricao'           => self::texto($pe['descricao']),
            'pe_situacao'            => ProcessoExtrajudicialService::SITUACOES[$situacao] ?? $situacao,
        ];
    }

    private static function texto(mixed $valor): string
    {
        return trim((string)($valor ?? ''));
    }

    /** Data ISO -> dd/mm/aaaa; ausente ou invalida -> ''. */
    private static function data(?string $iso): string
    {
        $data = data_br($iso);
        return $data === '—' ? '' : $data;
    }

    private static function prazo(?string $data, ?string $hora): string
    {
        $prazo = Prazo::formatar($data, $hora);
        return $prazo === '—' ? '' : $prazo;
    }

    /**
     * Enumeracao como se escreve num expediente: "A", "A e B", "A, B e C".
     * @param array<int, mixed> $itens
     */
    public static function enumerar(array $itens): string
    {
        $itens = array_values(array_unique(array_filter(array_map(self::texto(...), $itens), 'strlen')));
        if (count($itens) <= 1) {
            return $itens[0] ?? '';
        }
        $ultimo = array_pop($itens);
        return implode(', ', $itens) . ' e ' . $ultimo;
    }
}
