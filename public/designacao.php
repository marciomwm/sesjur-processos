<?php
declare(strict_types=1);

/**
 * Designa um usuario a um processo ou remove a designacao.
 * Somente POST com CSRF. A permissao (administrador ou gestor) e
 * verificada no DesignacaoService.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AcessoNegadoException;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Repositories\DesignacaoRepository;
use App\Services\DesignacaoService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('index.php');
}
Csrf::exigir();

$tipo       = entrada('tipo');
$processoId = entrada_int('processo_id', $_POST);
$acao       = entrada('acao');

if (!DesignacaoRepository::tipoValido($tipo) || $processoId === null) {
    flash('erro', 'Requisição inválida.');
    redirecionar('index.php');
}

$ficha = 'processo-administrativo.php?id=' . $processoId;
$servico = new DesignacaoService();

try {
    if ($acao === 'designar') {
        $servico->designar($tipo, $processoId, entrada('usuario_id'), entrada('observacao'));
        flash('sucesso', 'Usuário designado para o processo.');
    } elseif ($acao === 'remover') {
        $designacaoId = entrada_int('designacao_id', $_POST);
        $removida = $designacaoId !== null && $servico->remover($tipo, $processoId, $designacaoId);
        flash($removida ? 'sucesso' : 'info', $removida ? 'Designação removida.' : 'A designação informada não existia mais.');
    } else {
        flash('erro', 'Requisição inválida.');
    }
} catch (ValidationException $e) {
    flash('erro', implode(' ', array_values($e->erros())) ?: $e->getMessage());
} catch (AcessoNegadoException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'designacao.' . $acao);
    flash('erro', "Não foi possível alterar a designação (código {$codigo}).");
}

redirecionar($ficha . '#designacao');
