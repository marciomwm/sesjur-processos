<?php
declare(strict_types=1);

/**
 * Ativa ou inativa um item de tabela de apoio (cargo, instituicao, nivel).
 * Somente POST com CSRF.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Administradores (Pastas e Areas de Recursos: tambem gestores).
App\Services\TabelaApoioService::exigirAcesso(entrada('tipo'));

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\TabelaApoioService;
use App\Validators\UsuarioValidator;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('usuarios.php');
}
Csrf::exigir();

$tipo  = entrada('tipo');
$id    = entrada_int('id', $_POST);
$ativo = UsuarioValidator::ativo(entrada('ativo'));

if (!TabelaApoioService::tipoValido($tipo) || $id === null || $ativo === null) {
    flash('erro', 'Requisição inválida.');
    redirecionar('usuarios.php');
}

// Retorno para a listagem com os mesmos filtros (apenas chaves conhecidas).
parse_str(entrada('retorno'), $retorno);
$retorno = ['tipo' => $tipo] + array_intersect_key($retorno, array_flip(['busca', 'status', 'pai_id']));

$servico = new TabelaApoioService($tipo);
$meta    = $servico->meta();
$nome    = ucfirst($meta['singular']);
$fim     = $meta['artigo'];

try {
    if (!$servico->alterarStatus($id, $ativo)) {
        flash('info', $nome . ' já estava ' . ($ativo ? 'ativ' : 'inativ') . $fim . '.');
    } elseif ($ativo) {
        flash('sucesso', $nome . ' reativad' . $fim . ' com sucesso.');
    } else {
        flash('sucesso', $nome . ' inativad' . $fim . '. Registros que já ' . ($fim === 'a' ? 'a' : 'o') . ' usam não foram alterados.');
    }
} catch (ValidationException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, $tipo . '.status');
    flash('erro', "Não foi possível alterar o status (código {$codigo}).");
}

redirecionar('tabela-apoio.php?' . http_build_query($retorno));
