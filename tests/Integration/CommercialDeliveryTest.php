<?php
declare(strict_types=1);
namespace Aibid\Tests\Integration;

use Aibid\Application\Actor;
use Aibid\Domain\{ActivationCode, Problem, Protocol, Uuid};
use Aibid\Tests\{FrozenClock, MySqlFixture, NativeStackFixture};
use PHPUnit\Framework\TestCase;

final class CommercialDeliveryTest extends TestCase
{
    private MySqlFixture $fixture;
    private FrozenClock $clock;
    private Actor $actor;
    private array $license;
    private const PASSWORD = 'Synthetic-Commercial-2026!';
    private const MFA = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        if (!getenv('TEST_MYSQL_DSN')) { self::markTestSkipped('Requires isolated MySQL 8.'); }
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-21T12:00:00Z'));
        $this->fixture = new MySqlFixture($this->clock);
        $app=$this->fixture->app;
        $this->actor = new Actor($app->auth->bootstrap('delivery@example.test','Entrega sintética',self::PASSWORD),'superadmin');
        $this->enroll($this->actor);
        $customer=$app->catalog->saveCustomer($this->actor,Uuid::create(),null,['name'=>'Organismo sintético','legal_name'=>'Organismo de pruebas','tax_id'=>'AAA010101AAA','contact_name'=>'Prueba','email'=>'','state'=>'active']);
        $this->license=$app->licenses->issue($this->actor,Uuid::create(),['customer_id'=>$customer['id'],'product_id'=>'gestor_documental','license_type'=>'perpetual','entitled_release_until'=>'2026-10-21T12:00','features'=>['ocr']]);
    }

    protected function tearDown(): void { if (isset($this->fixture)) { $this->fixture->destroy(); } }
    private function enroll(Actor $actor): void { $this->fixture->owner->execute('UPDATE admin_users SET mfa_secret_encrypted=? WHERE id=?',[$this->fixture->app->crypto->encrypt(self::MFA,'mfa:'.$actor->id),Uuid::bytes($actor->id)]); }
    private function code(): string { $this->clock->instant=$this->clock->instant->modify('+31 seconds'); return \OTPHP\TOTP::createFromSecret(self::MFA,$this->clock)->now(); }
    private function operator(array $permissions=[]): Actor
    {
        $result=$this->fixture->app->admins->create($this->actor,Uuid::create(),['login'=>Uuid::create().'@example.test','name'=>'Operador','role'=>'operator','new_password'=>self::PASSWORD,'permissions'=>$permissions]);
        $actor=new Actor($result['id'],'operator','Operador',$permissions); $this->enroll($actor); return $actor;
    }
    private function text(string $pdf): string
    {
        $path=tempnam(sys_get_temp_dir(),'aidex-pdf-');file_put_contents($path,$pdf);chmod($path,0600);
        try { return NativeStackFixture::command([getenv('TEST_PDFTOTEXT_BIN') ?: 'pdftotext','-enc','UTF-8',$path,'-']); }
        finally { unlink($path); }
    }
    private function publicDocument(): array { return $this->fixture->app->documents->generate($this->actor,Uuid::create(),$this->license['id'],['document_type'=>'licensing']); }
    private function offline(): array
    {
        $app=$this->fixture->app;$keys=$this->fixture->signingKeys($this->clock);$keys->generate('commercial-test','license');$keys->activate('commercial-test','Synthetic');
        $pair=sodium_crypto_sign_keypair();$installation=Uuid::create();
        $payload=['action'=>'activate','request_id'=>Uuid::create(),'created_at'=>'2026-10-21T12:00:00Z','product_id'=>'gestor_documental','installation_id'=>$installation,'installation_public_key'=>Protocol::encode(sodium_crypto_sign_publickey($pair)),'fingerprint_version'=>'1','fingerprint_hash'=>'sha256:'.str_repeat('a',64)];
        $encoded=Protocol::encode(Protocol::json($payload));$request=Protocol::json(['schema_version'=>'1.0','payload_b64u'=>$encoded,'signature_b64u'=>Protocol::encode(sodium_crypto_sign_detached("LICREQ-V1\n".$encoded,sodium_crypto_sign_secretkey($pair)))]);
        $imported=$app->offline->import($this->actor,Uuid::create(),$request);
        return $app->offline->decide($this->actor,Uuid::create(),$imported['id'],'approved',['license_id'=>$this->license['id'],'version'=>1,'renewal_mode'=>'current','reason'=>'Equipo verificado por operador']);
    }

    public function testDocumentGenerationIsIdempotentAndDoesNotPublishTechnicalRevisions(): void
    {
        $app=$this->fixture->app;$operation=Uuid::create();$input=['document_type'=>'licensing'];
        $first=$app->documents->generate($this->actor,$operation,$this->license['id'],$input);
        $retry=$app->documents->generate($this->actor,$operation,$this->license['id'],$input);
        self::assertSame($first['document_id'],$retry['document_id']);self::assertTrue($retry['replayed']);
        self::assertSame(1,(int)$app->db->execute('SELECT COUNT(*) FROM license_documents')->fetchColumn());
        self::assertSame(0,(int)$app->db->execute('SELECT revision_counter FROM licenses')->fetchColumn());
        self::assertSame(0,(int)$app->db->execute('SELECT COUNT(*) FROM activations')->fetchColumn());
    }

    public function testOlderArtifactsRemainDiscoverableAndDownloadableAfterFiftyRevisions(): void
    {
        $app=$this->fixture->app;$offline=$this->offline();
        for ($i=1;$i<=50;++$i) { $app->licenses->change($this->actor,Uuid::create(),$this->license['id'],'features',['version'=>$i,'features'=>$i%2===0?['ocr']:[],'reason'=>'Revisión sintética']); }
        $first=$app->read->license($this->license['id']);$older=$app->read->license($this->license['id'],2);
        self::assertCount(50,$first['revisions']);self::assertCount(1,$older['revisions']);self::assertSame(2,$first['revision_pages']);
        self::assertSame($offline['revision_id'],$older['revisions'][0]['id_text']);
        $historical=$app->materials->revision($this->actor,$this->license['id'],$offline['revision_id'],self::PASSWORD,$this->code());
        self::assertSame(1,(int)$historical['license_revision']);
        self::assertSame(51,(int)$app->db->execute('SELECT revision_counter FROM licenses')->fetchColumn());
        self::assertSame(1,(int)$app->db->execute('SELECT COUNT(*) FROM activations')->fetchColumn());
    }

    public function testCodesAreRecoverableOnlyUnderPermissionAndMfaWithoutPlaintextStorage(): void
    {
        $app=$this->fixture->app;
        self::assertSame($this->license['secret'],ActivationCode::normalize($this->license['secret']));
        $row=$app->db->one('SELECT * FROM license_credentials WHERE license_id=?',[Uuid::bytes($this->license['id'])]);
        self::assertNotNull($row['credential_encrypted']);self::assertStringNotContainsString($this->license['secret'],json_encode($row,JSON_INVALID_UTF8_SUBSTITUTE));
        $operator=$this->operator();
        try { $app->materials->reveal(new Actor($operator->id,'superadmin'),$this->license['id'],self::PASSWORD,$this->code());self::fail('Spoofed role revealed credential'); } catch(Problem $e) { self::assertSame(403,$e->status); }
        try { $app->materials->reveal($this->actor,$this->license['id'],'wrong',$this->code());self::fail('Bad password revealed credential'); } catch(Problem $e) { self::assertSame(401,$e->status); }
        self::assertSame($this->license['secret'],$app->materials->reveal($this->actor,$this->license['id'],self::PASSWORD,$this->code()));
        $granted=$this->operator(['credentials.reveal']);
        self::assertSame($this->license['secret'],$app->materials->reveal($granted,$this->license['id'],self::PASSWORD,$this->code()));
        $audit=json_encode($app->db->all('SELECT payload_json FROM audit_events'));
        self::assertStringNotContainsString($this->license['secret'],$audit);self::assertStringContainsString('credential.revealed',$audit);self::assertStringContainsString('material.access_denied',$audit);
        self::assertStringNotContainsString($this->license['secret'],json_encode($app->read->license($this->license['id']),JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function testAdministrativeEditsKeepTechnicalLicenseAndHistoricalDocumentsUnchanged(): void
    {
        $app=$this->fixture->app;$offline=$this->offline();
        $before=$app->read->license($this->license['id']);self::assertSame('',$before['commercial_reference']);self::assertSame('',$before['authorization_note']);
        $first=$this->publicDocument();$pdf1=$app->documents->download($this->actor,$first['document_id']);
        $text=$this->text($pdf1['bytes']);self::assertStringContainsString('Constancia de licenciamiento',$text);self::assertStringContainsString('AAA010101AAA',$text);self::assertStringNotContainsString($this->license['secret'],$text);
        $app->licenses->administrative($this->actor,Uuid::create(),$this->license['id'],['reference'=>'Contrato ADQ-2026-084','reason'=>'','administrative_version'=>1]);
        $after=$app->read->license($this->license['id']);
        foreach (['row_version','revision_counter','expires_at','features','activations','revisions'] as $field) { self::assertSame($before[$field],$after[$field]); }
        self::assertSame('Contrato ADQ-2026-084',$after['commercial_reference']);self::assertCount(1,$after['administrative_history']);
        $second=$this->publicDocument();$pdf2=$app->documents->download($this->actor,$second['document_id']);
        self::assertSame($pdf1['bytes'],$app->documents->download($this->actor,$first['document_id'])['bytes']);
        self::assertStringNotContainsString('Contrato ADQ-2026-084',$text);self::assertStringContainsString('Contrato ADQ-2026-084',$this->text($pdf2['bytes']));
        self::assertNotSame($pdf1['bytes'],$pdf2['bytes']);
        $app->licenses->administrative($this->actor,Uuid::create(),$this->license['id'],['reference'=>'','reason'=>'Nota posterior','administrative_version'=>2]);
        $app->licenses->administrative($this->actor,Uuid::create(),$this->license['id'],['reference'=>'','reason'=>'','administrative_version'=>3]);
        self::assertSame('',$app->read->license($this->license['id'])['authorization_note']);
        self::assertSame($this->license['secret'],$app->materials->reveal($this->actor,$this->license['id'],self::PASSWORD,$this->code()));
    }

    public function testDeliveryPdfAndHistoryCannotBypassCredentialPermission(): void
    {
        $app=$this->fixture->app;
        $doc=$app->documents->generate($this->actor,Uuid::create(),$this->license['id'],['document_type'=>'delivery','activation_method'=>'online'],self::PASSWORD,$this->code());
        $pdf=$app->documents->download($this->actor,$doc['document_id'],self::PASSWORD,$this->code());
        self::assertStringContainsString($this->license['secret'],$this->text($pdf['bytes']));
        $operator=$this->operator(['documents.delivery']);
        try { $app->documents->download($operator,$doc['document_id'],self::PASSWORD,$this->code());self::fail('Delivery PDF bypassed reveal permission'); } catch(Problem $e) { self::assertSame(403,$e->status); }
        $row=$app->db->one('SELECT * FROM license_documents WHERE id=?',[Uuid::bytes($doc['document_id'])]);
        self::assertStringNotContainsString($this->license['secret'],json_encode($row,JSON_INVALID_UTF8_SUBSTITUTE));
        $snapshot=json_decode($app->crypto->open($row['snapshot_encrypted'],'document-snapshot:'.$doc['document_id'],'document'),true);
        $token=basename($snapshot['verification_url']);$verified=$app->documents->verify($token);
        self::assertSame(hash('sha256',$pdf['bytes']),$verified['pdf_sha256']);
        foreach (['activation_code','holder','tax_id','license_id','installation','verification_url'] as $field) { self::assertArrayNotHasKey($field,$verified); }
        self::assertTrue($verified['delivery_current']);
        $app->licenses->change($this->actor,Uuid::create(),$this->license['id'],'reissue',['version'=>1,'reason'=>'Reemplazo autorizado']);
        self::assertFalse($app->documents->verify($token)['delivery_current']);
        self::assertSame($pdf['bytes'],$app->documents->download($this->actor,$doc['document_id'],self::PASSWORD,$this->code())['bytes']);
        $app->licenses->change($this->actor,Uuid::create(),$this->license['id'],'revoke',['version'=>2,'reason'=>'Revocación sintética']);
        self::assertSame('revoked',$app->documents->verify($token)['current_state']);
        self::assertNull($app->documents->verify(str_repeat('x',43)));
    }

    public function testOfflineArtifactIsImmutableAndDeliveryPdfIdentifiesItsExactHash(): void
    {
        $app=$this->fixture->app;$offline=$this->offline();
        $first=$app->materials->revision($this->actor,$this->license['id'],$offline['revision_id'],self::PASSWORD,$this->code());
        $doc=$app->documents->generate($this->actor,Uuid::create(),$this->license['id'],['document_type'=>'delivery','activation_method'=>'offline','revision_id'=>$offline['revision_id']],self::PASSWORD,$this->code());
        $pdf=$app->documents->download($this->actor,$doc['document_id'],self::PASSWORD,$this->code());$text=$this->text($pdf['bytes']);
        self::assertStringContainsString(hash('sha256',$first['license_jws']),preg_replace('/\s+/','',$text));
        self::assertStringNotContainsString($this->license['secret'],$text);
        $this->clock->instant=$this->clock->instant->modify('+50 years');
        $again=$app->materials->revision($this->actor,$this->license['id'],$offline['revision_id'],self::PASSWORD,$this->code());
        self::assertSame($first,$again);self::assertCount(1,$app->db->all('SELECT * FROM activations'));self::assertSame(1,(int)$app->db->execute('SELECT revision_counter FROM licenses')->fetchColumn());
        $payload=json_decode(sodium_base642bin(explode('.',$first['license_jws'])[1],SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),true);
        self::assertNull($payload['expires_at']);self::assertSame(0,$payload['grace_days']);self::assertArrayNotHasKey('offline_expires_at',$payload);
        $operator=$this->operator();
        $this->expectException(Problem::class);$app->materials->revision($operator,$this->license['id'],$offline['revision_id'],self::PASSWORD,$this->code());
    }

    public function testLegacyDigestIsNotRecoveredAndUnprivilegedIssuanceDoesNotDisclose(): void
    {
        $app=$this->fixture->app;
        $this->fixture->owner->execute('UPDATE license_credentials SET credential_encrypted=NULL,code_format=NULL WHERE license_id=?',[Uuid::bytes($this->license['id'])]);
        try { $app->materials->reveal($this->actor,$this->license['id'],self::PASSWORD,$this->code());self::fail('Legacy hash recovered'); } catch(Problem $e) { self::assertSame(409,$e->status); }
        $input=['version'=>1,'reason'=>'Reemplazo de desarrollo'];$operator=$this->operator();
        $result=$app->licenses->change($operator,Uuid::create(),$this->license['id'],'reissue',$input);self::assertArrayNotHasKey('secret',$result);
        $secret=$app->materials->reveal($this->actor,$this->license['id'],self::PASSWORD,$this->code());self::assertNotSame($this->license['secret'],$secret);self::assertNotNull(ActivationCode::normalize($secret));
        self::assertSame(2,(int)$app->db->execute('SELECT COUNT(*) FROM license_credentials')->fetchColumn());
    }
}
