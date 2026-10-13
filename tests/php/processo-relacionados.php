<?php
declare(strict_types=1);

/**
 * Prioridades, Produtos de Saude (SIGTAP) e Processos Extrajudiciais
 * ligados ao Processo Administrativo.
 *
 * @var \Tests\Runner $t
 */

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\OrgaoExternoRepository;
use App\Repositories\PrioridadeRepository;
use App\Repositories\ProcessoExtrajudicialRepository;
use App\Repositories\ProdutoSaudeRepository;
use App\Repositories\SigtapRepository;
use App\Repositories\VinculoExtrajudicialRepository;
use App\Services\PesquisaService;
use App\Services\PrioridadeService;
use App\Services\ProcessoAdministrativoService;
use App\Services\ProcessoExtrajudicialService;
use App\Services\ProdutoSaudeService;
use App\Services\TabelaApoioService;
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
        'nome'       => "Pessoa Relacionados {$seq}",
        'email'      => "relacionados{$seq}@ses.mt.gov.br",
        'matricula'  => 'REL-' . $seq,
        'cargo_id'   => (string)$db->query("SELECT id FROM cargos WHERE nome = 'Analista'")->fetchColumn(),
        'lotacao_id' => (string)$db->query("SELECT id FROM lotacoes WHERE nome = 'Unidade Jurídica'")->fetchColumn(),
        'perfil'     => $perfil,
        'data_nascimento' => '',
    ]);
};

$acoesNoProcesso = static function (int $processoId): array {
    $contagem = array_count_values(array_column((new AuditoriaRepository())->listarPorEntidade('processos_administrativos', $processoId, 200), 'acao'));
    ksort($contagem);
    return $contagem;
};

// ---------------------------------------------------------------------
$t->grupo('Prioridades — regras');

$t->teste('nível de 1 a 5, sempre exibido com número e rótulo', function ($t) {
    $t->igual(PrioridadeService::nivel('3'), 3, 'texto numérico');
    $t->igual(PrioridadeService::nivel(5), 5, 'inteiro');
    foreach (['0', '6', '-1', '2.5', 'alta', '', null] as $invalido) {
        $t->igual(PrioridadeService::nivel($invalido), null, 'inválido: ' . var_export($invalido, true));
    }
    $t->igual(PrioridadeService::rotuloNivel(5), 'Nível 5 · Máxima', 'rótulo textual');
    $t->igual(PrioridadeService::classeBadge(1), 'badge--neutro', 'cor é só complemento');
});

$t->teste('cadastro: validação, nome único, somente administrador, auditoria', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo) {
    $exigirBanco($t);
    $servico = new PrioridadeService();
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['nome' => '', 'nivel' => '9']), 'dados inválidos');
    $t->afirmar(isset($e->erros()['nome'], $e->erros()['nivel']), 'erros em nome e nível');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['nome' => 'idoso', 'nivel' => '3']), 'nome repetido (sem diferenciar maiúsculas)');
    $t->igual($e->erros()['nome'], PrioridadeService::MSG_NOME_DUPLICADO, 'mensagem de duplicidade');

    $usuario = $novoUsuario(Perfil::USUARIO);
    $entrarComo($usuario);
    $t->lanca(AcessoNegadoException::class, fn() => $servico->criar(['nome' => 'Gestante', 'nivel' => '3']), 'usuário comum não cadastra');
    $entrarComo(null);

    $id = $servico->criar(['nome' => '  Gestante ', 'nivel' => '4', 'descricao' => "Paciente gestante\n"]);
    $p = (new PrioridadeRepository())->buscarPorId($id);
    $t->igual([$p['nome'], (int)$p['nivel'], $p['descricao']], ['Gestante', 4, 'Paciente gestante'], 'normalização');
    $servico->atualizar($id, ['nome' => 'Gestante', 'nivel' => '3', 'descricao' => 'Paciente gestante']);
    $t->afirmar($servico->alterarStatus($id, false), 'inativada');
    $t->afirmar(!$servico->alterarStatus($id, false), 'já estava inativa');
    $acoes = array_column((new AuditoriaRepository())->listarPorEntidade('prioridades', $id), 'acao');
    sort($acoes);
    $t->igual($acoes, ['PRIORIDADE_CRIADA', 'PRIORIDADE_EDITADA', 'PRIORIDADE_INATIVADA'], 'auditoria da prioridade');
});

