<?php
declare(strict_types=1);
namespace Aibid\Tests\Unit;

use Aibid\Config;
use Aibid\Domain\{Capabilities, Input, Problem, Uuid};
use Aibid\Http\Network;
use Aibid\Infrastructure\{Crypto, Totp};
use Aibid\Tests\FrozenClock;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase
{
    public function testUuidRoundTripAndVersion(): void
    {
        $uuid = Uuid::create();
        self::assertMatchesRegularExpression('/^[a-f0-9-]{14}4[a-f0-9]{3}-[89ab]/', $uuid);
        self::assertSame($uuid, Uuid::text(Uuid::bytes($uuid)));
    }

    public function testInvalidCalendarDateIsRejected(): void
    {
        $this->expectException(Problem::class);
        Input::date('2026-02-30T12:30');
    }

    public function testReviewNeedsExpedientes(): void
    {
        $this->expectException(Problem::class);
        Capabilities::validate(['review_workflow'], ['review_workflow','expedientes'], [['capability_key'=>'review_workflow','required_key'=>'expedientes']]);
    }

    public function testLinkedExpedientesDoesNotInventManagedDependency(): void
    {
        Capabilities::validate(['linked_libraries','expedientes'], ['linked_libraries','managed_libraries','expedientes','review_workflow'], [['capability_key'=>'review_workflow','required_key'=>'expedientes']]);
        self::assertTrue(true);
    }

    public function testCyclesRejected(): void
    {
        $this->expectException(Problem::class);
        Capabilities::acyclic([['capability_key'=>'first','required_key'=>'second'],['capability_key'=>'second','required_key'=>'first']]);
    }

    public function testTotpRfcVectorAndReplayBoundary(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('@59'));
        $totp = new Totp($clock);
        // RFC 6238 SHA-1 vector 94287082; six-digit authenticator profile.
        self::assertSame(1, $totp->matchingStep('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', null));
        self::assertNull($totp->matchingStep('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 1));
        self::assertNull($totp->matchingStep('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '2870820', null));
    }

    public function testMfaEncryptionBindsAccountAndRejectsTampering(): void
    {
        $crypto = new Crypto(new Config(['MFA_KEY'=>base64_encode(random_bytes(32))]));
        $ciphertext = $crypto->encrypt('test-mfa-secret', 'mfa:account-one');
        self::assertSame('test-mfa-secret', $crypto->decrypt($ciphertext, 'mfa:account-one'));
        $this->expectException(\RuntimeException::class);
        $crypto->decrypt($ciphertext, 'mfa:account-two');
    }

    public function testNetworkRestrictionsCoverIpv4AndIpv6(): void
    {
        self::assertTrue(Network::allows('10.8.0.0/24,::1/128', '10.8.0.20'));
        self::assertFalse(Network::allows('10.8.0.0/24', '10.8.1.20'));
        self::assertTrue(Network::allows('::1/128', '::1'));
        self::assertFalse(Network::allows('invalid/0', '10.8.0.20'));
        self::assertFalse(Network::allows('0.0.0.0/33', '10.8.0.20'));
    }

    public function testDevelopmentHttpCannotBeUsedOnPublicOrigin(): void
    {
        $values=['APP_ENV'=>'development','APP_URL'=>'http://public.example'];
        foreach(['MFA_KEY','CREDENTIAL_KEY','AUDIT_KEY','SESSION_KEY','OPERATION_KEY'] as $name) { $values[$name]=base64_encode(random_bytes(32)); }
        $this->expectException(\RuntimeException::class);
        (new Config($values))->validate();
    }
}
