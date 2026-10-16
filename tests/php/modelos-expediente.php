<?php
declare(strict_types=1);

/**
 * Modelos do Gerador de Expedientes: visibilidade Individual/Compartilhado
 * e o que cada perfil pode fazer.
 *
 * @var \Tests\Runner $t
 */

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Perfil;
use App\Core\ValidationException;
use App\Repositories\AuditoriaRepository;
use App\Services\ModeloExpedienteService;
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
        'nome'       => "Pessoa Modelos {$seq}",
        'email'      => "modelos{$seq}@ses.mt.gov.br",
        'matricula'  => 'MOD-' . $seq,
        'cargo_id'   => (string)$db->query("SELECT id FROM cargos WHERE nome = 'Analista'")->fetchColumn(),
        'lotacao_id' => (string)$db->query("SELECT id FROM lotacoes WHERE nome = 'Unidade Jurídica'")->fetchColumn(),
        'perfil'     => $perfil,
        'data_nascimento' => '',
    ]);
};

$modelo = static fn(string $nome, array $extra = []): array => $extra + [
    'nome'     => $nome,
    'tipo'     => 'Ofício',
    'conteudo' => ['base' => 'expediente-livre', 'html' => '<p>Ref. {{PROCESSO_SIGADOC}}</p>', 'personalizadas' => [], 'selecao' => ['PROCESSO_SIGADOC']],
];

$nomes = static fn(array $lista): array => array_column($lista, 'nome');
$idGerador = static fn(int $id): string => 'modelo:' . $id;

$t->grupo('Modelos de expediente — validação');

$t->teste('nome obrigatório, visibilidade conhecida, Individual por padrão', function ($t) {
    $s = new ModeloExpedienteService();
    $t->lanca(ValidationException::class, fn() => $s->validar(['nome' => '  ']), 'nome vazio');
    $t->lanca(ValidationException::class, fn() => $s->validar(['nome' => 'X', 'visibilidade' => 'PUBLICO']), 'visibilidade desconhecida');
    $t->lanca(ValidationException::class, fn() => $s->validar(['nome' => 'X', 'conteudo' => 'texto']), 'conteúdo não é objeto');
    $d = $s->validar(['nome' => '  Ofício   padrão ', 'conteudo' => ['html' => '<p>a</p>', 'protocolo' => 'SES-PRO-1']]);
    $t->igual($d['nome'], 'Ofício padrão', 'nome normalizado');
    $t->igual($d['visibilidade'], ModeloExpedienteService::INDIVIDUAL, 'individual por padrão');
    $t->igual($d['tipo'], ModeloExpedienteService::TIPO_PADRAO, 'tipo padrão');
    $t->igual(json_decode($d['conteudo'], true), ['html' => '<p>a</p>'], 'só as chaves do modelo; nunca o protocolo');
});

$t->grupo('Modelos de expediente — visibilidade e permissões');

$t->teste('individual só aparece para quem criou; compartilhado aparece para todos', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $modelo, $nomes) {
    $exigirBanco($t);
    $usuario = $novoUsuario(Perfil::USUARIO);
    $colega  = $novoUsuario(Perfil::USUARIO);
    $gestor  = $novoUsuario(Perfil::GESTOR);
    $s = new ModeloExpedienteService();

    $entrarComo($usuario);
    $s->criar($modelo('Meu ofício'));
    $entrarComo($gestor);
    $s->criar($modelo('Ofício do gestor'));
    $s->criar($modelo('Ofício geral', ['visibilidade' => 'COMPARTILHADO']));

    $entrarComo($usuario);
    $vistos = $nomes($s->visiveis());
    $t->afirmar(in_array('Meu ofício', $vistos, true), 'vê o próprio');
    $t->afirmar(in_array('Ofício geral', $vistos, true), 'vê o compartilhado');
    $t->afirmar(!in_array('Ofício do gestor', $vistos, true), 'não vê o individual do gestor');

    $entrarComo($colega);
    $vistos = $nomes($s->visiveis());
    $t->afirmar(!in_array('Meu ofício', $vistos, true), 'colega não vê o individual alheio');
    $t->afirmar(in_array('Ofício geral', $vistos, true), 'colega vê o compartilhado');
});

