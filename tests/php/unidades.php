<?php
declare(strict_types=1);

/**
 * Unidades Administrativas (antiga Lotação): instituição, nível, hierarquia,
 * gestor, lotacionograma e competências.
 *
 * @var \Tests\Runner $t
 */

use App\Core\Database;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\LotacaoRepository;
use App\Repositories\NivelOrganizacionalRepository;
use App\Services\TabelaApoioService;
use App\Services\UnidadeAdministrativaService;
use App\Validators\Normalizador;

$bancoPronto = $GLOBALS['gj_banco_teste_pronto'] ?? false;
$exigirBanco = static function ($t) use ($bancoPronto): void {
    if (!$bancoPronto) {
        $t->ignorar('banco de testes indisponível');
    }
};

$instituicao = static function (string $sigla): int {
    $stmt = Database::conexao()->prepare('SELECT id FROM instituicoes WHERE sigla = :s');
    $stmt->execute([':s' => $sigla]);
    return (int)$stmt->fetchColumn();
};

$t->grupo('Unidades Administrativas — regras');

$t->teste('lotacionograma: ignora linhas vazias, exige nome e matrícula única', function ($t) {
    [$lista, $erro] = UnidadeAdministrativaService::servidores([
        ['nome' => '  Ana   Souza ', 'matricula' => '123', 'cargo' => 'Analista'],
        ['nome' => '', 'matricula' => '', 'cargo' => ''],
        ['nome' => 'Bruno', 'matricula' => '', 'cargo' => ''],
    ]);
    $t->igual($erro, null, 'sem erro');
    $t->igual($lista, [
        ['nome' => 'Ana Souza', 'matricula' => '123', 'cargo' => 'Analista'],
        ['nome' => 'Bruno', 'matricula' => null, 'cargo' => null],
    ], 'normalizado, na ordem, sem a linha vazia');
    $t->contem(UnidadeAdministrativaService::servidores([['nome' => '', 'matricula' => '9']])[1], 'linha 1', 'linha sem nome');
    $t->contem(UnidadeAdministrativaService::servidores([['nome' => 'A', 'matricula' => 'x1'], ['nome' => 'B', 'matricula' => 'X1']])[1], 'mais de uma vez', 'matrícula repetida');
    $t->igual(UnidadeAdministrativaService::servidores('texto'), [[], null], 'entrada inválida vira lista vazia');
});

$t->teste('hierarquia: caminho, descendentes, árvore e opções de superior', function ($t) {
    $u = static fn(int $id, ?int $sup, string $nome, int $inst = 1, int $ativo = 1): array =>
        ['id' => $id, 'unidade_superior_id' => $sup, 'nome' => $nome, 'sigla' => null, 'instituicao_id' => $inst, 'ativo' => $ativo];
    $estrutura = [
        1 => $u(1, null, 'Secretaria'),
        2 => $u(2, 1, 'Superintendência'),
        3 => $u(3, 2, 'Coordenadoria'),
        4 => $u(4, 1, 'Assessoria'),
        5 => $u(5, null, 'Procuradoria', 2),
        6 => $u(6, 1, 'Extinta', 1, 0),
    ];
    $t->igual(array_column(UnidadeAdministrativaService::caminho($estrutura, 3), 'id'), [1, 2, 3], 'caminho da raiz até a unidade');
    $t->igual(UnidadeAdministrativaService::descendentes($estrutura, 2), [2, 3], 'descendentes');

    $opcoes = UnidadeAdministrativaService::opcoesSuperior($estrutura, 2, null);
    $t->igual(array_column($opcoes, 'id'), [1, 4, 5], 'sem a própria, as de baixo e as inativas');
    $t->igual($opcoes[1]['rotulo'], 'Secretaria › Assessoria', 'rótulo pelo caminho');
    $t->afirmar(in_array(6, array_column(UnidadeAdministrativaService::opcoesSuperior($estrutura, 2, 6), 'id'), true), 'inativa já gravada continua');

    $linhas = array_map(static fn(array $e): array => $e + ['instituicao_sigla' => $e['instituicao_id'] === 1 ? 'SES-MT' : 'PGE-MT'], array_values($estrutura));
    $arvore = UnidadeAdministrativaService::emArvore($linhas, $estrutura);
    $t->igual(array_column($arvore, 'id'), [5, 1, 4, 6, 2, 3], 'ordem de árvore por instituição e nome');
    $t->igual(array_column($arvore, 'profundidade'), [0, 0, 1, 1, 1, 2], 'profundidade');
});

