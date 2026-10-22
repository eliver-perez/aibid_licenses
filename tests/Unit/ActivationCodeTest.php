<?php
declare(strict_types=1);
namespace Aibid\Tests\Unit;

use Aibid\Domain\ActivationCode;
use PHPUnit\Framework\TestCase;

final class ActivationCodeTest extends TestCase
{
    public function testRepresentativeRandomCodesUseExactAlphabetAndDoNotCollide(): void
    {
        $seen = [];
        for ($i=0;$i<10000;++$i) {
            $code = ActivationCode::generate();
            self::assertMatchesRegularExpression('/\A[2-9A-HJKMNP-Z]{3}(?:-[2-9A-HJKMNP-Z]{4}){3}\z/',$code);
            self::assertArrayNotHasKey($code,$seen);
            $seen[$code] = true;
        }
        self::assertGreaterThan(74,15*log(strlen(ActivationCode::ALPHABET),2));
    }

    public function testNormalizationAndStrictRejection(): void
    {
        foreach (['K7M-9R3X-6TVP-8CHD','k7m9r3x6tvp8chd',' k7m-9r3x-6tvp-8chd '] as $value) { self::assertSame('K7M-9R3X-6TVP-8CHD',ActivationCode::normalize($value)); }
        foreach (['K7I-9R3X-6TVP-8CHD','K7L-9R3X-6TVP-8CHD','K7O-9R3X-6TVP-8CHD','K70-9R3X-6TVP-8CHD','K71-9R3X-6TVP-8CHD','K7M-9R3X6-TVP-8CHD','K7M 9R3X 6TVP 8CHD','AIDEX-123456789012345','Ｋ7M-9R3X-6TVP-8CHD','',"K7M-9R3X-6TVP-8C\nHD"] as $value) { self::assertNull(ActivationCode::normalize($value)); }
    }
}
