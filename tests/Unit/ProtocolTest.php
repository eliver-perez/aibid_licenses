<?php
declare(strict_types=1);
namespace Aibid\Tests\Unit;

use Aibid\Domain\{ApiProblem, Jws, Protocol, StrictJson};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtocolTest extends TestCase
{
    public function testFixtureConfigurationCannotInheritRuntimeDatabaseSettings(): void
    {
        $previous=getenv('DB_DSN');
        putenv('DB_DSN=mysql:host=must-not-connect;dbname=unrelated');
        try { self::assertSame('mysql:dbname=isolated_test',(new \Aibid\Config(['DB_DSN'=>'mysql:dbname=isolated_test'],false))->get('DB_DSN')); }
        finally { $previous===false ? putenv('DB_DSN') : putenv('DB_DSN='.$previous); }
    }
    public static function invalidJson(): array
    {
        return array_map(fn($value)=>[$value], ['[]','null','{"a":1,"a":2}','{"a":1,"\\u0061":2}','{"a":[{"b":1,"b":2}]}','{"a":NaN}',"{\"a\":\"\xff\"}",'{"a":1} trailing']);
    }
    #[DataProvider('invalidJson')]
    public function testInvalidOrAmbiguousJsonIsRejected(string $json): void
    {
        $this->expectException(ApiProblem::class); StrictJson::object($json);
    }
    public function testNestedJsonAndEscapedDelimitersRemainIntact(): void
    {
        $json=json_encode(['a'=>(object)['b'=>[true,null,1.5,'a,}:"b']],'c'=>(object)[]],JSON_THROW_ON_ERROR);
        self::assertEquals(get_object_vars(json_decode($json)),StrictJson::object($json));
    }
    public function testBase64PaddingAndNoncanonicalTrailingBitsAreRejected(): void
    {
        $encoded=Protocol::encode(str_repeat("\0",32));
        self::assertSame(str_repeat("\0",32),Protocol::decode($encoded,32));
        foreach([$encoded.'=',substr($encoded,0,-1).'B'] as $invalid) {
            try { Protocol::decode($invalid,32); self::fail('Invalid base64 accepted'); } catch(ApiProblem $error) { self::assertSame('INVALID_REQUEST',$error->errorCode); }
        }
    }
    public function testJwsPublisherMatchesAllSharedFixturesByteForByte(): void
    {
        $vectors=json_decode(file_get_contents(dirname(__DIR__,2).'/contracts/v1/fixtures/vectors.json'),true,512,JSON_THROW_ON_ERROR);
        $keys=array_column($vectors['keys'],null,'name');
        foreach($vectors['licenses'] as $vector) {
            $pair=sodium_crypto_sign_seed_keypair(hex2bin($keys[$vector['key_name']]['seed_hex']));
            self::assertSame($vector['jws'],Jws::sign(json_decode($vector['payload_json'],true,512,JSON_THROW_ON_ERROR),$vector['key_name'],sodium_crypto_sign_secretkey($pair)));
        }
        foreach($vectors['proofs'] as $vector) {
            self::assertSame($vector['message_utf8'],Protocol::proofMessage($vector['action'],$vector,$vector['nonce']));
        }
    }
    public function testSchemaDoesNotCoerceOrAllowUnknownFields(): void
    {
        $valid=['action'=>'activate','product_id'=>'gestor_documental','installation_id'=>'22222222-2222-4222-8222-222222222222','activation_id'=>null];
        self::assertSame('activate',Protocol::input('challenge',$valid)['action']);
        foreach([$valid+['extra'=>true],array_replace($valid,['activation_id'=>'']),array_replace($valid,['installation_id'=>'22222222-2222-1222-8222-222222222222'])] as $invalid) {
            try { Protocol::input('challenge',$invalid); self::fail('Invalid schema accepted'); } catch(ApiProblem $error) { self::assertSame(400,$error->status); }
        }
    }
}