$t->teste('usuário cria individuais, mas não compartilha; usa e duplica o compartilhado', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $modelo) {
    $exigirBanco($t);
    $usuario = $novoUsuario(Perfil::USUARIO);
    $admin   = $novoUsuario(Perfil::ADMINISTRADOR);
    $s = new ModeloExpedienteService();

    $entrarComo($usuario);
    $t->lanca(AcessoNegadoException::class, fn() => $s->criar($modelo('Tentativa', ['visibilidade' => 'COMPARTILHADO'])), 'não cria compartilhado');
    $proprio = $s->criar($modelo('Despacho do usuário'));
    $t->lanca(AcessoNegadoException::class, fn() => $s->atualizar($proprio, ['visibilidade' => 'COMPARTILHADO']), 'não compartilha o próprio');
    $s->atualizar($proprio, ['descricao' => 'Uso diário']);
    $t->igual($s->obter($proprio)['descricao'], 'Uso diário', 'altera o próprio individual');

    $entrarComo($admin);
    $comum = $s->criar($modelo('Certidão comum', ['visibilidade' => 'COMPARTILHADO']));

    $entrarComo($usuario);
    $r = $s->obter($comum);
    $t->igual($r['visibilidade'], 'COMPARTILHADO', 'vê como compartilhado');
    $t->afirmar(!$r['podeAlterar'], 'sem permissão de alterar');
    $t->lanca(AcessoNegadoException::class, fn() => $s->atualizar($comum, ['nome' => 'Outra']), 'não altera o compartilhado');
    $t->lanca(AcessoNegadoException::class, fn() => $s->excluir($comum), 'não exclui o compartilhado');

    $s->registrarUso($comum);
    $s->marcarFavorito($comum, true);
    $copia = $s->duplicar($comum);
    $c = $s->obter($copia);
    $t->igual($c['nome'], 'Cópia de Certidão comum', 'nome da cópia');
    $t->igual($c['visibilidade'], 'INDIVIDUAL', 'cópia é individual');
    $t->afirmar($c['podeAlterar'] && $c['proprio'], 'cópia é do usuário');
    $t->igual($c['html'], '<p>Ref. {{PROCESSO_SIGADOC}}</p>', 'cópia leva o texto');
});

$t->teste('administrador e gestor alteram, excluem e mudam a visibilidade dos compartilhados', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $modelo, $nomes) {
    $exigirBanco($t);
    $admin   = $novoUsuario(Perfil::ADMINISTRADOR);
    $gestor  = $novoUsuario(Perfil::GESTOR);
    $usuario = $novoUsuario(Perfil::USUARIO);
    $s = new ModeloExpedienteService();

    $entrarComo($admin);
    $id = $s->criar($modelo('Termo de ciência'));
    $s->atualizar($id, ['visibilidade' => 'COMPARTILHADO']);

    $entrarComo($gestor);
    $s->atualizar($id, ['tipo' => 'Termo', 'conteudo' => ['html' => '<p>Novo texto</p>']]);
    $r = $s->obter($id);
    $t->igual($r['tipo'], 'Termo', 'gestor altera o compartilhado do administrador');
    $t->igual($r['html'], '<p>Novo texto</p>', 'conteúdo regravado');
    $t->igual($r['nome'], 'Termo de ciência', 'campos não informados ficam como estão');

    $s->atualizar($id, ['visibilidade' => 'INDIVIDUAL']);
    $t->afirmar(!in_array('Termo de ciência', $nomes($s->visiveis()), true), 'volta a ser individual do autor: some para o gestor');
    $entrarComo($usuario);
    $t->lanca(ValidationException::class, fn() => $s->obter($id), 'individual alheio não existe para o usuário');
    $entrarComo($admin);
    $t->afirmar(in_array('Termo de ciência', $nomes($s->visiveis()), true), 'autor continua vendo');

    $s->atualizar($id, ['visibilidade' => 'COMPARTILHADO']);
    $entrarComo($gestor);
    $s->excluir($id);
    $entrarComo($admin);
    $t->afirmar(!in_array('Termo de ciência', $nomes($s->visiveis()), true), 'excluído para todos');

    $acoes = array_column((new AuditoriaRepository())->listarPorEntidade('expediente_modelos', $id), 'acao');
    foreach (['MODELO_EXPEDIENTE_COMPARTILHADO', 'MODELO_EXPEDIENTE_INDIVIDUALIZADO', 'MODELO_EXPEDIENTE_EXCLUIDO'] as $acao) {
        $t->afirmar(in_array($acao, $acoes, true), 'auditoria: ' . $acao);
    }
});

