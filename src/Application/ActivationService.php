<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Domain\{ApiProblem, ApiResponse, Protocol, Uuid};
use Aibid\Infrastructure\{Audit, Clock, Crypto, Database, RateLimiter};

final class ActivationService
{
    public function __construct(private Database $db, private Clock $clock, private Crypto $crypto, private Audit $audit, private RevisionPublisher $publisher, private RateLimiter $rate) {}

    public function challenge(array $input): ApiResponse
    {
        $input = Protocol::input('challenge', $input);
        $id = Uuid::create(); $nonce = Crypto::token();
        $expires = $this->clock->now()->modify('+5 minutes')->format('Y-m-d H:i:s.u');
        $this->db->execute('INSERT INTO activation_challenges(challenge_id,action,product_id,installation_id,activation_id,nonce,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?)', [Uuid::bytes($id),$input['action'],$input['product_id'],Uuid::bytes($input['installation_id']),$input['activation_id'] === null ? null : Uuid::bytes($input['activation_id']),$nonce,$this->clock->sql(),$expires]);
        return ApiResponse::data(['challenge_id'=>$id,'nonce'=>$nonce,'expires_at'=>Protocol::utc($expires)]);
    }

    public function execute(string $action, array $input): ApiResponse
    {
        $input = Protocol::input($action, $input);
        $requestBytes = Uuid::bytes($input['request_id']);
        $digest = $this->crypto->digest('OPERATION_KEY', 'protocol-v1:online:' . $action . "\n" . Protocol::json($input));
        $saved = $this->db->one('SELECT * FROM license_requests WHERE product_id=? AND request_id=?', [$input['product_id'],$requestBytes]);
        if ($saved && $saved['channel'] === 'online' && $saved['response_body'] !== null) {
            $this->authenticateSaved($saved, $input, $action, $digest);
            $this->limitIdentity($input, $saved['public_key']);
            return new ApiResponse((int)$saved['response_status'], $saved['response_body']);
        }
        // Verify possession before reserving idempotency or taking business locks.
        $challenge = $this->db->one('SELECT * FROM activation_challenges WHERE challenge_id=?', [Uuid::bytes($input['challenge_id'])]);
        $this->matchChallenge($challenge, $input, $action, false);
        $public = $action === 'activate' ? Protocol::decode($input['installation_public_key'], 32) : $this->identity($input)['installation_public_key'];
        $message = Protocol::proofMessage($action, $input, $challenge['nonce']);
        $this->verify($input['proof'], $message, $public);
        $this->limitIdentity($input, $public);

        return $this->db->transaction(function () use ($action, $input, $requestBytes, $digest, $public, $message): ApiResponse {
            $this->db->execute("INSERT INTO license_requests(product_id,request_id,channel,action,input_digest,installation_id,activation_id,public_key,proof_message,created_at) VALUES (?,?,'online',?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE request_id=request_id", [$input['product_id'],$requestBytes,$action,$digest,Uuid::bytes($input['installation_id']),isset($input['activation_id']) ? Uuid::bytes($input['activation_id']) : null,$public,$message,$this->clock->sql()]);
            $record = $this->db->one('SELECT * FROM license_requests WHERE product_id=? AND request_id=? FOR UPDATE', [$input['product_id'],$requestBytes]);
            $this->authenticateSaved($record, $input, $action, $digest);
            if ($record['response_body'] !== null) { return new ApiResponse((int)$record['response_status'], $record['response_body']); }
            $challenge = $this->db->one('SELECT * FROM activation_challenges WHERE challenge_id=? FOR UPDATE', [Uuid::bytes($input['challenge_id'])]);
            $this->matchChallenge($challenge, $input, $action, true);
            $this->verify($input['proof'], Protocol::proofMessage($action, $input, $challenge['nonce']), $public);

            $this->db->one('SELECT product_id FROM products WHERE product_id=? FOR SHARE', [$input['product_id']]);
            $response = $action === 'activate' ? $this->activate($input) : $this->existing($action, $input, $public);
            // Authenticated commercial refusals also consume their challenge and
            // persist their response. Infrastructure/proof failures roll back.
            $this->db->execute('UPDATE activation_challenges SET consumed_at=?,consumed_request_id=? WHERE challenge_id=?', [$this->clock->sql(),$requestBytes,Uuid::bytes($input['challenge_id'])]);
            $this->db->execute('UPDATE license_requests SET response_status=?,response_body=?,completed_at=? WHERE product_id=? AND request_id=?', [$response->status,$response->body,$this->clock->sql(),$input['product_id'],$requestBytes]);
            $this->audit->append(null, 'activation.' . $action, 'request', Uuid::text($requestBytes), ['product_id'=>$input['product_id'],'http_status'=>$response->status]);
            return $response;
        }, 2);
    }

