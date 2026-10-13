<?php
declare(strict_types=1);

namespace Tests;

/**
 * Runner de testes minimalista (sem dependencias externas).
 * Uso: /Applications/XAMPP/bin/php tests/run-tests.php
 */
final class Runner
{
    private array $grupos = [];
    private string $grupoAtual = '';
    private int $ok = 0;
    private int $falhas = 0;
    private int $ignorados = 0;

    public function grupo(string $nome): void
    {
        $this->grupoAtual = $nome;
        $this->grupos[$nome] ??= [];
    }

    public function teste(string $descricao, callable $fn): void
    {
        try {
            $fn($this);
            $this->registrar('ok', $descricao, '');
        } catch (Ignorado $e) {
            $this->ignorados++;
            $this->grupos[$this->grupoAtual][] = ['estado' => 'skip', 'descricao' => $descricao, 'detalhe' => $e->getMessage()];
        } catch (\Throwable $e) {
            $this->registrar('falha', $descricao, $e->getMessage());
        }
    }

    public function afirmar(bool $condicao, string $mensagem): void
    {
        if (!$condicao) {
            throw new \RuntimeException($mensagem);
        }
    }

    public function igual(mixed $recebido, mixed $esperado, string $mensagem): void
    {
        if ($recebido !== $esperado) {
            throw new \RuntimeException(sprintf(
                '%s — esperado %s, recebido %s',
                $mensagem,
                var_export($esperado, true),
                var_export($recebido, true)
            ));
        }
    }

    public function contem(?string $texto, string $trecho, string $mensagem): void
    {
        if ($texto === null || !str_contains($texto, $trecho)) {
            throw new \RuntimeException($mensagem . ' — "' . $trecho . '" não encontrado em ' . var_export($texto, true));
        }
    }

    /** Espera que o callback lance a excecao informada. */
    public function lanca(string $classe, callable $fn, string $mensagem): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $classe) {
                return $e;
            }
            throw new \RuntimeException($mensagem . ' — esperada ' . $classe . ', lançada ' . $e::class . ': ' . $e->getMessage());
        }
        throw new \RuntimeException($mensagem . ' — nenhuma exceção foi lançada');
    }

    public function ignorar(string $motivo): never
    {
        throw new Ignorado($motivo);
    }

    private function registrar(string $estado, string $descricao, string $detalhe): void
    {
        $this->grupos[$this->grupoAtual][] = ['estado' => $estado, 'descricao' => $descricao, 'detalhe' => $detalhe];
        if ($estado === 'ok') {
            $this->ok++;
        } else {
            $this->falhas++;
        }
    }

    public function relatorio(): int
    {
        $cor = static fn(string $c, string $t): string => "\033[{$c}m{$t}\033[0m";

        foreach ($this->grupos as $nome => $itens) {
            echo "\n" . $cor('1;34', '▸ ' . $nome) . "\n";
            foreach ($itens as $item) {
                if ($item['estado'] === 'ok') {
                    echo '  ' . $cor('32', '✓') . ' ' . $item['descricao'] . "\n";
                } elseif ($item['estado'] === 'skip') {
                    echo '  ' . $cor('33', '○') . ' ' . $item['descricao'] . $cor('33', ' (ignorado: ' . $item['detalhe'] . ')') . "\n";
                } else {
                    echo '  ' . $cor('31', '✕') . ' ' . $item['descricao'] . "\n";
                    echo '      ' . $cor('31', $item['detalhe']) . "\n";
                }
            }
        }

        $total = $this->ok + $this->falhas;
        echo "\n" . str_repeat('─', 66) . "\n";
        echo sprintf(
            "%s  %d/%d testes aprovados · %d falha(s) · %d ignorado(s)\n",
            $this->falhas === 0 ? $cor('1;32', 'SUCESSO') : $cor('1;31', 'FALHA  '),
            $this->ok,
            $total,
            $this->falhas,
            $this->ignorados
        );

        return $this->falhas === 0 ? 0 : 1;
    }
}

final class Ignorado extends \RuntimeException
{
}
