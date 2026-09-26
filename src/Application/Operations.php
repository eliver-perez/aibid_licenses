<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Domain\{Problem, Uuid};
use Aibid\Infrastructure\{Clock, Crypto, Database};

final class Operations
{
    public function __construct(private Database $db, private Crypto $crypto, private Clock $clock) {}

    public function run(Actor $actor, array $roles, string $operationId, string $action, array $input, callable $operation): array
    {
        $actor->requireRole($roles);
        $operationBytes = Uuid::bytes($operationId);
        $digest = $this->crypto->digest('OPERATION_KEY', $action . "\n" . json_encode($input, JSON_THROW_ON_ERROR));
        return $this->db->transaction(function () use ($actor, $roles, $operationBytes, $action, $digest, $operation): array {
            $current = $this->db->one('SELECT role,state FROM admin_users WHERE id=? FOR SHARE', [Uuid::bytes($actor->id)]);
            if (!$current || $current['state'] !== 'active' || !in_array($current['role'], $roles, true)) {
                throw new Problem('Tu cuenta no tiene permiso para realizar esta acción.', 403);
            }
            $this->db->execute('INSERT INTO admin_operations(operation_id,admin_id,action,input_digest,created_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE operation_id=operation_id', [$operationBytes, Uuid::bytes($actor->id), $action, $digest, $this->clock->sql()]);
            $record = $this->db->one('SELECT * FROM admin_operations WHERE operation_id=? FOR UPDATE', [$operationBytes]);
            if ($record['admin_id'] !== Uuid::bytes($actor->id) || $record['action'] !== $action || !hash_equals($record['input_digest'], $digest)) {
                throw new Problem('El formulario ya se utilizó para otra operación. Vuelve a cargarlo.', 409);
            }
            if ($record['result_json'] !== null) {
                return json_decode($record['result_json'], true, 512, JSON_THROW_ON_ERROR) + ['replayed' => true];
            }
            $result = $operation();
            $persisted = $result;
            unset($persisted['secret']); // Commercial keys are never persisted as operation results.
            $this->db->execute('UPDATE admin_operations SET result_json=? WHERE operation_id=?', [json_encode($persisted, JSON_THROW_ON_ERROR), $operationBytes]);
            return $result + ['replayed' => false];
        });
    }
}
