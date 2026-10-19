<?php
declare(strict_types=1);

/**
 * Rota preservada: o cadastro de prioridades fica em Configuracoes >
 * Prioridades (mesmo PrioridadeService). Redireciona mantendo os filtros.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

$filtros = array_intersect_key($_GET, array_flip(['busca', 'nivel', 'status', 'pagina']));
$filtros = array_filter($filtros, 'is_string');
redirecionar('configuracoes.php?' . http_build_query(['aba' => 'prioridades'] + $filtros));
