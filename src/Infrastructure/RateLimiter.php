<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

use Aibid\Domain\Problem;

final class RateLimiter
{
    public function __construct(private Database $db, private Crypto $crypto, private Clock $clock) {}

    public function consume(string $scope, string $identity, int $limit, int $window = 900): void
    {
        if ($this->db->pdo->inTransaction()) {
            throw new \LogicException('Rate limit attempts must survive business rollback.');
        }
        $bucket = intdiv($this->clock->now()->getTimestamp(), $window) * $window;
        $digest = $this->crypto->digest('SESSION_KEY', $identity);
        $count = $this->db->transaction(function () use ($scope, $digest, $bucket): int {
            $this->db->execute('INSERT INTO rate_limit_buckets(scope,key_digest,window_start,count) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE count=count+1', [$scope, $digest, $bucket]);
            return (int) $this->db->execute('SELECT count FROM rate_limit_buckets WHERE scope=? AND key_digest=? AND window_start=?', [$scope, $digest, $bucket])->fetchColumn();
        });
        if ($count > $limit) {
            throw new Problem('Demasiados intentos. Espera unos minutos antes de volver a intentarlo.', 429);
        }
    }
}
