<?php
declare(strict_types=1);

/**
 * Remove apenas o vinculo entre um processo administrativo e um processo
 * judicial (judicial_id) ou extrajudicial (extrajudicial_id). Nenhum
 * processo e excluido.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\ValidationException;
use App\Services\ProcessoExtrajudicialService;
use App\Services\VinculoService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('index.php');
}
Csrf::exigir();

$administrativoId = entrada_int('administrativo_id', $_POST);
$judicialId       = entrada_int('judicial_id', $_POST);
$extrajudicialId  = entrada_int('extrajudicial_id', $_POST);
$retorno          = entrada('retorno') ?: 'index.php';

// Evita open redirect: apenas caminhos internos (com a guia/ancora, se houver) sao aceitos.
if (preg_match('~^[a-z0-9\-]+\.php(\?[\w\-=&%.]*)?(#[\w\-]+)?$~i', $retorno) !== 1) {
    $retorno = 'index.php';
}

if ($administrativoId === null || ($judicialId === null && $extrajudicialId === null)) {
    flash('erro', 'Vínculo não informado corretamente.');
    redirecionar($retorno);
}

try {
    $removido = $extrajudicialId !== null
        ? (new ProcessoExtrajudicialService())->desvincular($administrativoId, $extrajudicialId)
        : (new VinculoService())->desvincular($administrativoId, (int)$judicialId);
    flash(
        $removido ? 'sucesso' : 'aviso',
        $removido
            ? 'Vínculo removido. Nenhum processo foi excluído.'
            : 'O vínculo informado não existia.'
    );
} catch (ValidationException $e) {
    flash('erro', $e->getMessage());
} catch (Throwable $e) {
    $codigo = log_erro($e, 'vinculo.remover');
    flash('erro', "Não foi possível remover o vínculo (código {$codigo}).");
}

redirecionar($retorno);
