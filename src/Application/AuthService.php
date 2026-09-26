<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Domain\{Input, Problem, Uuid};
use Aibid\Infrastructure\{Audit, Clock, Crypto, Database, RateLimiter, Sessions, Totp};

final class AuthService
{
    private const PASSWORD_OPTIONS = ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1];

    public function __construct(private Database $db, private Crypto $crypto, private Clock $clock, private Audit $audit, private Sessions $sessions, private RateLimiter $rate, private Totp $totp) {}

    public function bootstrap(string $login, string $name, string $password): string
    {
        Input::password($password);
        $login = mb_strtolower(Input::email(Input::text(['login' => $login], 'login')));
        $name = Input::text(['name' => $name], 'name');
        return $this->db->transaction(function () use ($login, $name, $password): string {
            $this->db->one('SELECT id FROM security_state WHERE id=1 FOR UPDATE');
            if ((int) $this->db->execute('SELECT COUNT(*) FROM admin_users')->fetchColumn() !== 0) {
                throw new Problem('El bootstrap ya se realizó.');
            }
            $id = $this->insertUser($login, $name, $password, 'superadmin');
            $this->audit->append($id, 'admin.bootstrap', 'admin', $id);
            return $id;
        });
    }

    public function login(string $login, string $password, string $ip, string $oldToken): string
    {
        $login = mb_strtolower(trim($login));
        $this->rate->consume('login-ip', $ip, 20);
        $this->rate->consume('login-account', $login, 5);
        $dummy = password_hash('public-dummy-password-not-an-account', PASSWORD_ARGON2ID, self::PASSWORD_OPTIONS);
        $user = strlen($login) <= 190 ? $this->db->one('SELECT * FROM admin_users WHERE login=?', [$login]) : null;
        $valid = strlen($password) <= 1024 && password_verify($password, $user['password_hash'] ?? $dummy);
        if (!$valid || !$user || $user['state'] !== 'active') {
            $this->failed('session.login_failed', null);
            throw new Problem('No se pudo iniciar sesión con esos datos.', 401);
        }
        return $this->db->transaction(function () use ($user, $password, $oldToken): string {
            $current = $this->db->one('SELECT * FROM admin_users WHERE id=? FOR UPDATE', [$user['id']]);
            if ($current['state'] !== 'active' || !hash_equals($current['password_hash'], $user['password_hash'])) {
                throw new Problem('No se pudo iniciar sesión con esos datos.', 401);
            }
            $id = Uuid::text($current['id']);
            if (password_needs_rehash($current['password_hash'], PASSWORD_ARGON2ID, self::PASSWORD_OPTIONS)) {
                $this->db->execute('UPDATE admin_users SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_ARGON2ID, self::PASSWORD_OPTIONS), $current['id']]);
            }
            $enrollment = $current['mfa_secret_encrypted'] === null ? $this->crypto->encrypt($this->totp->generate(), 'mfa:' . $id) : null;
            $token = $this->sessions->create('pending', $id, $enrollment, $oldToken);
            $this->audit->append($id, 'session.password_verified', 'admin', $id);
            return $token;
        });
    }

    public function verifyMfa(string $token, string $code, string $ip): array
    {
        $session = $this->sessions->load($token);
        if (!$session || $session['stage'] !== 'pending') {
            throw new Problem('La sesión de verificación venció. Inicia sesión de nuevo.', 401);
        }
        $this->rate->consume('mfa-ip', $ip, 20);
        $this->rate->consume('mfa-account', $session['admin_uuid'], 10);
        $result = $this->db->transaction(function () use ($session, $token, $code): ?array {
            $user = $this->db->one('SELECT * FROM admin_users WHERE id=? FOR UPDATE', [$session['admin_id']]);
            $fresh = $this->db->one('SELECT * FROM admin_sessions WHERE token_hash=? FOR UPDATE', [$session['token_hash']]);
            if (!$fresh || $fresh['revoked_at'] !== null || $fresh['expires_at'] <= $this->clock->sql() || $user['state'] !== 'active') {
                throw new Problem('La sesión de verificación venció.', 401);
            }
            $enrolling = $user['mfa_secret_encrypted'] === null;
            $encrypted = $enrolling ? $fresh['enrollment_secret'] : $user['mfa_secret_encrypted'];
            if ($encrypted === null) {
                throw new Problem('Inicia sesión nuevamente para configurar el segundo factor.', 401);
            }
            $secret = $this->crypto->decrypt($encrypted, 'mfa:' . $session['admin_uuid']);
            $step = $this->totp->matchingStep($secret, $code, $user['last_totp_step'] === null ? null : (int) $user['last_totp_step']);
            $recovered = false;
            if ($step === null && !$enrolling) {
                $digest = $this->crypto->digest('MFA_KEY', 'recovery:' . $session['admin_uuid'] . ':' . strtoupper(trim($code)));
                $recovered = $this->db->execute('UPDATE admin_recovery_codes SET used_at=? WHERE admin_id=? AND code_hash=? AND used_at IS NULL', [$this->clock->sql(), $user['id'], $digest])->rowCount() === 1;
            }
            if ($step === null && !$recovered) {
                return null;
            }
            $codes = [];
            if ($enrolling) {
                $this->db->execute('UPDATE admin_users SET mfa_secret_encrypted=? WHERE id=?', [$encrypted, $user['id']]);
                $codes = $this->newRecoveryCodes($session['admin_uuid']);
            }
            if ($step !== null) {
                $this->db->execute('UPDATE admin_users SET last_totp_step=? WHERE id=?', [$step, $user['id']]);
            }
            $newToken = $this->sessions->create('authenticated', $session['admin_uuid'], null, $token);
            $this->audit->append($session['admin_uuid'], $enrolling ? 'admin.mfa_enrolled' : ($recovered ? 'session.recovery_login' : 'session.login'), 'admin', $session['admin_uuid']);
            return ['token' => $newToken, 'recovery_codes' => $codes];
        });
        if ($result === null) {
            $this->failed('session.mfa_failed', $session['admin_uuid']);
            throw new Problem('El código no es válido o ya se utilizó. Espera un código nuevo.', 401);
        }
        return $result;
    }

    public function logout(string $token, ?string $actorId): void
    {
        $this->db->transaction(function () use ($token, $actorId): void {
            $this->sessions->revoke($token);
            $this->audit->append($actorId, 'session.logout', 'admin', $actorId);
        });
    }

    public function reauthenticate(Actor $actor, string $password, string $code): void
    {
        $this->rate->consume('reauth', $actor->id, 10);
        $valid = $this->db->transaction(function () use ($actor, $password, $code): bool {
            $user = $this->db->one('SELECT * FROM admin_users WHERE id=? FOR UPDATE', [Uuid::bytes($actor->id)]);
            if (!$user || $user['state'] !== 'active' || strlen($password) > 1024 || !password_verify($password, $user['password_hash']) || $user['mfa_secret_encrypted'] === null) {
                return false;
            }
            $step = $this->totp->matchingStep($this->crypto->decrypt($user['mfa_secret_encrypted'], 'mfa:' . $actor->id), $code, $user['last_totp_step'] === null ? null : (int) $user['last_totp_step']);
            if ($step === null) { return false; }
            $this->db->execute('UPDATE admin_users SET last_totp_step=? WHERE id=?', [$step, $user['id']]);
            $this->audit->append($actor->id, 'session.reauthenticated', 'admin', $actor->id);
            return true;
        });
        if (!$valid) {
            $this->failed('session.reauth_failed', $actor->id);
            throw new Problem('Confirma tu contraseña y un código de autenticación nuevo.', 401);
        }
    }

    public function insertUser(string $login, string $name, string $password, string $role): string
    {
        if (!$this->db->pdo->inTransaction()) { throw new \LogicException('User creation requires a transaction.'); }
        Input::password($password);
        $id = Uuid::create();
        $this->db->execute('INSERT INTO admin_users(id,login,display_name,password_hash,role,state,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)', [Uuid::bytes($id), $login, $name, password_hash($password, PASSWORD_ARGON2ID, self::PASSWORD_OPTIONS), $role, 'active', $this->clock->sql(), $this->clock->sql()]);
        return $id;
    }

    public function changePassword(Actor $actor, string $password): void
    {
        Input::password($password);
        $this->db->transaction(function () use ($actor, $password): void {
            $this->db->one('SELECT id FROM admin_users WHERE id=? FOR UPDATE', [Uuid::bytes($actor->id)]);
            $this->db->execute('UPDATE admin_users SET password_hash=?,updated_at=? WHERE id=?', [password_hash($password, PASSWORD_ARGON2ID, self::PASSWORD_OPTIONS), $this->clock->sql(), Uuid::bytes($actor->id)]);
            $this->db->execute('UPDATE admin_sessions SET revoked_at=?,enrollment_secret=NULL WHERE admin_id=?', [$this->clock->sql(), Uuid::bytes($actor->id)]);
            $this->audit->append($actor->id, 'admin.password_changed', 'admin', $actor->id);
        });
    }

    private function newRecoveryCodes(string $adminId): array
    {
        $codes = [];
        for ($index = 0; $index < 8; ++$index) {
            $code = strtoupper(bin2hex(random_bytes(8)));
            $codes[] = $code;
            $this->db->execute('INSERT INTO admin_recovery_codes(id,admin_id,code_hash) VALUES (?,?,?)', [Uuid::bytes(Uuid::create()), Uuid::bytes($adminId), $this->crypto->digest('MFA_KEY', 'recovery:' . $adminId . ':' . $code)]);
        }
        return $codes;
    }

    private function failed(string $action, ?string $actorId): void
    {
        $this->db->transaction(fn () => $this->audit->append($actorId, $action, 'session', null));
    }
}
