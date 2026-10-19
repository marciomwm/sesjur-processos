<?php
declare(strict_types=1);

/** Ativa ou inativa um procedimento do catálogo. Somente POST com CSRF. */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\ProcedimentoService;
use App\Validators\UsuarioValidator;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('configuracoes.php?aba=procedimentos');
}
Csrf::exigir();

$id    = entrada_int('id', $_POST);
$ativo = UsuarioValidator::ativo(entrada('ativo'));

// Retorno para a guia com os mesmos filtros (apenas chaves conhecidas).
parse_str(entrada('retorno'), $retorno);
$retorno = array_intersect_key($retorno, array_flip(['busca', 'status', 'direcao', 'pagina']));
$destino = 'configuracoes.php?' . http_build_query(['aba' => 'procedimentos'] + $retorno);

if ($id === null || $ativo === null) {
    flash('erro', 'Requisição inválida.');
    redirecionar($destino);
}

try {
    if (!(new ProcedimentoService())->alterarStatus($id, $ativo)) {
        flash('info', 'O procedimento já estava ' . ($ativo ? 'ativo' : 'inativo') . '.');
    } else {
        flash('sucesso', $ativo
            ? 'Procedimento reativado: volta a poder ser escolhido nos processos.'
            : 'Procedimento inativado. Os processos que já o possuem não foram alterados.');
    }
} catch (ValidationException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'procedimento.status');
    flash('erro', "Não foi possível alterar o status (código {$codigo}).");
}

redirecionar($destino);