$t->teste('texto longo (competências) preserva as linhas', function ($t) {
    $t->igual(Normalizador::textoLongo("I - Assessorar;\r\n\r\n\r\nII -   Representar."), "I - Assessorar;\n\nII - Representar.", 'normalizado');
});

$t->grupo('Unidades Administrativas — cadastro');

$t->teste('validação: instituição e nome obrigatórios; nome único por instituição; sigla única', function ($t) use ($exigirBanco, $instituicao) {
    $exigirBanco($t);
    $servico = new UnidadeAdministrativaService();
    $ses = $instituicao('SES-MT');
    $pge = $instituicao('PGE-MT');

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['nome' => '']), 'vazia');
    $t->igual(array_keys($e->erros()), ['instituicao_id', 'nome'], 'instituição e nome');

    $id = $servico->criar(['instituicao_id' => $ses, 'nome' => '  Gabinete   do Secretário ', 'sigla' => 'ses gbsec']);
    $u = (new LotacaoRepository())->buscarDetalhado($id);
    $t->igual([$u['nome'], $u['sigla'], $u['instituicao_sigla']], ['Gabinete do Secretário', 'SESGBSEC', 'SES-MT'], 'gravada normalizada');

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['instituicao_id' => $ses, 'nome' => 'gabinete do secretário']), 'mesmo nome na mesma instituição');
    $t->afirmar(isset($e->erros()['nome']), 'erro no nome');
    $outra = $servico->criar(['instituicao_id' => $pge, 'nome' => 'Gabinete do Secretário']);
    $t->afirmar($outra > 0, 'mesmo nome em outra instituição é permitido');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['instituicao_id' => $pge, 'nome' => 'Outro', 'sigla' => 'SESGBSEC']), 'sigla repetida');
    $t->afirmar(isset($e->erros()['sigla']), 'erro na sigla');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['instituicao_id' => $ses, 'nome' => 'Sem nome gestor', 'gestor_matricula' => '777']), 'matrícula sem gestor');
    $t->afirmar(isset($e->erros()['gestor_nome']), 'erro no gestor');

    $opcoes = array_column((new LotacaoRepository())->opcoes(), 'rotulo', 'id');
    $t->igual($opcoes[$id], 'Gabinete do Secretário (SES-MT)', 'rótulo com a instituição nas listas');
});

