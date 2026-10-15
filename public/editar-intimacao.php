<?php
declare(strict_types=1);

/**
 * Edicao de Intimacao Pessoal (administradores e gestores).
 * Retificar o prazo exige justificativa, que vai para o historico.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores e gestores.
App\Services\IntimacaoPessoalService::exigirAcesso();

use App\Core\AcessoNegadoException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\AgentePublicoRepository;
use App\Repositories\IntimacaoPessoalRepository;
use App\Services\IntimacaoPessoalService;

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$id = entrada_int('id', $post ? $_POST + $_GET : $_GET);
$registro = $id !== null ? (new IntimacaoPessoalRepository())->buscarPorId($id) : null;
if ($registro === null || $registro['excluida_em'] !== null) {
    flash('erro', 'Intimação não encontrada.');
    redirecionar('intimacoes-pessoais.php');
}
if (!IntimacaoPessoalService::podeAlterar($registro)) {
    flash('erro', IntimacaoPessoalService::MSG_SEM_PERMISSAO);
    redirecionar('intimacao.php?id=' . $id);
}

$retornoInformado = entrada('retorno', $post ? $_POST : $_GET);
$retorno = $retornoInformado !== '' ? destino_intimacao($retornoInformado) : 'intimacao.php?id=' . $id;

$servico = new IntimacaoPessoalService();
$erros = [];
$dados = [
    'processo_tipo'        => $registro['processo_tipo'],
    'processo_id'          => (string)$registro['processo_id'],
    'agente_intimado_id'   => (string)$registro['agente_intimado_id'],
    'agente_recebedor_id'  => (string)$registro['agente_recebedor_id'],
    'data_recebimento'     => $registro['data_recebimento'],
    'data_prazo'           => $registro['data_prazo'],
    'data_cumprimento'     => $registro['data_cumprimento'],
    'status'               => $registro['status'],
    'documento_referencia' => $registro['documento_referencia'],
    'observacoes'          => $registro['observacoes'],
];

if ($post) {
    Csrf::exigir();
    $dados = entrada_intimacao();
    try {
        $servico->atualizar($id, $dados);
        flash('sucesso', 'Intimação atualizada.');
        redirecionar($retornoInformado !== '' ? destino_intimacao($retornoInformado, '#intimacao-' . $id) : 'intimacao.php?id=' . $id);
    } catch (ValidationException $e) {
        $erros = $e->erros() ?: ['_geral' => $e->getMessage()];
    } catch (AcessoNegadoException $e) {
        $erros['_geral'] = $e->getMessage();
    } catch (Throwable $e) {
        $codigo = log_erro($e, 'intimacao.atualizar');
        $erros['_geral'] = "Não foi possível salvar a intimação (código {$codigo}).";
    }
}

$rotulos = $servico->rotulosFormulario($dados);
$agenteLogado = (int)(Auth::usuario()['agente_publico_id'] ?? 0);
$meuAgenteRegistro = $agenteLogado > 0 ? (new AgentePublicoRepository())->buscarPorId($agenteLogado) : null;
$meuAgente = $meuAgenteRegistro !== null ? IntimacaoPessoalService::descreverAgente($meuAgenteRegistro) : null;
$prazoOriginal = (string)$registro['data_prazo'];

$titulo       = 'Editar Intimação';
$menuAtivo    = 'intimacoes';
$breadcrumbs  = ['Intimações Pessoais' => 'intimacoes-pessoais.php', 'Intimação nº ' . $id => 'intimacao.php?id=' . $id, 'Editar' => null];
$scripts      = ['intimacoes.js'];
$rotuloSalvar = 'Salvar alterações';

require APP_ROOT . '/Views/layout/cabecalho.php';
?>

<?php if (isset($erros['_geral'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span><?= e($erros['_geral']) ?></span></div>
<?php elseif ($erros !== [] && !isset($erros['confirmar_duplicidade'])): ?>
    <div class="alert alert--erro" role="alert"><span class="alert__icone">✕</span><span>Corrija os campos destacados.</span></div>
<?php endif; ?>

<?php require APP_ROOT . '/Views/partials/form-intimacao.php'; ?>

<?php require APP_ROOT . '/Views/layout/rodape.php'; ?>
