<?php
declare(strict_types=1);
namespace Aibid\Domain;

final class Uuid
{
    public static function create(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return self::text($bytes);
    }

    public static function bytes(string $uuid): string
    {
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $uuid)) {
            throw new Problem('El identificador no es válido.', 400);
        }
        return hex2bin(str_replace('-', '', $uuid));
    }

    public static function text(string $bytes): string
    {
        if (strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('UUID binary length must be 16.');
        }
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