$t->teste('nível: escolhido na lista ou criado na hora, sem duplicar', function ($t) use ($exigirBanco, $instituicao) {
    $exigirBanco($t);
    $servico = new UnidadeAdministrativaService();
    $niveis = new NivelOrganizacionalRepository();
    $ses = $instituicao('SES-MT');

    $a = $servico->criar(['instituicao_id' => $ses, 'nome' => 'Conselho Teste', 'nivel_id' => 'novo', 'nivel_novo' => ' Nível I - Decisão Colegiada ']);
    $nivel = $niveis->buscarPorNome('Nível I - Decisão Colegiada');
    $t->afirmar($nivel !== null, 'nível criado');
    $t->igual((int)(new LotacaoRepository())->buscarPorId($a)['nivel_id'], (int)$nivel['id'], 'unidade com o novo nível');

    $b = $servico->criar(['instituicao_id' => $ses, 'nome' => 'Comitê Teste', 'nivel_novo' => 'nivel i - decisao colegiada']);
    $t->igual((int)(new LotacaoRepository())->buscarPorId($b)['nivel_id'], (int)$nivel['id'], 'mesmo nome (caixa/acento) reaproveita');
    $t->igual(count(array_filter($niveis->todos(), static fn($n) => $n['id'] == $nivel['id'])), 1, 'não duplicou');

    $c = $servico->criar(['instituicao_id' => $ses, 'nome' => 'Pela Lista', 'nivel_id' => (string)$nivel['id']]);
    $t->igual((int)(new LotacaoRepository())->buscarPorId($c)['nivel_id'], (int)$nivel['id'], 'nível pela lista');

    (new TabelaApoioService('niveis'))->alterarStatus((int)$nivel['id'], false);
    $t->lanca(ValidationException::class, fn() => $servico->criar(['instituicao_id' => $ses, 'nome' => 'Com Inativo', 'nivel_id' => (string)$nivel['id']]), 'nível inativo na lista');
    $t->lanca(ValidationException::class, fn() => $servico->criar(['instituicao_id' => $ses, 'nome' => 'Com Inativo', 'nivel_novo' => 'Nível I - Decisão Colegiada']), 'nível inativo digitado');
    $servico->atualizar($c, ['instituicao_id' => $ses, 'nome' => 'Pela Lista Renomeada', 'nivel_id' => (string)$nivel['id']]);
    $t->igual((new LotacaoRepository())->buscarPorId($c)['nome'], 'Pela Lista Renomeada', 'nível inativo já gravado continua na edição');

    $auditado = array_column((new AuditoriaRepository())->listarPorEntidade('niveis', (int)$nivel['id']), 'acao');
    $t->igual($auditado, ['NIVEL_INATIVADO', 'NIVEL_CRIADO'], 'criação e inativação do nível auditadas');
});

$t->teste('unidade superior: mesma instituição e sem ciclo', function ($t) use ($exigirBanco, $instituicao) {
    $exigirBanco($t);
    $servico = new UnidadeAdministrativaService();
    $ses = $instituicao('SES-MT');
    $pge = $instituicao('PGE-MT');
    $base = static fn(string $nome, ?int $sup = null, ?int $inst = null): array =>
        ['instituicao_id' => $inst ?? $ses, 'nome' => $nome, 'unidade_superior_id' => $sup !== null ? (string)$sup : ''];

    $raiz = $servico->criar($base('Raiz Hierarquia'));
    $meio = $servico->criar($base('Meio Hierarquia', $raiz));
    $folha = $servico->criar($base('Folha Hierarquia', $meio));

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar($base('De Outra', $raiz, $pge)), 'superior de outra instituição');
    $t->contem($e->erros()['unidade_superior_id'] ?? '', 'mesma instituição', 'mensagem');
    $e = $t->lanca(ValidationException::class, fn() => $servico->atualizar($raiz, $base('Raiz Hierarquia', $folha)), 'ciclo');
    $t->contem($e->erros()['unidade_superior_id'] ?? '', 'ciclo', 'mensagem de ciclo');
    $t->lanca(ValidationException::class, fn() => $servico->atualizar($meio, $base('Meio Hierarquia', $meio)), 'superior de si mesma');
    $t->lanca(ValidationException::class, fn() => $servico->criar($base('Sem Superior Valida', 999)), 'superior inexistente');

    $repo = new LotacaoRepository();
    $t->igual(array_column($repo->subordinadas($raiz), 'id'), [$meio], 'subordinadas diretas');
    $t->igual(array_column(UnidadeAdministrativaService::caminho($repo->estrutura(), $folha), 'nome'), ['Raiz Hierarquia', 'Meio Hierarquia', 'Folha Hierarquia'], 'caminho gravado');

    $servico->alterarStatus($meio, false);
    $t->lanca(ValidationException::class, fn() => $servico->criar($base('Sob Inativa', $meio)), 'nova sob unidade inativa');
    $servico->atualizar($folha, $base('Folha Renomeada', $meio));
    $t->igual($repo->buscarPorId($folha)['nome'], 'Folha Renomeada', 'superior inativa já gravada continua na edição');
});

