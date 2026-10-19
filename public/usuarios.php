<?php
declare(strict_types=1);

/**
 * Rota preservada: a gestao de usuarios fica em Configuracoes > Usuarios.
 * Redireciona mantendo os filtros conhecidos (links de unidades, pesquisa...).
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Somente administradores.
App\Core\Auth::exigirPerfil(App\Core\Perfil::ADMINISTRADOR);

$filtros = array_intersect_key($_GET, array_flip(['busca', 'cargo_id', 'lotacao_id', 'perfil', 'status', 'pagina']));
$filtros = array_filter($filtros, 'is_string');
redirecionar('configuracoes.php?' . http_build_query(['aba' => 'usuarios'] + $filtros));
