<?php
declare(strict_types=1);

/**
 * Central de Recursos (Pastas = tipos, Áreas = categorias) e os dados das
 * visualizações de Unidades Administrativas.
 *
 * @var \Tests\Runner $t
 */

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\RecursoRepository;
use App\Services\RecursoService;
use App\Services\TabelaApoioService;
use App\Services\UnidadeAdministrativaService;
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
        'nome'       => "Pessoa Recursos {$seq}",
        'email'      => "recursos{$seq}@ses.mt.gov.br",
        'matricula'  => 'REC-' . $seq,
        'cargo_id'   => (string)$db->query("SELECT id FROM cargos WHERE nome = 'Analista'")->fetchColumn(),
        'lotacao_id' => (string)$db->query("SELECT id FROM lotacoes WHERE nome = 'Unidade Jurídica'")->fetchColumn(),
        'perfil'     => $perfil,
        'data_nascimento' => '',
    ]);
};

$idPorNome = static function (string $tabela, string $nome): string {
    $stmt = Database::conexao()->prepare("SELECT id FROM {$tabela} WHERE nome = :n");
    $stmt->execute([':n' => $nome]);
    return (string)$stmt->fetchColumn();
};

$t->grupo('Recursos — regras');

$t->teste('URL: assume https://, aceita só http(s) com host válido', function ($t) {
    $t->igual(RecursoService::url('pje.tjmt.jus.br/pje'), ['https://pje.tjmt.jus.br/pje', null], 'sem esquema vira https');
    $t->igual(RecursoService::url('  http://sigtap.datasus.gov.br  '), ['http://sigtap.datasus.gov.br', null], 'http mantido, espaços removidos');
    $t->igual(RecursoService::url(null)[1], 'Informe a URL de acesso.', 'vazia');
    $t->contem(RecursoService::url('javascript:alert(1)')[1], 'http', 'javascript: bloqueado');
    $t->contem(RecursoService::url('data:text/html,oi')[1], 'http', 'data: bloqueado');
    $t->contem(RecursoService::url('ftp://arquivos.gov.br')[1], 'http', 'ftp bloqueado');
    $t->contem(RecursoService::url('https://exemplo .gov.br')[1], 'não é válida', 'espaço no host');
    $t->contem(RecursoService::url('https://' . str_repeat('a', 600) . '.br')[1], 'no máximo', 'longa demais');
});

$t->teste('cores das pastas são estáveis e circulares', function ($t) {
    $t->igual(RecursoService::cor(0)['forte'], '#3461fd', 'primeira');
    $t->igual(RecursoService::cor(8), RecursoService::cor(0), 'volta ao início');
    $t->igual(RecursoService::cor(-1), RecursoService::cor(7), 'índice negativo');
});

$t->teste('Pastas e Áreas são tabelas de apoio mantidas por administradores e gestores', function ($t) {
    $t->afirmar(TabelaApoioService::tipoValido('tipos_recurso'), 'pastas');
    $t->afirmar(TabelaApoioService::tipoValido('categorias_recurso'), 'áreas');
    $t->igual(TabelaApoioService::perfis('tipos_recurso'), [Perfil::ADMINISTRADOR, Perfil::GESTOR], 'pastas: admin e gestor');
    $t->igual(TabelaApoioService::perfis('cargos'), [Perfil::ADMINISTRADOR], 'cargos: só admin');
    $t->igual(TabelaApoioService::perfis('inexistente'), [Perfil::ADMINISTRADOR], 'tipo inválido: só admin');
});

$t->grupo('Recursos — cadastro e permissões');

