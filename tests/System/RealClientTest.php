<?php
declare(strict_types=1);
namespace Aibid\Tests\System;

use Aibid\{App,Config};
use Aibid\Application\Actor;
use Aibid\Domain\{Protocol,Uuid};
use Aibid\Tests\{MySqlFixture,NativeStackFixture,RealClientBridge};
use PHPUnit\Framework\TestCase;

final class RealClientTest extends TestCase
{
    private MySqlFixture $fixture;
    private NativeStackFixture $stack;
    private RealClientBridge $bridge;
    private Actor $actor;
    private array $keys;

    protected function setUp(): void
    {
        if (!getenv('TEST_MYSQL_DSN') || !getenv('TEST_CLIENT_SOURCE')) { self::markTestSkipped('Set explicit MySQL and real client source for native TLS/FPM tests.'); }
        $this->fixture = new MySqlFixture();
        $this->stack = new NativeStackFixture($this->fixture);
        $owner = new App(new Config($this->stack->values,false),$this->fixture->owner);
        $owner->signingKeys->generate('interop-random-a','license'); $owner->signingKeys->activate('interop-random-a','Isolated real client integration');
        $this->keys = $this->trustKeys();
        $this->actor = new Actor($this->stack->app->auth->bootstrap('interop@example.test','Interop','Fixture-Interop-Only-2026!'),'superadmin');
        $this->bridge = new RealClientBridge(getenv('TEST_CLIENT_SOURCE'));
    }
    protected function tearDown(): void
    {
        if (isset($this->bridge)) { $this->bridge->destroy(); }
        if (isset($this->stack)) { $this->stack->destroy(); }
        if (isset($this->fixture)) { $this->fixture->destroy(); }
    }
    private function trustKeys(): array
    {
        return array_map(fn($key)=>['kid'=>$key['kid'],'public_key'=>Protocol::encode($key['public_key']),'environment'=>'production','purpose'=>'license'],$this->fixture->owner->all("SELECT kid,public_key FROM signing_keys WHERE purpose='license'"));
    }
    private function client(string $installation, string $operation, array $input = []): array
    {
        return $this->bridge->run($this->stack,$installation,$this->keys,['operation'=>$operation]+$input);
    }
    private function issue(string $type = 'perpetual'): array
    {
        $app = $this->stack->app;
        $customer = $app->catalog->saveCustomer($this->actor,Uuid::create(),null,['name'=>'Real client integration','contact_name'=>'Synthetic','email'=>'','state'=>'active']);
        return $app->licenses->issue($this->actor,Uuid::create(),['customer_id'=>$customer['id'],'product_id'=>'gestor_documental','license_type'=>$type,'entitled_release_until'=>gmdate('Y-m-d\TH:i'),'expires_at'=>gmdate('Y-m-d\TH:i',time()+86400),'features'=>['linked_libraries','managed_libraries','ocr','expedientes','review_workflow'],'reason'=>'Native integration test']);
    }
    private function offline(string $installation, string $action, string $licenseId, array $input = []): array
    {
        $request = $this->client($installation,'offline',['action'=>$action,'request_id'=>Uuid::create()]);
        self::assertArrayNotHasKey('error',$request);
        $app = $this->stack->app;
        $imported = $app->offline->import($this->actor,Uuid::create(),$request['contents']);
        $license = $app->read->license($licenseId);
        $result = $app->offline->decide($this->actor,Uuid::create(),$imported['id'],'approved',$input+['license_id'=>$licenseId,'version'=>$license['row_version'],'renewal_mode'=>'current','reason'=>'Client-generated offline request']);
        $jws = $app->read->revision($result['license_id'],$result['revision_id'])['license_jws'];
        $response = $this->client($installation,'import',['contents'=>$jws]);
        self::assertArrayNotHasKey('error',$response);
        return $response + ['jws'=>$jws];
    }

