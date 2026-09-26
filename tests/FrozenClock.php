<?php
declare(strict_types=1);
namespace Aibid\Tests;

final class FrozenClock extends \Aibid\Infrastructure\Clock
{
    public function __construct(public \DateTimeImmutable $instant) {}
    public function now(): \DateTimeImmutable { return $this->instant; }
}