$t->teste('cria com pasta e áreas, valida campos e registra autoria', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $idPorNome) {
    $exigirBanco($t);
    $autor = $novoUsuario(Perfil::USUARIO);
    $entrarComo($autor);
    $servico = new RecursoService();

    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['titulo' => ' ', 'url' => '', 'tipo_id' => '']), 'tudo vazio');
    $t->afirmar(isset($e->erros()['titulo'], $e->erros()['url'], $e->erros()['tipo_id']), 'erros de título, URL e tipo');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar([
        'titulo' => 'X', 'url' => 'x.gov.br', 'tipo_id' => $idPorNome('tipos_recurso', 'Sites'), 'categorias' => ['99999'],
    ]), 'categoria inexistente');
    $t->afirmar(isset($e->erros()['categorias']), 'erro nas categorias');

    $judicial = $idPorNome('categorias_recurso', 'Judicial');
    $id = $servico->criar([
        'titulo'     => '  PJe   TJMT ',
        'descricao'  => "Processo judicial\neletrônico",
        'url'        => 'pje.tjmt.jus.br',
        'tipo_id'    => $idPorNome('tipos_recurso', 'Sistemas'),
        'categorias' => [$judicial, $judicial, 'lixo'],
    ]);
    $r = (new RecursoRepository())->buscarPorId($id);
    $t->igual($r['titulo'], 'PJe TJMT', 'título normalizado');
    $t->igual($r['url'], 'https://pje.tjmt.jus.br', 'URL com https');
    $t->igual((int)$r['criado_por'], $autor, 'autor gravado');
    $t->igual((new RecursoRepository())->categorias($id), [(int)$judicial], 'categoria sem repetição');
    $historico = (new AuditoriaRepository())->listarPorEntidade('recursos', $id);
    $t->igual($historico[0]['acao'] ?? null, 'RECURSO_CRIADO', 'auditoria do cadastro');

    $lista = array_values(array_filter((new RecursoRepository())->listar(), static fn(array $i): bool => (int)$i['id'] === $id));
    $t->igual($lista[0]['categorias'], [(int)$judicial], 'listagem traz as categorias');
    $t->igual($lista[0]['criado_por_nome'], 'Pessoa Recursos 1', 'listagem traz o autor');
    $entrarComo(null);
});

$t->teste('só administrador, gestor ou o autor altera e exclui', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $idPorNome) {
    $exigirBanco($t);
    $autor = $novoUsuario(Perfil::USUARIO);
    $outro = $novoUsuario(Perfil::USUARIO);
    $gestor = $novoUsuario(Perfil::GESTOR);
    $base = ['titulo' => 'Portal', 'url' => 'https://portal.gov.br', 'tipo_id' => $idPorNome('tipos_recurso', 'Sites')];

    $entrarComo($autor);
    $servico = new RecursoService();
    $id = $servico->criar($base);

    $entrarComo($outro);
    $t->afirmar(!RecursoService::podeAlterar((new RecursoRepository())->buscarPorId($id)), 'outro usuário não pode');
    $t->lanca(AcessoNegadoException::class, fn() => $servico->atualizar($id, ['titulo' => 'Hack'] + $base), 'outro não edita');
    $t->lanca(AcessoNegadoException::class, fn() => $servico->excluir($id), 'outro não exclui');
    $t->afirmar(!RecursoService::podeGerenciarListas(), 'usuário não mantém pastas');

    $entrarComo($autor);
    $servico->atualizar($id, ['titulo' => 'Portal do Servidor', 'categorias' => [$idPorNome('categorias_recurso', 'Administrativa')]] + $base);
    $t->igual((new RecursoRepository())->buscarPorId($id)['titulo'], 'Portal do Servidor', 'autor edita');
    $t->igual((int)(new RecursoRepository())->buscarPorId($id)['atualizado_por'], $autor, 'quem alterou');

    $entrarComo($gestor);
    $t->afirmar(RecursoService::podeGerenciarListas(), 'gestor mantém pastas e áreas');
    $t->igual($servico->excluir($id), 'Portal do Servidor', 'gestor exclui');
    $t->igual((new RecursoRepository())->buscarPorId($id), null, 'excluído');
    $t->igual((int)Database::conexao()->query("SELECT COUNT(*) FROM recurso_categorias WHERE recurso_id = {$id}")->fetchColumn(), 0, 'áreas removidas junto');
    $entrarComo(null);
});

