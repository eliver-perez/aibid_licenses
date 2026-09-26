<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

class Clock implements \Psr\Clock\ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function sql(): string
    {
        return $this->now()->format('Y-m-d H:i:s.u');
    }
}
