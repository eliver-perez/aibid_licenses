<?php
declare(strict_types=1);
namespace Aibid\Tests\Unit;

use Aibid\Domain\{ApiProblem, OfflineRequest, Protocol, Uuid};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfflineRequestTest extends TestCase
{
    private function envelope(array $changes = [], ?string $inner = null): string
    {
        $pair = sodium_crypto_sign_seed_keypair(str_repeat('x',32));
        $payload = ['action'=>'activate','request_id'=>Uuid::create(),'created_at'=>'2001-02-03T12:34:56.123456789+00:00','product_id'=>'gestor_documental','installation_id'=>Uuid::create(),'installation_public_key'=>Protocol::encode(sodium_crypto_sign_publickey($pair)),'fingerprint_version'=>'1','fingerprint_hash'=>'sha256:'.str_repeat('a',64),'license_key'=>'AIBID-sensitive-optional'];
        $encoded = Protocol::encode($inner ?? Protocol::json(array_replace($payload,$changes)));
        return Protocol::json(['schema_version'=>'1.0','payload_b64u'=>$encoded,'signature_b64u'=>Protocol::encode(sodium_crypto_sign_detached("LICREQ-V1\n".$encoded,sodium_crypto_sign_secretkey($pair)))]);
    }

    public function testExactSignedBytesAndSafeProjection(): void
    {
        $envelope = $this->envelope();
        $verified = OfflineRequest::verify($envelope);
        self::assertArrayNotHasKey('license_key',$verified['payload']);
        self::assertSame('2001-02-03T12:34:56.123456789+00:00',$verified['payload']['created_at']);
        $reformatted = json_encode(array_reverse(json_decode($envelope,true),true),JSON_PRETTY_PRINT);
        self::assertSame($verified,OfflineRequest::verify($reformatted));
        foreach (glob(dirname(__DIR__,2).'/contracts/v1/fixtures/*.licreq') as $file) { self::assertContains(OfflineRequest::verify(file_get_contents($file))['payload']['action'],['activate','renew','deactivate']); }
    }

    public static function invalidPayloads(): array
    {
        return [
            'wrong calendar'=>[['created_at'=>'2026-02-30T00:00:00Z']],
            'non UTC'=>[['created_at'=>'2026-01-01T00:00:00-06:00']],
            'invalid hour'=>[['created_at'=>'2026-01-01T24:00:00Z']],
            'missing seconds'=>[['created_at'=>'2026-01-01T00:00Z']],
            'non v4'=>[['installation_id'=>'00000000-0000-1000-8000-000000000000']],
            'numeric version'=>[['fingerprint_version'=>1]],
            'unknown member'=>[['features'=>['ocr'=>true]]],
            'renew missing identity'=>[['action'=>'renew']],
            'invalid fingerprint'=>[['fingerprint_hash'=>'sha256:'.str_repeat('A',64)]],
            'invalid action'=>[['action'=>'refresh']],
            'padded public'=>[['installation_public_key'=>str_repeat('a',43).'=']],
            'control character'=>[['license_key'=>"secret\n"]],
        ];
    }
    #[DataProvider('invalidPayloads')]
    public function testMalformedSignedPayloadIsRejected(array $changes): void
    {
        $this->expectException(ApiProblem::class);
        OfflineRequest::verify($this->envelope($changes));
    }

    public function testTamperedSignatureAndDuplicateOuterKeys(): void
    {
        $outer = json_decode($this->envelope(),true);
        $outer['signature_b64u'] = Protocol::encode(str_repeat("\0",64));
        try { OfflineRequest::verify(Protocol::json($outer)); self::fail('Invalid signature accepted'); }
        catch (ApiProblem $error) { self::assertSame('INVALID_PROOF',$error->errorCode); }
        $this->expectException(ApiProblem::class);
        OfflineRequest::verify('{"schema_version":"1.0",'.substr($this->envelope(),1));
    }

    public function testDuplicateInnerKeysAndOversizeEnvelopeAreRejected(): void
    {
        try { OfflineRequest::verify($this->envelope([], '{"action":"activate","action":"renew"}')); self::fail('Ambiguous JSON accepted'); }
        catch (ApiProblem $error) { self::assertSame('INVALID_REQUEST',$error->errorCode); }
        try { OfflineRequest::verify(str_repeat(' ',65537)); self::fail('Oversize accepted'); }
        catch (ApiProblem $error) { self::assertSame(413,$error->status); }
        $outer = json_decode($this->envelope(),true); $outer['schema_version'] = '2.0';
        try { OfflineRequest::verify(Protocol::json($outer)); self::fail('Unknown schema accepted'); }
        catch (ApiProblem $error) { self::assertSame('INCOMPATIBLE_SCHEMA',$error->errorCode); }
    }
}