$t->teste('atribuição ao processo: sem repetição, só ativas, remoção auditada e listagem', function ($t) use ($exigirBanco, $acoesNoProcesso) {
    $exigirBanco($t);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/12345']);
    $repo = new PrioridadeRepository();
    $servico = new PrioridadeService();
    $idoso = (int)Database::conexao()->query("SELECT id FROM prioridades WHERE nome = 'Idoso'")->fetchColumn();
    $judicial = (int)Database::conexao()->query("SELECT id FROM prioridades WHERE nome = 'Determinação Judicial'")->fetchColumn();

    $servico->atribuir(['processo_id' => $pid, 'prioridade_id' => (string)$idoso, 'observacao' => 'Paciente com 72 anos']);
    $servico->atribuir(['processo_id' => $pid, 'prioridade_id' => (string)$judicial]);
    $e = $t->lanca(ValidationException::class, fn() => $servico->atribuir(['processo_id' => $pid, 'prioridade_id' => (string)$idoso]), 'repetida');
    $t->contem($e->erros()['prioridade_id'], 'já possui', 'mensagem de repetição');
    $t->lanca(ValidationException::class, fn() => $servico->atribuir(['processo_id' => $pid, 'prioridade_id' => '999999']), 'inexistente');
    $t->lanca(ValidationException::class, fn() => $servico->atribuir(['processo_id' => 999999, 'prioridade_id' => (string)$idoso]), 'processo inexistente');

    $inativa = $servico->criar(['nome' => 'Critério antigo', 'nivel' => '1']);
    $servico->alterarStatus($inativa, false);
    $e = $t->lanca(ValidationException::class, fn() => $servico->atribuir(['processo_id' => $pid, 'prioridade_id' => (string)$inativa]), 'inativa');
    $t->contem($e->erros()['prioridade_id'], 'inativa', 'mensagem de inativa');

    $doProcesso = $repo->doProcesso($pid);
    $t->igual(array_column($doProcesso, 'nome'), ['Determinação Judicial', 'Idoso'], 'da mais alta para a mais baixa');
    $t->igual($doProcesso[1]['observacao'], 'Paciente com 72 anos', 'observação da atribuição');

    $servico->remover($pid, $idoso);
    $t->lanca(ValidationException::class, fn() => $servico->remover($pid, $idoso), 'remover o que não tem');
    $t->igual(array_column($repo->doProcesso($pid), 'nome'), ['Determinação Judicial'], 'removida do processo');
    $t->afirmar($repo->buscarPorId($idoso) !== null, 'a prioridade continua cadastrada');
    $t->igual($acoesNoProcesso($pid), ['PRIORIDADE_ATRIBUIDA' => 2, 'PRIORIDADE_REMOVIDA' => 1], 'auditoria no processo');

    $lista = $repo->listar(['nivel' => 5, 'status' => 'ativos'], 1, 50);
    $t->afirmar(in_array('Determinação Judicial', array_column($lista->itens, 'nome'), true), 'filtro por nível');
    $t->afirmar(!in_array('Idoso', array_column($lista->itens, 'nome'), true), 'nível 3 fora do filtro');
    $t->igual($repo->listar(['busca' => 'antigo', 'status' => 'inativos'], 1, 50)->total, 1, 'filtro por nome e status');

    (new ProcessoAdministrativoService())->excluir($pid);
    $t->igual($repo->totalProcessos($judicial), 0, 'excluir o processo remove as atribuições');
});

// ---------------------------------------------------------------------
$t->grupo('Produtos de Saúde — SIGTAP');

$t->teste('código SIGTAP: 10 dígitos, com ou sem máscara', function ($t) {
    $t->igual(ProdutoSaudeService::codigoSigtap('03.01.01.007-2'), '0301010072', 'com máscara');
    $t->igual(ProdutoSaudeService::codigoSigtap('0301010072'), '0301010072', 'só dígitos');
    $t->igual(ProdutoSaudeService::codigoSigtap('03.01.01.007'), null, '9 dígitos');
    $t->igual(ProdutoSaudeService::formatarSigtap('0301010072'), '03.01.01.007-2', 'formatação');
});

