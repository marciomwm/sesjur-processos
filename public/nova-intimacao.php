<?php
declare(strict_types=1);

/**
 * Cadastro de Intimacao Pessoal. Vindo da ficha de um processo ou de um
 * agente (?processo_tipo=&processo_id= / ?agente_intimado_id=), o campo ja
 * chega preenchido. Depois de salvar, volta ao `retorno` ou abre a intimacao.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores e gestores.
App\Services\IntimacaoPessoalService::exigirAcesso();

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\AgentePublicoRepository;
use App\Services\IntimacaoPessoalService;

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$retornoInformado = entrada('retorno', $post ? $_POST : $_GET);
$retorno = $retornoInformado !== '' ? destino_intimacao($retornoInformado) : 'intimacoes-pessoais.php';

$servico = new IntimacaoPessoalService();
$erros = [];
$dados = [
    'processo_tipo'      => strtoupper(entrada('processo_tipo', $_GET)),
    'processo_id'        => (string)(entrada_int('processo_id') ?? ''),
    'agente_intimado_id' => (string)(entrada_int('agente_intimado_id') ?? ''),
    'data_recebimento'   => date('Y-m-d'),
    'status'             => 'PENDENTE',
];

if ($post) {
    Csrf::exigir();
    $dados = entrada_intimacao();
    try {
        $id = $servico->criar($dados);
        flash('sucesso', 'Intimação pessoal registrada (nº ' . $id . ').');
        redirecionar($retornoInformado !== '' ? destino_intimacao($retornoInformado, '#intimacao-' . $id) : 'intimacao.php?id=' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'intimacao.criar');
        $erros['_geral'] = "Não foi possível salvar a intimação (código {$codigo}).";
    }
}

$rotulos = $servico->rotulosFormulario($dados);
$agenteLogado = (int)(Auth::usuario()['agente_publico_id'] ?? 0);
$meuAgenteRegistro = $agenteLogado > 0 ? (new AgentePublicoRepository())->buscarPorId($agenteLogado) : null;
$meuAgente = $meuAgenteRegistro !== null ? IntimacaoPessoalService::descreverAgente($meuAgenteRegistro) : null;
$prazoOriginal = null;

$titulo       = 'Nova Intimação';
$menuAtivo    = 'intimacoes';
$breadcrumbs  = ['Intimações Pessoais' => 'intimacoes-pessoais.php', 'Nova' => null];
$scripts      = ['intimacoes.js'];
$rotuloSalvar = 'Registrar intimação';

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php elseif ($erros !== [] && !isset($erros['confirmar_duplicidade'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span>Corrija os campos destacados.</span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-intimacao.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
