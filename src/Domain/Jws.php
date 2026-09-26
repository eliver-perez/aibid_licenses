<?php
declare(strict_types=1);
namespace Aibid\Domain;

final class Jws
{
    public static function sign(array $payload, string $kid, string $secret): string
    {
        $message = Protocol::encode(Protocol::json(['alg'=>'EdDSA','kid'=>$kid,'typ'=>'lic+jws'])) . '.' . Protocol::encode(Protocol::json($payload));
        return $message . '.' . Protocol::encode(sodium_crypto_sign_detached($message, $secret));
    }
}