$t->teste('nome único entre os modelos que o usuário vê', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $modelo) {
    $exigirBanco($t);
    $um     = $novoUsuario(Perfil::USUARIO);
    $outro  = $novoUsuario(Perfil::USUARIO);
    $gestor = $novoUsuario(Perfil::GESTOR);
    $s = new ModeloExpedienteService();

    $entrarComo($um);
    $s->criar($modelo('Informação técnica'));
    $t->lanca(ValidationException::class, fn() => $s->criar($modelo('INFORMAÇÃO TÉCNICA')), 'repetido para o mesmo usuário');
    $entrarComo($outro);
    $s->criar($modelo('Informação técnica'));
    $t->afirmar(true, 'outro usuário pode usar o mesmo nome no individual dele');

    $entrarComo($gestor);
    $s->criar($modelo('Notificação', ['visibilidade' => 'COMPARTILHADO']));
    $entrarComo($um);
    $t->lanca(ValidationException::class, fn() => $s->criar($modelo('Notificação')), 'nome de um compartilhado');
});

$t->teste('favorito e uso são de cada usuário', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $modelo) {
    $exigirBanco($t);
    $gestor  = $novoUsuario(Perfil::GESTOR);
    $usuario = $novoUsuario(Perfil::USUARIO);
    $s = new ModeloExpedienteService();

    $entrarComo($gestor);
    $id = $s->criar($modelo('Manifestação padrão', ['visibilidade' => 'COMPARTILHADO']));
    $antes = $s->obter($id)['atualizadoEm'];

    $entrarComo($usuario);
    $s->marcarFavorito($id, true);
    $s->registrarUso($id);
    $s->registrarUso($id);
    $r = $s->obter($id);
    $t->afirmar($r['favorito'], 'favorito do usuário');
    $t->igual($r['usos'], 2, 'usos do usuário');

    $entrarComo($gestor);
    $r = $s->obter($id);
    $t->afirmar(!$r['favorito'], 'não é favorito do gestor');
    $t->igual($r['usos'], 0, 'gestor não usou');
    $t->igual($r['atualizadoEm'], $antes, 'favorito e uso não contam como edição');
});

$t->grupo('Modelos de expediente — página');

$t->teste('o gerador recebe só os modelos visíveis e a permissão de compartilhar', function ($t) use ($exigirBanco, $novoUsuario, $entrarComo, $modelo) {
    $exigirBanco($t);
    $gestor  = $novoUsuario(Perfil::GESTOR);
    $usuario = $novoUsuario(Perfil::USUARIO);
    $s = new ModeloExpedienteService();
    $entrarComo($gestor);
    $s->criar($modelo('Página compartilhado', ['visibilidade' => 'COMPARTILHADO']));
    $s->criar($modelo('Página individual do gestor'));

    $configuracao = static function () use ($t): array {
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        try {
            require PROJECT_ROOT . '/public/expediente.php';
        } finally {
            $html = (string)ob_get_clean();
        }
        $t->afirmar((bool)preg_match('#<script type="application/json" id="sesjurExpediente">(.*?)</script>#s', $html, $m), 'configuração na página');
        return json_decode($m[1] ?? '{}', true) ?: [];
    };

    $cfg = $configuracao();
    $t->afirmar($cfg['podeCompartilhar'] === true, 'gestor pode compartilhar');
    $t->afirmar(in_array('Página individual do gestor', array_column($cfg['modelos'], 'nome'), true), 'gestor vê o individual dele');

    $entrarComo($usuario);
    $cfg = $configuracao();
    $t->afirmar($cfg['podeCompartilhar'] === false, 'usuário não pode compartilhar');
    $nomes = array_column($cfg['modelos'], 'nome');
    $t->afirmar(in_array('Página compartilhado', $nomes, true), 'usuário recebe o compartilhado');
    $t->afirmar(!in_array('Página individual do gestor', $nomes, true), 'usuário não recebe o individual alheio');
    $t->afirmar($cfg['csrf'] !== '', 'token para as gravações');
});
