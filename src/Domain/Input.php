<?php
declare(strict_types=1);
namespace Aibid\Domain;

final class Input
{
    public static function text(array $input, string $key, int $max = 190, bool $required = true): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new Problem('El campo ' . $key . ' no es válido.');
        }
        $value = trim($value);
        if (($required && $value === '') || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
            throw new Problem('Revisa el campo ' . $key . '.');
        }
        return $value;
    }

    public static function choice(array $input, string $key, array $choices): string
    {
        $value = self::text($input, $key);
        if (!in_array($value, $choices, true)) {
            throw new Problem('La opción de ' . $key . ' no es válida.');
        }
        return $value;
    }

    public static function identifier(string $value): string
    {
        if (!preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $value)) {
            throw new Problem('El identificador debe usar letras minúsculas, números y guiones bajos (2 a 64 caracteres).');
        }
        return $value;
    }

    public static function date(string $value): \DateTimeImmutable
    {
        $format = strlen($value) === 16 ? '!Y-m-d\TH:i' : '!Y-m-d\TH:i:s';
        $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format(substr($format, 1)) !== $value) {
            throw new Problem('Introduce una fecha UTC válida.');
        }
        return $date;
    }

    public static function email(string $email): string
    {
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Problem('El correo electrónico no es válido.');
        }
        return $email;
    }

    public static function password(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 1024) {
            throw new Problem('La contraseña debe tener entre 12 y 1024 bytes.');
        }
    }
}