$t->teste('inclusão: validação, item sem repetição e classificação SIGTAP reaproveitada', function ($t) use ($exigirBanco, $acoesNoProcesso) {
    $exigirBanco($t);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/12346']);
    $servico = new ProdutoSaudeService();
    $repo = new ProdutoSaudeRepository();

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['processo_id' => $pid, 'categoria' => 'X', 'descricao' => '']), 'dados inválidos');
    $t->afirmar(isset($e->erros()['categoria'], $e->erros()['descricao']), 'erros em categoria e descrição');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['processo_id' => $pid, 'categoria' => 'EXAME', 'descricao' => 'Tomografia', 'sigtap_codigo' => '123']), 'código curto');
    $t->contem($e->erros()['sigtap_codigo'], '10 dígitos', 'mensagem do código');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['processo_id' => $pid, 'categoria' => 'EXAME', 'descricao' => 'Tomografia', 'sigtap_codigo' => '02.06.01.007-9']), 'código novo sem descrição');
    $t->afirmar(isset($e->erros()['sigtap_nome']), 'pede a descrição oficial');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['processo_id' => $pid, 'categoria' => 'EXAME', 'descricao' => 'Tomografia', 'sigtap_nome' => 'Tomografia']), 'descrição sem código');
    $t->afirmar(isset($e->erros()['sigtap_codigo']), 'pede o código');

    $servico->criar(['processo_id' => $pid, 'categoria' => 'MEDICAMENTO', 'descricao' => 'Rituximabe 500 mg']);
    $idExame = $servico->criar([
        'processo_id' => $pid, 'categoria' => 'EXAME', 'descricao' => 'Tomografia computadorizada de tórax',
        'sigtap_codigo' => '02.06.02.003-1', 'sigtap_nome' => 'Tomografia computadorizada de tórax',
        'sigtap_grupo_nome' => 'Procedimentos com finalidade diagnóstica',
    ]);
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['processo_id' => $pid, 'categoria' => 'MEDICAMENTO', 'descricao' => 'rituximabe 500 MG']), 'mesmo item');
    $t->contem($e->erros()['descricao'], 'já está relacionado', 'mensagem de repetição');
    $servico->criar(['processo_id' => $pid, 'categoria' => 'PROCEDIMENTO', 'descricao' => 'Rituximabe 500 mg']);

    $sigtap = (new SigtapRepository())->buscarPorCodigo('0206020031');
    $t->igual([$sigtap['origem'], $sigtap['grupo_codigo'], $sigtap['subgrupo_codigo'], $sigtap['forma_organizacao_codigo']], ['MANUAL', '02', '06', '02'], 'estrutura derivada do código');
    $t->igual($sigtap['grupo_nome'], 'Procedimentos com finalidade diagnóstica', 'grupo informado');

    // Outro processo reaproveita o código sem repetir a descrição e completa a estrutura.
    $pid2 = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/12347']);
    $idOutro = $servico->criar(['processo_id' => $pid2, 'categoria' => 'EXAME', 'descricao' => 'TC de tórax', 'sigtap_codigo' => '0206020031', 'sigtap_subgrupo_nome' => 'Diagnóstico por tomografia']);
    $t->igual((int)$repo->buscarPorId($idOutro)['sigtap_procedimento_id'], (int)$sigtap['id'], 'mesmo procedimento SIGTAP');
    $t->igual((new SigtapRepository())->buscarPorCodigo('0206020031')['subgrupo_nome'], 'Diagnóstico por tomografia', 'estrutura completada');
    $t->igual((int)Database::conexao()->query("SELECT COUNT(*) FROM sigtap_procedimentos WHERE codigo = '0206020031'")->fetchColumn(), 1, 'sem duplicar o código');

    $servico->atualizar($idExame, ['categoria' => 'EXAME', 'descricao' => 'Tomografia de tórax', 'sigtap_codigo' => '0206020031']);
    $t->igual($repo->buscarPorId($idExame)['descricao'], 'Tomografia de tórax', 'edição');
    $servico->atualizar($idExame, ['categoria' => 'EXAME', 'descricao' => 'Tomografia de tórax', 'sigtap_codigo' => '']);
    $t->igual($repo->buscarPorId($idExame)['sigtap_procedimento_id'], null, 'classificação retirada');
    $t->igual(array_column($repo->listarPorProcesso($pid), 'categoria'), ['MEDICAMENTO', 'PROCEDIMENTO', 'EXAME'], 'vários produtos no processo');
    $t->igual($acoesNoProcesso($pid), ['PRODUTO_SAUDE_CRIADO' => 3, 'PRODUTO_SAUDE_EDITADO' => 2], 'auditoria no processo');

    (new ProcessoAdministrativoService())->excluir($pid);
    $t->igual($repo->buscarPorId($idExame), null, 'excluir o processo exclui os produtos');
    $t->afirmar((new SigtapRepository())->buscarPorCodigo('0206020031') !== null, 'a classificação SIGTAP permanece');
});