$t->teste('gestor, lotacionograma, competências e auditoria', function ($t) use ($exigirBanco, $instituicao) {
    $exigirBanco($t);
    $servico = new UnidadeAdministrativaService();
    $repo = new LotacaoRepository();
    $ses = $instituicao('SES-MT');
    $dados = [
        'instituicao_id' => $ses, 'nome' => 'Unidade Completa', 'gestor_nome' => 'Maria Gestora', 'gestor_matricula' => '1001',
        'competencias' => "I - Assessorar;\nII - Representar.",
        'servidores' => [
            ['nome' => 'Ana', 'matricula' => '1', 'cargo' => 'Analista'],
            ['nome' => 'Bruno', 'matricula' => '2', 'cargo' => ''],
        ],
    ];
    $id = $servico->criar($dados);
    $u = $repo->buscarPorId($id);
    $t->igual([$u['gestor_nome'], $u['gestor_matricula']], ['Maria Gestora', '1001'], 'gestor');
    $t->igual($u['competencias'], "I - Assessorar;\nII - Representar.", 'competências com linhas');
    $t->igual(array_column($repo->servidores($id), 'nome'), ['Ana', 'Bruno'], 'lotacionograma na ordem');

    $servico->atualizar($id, $dados); // sem mudança: não audita
    $dados['servidores'] = [['nome' => 'Carla', 'matricula' => '3', 'cargo' => 'Técnica'], $dados['servidores'][0]];
    $servico->atualizar($id, $dados);
    $t->igual(array_column($repo->servidores($id), 'nome'), ['Carla', 'Ana'], 'lotacionograma substituído');

    $e = $t->lanca(ValidationException::class, fn() => $servico->atualizar($id, ['servidores' => [['nome' => '', 'cargo' => 'Sem nome']]] + $dados), 'linha sem nome');
    $t->afirmar(isset($e->erros()['servidores']), 'erro no lotacionograma');
    $t->igual(count($repo->servidores($id)), 2, 'erro não apaga o lotacionograma');

    $servico->alterarStatus($id, false);
    $t->igual($servico->alterarStatus($id, false), false, 'já inativa');
    $t->afirmar(!in_array($id, array_map('intval', array_column($repo->opcoes(), 'id')), true), 'inativa some das opções');

    $registros = (new AuditoriaRepository())->listarPorEntidade('lotacoes', $id);
    $t->igual(array_column($registros, 'acao'), ['LOTACAO_INATIVADA', 'LOTACAO_EDITADA', 'LOTACAO_CRIADA'], 'eventos auditados');
    $t->igual(array_keys(json_decode($registros[1]['dados_novos'], true)), ['lotacionograma'], 'edição registra só o lotacionograma');
});

$t->teste('unidade anterior sem instituição: edição exige a instituição; usuários seguem lotados', function ($t) use ($exigirBanco, $instituicao) {
    $exigirBanco($t);
    $repo = new LotacaoRepository();
    $gabinete = (int)Database::conexao()->query("SELECT id FROM lotacoes WHERE nome = 'Gabinete' AND instituicao_id IS NULL")->fetchColumn();
    $t->afirmar($gabinete > 0, 'unidade semeada sem instituição');
    $servico = new UnidadeAdministrativaService();
    $e = $t->lanca(ValidationException::class, fn() => $servico->atualizar($gabinete, ['nome' => 'Gabinete']), 'sem instituição');
    $t->afirmar(isset($e->erros()['instituicao_id']), 'erro na instituição');
    $servico->atualizar($gabinete, ['nome' => 'Gabinete', 'instituicao_id' => (string)$instituicao('SES-MT')]);
    $t->igual($repo->buscarDetalhado($gabinete)['instituicao_sigla'], 'SES-MT', 'instituição completada');

    $listada = array_values(array_filter($repo->listarUnidades(['busca' => 'Gabinete', 'status' => 'todos']), static fn($u) => (int)$u['id'] === $gabinete));
    $t->igual(count($listada), 1, 'aparece na listagem');
    $t->afirmar(array_key_exists('usuarios_ativos', $listada[0]) && array_key_exists('total_servidores', $listada[0]), 'contagens na listagem');
});
