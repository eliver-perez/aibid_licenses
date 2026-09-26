<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

use Aibid\Config;

final class Crypto
{
    public function __construct(private Config $config) {}

    public function digest(string $purpose, string $data): string
    {
        return hash_hmac('sha256', $data, $this->config->key($purpose));
    }

    public function encrypt(string $plaintext, string $context): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $this->config->key('MFA_KEY')));
    }

    public function decrypt(string $ciphertext, string $context): string
    {
        $bytes = base64_decode($ciphertext, true);
        if ($bytes === false || strlen($bytes) < 40) {
            throw new \RuntimeException('Invalid encrypted record.');
        }
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($bytes, 24), $context, substr($bytes, 0, 24), $this->config->key('MFA_KEY'));
        if ($plaintext === false) {
            throw new \RuntimeException('Encrypted record authentication failed.');
        }
        return $plaintext;
    }

    public static function token(): string
    {
        return sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function encryptEvidence(string $plaintext, string $context): string
    {
        $nonce = random_bytes(24);
        $key = hash_hkdf('sha256', $this->config->key('CREDENTIAL_KEY'), 32, 'aibid/offline-evidence/v1');
        try { return base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $key)); }
        finally { sodium_memzero($key); }
    }

    public function decryptEvidence(string $ciphertext, string $context): string
    {
        $bytes = base64_decode($ciphertext, true);
        if ($bytes === false || strlen($bytes) < 40) { throw new \RuntimeException('Invalid evidence.'); }
        $key = hash_hkdf('sha256', $this->config->key('CREDENTIAL_KEY'), 32, 'aibid/offline-evidence/v1');
        try { $result = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($bytes,24), $context, substr($bytes,0,24), $key); }
        finally { sodium_memzero($key); }
        if ($result === false) { throw new \RuntimeException('Evidence authentication failed.'); }
        return $result;
    }
}
