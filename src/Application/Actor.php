<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Domain\Problem;

final readonly class Actor
{
    public function __construct(public string $id, public string $role, public string $name = '', public array $permissions = []) {}

    public const PERMISSIONS = [
        'credentials.reveal'=>'Revelar códigos de activación',
        'documents.delivery'=>'Generar y descargar constancias de activación',
        'offline.download'=>'Descargar archivos offline',
    ];

    public function allows(string $permission): bool
    {
        return $this->role === 'superadmin' || ($this->role === 'operator' && in_array($permission, $this->permissions, true));
    }

    public function requirePermission(string $permission): void
    {
        if (!$this->allows($permission)) { throw new Problem('Tu cuenta no tiene el permiso especial requerido.', 403); }
    }

    public function requireRole(array $roles): void
    {
        if (!in_array($this->role, $roles, true)) {
            throw new Problem('Tu cuenta no tiene permiso para realizar esta acción.', 403);
        }
    }

    public function canWrite(): bool { return in_array($this->role, ['superadmin', 'operator'], true); }
}
