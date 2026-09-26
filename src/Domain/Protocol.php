<?php
declare(strict_types=1);
namespace Aibid\Domain;

final class Protocol
{
    public static function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    public static function encode(string $bytes): string { return sodium_bin2base64($bytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING); }
    public static function decode(string $value, int $size): string
    {
        try {
            if (!preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) { throw new \RuntimeException(); }
            $bytes = sodium_base642bin($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
            if (strlen($bytes) !== $size || self::encode($bytes) !== $value) { throw new \RuntimeException(); }
            return $bytes;
        } catch (\Throwable) { throw new ApiProblem('INVALID_REQUEST'); }
    }

    public static function uuid(mixed $value, bool $v4 = false): string
    {
        if (!is_string($value) || !preg_match($v4 ? '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i' : '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value)) { throw new ApiProblem('INVALID_REQUEST'); }
        return $value; // Preserve received bytes for the proof; compare UUIDs in binary.
    }

    public static function input(string $action, array $input): array
    {
        $fields = match ($action) {
            'challenge' => ['action','product_id','installation_id','activation_id'],
            'activate' => ['request_id','product_id','license_key','installation_id','installation_public_key','fingerprint_version','fingerprint_hash','challenge_id','proof','app_version'],
            'refresh','deactivate' => ['request_id','product_id','installation_id','activation_id','challenge_id','proof','app_version'],
            default => throw new ApiProblem('INVALID_REQUEST'),
        };
        $keys = array_keys($input); sort($keys); $expected = $fields; sort($expected);
        if ($keys !== $expected) { throw new ApiProblem('INVALID_REQUEST'); }
        foreach ($input as $field => $value) {
            if ($action === 'challenge' && $field === 'activation_id' && $value === null) { continue; }
            if (!is_string($value) || $value === '' || strlen($value) > 512 || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new ApiProblem('INVALID_REQUEST'); }
        }
        if (!preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $input['product_id'])) { throw new ApiProblem('INVALID_REQUEST'); }
        self::uuid($input['installation_id'], true);
        if ($action === 'challenge') {
            if (!in_array($input['action'], ['activate','refresh','deactivate'], true)) { throw new ApiProblem('INVALID_REQUEST'); }
            if ($input['action'] === 'activate') {
                if ($input['activation_id'] !== null) { throw new ApiProblem('INVALID_REQUEST'); }
            } else { self::uuid($input['activation_id']); }
        } else {
            self::uuid($input['request_id']); self::uuid($input['challenge_id']); self::decode($input['proof'], 64);
            if (strlen($input['app_version']) > 100) { throw new ApiProblem('INVALID_REQUEST'); }
            if ($action === 'activate') {
                self::decode($input['installation_public_key'], 32);
                if ($input['fingerprint_version'] !== '1' || !preg_match('/\Asha256:[a-f0-9]{64}\z/', $input['fingerprint_hash']) || strlen($input['license_key']) > 256) { throw new ApiProblem('INVALID_REQUEST'); }
            } else { self::uuid($input['activation_id']); }
        }
        ksort($input);
        return $input;
    }

    public static function proofMessage(string $action, array $input, string $nonce): string
    {
        return implode("\n", ['LIC-V1', $action, $input['challenge_id'], $nonce, $input['product_id'], $input['installation_id'], $input['activation_id'] ?? '-']);
    }
    public static function utc(?string $sql): ?string
    {
        return $sql === null ? null : (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
