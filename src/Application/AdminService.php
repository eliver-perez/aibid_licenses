<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Domain\{Input, Problem, Uuid};
use Aibid\Infrastructure\{Audit, Clock, Database};

final class AdminService
{
    public function __construct(private Database $db, private Operations $operations, private AuthService $auth, private Audit $audit, private Clock $clock) {}

    public function create(Actor $actor, string $operationId, array $input): array
    {
        $login = mb_strtolower(Input::email(Input::text($input, 'login')));
        $name = Input::text($input, 'name');
        $role = Input::choice($input, 'role', ['superadmin', 'operator', 'viewer']);
        $password = $input['new_password'] ?? '';
        if (!is_string($password)) { throw new Problem('La contraseña no es válida.'); }
        Input::password($password);
        return $this->operations->run($actor, ['superadmin'], $operationId, 'admin.create', [$login, $name, $role, $password], function () use ($actor, $login, $name, $role, $password): array {
            $this->db->one('SELECT id FROM security_state WHERE id=1 FOR UPDATE');
            if ($this->db->one('SELECT id FROM admin_users WHERE login=?', [$login])) { throw new Problem('Ya existe una cuenta con ese correo.'); }
            $id = $this->auth->insertUser($login, $name, $password, $role);
            $this->audit->append($actor->id, 'admin.created', 'admin', $id, ['role' => $role]);
            return ['id' => $id];
        });
    }

    public function update(Actor $actor, string $operationId, string $id, array $input): array
    {
        $role = Input::choice($input, 'role', ['superadmin', 'operator', 'viewer']);
        $state = Input::choice($input, 'state', ['active', 'disabled']);
        $reason = Input::text($input, 'reason', 500);
        return $this->operations->run($actor, ['superadmin'], $operationId, 'admin.update', [$id, $role, $state, $reason], function () use ($actor, $id, $role, $state, $reason): array {
            $this->db->one('SELECT id FROM security_state WHERE id=1 FOR UPDATE');
            $user = $this->db->one('SELECT * FROM admin_users WHERE id=? FOR UPDATE', [Uuid::bytes($id)]);
            if (!$user) { throw new Problem('No se encontró la cuenta.', 404); }
            if ($user['role'] === 'superadmin' && $user['state'] === 'active' && ($role !== 'superadmin' || $state !== 'active')) {
                $superadmins = (int) $this->db->execute("SELECT COUNT(*) FROM admin_users WHERE role='superadmin' AND state='active'")->fetchColumn();
                if ($superadmins <= 1) { throw new Problem('Debe quedar al menos un superadministrador activo.'); }
            }
            $this->db->execute('UPDATE admin_users SET role=?,state=?,updated_at=? WHERE id=?', [$role, $state, $this->clock->sql(), $user['id']]);
            $this->db->execute('UPDATE admin_sessions SET revoked_at=?,enrollment_secret=NULL WHERE admin_id=?', [$this->clock->sql(), $user['id']]);
            $this->audit->append($actor->id, 'admin.updated', 'admin', $id, ['role' => $role, 'state' => $state], $reason);
            return ['id' => $id];
        });
    }
}