    public function testActualClientOnlineOfflineTransferRotationAndReadOnlyThroughTlsFpm(): void
    {
        self::assertStringContainsString('PASS',$this->bridge->existingTests());
        $license = $this->issue(); $app = $this->stack->app;
        $activationRequest = Uuid::create();
        $active = $this->client('online','online',['action'=>'activate','request_id'=>$activationRequest,'commercial_key'=>$license['secret']]);
        self::assertArrayNotHasKey('error',$active); self::assertSame('active',$active['status']['state']);
        self::assertTrue($active['write_allowed']); self::assertTrue($active['ocr_allowed']); self::assertNull($active['status']['maintenance_until']);
        $replay = $this->client('online','online',['action'=>'activate','request_id'=>$activationRequest,'commercial_key'=>$license['secret']]);
        self::assertSame(1,$replay['status']['license_revision']);
        $foreign = $this->client('foreign','online',['action'=>'activate','request_id'=>Uuid::create(),'commercial_key'=>$license['secret']]);
        self::assertSame('ACTIVATION_LIMIT',$foreign['error']);
        $app->licenses->change($this->actor,Uuid::create(),$license['id'],'features',['version'=>1,'features'=>['linked_libraries','managed_libraries','expedientes'],'reason'=>'Remove OCR']);
        $fresh = $this->client('online','online',['action'=>'refresh','request_id'=>Uuid::create()]);
        self::assertSame(2,$fresh['status']['license_revision']); self::assertFalse($fresh['ocr_allowed']);
        $terminal = $this->client('online','online',['action'=>'deactivate','request_id'=>Uuid::create()]);
        self::assertSame('revoked',$terminal['status']['state']); self::assertFalse($terminal['write_allowed']); self::assertTrue($terminal['read_allowed']); self::assertTrue($terminal['export_allowed']);
        $offline = $this->offline('offline','activate',$license['id']);
        self::assertSame(4,$offline['status']['license_revision']);
        self::assertNotSame($active['status']['activation_id'],$offline['status']['activation_id']);
        $foreign = $this->client('foreign','import',['contents'=>$offline['jws']]);
        self::assertSame('LICENSE_BINDING_MISMATCH',$foreign['error']);
        $renewed = $this->offline('offline','renew',$license['id']); self::assertSame(5,$renewed['status']['license_revision']);
        self::assertSame('LICENSE_REVISION_CONFLICT',$this->client('offline','import',['contents'=>$offline['jws']])['error']);
        $retired = $this->offline('offline','deactivate',$license['id']);
        self::assertSame('revoked',$retired['status']['state']); self::assertTrue($retired['read_allowed']); self::assertFalse($retired['write_allowed']);
        self::assertTrue($retired['status']['offline_deactivation_pending']);
        $next = $this->offline('next','activate',$license['id']);
        $transfer = $this->offline('destination','activate',$license['id'],['force_activation_id'=>$next['status']['activation_id'],'accept_offline_limit'=>'1']);
        self::assertSame(9,$transfer['status']['license_revision']);
        $outgoing = $this->client('next','online',['action'=>'refresh','request_id'=>Uuid::create()]);
        self::assertSame('revoked',$outgoing['status']['state']); self::assertSame(8,$outgoing['status']['license_revision']);
        $owner = new App(new Config($this->stack->values,false),$this->fixture->owner);
        $owner->signingKeys->generate('interop-random-b','license'); $owner->signingKeys->activate('interop-random-b','Isolated rotation test');
        $app->licenses->change($this->actor,Uuid::create(),$license['id'],'maintenance',['version'=>2,'until'=>gmdate('Y-m-d\TH:i',time()+365*86400),'reference'=>'INTEROP','reason'=>'Explicit maintenance']);
        $unknown = $this->client('destination','online',['action'=>'refresh','request_id'=>Uuid::create()]); self::assertSame('LICENSE_UNKNOWN_KEY',$unknown['error']);
        self::assertSame('active',$this->client('destination','status')['status']['state']);
        $this->keys = $this->trustKeys();
        $rotated = $this->client('destination','online',['action'=>'refresh','request_id'=>Uuid::create()]); self::assertSame(10,$rotated['status']['license_revision']);
        $app->licenses->change($this->actor,Uuid::create(),$license['id'],'revoke',['version'=>3,'reason'=>'Contract revocation test']);
        $revoked = $this->client('destination','online',['action'=>'refresh','request_id'=>Uuid::create()]); self::assertSame('revoked',$revoked['status']['state']); self::assertTrue($revoked['export_allowed']);

        $subscription = $this->issue('subscription');
        $subscriptionActive = $this->offline('subscription','activate',$subscription['id']);
        $subscriptionRenewed = $this->offline('subscription','renew',$subscription['id'],['renewal_mode'=>'extend','until'=>gmdate('Y-m-d\TH:i',time()+10*86400),'reference'=>'EXPLICIT-RENEWAL']);
        self::assertSame($subscriptionActive['status']['activation_id'],$subscriptionRenewed['status']['activation_id']);
        $expiry = new \DateTimeImmutable($subscriptionRenewed['status']['expires_at']);
        foreach ([[-1,'active',true],[0,'grace',true],[1295999,'grace',true],[1296000,'expired',false]] as [$offset,$state,$write]) {
            $status = $this->client('subscription','status',['now'=>$expiry->modify(($offset<0?'':'+').$offset.' seconds')->format(DATE_ATOM)]);
            self::assertSame($state,$status['status']['state']); self::assertSame($write,$status['write_allowed']); self::assertTrue($status['read_allowed']); self::assertTrue($status['export_allowed']);
        }
        self::assertGreaterThan(25,$app->audit->verify());
        $report = ['client_source'=>realpath(getenv('TEST_CLIENT_SOURCE')),'client_files_sha256'=>$this->bridge->sourceHashes,'php'=>PHP_VERSION,'tested_at'=>gmdate(DATE_ATOM),'scenarios'=>['online','offline','transfer','rotation','binding','revision','15-day-boundaries','read-only','TLS/FPM']];
        file_put_contents(dirname(__DIR__,2).'/var/client-integration-report.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    }

    public function testNginxErrorsMaintenanceAndProductionCookiesAreCorrect(): void
    {
        $response = $this->stack->request('/login','','GET'); self::assertSame(200,$response['status']);
        self::assertStringContainsString('__host-license_admin=',$response['headers']); self::assertStringContainsString('; secure',$response['headers']); self::assertStringContainsString('httponly',$response['headers']);
        self::assertStringContainsString('samesite=lax',$response['headers']);
        self::assertSame(413,$this->stack->request('/admin/offline/import',str_repeat('x',98305),'POST',['Content-Type: multipart/form-data; boundary=test'])['status']);
        self::assertSame(404,$this->stack->request('/other.php','','GET')['status']);
        self::assertSame(403,$this->stack->request('/.env','','GET')['status']);
        foreach ([['/v1/activations',str_repeat('x',16385),413,'INVALID_REQUEST'],['/v1/activations','{}',400,'INVALID_REQUEST']] as [$path,$body,$code,$error]) {
            $response = $this->stack->request($path,$body); self::assertSame($code,$response['status']); self::assertSame($error,$response['json']['error']['code']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/',$response['json']['error']['request_id']);
            self::assertStringNotContainsString('set-cookie:',$response['headers']);
        }
        $this->stack->privateFile('maintenance.flag','maintenance test');
        $response = $this->stack->request('/v1/activations/challenge'); self::assertSame(503,$response['status']); self::assertSame('TEMPORARY_UNAVAILABLE',$response['json']['error']['code']);
        self::assertSame(503,$this->stack->request('/admin','','GET')['status']);
        unlink($this->stack->directory.'/maintenance.flag');
        self::assertSame(200,$this->stack->request('/login','','GET')['status']);
    }
}
