<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

use Aibid\Domain\Uuid;

final class Audit
{
    public function __construct(private Database $db, private Crypto $crypto, private Clock $clock) {}

    public function append(?string $actorId, string $operation, string $target, ?string $targetId, array $metadata = [], string $reason = ''): void
    {
        if (!$this->db->pdo->inTransaction()) {
            throw new \LogicException('Audit must commit with the operation.');
        }
        $head = $this->db->one("SELECT * FROM audit_heads WHERE stream_id = 'admin' FOR UPDATE");
        $sequence = (int) $head['last_sequence'] + 1;
        $event = [
            'sequence' => $sequence, 'event_id' => Uuid::create(), 'occurred_at' => $this->clock->sql(),
            'actor_id' => $actorId, 'operation' => $operation, 'target_type' => $target,
            'target_id' => $targetId, 'reason' => $reason, 'metadata' => $metadata,
        ];
        $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $eventHash = $this->crypto->digest('AUDIT_KEY', $head['last_event_hash'] . "\n" . $json);
        $this->db->execute('INSERT INTO audit_events (sequence, occurred_at, actor_id, operation, target_type, target_id, payload_json, previous_hash, event_hash) VALUES (?,?,?,?,?,?,?,?,?)', [
            $sequence, $event['occurred_at'], $actorId === null ? null : Uuid::bytes($actorId), $operation, $target, $targetId, $json, $head['last_event_hash'], $eventHash,
        ]);
        $this->db->execute("UPDATE audit_heads SET last_sequence=?, last_event_hash=? WHERE stream_id='admin'", [$sequence, $eventHash]);
    }

    public function verify(): int
    {
        $previous = str_repeat('0', 64);
        $sequence = 0;
        foreach ($this->db->execute('SELECT * FROM audit_events ORDER BY sequence') as $event) {
            ++$sequence;
            if ((int) $event['sequence'] !== $sequence || !hash_equals($previous, $event['previous_hash']) || !hash_equals($event['event_hash'], $this->crypto->digest('AUDIT_KEY', $previous . "\n" . $event['payload_json']))) {
                throw new \RuntimeException('Audit chain mismatch at sequence ' . $sequence);
            }
            $previous = $event['event_hash'];
        }
        $head = $this->db->one("SELECT * FROM audit_heads WHERE stream_id='admin'");
        if ((int) $head['last_sequence'] !== $sequence || !hash_equals($previous, $head['last_event_hash'])) {
            throw new \RuntimeException('Audit head mismatch.');
        }
        return $sequence;
    }

    /** An anchor must come from a separately protected, previously recorded copy. */
    public function assertAnchor(array $anchor): void
    {
        if (!isset($anchor['events'],$anchor['last_hash']) || !is_int($anchor['events']) || $anchor['events']<0 || !is_string($anchor['last_hash']) || !preg_match('/\A[a-f0-9]{64}\z/',$anchor['last_hash'])) { throw new \RuntimeException('Invalid external audit anchor.'); }
        $hash = $anchor['events']===0 ? str_repeat('0',64) : $this->db->execute('SELECT event_hash FROM audit_events WHERE sequence=?',[$anchor['events']])->fetchColumn();
        if (!is_string($hash) || !hash_equals($anchor['last_hash'],$hash)) { throw new \RuntimeException('The restored history does not reach or match the external anchor.'); }
    }
}