$t->teste('permissão: administrador, gestor ou quem incluiu', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo) {
    $exigirBanco($t);
    $autor  = $novoUsuario(Perfil::USUARIO);
    $colega = $novoUsuario(Perfil::USUARIO);
    $gestor = $novoUsuario(Perfil::GESTOR);
    $pid = (new ProcessoAdministrativoService())->criar(['protocolo' => 'SES-PRO-2026/12348']);
    $servico = new ProdutoSaudeService();

    $entrarComo($autor);
    $id = $servico->criar(['processo_id' => $pid, 'categoria' => 'ORTESE', 'descricao' => 'Órtese de joelho']);
    $t->igual((int)(new ProdutoSaudeRepository())->buscarPorId($id)['criado_por'], $autor, 'autoria');
    $entrarComo($colega);
    $e = $t->lanca(AcessoNegadoException::class, fn() => $servico->excluir($id), 'colega não exclui');
    $t->igual($e->getMessage(), ProdutoSaudeService::MSG_SEM_PERMISSAO, 'mensagem');
    $entrarComo($gestor);
    $servico->atualizar($id, ['categoria' => 'ORTESE', 'descricao' => 'Órtese articulada de joelho']);
    $t->igual((int)(new ProdutoSaudeRepository())->buscarPorId($id)['atualizado_por'], $gestor, 'quem alterou por último');
    $entrarComo($autor);
    $servico->excluir($id);
    $entrarComo(null);
});

// ---------------------------------------------------------------------
$t->grupo('Processos Extrajudiciais');

$t->teste('órgão externo: nome único dentro da instituição (tabela de apoio com pai)', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $db = Database::conexao();
    $mp  = (string)$db->query("SELECT id FROM instituicoes_externas WHERE sigla = 'MP-MT'")->fetchColumn();
    $dpe = (string)$db->query("SELECT id FROM instituicoes_externas WHERE sigla = 'DPE-MT'")->fetchColumn();
    $servico = new TabelaApoioService('orgaos_externos');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['nome' => 'Núcleo X']), 'sem instituição');
    $t->afirmar(isset($e->erros()['instituicao_externa_id']), 'exige a instituição');
    $servico->criar(['nome' => '2ª Promotoria de Justiça de Cuiabá', 'instituicao_externa_id' => $mp]);
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['nome' => '2ª promotoria de justiça de cuiabá', 'instituicao_externa_id' => $mp]), 'repetido na instituição');
    $t->contem($e->erros()['nome'], 'nesta instituição externa', 'mensagem com a instituição');
    $servico->criar(['nome' => '2ª Promotoria de Justiça de Cuiabá', 'instituicao_externa_id' => $dpe]);
    $lista = (new OrgaoExternoRepository())->listar(['pai_id' => (int)$mp, 'busca' => '2ª Promotoria']);
    $t->igual(count($lista), 1, 'filtro pela instituição');
    $t->igual($lista[0]['pai_sigla'], 'MP-MT', 'sigla da instituição na listagem');
});

