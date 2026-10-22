<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Domain\{Problem, Uuid};
use Aibid\Infrastructure\Database;

final class Access
{
    public function __construct(private Database $db) {}

    public function actor(string $id): Actor
    {
        $user = $this->db->one('SELECT role,state,display_name FROM admin_users WHERE id=? FOR SHARE', [Uuid::bytes($id)]);
        if (!$user || $user['state'] !== 'active') { throw new Problem('Tu cuenta no tiene acceso.', 403); }
        $permissions = array_column($this->db->all('SELECT permission FROM admin_permission_grants WHERE admin_id=? FOR SHARE', [Uuid::bytes($id)]), 'permission');
        return new Actor($id, $user['role'], $user['display_name'], $permissions);
    }

    public function require(Actor $actor, string $permission): Actor
    {
        $current = $this->actor($actor->id);
        $current->requirePermission($permission);
        return $current;
    }
}
