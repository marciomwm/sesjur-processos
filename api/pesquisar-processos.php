<?php
declare(strict_types=1);

/**
 * Autocomplete unico de processos ja cadastrados - judiciais (numero CNJ),
 * extrajudiciais e administrativos (protocolo) -, com o tipo de cada um.
 * Usado pelas Intimacoes Pessoais.
 */

require_once __DIR__ . '/_bootstrap.php';

// Somente administradores e gestores.
App\Services\IntimacaoPessoalService::exigirAcesso();

use App\Repositories\ProcessoAdministrativoRepository;
use App\Repositories\ProcessoExtrajudicialRepository;
use App\Repositories\ProcessoJudicialRepository;
use App\Validators\CnjValidator;
use App\Validators\ProtocoloValidator;

exigir_metodo('GET');

$termo = mb_substr(trim((string)($_GET['termo'] ?? '')), 0, 100);
if (mb_strlen($termo) < 3) {
    responder_json(['success' => true, 'itens' => []]);
}

$protocolo = ProtocoloValidator::normalizar($termo);
$protocolo = $protocolo !== null && ProtocoloValidator::valido($protocolo) ? $protocolo : $termo;

try {
    $judiciais = (new ProcessoJudicialRepository())->pesquisar(CnjValidator::normalizar($termo) ?? $termo, 6);
    $extrajudiciais = (new ProcessoExtrajudicialRepository())->pesquisar($termo, 6);
    $administrativos = (new ProcessoAdministrativoRepository())->pesquisar($protocolo, 6);
} catch (Throwable $e) {
    log_erro($e, 'api.processos');
    responder_json([
        'success' => false,
        'error'   => ['code' => 'DB_ERROR', 'message' => 'Falha ao consultar o banco de dados.'],
    ], 500);
}

$itens = [];
foreach ($judiciais as $p) {
    $itens[] = ['id' => (int)$p['id'], 'tipo' => 'JUDICIAL', 'rotulo' => $p['numero_cnj'],
                'detalhe' => implode(' · ', array_filter(['Judicial', $p['orgao_julgador'] ?? null, $p['classe_judicial'] ?? null]))];
}
foreach ($extrajudiciais as $p) {
    $itens[] = ['id' => (int)$p['id'], 'tipo' => 'EXTRAJUDICIAL', 'rotulo' => $p['protocolo'],
                'detalhe' => implode(' · ', array_filter(['Extrajudicial', $p['instituicao_sigla'] ?: $p['instituicao_nome'],
                    mb_strimwidth((string)$p['assunto'], 0, 80, '…')]))];
}
foreach ($administrativos as $p) {
    $itens[] = ['id' => (int)$p['id'], 'tipo' => 'ADMINISTRATIVO', 'rotulo' => $p['protocolo'],
                'detalhe' => implode(' · ', array_filter(['Administrativo', data_br($p['data_abertura']),
                    mb_strimwidth((string)($p['objeto'] ?? ''), 0, 80, '…')]))];
}

responder_json(['success' => true, 'itens' => $itens]);