    private function activate(array $input): ApiResponse
    {
        $digest = $this->crypto->digest('CREDENTIAL_KEY', $input['license_key']);
        $candidate = $this->db->one('SELECT c.license_id FROM license_credentials c JOIN licenses l ON l.license_id=c.license_id WHERE c.key_digest=? AND c.digest_key_version=1 AND l.product_id=?', [$digest,$input['product_id']]);
        if (!$candidate) { return $this->failure('LICENSE_NOT_FOUND', 404, $input); }
        $license = $this->db->one('SELECT * FROM licenses WHERE license_id=? FOR UPDATE', [$candidate['license_id']]);
        $credential = $this->db->one('SELECT state FROM license_credentials WHERE key_digest=? AND digest_key_version=1 AND license_id=?', [$digest,$license['license_id']]);
        if ($license['commercial_status'] === 'revoked') { return $this->failure('REVOKED', 403, $input); }
        if (!$credential || $credential['state'] !== 'active') { return $this->failure('LICENSE_NOT_FOUND', 404, $input); }
        if ($this->db->one("SELECT activation_id FROM activations WHERE license_id=? AND state='active' FOR UPDATE", [$license['license_id']])) { return $this->failure('ACTIVATION_LIMIT', 409, $input); }
        $id = Uuid::bytes(Uuid::create());
        $this->db->execute("INSERT INTO activations(activation_id,license_id,product_id,installation_id,installation_public_key,fingerprint_version,fingerprint_hash,state,activated_at) VALUES (?,?,?,?,?,?,?,'active',?)", [$id,$license['license_id'],$input['product_id'],Uuid::bytes($input['installation_id']),Protocol::decode($input['installation_public_key'],32),$input['fingerprint_version'],$input['fingerprint_hash'],$this->clock->sql()]);
        $revision = $this->publisher->publish($license['license_id'], $id);
        return $this->licenseResponse($revision['jws'], $input);
    }

    private function existing(string $action, array $input, string $public): ApiResponse
    {
        $candidate = $this->identity($input);
        $this->db->one('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE', [$candidate['license_id']]);
        $activation = $this->identity($input, true);
        if (!hash_equals($public, $activation['installation_public_key'])) { throw new ApiProblem('INVALID_PROOF', 401); }
        if ($action === 'refresh') {
            $revision = $this->db->one('SELECT license_jws FROM license_revisions WHERE revision_id=? AND activation_id=?', [$activation['current_revision_id'],$activation['activation_id']]);
            if (!$revision) { throw new \RuntimeException('Missing current revision.'); }
            return $this->licenseResponse($revision['license_jws'], $input);
        }
        if ($activation['state'] === 'revoked') { return $this->failure('REVOKED', 403, $input); }
        if ($activation['state'] === 'active') {
            $activation['ended_at'] = $this->clock->sql();
            $this->db->execute("UPDATE activations SET state='deactivated',ended_at=? WHERE activation_id=?", [$activation['ended_at'],$activation['activation_id']]);
            $this->publisher->publish($activation['license_id'], $activation['activation_id']);
        }
        return ApiResponse::data(['activation_id'=>Uuid::text($activation['activation_id']),'deactivated_at'=>Protocol::utc($activation['ended_at']),'status'=>'deactivated']);
    }

    private function identity(array $input, bool $lock = false): array
    {
        $record = $this->db->one('SELECT * FROM activations WHERE activation_id=? AND product_id=? AND installation_id=?' . ($lock ? ' FOR UPDATE' : ''), [Uuid::bytes($input['activation_id']),$input['product_id'],Uuid::bytes($input['installation_id'])]);
        return $record ?? throw new ApiProblem('INVALID_PROOF', 401);
    }

    private function matchChallenge(?array $challenge, array $input, string $action, bool $checkTime): void
    {
        if (!$challenge || $challenge['action'] !== $action || $challenge['product_id'] !== $input['product_id'] || $challenge['installation_id'] !== Uuid::bytes($input['installation_id']) || $challenge['activation_id'] !== (isset($input['activation_id']) ? Uuid::bytes($input['activation_id']) : null)) { throw new ApiProblem('INVALID_PROOF', 401); }
        if ($checkTime && ($challenge['expires_at'] <= $this->clock->sql() || $challenge['consumed_at'] !== null)) { throw new ApiProblem('INVALID_PROOF', 401); }
    }

    private function authenticateSaved(array $record, array $input, string $action, string $digest): void
    {
        // Cross-channel attempts reach here only after their own online proof was verified.
        if ($record['channel'] !== 'online') { throw new ApiProblem('INVALID_REQUEST', 409); }
        $this->verify($input['proof'], $record['proof_message'], $record['public_key']);
        if ($record['channel'] !== 'online' || $record['action'] !== $action || (int)$record['digest_key_version'] !== 1 || !hash_equals($record['input_digest'], $digest)) { throw new ApiProblem('INVALID_REQUEST', 409); }
    }

    private function verify(string $proof, string $message, string $public): void
    {
        if (!sodium_crypto_sign_verify_detached(Protocol::decode($proof, 64), $message, $public)) { throw new ApiProblem('INVALID_PROOF', 401); }
    }
    private function limitIdentity(array $input, string $public): void
    {
        $this->rate->consume('api-identity', $input['product_id'] . ':' . bin2hex($public), 30, 60);
    }
    private function failure(string $code, int $status, array $input): ApiResponse { return ApiResponse::error(new ApiProblem($code,$status), $input['request_id']); }
    private function licenseResponse(string $jws, array $input): ApiResponse { return ApiResponse::data(['license_jws'=>$jws,'server_time'=>Protocol::utc($this->clock->sql()),'request_id'=>$input['request_id']]); }
}
