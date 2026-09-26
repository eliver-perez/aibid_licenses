<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

use OTPHP\TOTP as Otp;

final class Totp
{
    public function __construct(private Clock $clock) {}

    public function generate(): string { return Otp::generate($this->clock)->getSecret(); }

    public function matchingStep(string $secret, string $code, ?int $lastStep): ?int
    {
        if (!preg_match('/\A[0-9]{6}\z/', $code)) {
            return null;
        }
        $otp = Otp::createFromSecret($secret, $this->clock);
        $current = intdiv($this->clock->now()->getTimestamp(), 30);
        foreach ([$current, $current - 1, $current + 1] as $step) {
            if (($lastStep === null || $step > $lastStep) && hash_equals($otp->at($step * 30), $code)) {
                return $step;
            }
        }
        return null;
    }

    public function provisioningUri(string $secret, string $login): string
    {
        $otp = Otp::createFromSecret($secret, $this->clock);
        $otp->setLabel($login);
        $otp->setIssuer('AIBID Licencias');
        return $otp->getProvisioningUri();
    }
}
