<?php
declare(strict_types=1);

/**
 * Bootstrap da aplicacao: autoloader PSR-4 simplificado, configuracao,
 * sessao, timezone e tratamento de erros.
 */

define('APP_ROOT', __DIR__);
define('PROJECT_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = APP_ROOT . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Arquivo app/config/config.php nao encontrado. Copie app/config/config.example.php.');
}

/** @var array<string,mixed> $GLOBALS['gj_config'] */
$GLOBALS['gj_config'] = require $configFile;

date_default_timezone_set($GLOBALS['gj_config']['app']['timezone'] ?? 'America/Cuiaba');

$debug = (bool)($GLOBALS['gj_config']['app']['debug'] ?? false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');

require_once APP_ROOT . '/Core/helpers.php';

// Excecao nao tratada: detalhes vao para o log do servidor; o usuario ve
// apenas uma mensagem generica com o codigo de rastreio (salvo em debug).
if (PHP_SAPI !== 'cli') {
    set_exception_handler(static function (Throwable $e) use ($debug): void {
        // Falta de permissao nao e erro interno: 403 com a mensagem, sem log.
        if ($e instanceof App\Core\AcessoNegadoException) {
            if (defined('GJ_API')) {
                responder_json(['success' => false, 'error' => ['code' => 'ACESSO_NEGADO', 'message' => $e->getMessage()]], 403);
            }
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><title>Acesso negado</title>'
                . '<body style="font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 16px;color:#152238">'
                . '<h1 style="font-size:20px">Acesso negado</h1><p>' . e($e->getMessage()) . '</p>'
                . '<p><a href="' . e(url('index.php')) . '">Voltar ao painel</a></p></body></html>';
            return;
        }

        $codigo = log_erro($e, 'nao-tratada');
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><title>Erro</title>'
            . '<body style="font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 16px;color:#152238">'
            . '<h1 style="font-size:20px">Não foi possível concluir a operação</h1>'
            . '<p>Ocorreu um erro interno. Tente novamente em instantes. Se o problema persistir, '
            . 'informe ao suporte o código <strong>' . e($codigo) . '</strong>.</p>'
            . ($debug ? '<pre style="white-space:pre-wrap;background:#f4f4f8;padding:12px;border-radius:8px">'
                . e($e::class . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()) . '</pre>' : '')
            . '<p><a href="' . e(url('index.php')) . '">Voltar ao painel</a></p></body></html>';
    });
}

// A API de Integracao (api/v1/) autentica por token e nao usa sessao.
if (PHP_SAPI !== 'cli' && !defined('GJ_SEM_SESSAO') && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off',
    ]);
    session_start();
}

// Toda pagina e endpoint exige login, exceto as que se declaram publicas
// (define('GJ_ROTA_PUBLICA', true) antes de incluir este arquivo).
if (PHP_SAPI !== 'cli' && !defined('GJ_ROTA_PUBLICA')) {
    App\Core\Auth::exigirLogin();
}
