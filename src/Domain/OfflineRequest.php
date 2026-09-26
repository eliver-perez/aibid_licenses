<?php
declare(strict_types=1);
namespace Aibid\Domain;

final class OfflineRequest
{
    public static function verify(string $envelope): array
    {
        $outer = StrictJson::object($envelope, 65536);
        $keys = array_keys($outer); sort($keys);
        if ($keys !== ['payload_b64u','schema_version','signature_b64u']) { throw new ApiProblem('INVALID_REQUEST'); }
        if ($outer['schema_version'] !== '1.0') { throw new ApiProblem('INCOMPATIBLE_SCHEMA'); }
        if (!is_string($outer['payload_b64u']) || !is_string($outer['signature_b64u'])) { throw new ApiProblem('INVALID_REQUEST'); }
        try { $bytes = sodium_base642bin($outer['payload_b64u'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING); }
        catch (\Throwable) { throw new ApiProblem('INVALID_REQUEST'); }
        if (Protocol::encode($bytes) !== $outer['payload_b64u']) { throw new ApiProblem('INVALID_REQUEST'); }
        $payload = StrictJson::object($bytes, 49152);
        $fields = ['action','request_id','created_at','product_id','installation_id','installation_public_key','fingerprint_version','fingerprint_hash'];
        if (!in_array($payload['action'] ?? null, ['activate','renew','deactivate'], true)) { throw new ApiProblem('INVALID_REQUEST'); }
        if ($payload['action'] !== 'activate') { $fields = [...$fields, 'license_id','activation_id']; }
        if (array_key_exists('license_key', $payload)) { $fields[] = 'license_key'; }
        $actual = array_keys($payload); sort($actual); sort($fields);
        if ($actual !== $fields) { throw new ApiProblem('INVALID_REQUEST'); }
        foreach ($payload as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 256 || preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new ApiProblem('INVALID_REQUEST'); }
        }
        Protocol::uuid($payload['request_id']); Protocol::uuid($payload['installation_id'], true);
        if ($payload['action'] !== 'activate') { Protocol::uuid($payload['license_id']); Protocol::uuid($payload['activation_id']); }
        if (!preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $payload['product_id']) || $payload['fingerprint_version'] !== '1' || !preg_match('/\Asha256:[a-f0-9]{64}\z/', $payload['fingerprint_hash'])) { throw new ApiProblem('INVALID_REQUEST'); }
        // Validate calendar and UTC without normalizing the signed bytes or trusting the device clock.
        if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|\+00:00)\z/', $payload['created_at'], $date) || !checkdate((int)$date[2], (int)$date[3], (int)$date[1]) || (int)$date[4] > 23 || (int)$date[5] > 59 || (int)$date[6] > 59) { throw new ApiProblem('INVALID_REQUEST'); }
        $public = Protocol::decode($payload['installation_public_key'], 32);
        if (!sodium_crypto_sign_verify_detached(Protocol::decode($outer['signature_b64u'],64), "LICREQ-V1\n" . $outer['payload_b64u'], $public)) { throw new ApiProblem('INVALID_PROOF',401); }
        unset($payload['license_key']); // Only the encrypted original may contain a commercial credential.
        return ['payload'=>$payload, 'public'=>$public, 'digest_material'=>Protocol::json(['schema_version'=>'1.0','payload_b64u'=>$outer['payload_b64u'],'signature_b64u'=>$outer['signature_b64u']])];
    }
}