$t->teste('cadastro: validação, órgão da mesma instituição, órgão novo e protocolo por instituição', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $db = Database::conexao();
    $mp  = (string)$db->query("SELECT id FROM instituicoes_externas WHERE sigla = 'MP-MT'")->fetchColumn();
    $tce = (string)$db->query("SELECT id FROM instituicoes_externas WHERE sigla = 'TCE-MT'")->fetchColumn();
    $orgaoTce = (string)$db->query("SELECT id FROM orgaos_externos WHERE instituicao_externa_id = {$tce}")->fetchColumn();
    $servico = new ProcessoExtrajudicialService();
    $base = ['protocolo' => 'SIMP 000123-001/2026', 'data_expediente' => '2026-09-30', 'instituicao_externa_id' => $mp,
             'assunto' => 'Fornecimento de medicamento oncológico', 'situacao' => 'EM_ANDAMENTO'];

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['protocolo' => '', 'data_expediente' => '2999-01-01', 'instituicao_externa_id' => '999']), 'dados inválidos');
    $t->afirmar(isset($e->erros()['protocolo'], $e->erros()['instituicao_externa_id'], $e->erros()['assunto']), 'obrigatórios');
    $t->igual($e->erros()['data_expediente'], 'A data não pode ser futura.', 'data futura');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($base + ['orgao_externo_id' => $orgaoTce]), 'órgão de outra instituição');
    $t->contem($e->erros()['orgao_externo_id'], 'não pertence', 'mensagem do órgão');

    $id = $servico->criar($base + ['orgao_novo' => '3ª Promotoria de Justiça de Várzea Grande', 'descricao' => "Linha 1\nLinha 2"]);
    $pe = (new ProcessoExtrajudicialRepository())->buscarPorId($id);
    $t->igual($pe['orgao_nome'], '3ª Promotoria de Justiça de Várzea Grande', 'órgão criado no cadastro');
    $t->igual($pe['descricao'], "Linha 1\nLinha 2", 'descrição com quebras');
    $id2 = $servico->criar(['protocolo' => 'SIMP 000124-001/2026', 'orgao_novo' => '3ª Promotoria de Justiça de Várzea Grande'] + $base);
    $t->igual((new ProcessoExtrajudicialRepository())->buscarPorId($id2)['orgao_externo_id'], $pe['orgao_externo_id'], 'órgão de mesmo nome reaproveitado');

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($base), 'protocolo repetido na instituição');
    $t->igual($e->erros()['protocolo'], ProcessoExtrajudicialService::MSG_PROTOCOLO_DUPLICADO, 'mensagem de duplicidade');
    $servico->criar(['instituicao_externa_id' => $tce] + $base);
    $t->igual((int)$db->query("SELECT COUNT(*) FROM processos_extrajudiciais WHERE protocolo = 'SIMP 000123-001/2026'")->fetchColumn(), 2, 'mesmo protocolo em outra instituição');

    $servico->atualizar($id, ['situacao' => 'RESPONDIDO'] + $base + ['orgao_externo_id' => (string)$pe['orgao_externo_id']]);
    $t->igual((new ProcessoExtrajudicialRepository())->buscarPorId($id)['situacao'], 'RESPONDIDO', 'situação alterada');
    $acoes = array_column((new AuditoriaRepository())->listarPorEntidade('processos_extrajudiciais', $id), 'acao');
    $t->igual($acoes, ['PROCESSO_EXTRAJUDICIAL_EDITADO', 'PROCESSO_EXTRAJUDICIAL_CRIADO'], 'auditoria do extrajudicial');
});

