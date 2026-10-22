<?php
declare(strict_types=1);
namespace Aibid\Domain;

final class ActivationCode
{
    // 31 symbols, 15 independent draws: 74.31 bits. No checksum consumes secret bits.
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function generate(): string
    {
        $raw = '';
        for ($i = 0; $i < 15; ++$i) { $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)]; }
        return self::format($raw);
    }

    public static function normalize(string $input): ?string
    {
        $value = strtoupper(trim($input));
        $chars = '[' . self::ALPHABET . ']';
        if (!preg_match('/\A(?:'.$chars.'{15}|'.$chars.'{3}-'.$chars.'{4}-'.$chars.'{4}-'.$chars.'{4})\z/', $value)) { return null; }
        return self::format(str_replace('-', '', $value));
    }

    private static function format(string $raw): string
    {
        return substr($raw, 0, 3).'-'.substr($raw, 3, 4).'-'.substr($raw, 7, 4).'-'.substr($raw, 11, 4);
    }
}