$t->teste('pasta inativada continua valendo para o recurso que já a usa', function ($t) use ($exigirBanco, $idPorNome) {
    $exigirBanco($t);
    $servico = new RecursoService();
    $planilhas = $idPorNome('tipos_recurso', 'Planilhas');
    $id = $servico->criar(['titulo' => 'Controle de prazos', 'url' => 'https://planilha.gov.br', 'tipo_id' => $planilhas]);

    (new TabelaApoioService('tipos_recurso'))->alterarStatus((int)$planilhas, false);
    $servico->atualizar($id, ['titulo' => 'Controle de prazos 2026', 'url' => 'https://planilha.gov.br', 'tipo_id' => $planilhas]);
    $t->igual((new RecursoRepository())->buscarPorId($id)['titulo'], 'Controle de prazos 2026', 'edição com a pasta inativa já gravada');
    $e = $t->lanca(ValidationException::class, fn() => $servico->criar(['titulo' => 'Nova', 'url' => 'https://x.gov.br', 'tipo_id' => $planilhas]), 'novo item não usa pasta inativa');
    $t->contem($e->erros()['tipo_id'] ?? null, 'inativo', 'mensagem da pasta inativa');

    $itens = (new TabelaApoioService('tipos_recurso'))->repositorio()->listar(['status' => 'todos']);
    $linha = array_values(array_filter($itens, static fn(array $i): bool => (int)$i['id'] === (int)$planilhas))[0];
    $t->igual((int)$linha['usuarios_ativos'], 1, 'contagem de recursos na pasta');
    (new TabelaApoioService('tipos_recurso'))->alterarStatus((int)$planilhas, true);
});

$t->grupo('Unidades — dados das visualizações');

$t->teste('Lista, Hierarquia e Nível recebem cores, níveis e superiores consistentes', function ($t) {
    $dados = UnidadeAdministrativaService::paraVisualizacao(
        [
            ['id' => '1', 'nome' => 'Gabinete', 'sigla' => 'GAB', 'instituicao_id' => '1', 'nivel_id' => '2', 'unidade_superior_id' => null,
             'gestor_nome' => 'Ana', 'gestor_matricula' => '1', 'competencias' => 'Dirigir', 'ativo' => '1', 'total_servidores' => '3', 'usuarios_ativos' => '2'],
            ['id' => '2', 'nome' => 'Núcleo', 'sigla' => null, 'instituicao_id' => '1', 'nivel_id' => null, 'unidade_superior_id' => '1',
             'gestor_nome' => null, 'gestor_matricula' => null, 'competencias' => null, 'ativo' => '0', 'total_servidores' => '0', 'usuarios_ativos' => '0'],
            // Superior fora da lista (filtro ou registro torto): vira raiz em vez de sumir.
            ['id' => '3', 'nome' => 'Órfã', 'sigla' => null, 'instituicao_id' => null, 'nivel_id' => null, 'unidade_superior_id' => '99',
             'gestor_nome' => null, 'gestor_matricula' => null, 'competencias' => null, 'ativo' => '1', 'total_servidores' => '0', 'usuarios_ativos' => '0'],
        ],
        [['id' => '1', 'nome' => 'SES', 'sigla' => 'SES-MT', 'ativo' => '1'], ['id' => '2', 'nome' => 'PGE', 'sigla' => 'PGE-MT', 'ativo' => '0']],
        [['id' => '2', 'nome' => 'Nível I']]
    );
    $t->igual(array_column($dados['instituicoes'], 'cor'), ['#3461fd', '#12b7a3'], 'cores pela posição');
    $t->igual($dados['instituicoes'][1]['ativo'], false, 'instituição inativa sinalizada');
    $t->igual($dados['niveis'], [['id' => 2, 'nome' => 'Nível I']], 'níveis');
    $t->igual($dados['unidades'][0]['superior_id'], null, 'raiz');
    $t->igual($dados['unidades'][1]['superior_id'], 1, 'subordinada');
    $t->igual($dados['unidades'][1]['ativo'], false, 'inativa');
    $t->igual($dados['unidades'][2]['superior_id'], null, 'superior ausente vira raiz');
    $t->igual([$dados['unidades'][0]['servidores'], $dados['unidades'][0]['usuarios']], [3, 2], 'contagens');
});
