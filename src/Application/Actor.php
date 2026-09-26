<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Domain\Problem;

final readonly class Actor
{
    public function __construct(public string $id, public string $role, public string $name = '') {}

    public function requireRole(array $roles): void
    {
        if (!in_array($this->role, $roles, true)) {
            throw new Problem('Tu cuenta no tiene permiso para realizar esta acción.', 403);
        }
    }

    public function canWrite(): bool { return in_array($this->role, ['superadmin', 'operator'], true); }
}
