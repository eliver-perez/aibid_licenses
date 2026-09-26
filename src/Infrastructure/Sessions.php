<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

use Aibid\Domain\Uuid;

final class Sessions
{
    public function __construct(private Database $db, private Crypto $crypto, private Clock $clock) {}

    public function load(?string $token): ?array
    {
        if ($token === null || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token)) {
            return null;
        }
        $record = $this->db->one('SELECT s.*, u.login, u.display_name, u.role, u.state AS user_state, u.mfa_secret_encrypted AS user_mfa FROM admin_sessions s LEFT JOIN admin_users u ON u.id=s.admin_id WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>? AND s.last_seen_at>?', [hash('sha256', $token), $this->clock->sql(), $this->clock->now()->modify('-30 minutes')->format('Y-m-d H:i:s.u')]);
        if (!$record || ($record['admin_id'] !== null && $record['user_state'] !== 'active') || ($record['stage'] === 'authenticated' && ($record['user_mfa'] === null || $record['mfa_verified_at'] === null))) {
            return null;
        }
        if ($record['last_seen_at'] < $this->clock->now()->modify('-1 minute')->format('Y-m-d H:i:s.u')) {
            $this->db->execute('UPDATE admin_sessions SET last_seen_at=? WHERE token_hash=? AND revoked_at IS NULL', [$this->clock->sql(), $record['token_hash']]);
        }
        $record['admin_uuid'] = $record['admin_id'] === null ? null : Uuid::text($record['admin_id']);
        return $record;
    }

    public function create(string $stage = 'anonymous', ?string $adminId = null, ?string $enrollmentSecret = null, ?string $oldToken = null): string
    {
        $token = Crypto::token();
        $expires = $this->clock->now()->modify($stage === 'authenticated' ? '+8 hours' : '+10 minutes');
        $this->db->execute('INSERT INTO admin_sessions(token_hash,admin_id,stage,enrollment_secret,created_at,last_seen_at,expires_at,mfa_verified_at) VALUES (?,?,?,?,?,?,?,?)', [hash('sha256', $token), $adminId === null ? null : Uuid::bytes($adminId), $stage, $enrollmentSecret, $this->clock->sql(), $this->clock->sql(), $expires->format('Y-m-d H:i:s.u'), $stage === 'authenticated' ? $this->clock->sql() : null]);
        if ($oldToken !== null) {
            $this->revoke($oldToken);
        }
        return $token;
    }

    public function revoke(string $token): void
    {
        $this->db->execute('UPDATE admin_sessions SET revoked_at=?, enrollment_secret=NULL WHERE token_hash=?', [$this->clock->sql(), hash('sha256', $token)]);
    }

    public function csrf(string $token): string { return $this->crypto->digest('SESSION_KEY', 'csrf:' . $token); }

    public function validCsrf(string $token, mixed $provided): bool
    {
        return is_string($provided) && hash_equals($this->csrf($token), $provided);
    }
}
