<?php
declare(strict_types=1);
namespace Aibid\Http;

final class Network
{
    public static function allows(string $networks, string $address): bool
    {
        if (trim($networks) === '') { return true; }
        $packedAddress = @inet_pton($address);
        if ($packedAddress === false) { return false; }
        foreach (explode(',', $networks) as $network) {
            [$base, $prefix] = array_pad(explode('/', trim($network), 2), 2, null);
            $packedBase = @inet_pton($base);
            if ($packedBase === false || strlen($packedBase) !== strlen($packedAddress)) { continue; }
            $bits = $prefix === null ? strlen($packedBase) * 8 : (ctype_digit($prefix) ? (int) $prefix : -1);
            if ($bits < 0 || $bits > strlen($packedBase) * 8) { continue; }
            $matches = true;
            for ($index = 0; $index < strlen($packedBase); ++$index) {
                $remaining = max(0, min(8, $bits - $index * 8));
                $mask = $remaining === 0 ? 0 : (0xff << (8 - $remaining)) & 0xff;
                if ((ord($packedBase[$index]) & $mask) !== (ord($packedAddress[$index]) & $mask)) { $matches = false; break; }
            }
            if ($matches) { return true; }
        }
        return false;
    }
}