$t->teste('vínculo N:N com o processo administrativo, nos dois sentidos, auditado', function ($t) use ($exigirBanco, $acoesNoProcesso) {
    $exigirBanco($t);
    $db = Database::conexao();
    $dpe = (string)$db->query("SELECT id FROM instituicoes_externas WHERE sigla = 'DPE-MT'")->fetchColumn();
    $paService = new ProcessoAdministrativoService();
    $pa1 = $paService->criar(['protocolo' => 'SES-PRO-2026/12351']);
    $pa2 = $paService->criar(['protocolo' => 'SES-PRO-2026/12352']);
    $servico = new ProcessoExtrajudicialService();
    $vinculos = new VinculoExtrajudicialRepository();
    $dados = ['protocolo' => 'PADAC 77/2026', 'data_expediente' => '30/09/2026', 'instituicao_externa_id' => $dpe, 'assunto' => 'Cirurgia ortopédica'];

    // Um extrajudicial repercutindo em dois administrativos.
    $pe = $servico->criar($dados + ['administrativos_ids' => [$pa1, $pa2, $pa2]]);
    $t->igual($vinculos->idsAdministrativos($pe), [$pa1, $pa2], 'vinculado aos dois, sem repetir');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['protocolo' => 'PADAC 78/2026'] + $dados + ['administrativos_ids' => [999999]]), 'administrativo inexistente');
    $t->afirmar(isset($e->erros()['administrativos']), 'erro nos vínculos');

    // Um administrativo com vários extrajudiciais, pela edição do administrativo.
    $pe2 = $servico->criar(['protocolo' => 'PADAC 79/2026'] + $dados);
    $paService->atualizar($pa1, ['protocolo' => 'SES-PRO-2026/12351', 'extrajudiciais_ids' => [$pe, $pe2]]);
    $t->igual(array_map('intval', array_column($vinculos->extrajudiciaisDoAdministrativo($pa1), 'id')), [$pe2, $pe], 'dois extrajudiciais');
    $paService->atualizar($pa1, ['protocolo' => 'SES-PRO-2026/12351']);
    $t->igual(count($vinculos->extrajudiciaisDoAdministrativo($pa1)), 2, 'sem o campo, os vínculos ficam');
    $paService->atualizar($pa1, ['protocolo' => 'SES-PRO-2026/12351', 'extrajudiciais_ids' => [$pe2]]);
    $t->igual($vinculos->idsExtrajudiciais($pa1), [$pe2], 'vínculo retirado');

    $t->afirmar(!$servico->vincular($pa1, $pe2), 'vínculo repetido é ignorado');
    $t->afirmar($servico->desvincular($pa2, $pe), 'remove só o vínculo');
    $t->afirmar((new ProcessoExtrajudicialRepository())->buscarPorId($pe) !== null, 'o extrajudicial permanece');

    $servico->excluir($pe2);
    $t->igual($vinculos->idsExtrajudiciais($pa1), [], 'excluir o extrajudicial remove os vínculos');
    $t->afirmar((new \App\Repositories\ProcessoAdministrativoRepository())->buscarPorId($pa1) !== null, 'o administrativo permanece');
    $t->igual($acoesNoProcesso($pa1), ['EXTRAJUDICIAL_DESVINCULADO' => 2, 'EXTRAJUDICIAL_VINCULADO' => 2], 'histórico do administrativo');
});

$t->teste('listagem: protocolo, instituição, órgão, período e situação; pesquisa geral', function ($t) use ($exigirBanco) {
    $exigirBanco($t);
    $db = Database::conexao();
    $tce = (int)$db->query("SELECT id FROM instituicoes_externas WHERE sigla = 'TCE-MT'")->fetchColumn();
    $orgao = (int)$db->query("SELECT id FROM orgaos_externos WHERE instituicao_externa_id = {$tce}")->fetchColumn();
    $servico = new ProcessoExtrajudicialService();
    $base = ['instituicao_externa_id' => (string)$tce, 'assunto' => 'Auditoria LISTAEXTRA'];
    $a = $servico->criar(['protocolo' => 'TCE-LST-1', 'data_expediente' => '2026-01-10', 'orgao_externo_id' => (string)$orgao] + $base);
    $b = $servico->criar(['protocolo' => 'TCE-LST-2', 'data_expediente' => '2026-06-15', 'situacao' => 'ARQUIVADO'] + $base);
    $repo = new ProcessoExtrajudicialRepository();
    $ids = static fn($p): array => array_map('intval', array_column($p->itens, 'id'));

    $t->igual($ids($repo->listar(['busca' => 'LISTAEXTRA'], 1, 50)), [$b, $a], 'mais recentes primeiro');
    $t->igual($ids($repo->listar(['busca' => 'TCE-LST-1'], 1, 50)), [$a], 'por protocolo');
    $t->igual($ids($repo->listar(['busca' => 'LISTAEXTRA', 'orgao_id' => $orgao], 1, 50)), [$a], 'por órgão');
    $t->igual($ids($repo->listar(['busca' => 'LISTAEXTRA', 'data_de' => '2026-06-01', 'data_ate' => '2026-06-30'], 1, 50)), [$b], 'por período');
    $t->igual($ids($repo->listar(['busca' => 'LISTAEXTRA', 'situacao' => 'ARQUIVADO', 'instituicao_id' => $tce], 1, 50)), [$b], 'por situação e instituição');
    $t->igual($repo->listar(['busca' => 'LISTAEXTRA'], 2, 1)->totalPaginas, 2, 'paginação');

    $grupos = (new PesquisaService())->pesquisar('tce-lst-2');
    $t->contem($grupos['extrajudiciais']['itens'][0]['url'] ?? null, 'processo-extrajudicial.php?id=' . $b, 'pesquisa geral');
});
