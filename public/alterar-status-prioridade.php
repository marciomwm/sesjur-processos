<?php
declare(strict_types=1);

/** Ativa ou inativa uma prioridade. Somente POST com CSRF. */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\PrioridadeService;
use App\Validators\UsuarioValidator;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('configuracoes.php?aba=prioridades');
}
Csrf::exigir();

$id    = entrada_int('id', $_POST);
$ativo = UsuarioValidator::ativo(entrada('ativo'));

// Retorno para a listagem com os mesmos filtros (apenas chaves conhecidas).
parse_str(entrada('retorno'), $retorno);
$retorno = array_intersect_key($retorno, array_flip(['busca', 'nivel', 'status', 'pagina']));
$destino = 'configuracoes.php?' . http_build_query(['aba' => 'prioridades'] + $retorno);

if ($id === null || $ativo === null) {
    flash('erro', 'Requisição inválida.');
    redirecionar($destino);
}

try {
    if (!(new PrioridadeService())->alterarStatus($id, $ativo)) {
        flash('info', 'A prioridade já estava ' . ($ativo ? 'ativa' : 'inativa') . '.');
    } else {
        flash('sucesso', $ativo
            ? 'Prioridade reativada com sucesso.'
            : 'Prioridade inativada. Os processos que já a possuem não foram alterados.');
    }
} catch (ValidationException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'prioridade.status');
    flash('erro', "Não foi possível alterar o status (código {$codigo}).");
}

redirecionar($destino);
