<?php
declare(strict_types=1);

namespace App\Validators;

use App\Core\Perfil;
use DateTimeImmutable;

/**
 * Normalizacao e validacao de formato dos dados de usuario.
 * Nao acessa o banco: unicidade e existencia de cargo/lotacao ficam no
 * UsuarioService. Cada metodo devolve [valorNormalizado, mensagemDeErro|null].
 */
final class UsuarioValidator
{
    public const NOME_MIN      = 3;
    public const NOME_MAX      = 150;
    public const EMAIL_MAX     = 254;
    public const MATRICULA_MAX = 30;
    public const DATA_MINIMA   = '1900-01-01';

    /** @return array{0:?string,1:?string} */
    public static function nome(mixed $valor): array
    {
        $nome = Normalizador::texto(is_string($valor) ? $valor : null);
        if ($nome === null) {
            return [null, 'Informe o nome completo.'];
        }
        $tamanho = mb_strlen($nome);
        if ($tamanho < self::NOME_MIN) {
            return [$nome, 'O nome deve ter pelo menos ' . self::NOME_MIN . ' caracteres.'];
        }
        if ($tamanho > self::NOME_MAX) {
            return [$nome, 'O nome deve ter no máximo ' . self::NOME_MAX . ' caracteres.'];
        }
        if (!preg_match('/\p{L}/u', $nome)) {
            return [$nome, 'O nome deve conter letras.'];
        }
        return [$nome, null];
    }

    /** @return array{0:?string,1:?string} */
    public static function email(mixed $valor): array
    {
        $email = is_string($valor) ? preg_replace('/\s+/u', '', $valor) : '';
        $email = mb_strtolower((string)$email);
        if ($email === '') {
            return [null, 'Informe o e-mail institucional.'];
        }
        if (strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [$email, 'Informe um e-mail válido, por exemplo usuario@ses.mt.gov.br.'];
        }
        return [$email, null];
    }

    /**
     * Matricula como texto (preserva zeros a esquerda), em maiusculas.
     * Aceita letras, digitos e os separadores . / -
     *
     * @return array{0:?string,1:?string}
     */
    public static function matricula(mixed $valor): array
    {
        $matricula = mb_strtoupper(trim(is_string($valor) ? $valor : ''));
        if ($matricula === '') {
            return [null, 'Informe a matrícula.'];
        }
        if (mb_strlen($matricula) > self::MATRICULA_MAX) {
            return [$matricula, 'A matrícula deve ter no máximo ' . self::MATRICULA_MAX . ' caracteres.'];
        }
        if (!preg_match('#^[A-Z0-9](?:[A-Z0-9./-]*[A-Z0-9])?$#', $matricula)) {
            return [$matricula, 'A matrícula aceita apenas letras, números e os separadores ". / -".'];
        }
        return [$matricula, null];
    }

    /**
     * Data de nascimento opcional (ou obrigatoria, conforme $obrigatoria).
     * Aceita dd/mm/aaaa ou aaaa-mm-dd; devolve ISO.
     *
     * @return array{0:?string,1:?string}
     */
    public static function dataNascimento(mixed $valor, bool $obrigatoria = false, ?DateTimeImmutable $hoje = null): array
    {
        $texto = Normalizador::texto(is_string($valor) ? $valor : null);
        if ($texto === null) {
            return [null, $obrigatoria ? 'Informe a data de nascimento.' : null];
        }
        $iso = Normalizador::dataIso($texto);
        if ($iso === null) {
            return [null, 'Data de nascimento inválida.'];
        }
        $hoje ??= new DateTimeImmutable('today');
        if ($iso > $hoje->format('Y-m-d')) {
            return [$iso, 'A data de nascimento não pode ser futura.'];
        }
        if ($iso < self::DATA_MINIMA) {
            return [$iso, 'Data de nascimento inválida.'];
        }
        return [$iso, null];
    }

    /** Id positivo de uma tabela de apoio (cargo/lotacao) ou null. */
    public static function id(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor > 0 ? $valor : null;
        }
        if (!is_string($valor) || !ctype_digit($valor)) {
            return null;
        }
        $id = (int)$valor;
        return $id > 0 ? $id : null;
    }

    /**
     * Perfil de acesso (ADMINISTRADOR | GESTOR | USUARIO).
     * @return array{0:?string,1:?string}
     */
    public static function perfil(mixed $valor): array
    {
        $perfil = is_string($valor) ? strtoupper(trim($valor)) : '';
        if ($perfil === '') {
            return [null, 'Selecione o perfil de acesso.'];
        }
        if (!Perfil::valido($perfil)) {
            return [null, 'Perfil de acesso inválido.'];
        }
        return [$perfil, null];
    }

    /** Status informado no formulario ('1'/'0'); null quando invalido. */
    public static function ativo(mixed $valor): ?bool
    {
        return match ($valor) {
            '1', 1, true  => true,
            '0', 0, false => false,
            default       => null,
        };
    }
}
