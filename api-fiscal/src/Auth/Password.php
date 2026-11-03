<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Hash (Argon2id, se disponível; senão bcrypt) e política de força de senha.
 */
final class Password
{
    public const MIN = 8;
    public const MAX = 128;

    /** Lista curta das senhas mais comuns (comparação sem diferenciar maiúsculas). */
    private const COMMON = [
        'password', 'password1', 'password123', '12345678', '123456789', '1234567890', 'qwerty123', 'qwertyuiop',
        'senha123', 'senha1234', 'mudar123', 'mudar@123', 'admin123', 'admin@123', 'abc12345', 'abcd1234',
        'iloveyou', '11111111', '00000000', '87654321', 'brasil123', 'nfe12345', 'teste123', 'teste@123',
        'senha@123', 'Senha@123', 'Senha123', 'P@ssw0rd', 'Passw0rd', 'Password1!', 'Mudar@123', 'Admin@123',
    ];

    public static function algo(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function hash(string $plain): string
    {
        return password_hash($plain, self::algo());
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo());
    }

    /** Hash fixo para gastar o mesmo tempo quando o usuário não existe. */
    public static function dummyHash(): string
    {
        static $h = null;
        return $h ??= password_hash('dummy-' . bin2hex(random_bytes(8)), self::algo());
    }

    /**
     * Valida a força. Devolve mensagem de erro (pt-BR) ou null se aceitável.
     * Regras: 8 a 128 caracteres; 3 de 4 classes (minúscula, maiúscula, dígito,
     * símbolo) OU frase longa (>= 14) com 2 classes; não pode ser senha comum,
     * repetição de um caractere ou conter o e-mail/nome.
     *
     * @param list<string> $context termos pessoais (e-mail, nome, empresa)
     */
    public static function problem(mixed $pw, array $context = []): ?string
    {
        if (!is_string($pw)) {
            return 'Senha obrigatória';
        }
        $len = mb_strlen($pw);
        if ($len < self::MIN) {
            return 'A senha deve ter pelo menos ' . self::MIN . ' caracteres';
        }
        if ($len > self::MAX) {
            return 'A senha deve ter no máximo ' . self::MAX . ' caracteres';
        }
        if (self::algo() === PASSWORD_BCRYPT && strlen($pw) > 72) {
            return 'A senha deve ter no máximo 72 bytes';
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $pw)) {
            return 'A senha contém caracteres de controle';
        }
        $classes = (int) preg_match('/[a-z]/', $pw) + (int) preg_match('/[A-Z]/', $pw)
            + (int) preg_match('/\d/', $pw) + (int) preg_match('/[^a-zA-Z\d]/', $pw);
        if (!($classes >= 3 || ($len >= 14 && $classes >= 2))) {
            return 'Senha fraca: combine letras maiúsculas, minúsculas, números e símbolos (ou use uma frase com 14+ caracteres)';
        }
        $lower = mb_strtolower($pw);
        foreach (self::COMMON as $c) {
            if ($lower === mb_strtolower($c)) {
                return 'Senha muito comum. Escolha outra.';
            }
        }
        $chars = mb_str_split($pw);
        if (count(array_unique($chars)) < 4 || max(array_count_values($chars)) * 2 > $len) {
            return 'Senha muito repetitiva';
        }
        foreach ($context as $term) {
            $local = explode('@', mb_strtolower($term))[0];
            foreach (preg_split('/[\s._\-+]+/u', $local) ?: [] as $piece) {
                if (mb_strlen($piece) >= 4 && str_contains($lower, $piece)) {
                    return 'A senha não pode conter seu nome ou e-mail';
                }
            }
        }
        return null;
    }
}
