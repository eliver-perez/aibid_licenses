<?php
declare(strict_types=1);
namespace Aibid\Tests\Integration;

use Aibid\{App, Config};
use Aibid\Application\Actor;
use Aibid\Domain\{ApiProblem, ApiResponse, Protocol, Uuid};
use Aibid\Tests\{FrozenClock, MySqlFixture};
use PHPUnit\Framework\TestCase;

final class ActivationsTest extends TestCase
{
    private MySqlFixture $fixture;
    private App $app;
    private FrozenClock $clock;
    private Actor $actor;
    private array $issued;
    private array $device;

    protected function setUp(): void
    {
        if(!getenv('TEST_MYSQL_DSN'))self::markTestSkipped('Requires isolated MySQL 8.');
        $this->clock=new FrozenClock(new \DateTimeImmutable('2026-09-25T12:00:00Z'));
        $this->fixture=new MySqlFixture($this->clock); $this->app=$this->fixture->app;
        $keys=$this->fixture->signingKeys($this->clock);
        $keys->generate('random-test-a','license'); $keys->activate('random-test-a','Isolated test setup');
        $id=$this->app->auth->bootstrap('admin@example.test','Admin','Fixture-Password-Only-2026!');
        $this->actor=new Actor($id,'superadmin');
        $this->issued=$this->issue(); $this->device=$this->device();
    }
    protected function tearDown(): void { if(isset($this->fixture))$this->fixture->destroy(); }
    private function issue(string $type='perpetual'): array
    {
        $customer=$this->app->catalog->saveCustomer($this->actor,Uuid::create(),null,['name'=>'API test','contact_name'=>'Test','email'=>'api@example.test','state'=>'active']);
        return $this->app->licenses->issue($this->actor,Uuid::create(),['customer_id'=>$customer['id'],'product_id'=>'gestor_documental','license_type'=>$type,'entitled_release_until'=>'2026-09-25T12:00','expires_at'=>'2026-10-01T00:00','features'=>['linked_libraries','expedientes','review_workflow'],'reason'=>'Protocol test']);
    }
    private function device(): array
    {
        $pair=sodium_crypto_sign_keypair();
        return ['id'=>Uuid::create(),'public'=>Protocol::encode(sodium_crypto_sign_publickey($pair)),'secret'=>sodium_crypto_sign_secretkey($pair)];
    }
    private function request(string $action='activate',?array $device=null,?string $activation=null): array
    {
        $device??=$this->device;
        $challenge=json_decode($this->app->activations->challenge(['action'=>$action,'product_id'=>'gestor_documental','installation_id'=>$device['id'],'activation_id'=>$activation])->body,true);
        $input=['request_id'=>Uuid::create(),'product_id'=>'gestor_documental','installation_id'=>$device['id'],'challenge_id'=>$challenge['challenge_id'],'app_version'=>'1.0.0'];
        if($action==='activate')$input+=['license_key'=>$this->issued['secret'],'installation_public_key'=>$device['public'],'fingerprint_version'=>'1','fingerprint_hash'=>'sha256:'.str_repeat('a',64)];
        else $input['activation_id']=$activation;
        $input['proof']=Protocol::encode(sodium_crypto_sign_detached(Protocol::proofMessage($action,$input,$challenge['nonce']),$device['secret']));
        return $input;
    }
    private function payload(ApiResponse $response): array
    {
        self::assertSame(200,$response->status,$response->body);
        $jws=json_decode($response->body,true,512,JSON_THROW_ON_ERROR)['license_jws'];
        [$header,$body,$signature]=explode('.',$jws);
        $decoded=json_decode(sodium_base642bin($header,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),true);
        self::assertSame('EdDSA',$decoded['alg']);self::assertSame('lic+jws',$decoded['typ']);
        $public=$this->app->db->execute('SELECT public_key FROM signing_keys WHERE kid=?',[$decoded['kid']])->fetchColumn();
        self::assertTrue(sodium_crypto_sign_verify_detached(Protocol::decode($signature,64),$header.'.'.$body,$public));
        return json_decode(sodium_base642bin($body,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),true,512,JSON_THROW_ON_ERROR);
    }
    private function activate(): array { return $this->payload($this->app->activations->execute('activate',$this->request())); }

    public function testActivationHasExactRightsAndRefreshReusesSignedBytes(): void
    {
        $response=$this->app->activations->execute('activate',$this->request());$payload=$this->payload($response);
        self::assertSame($this->issued['id'],$payload['license_id']);self::assertSame($this->device['id'],$payload['installation_id']);
        self::assertSame(1,$payload['license_revision']);self::assertNull($payload['expires_at']);self::assertNull($payload['maintenance_until']);
        self::assertSame(0,$payload['grace_days']);self::assertFalse($payload['features']['managed_libraries']);
        self::assertSame(['max_installations'=>1,'max_users'=>null,'max_libraries'=>null,'max_documents'=>null],$payload['limits']);
        self::assertCount(18,$payload);
        $this->clock->instant=$this->clock->instant->modify('+1 day');
        $refreshed=$this->app->activations->execute('refresh',$this->request('refresh',null,$payload['activation_id']));
        self::assertSame(json_decode($response->body,true)['license_jws'],json_decode($refreshed->body,true)['license_jws']);
        self::assertSame(1,(int)$this->app->db->execute('SELECT COUNT(*) FROM license_revisions')->fetchColumn());
    }
    public function testIdenticalReplaySurvivesChallengeCleanupAndCredentialRetirement(): void
    {
        $input=$this->request();$original=$this->app->activations->execute('activate',$input);
        $this->app->licenses->change($this->actor,Uuid::create(),$this->issued['id'],'reissue',['version'=>1,'reason'=>'Replace credential']);
        $this->fixture->owner->execute('DELETE FROM activation_challenges');$this->clock->instant=$this->clock->instant->modify('+2 days');
        self::assertSame($original->body,$this->app->activations->execute('activate',array_reverse($input,true))->body);
        $input['app_version']='changed';
        try{$this->app->activations->execute('activate',$input);self::fail('Changed retry accepted');}catch(ApiProblem $e){self::assertSame(409,$e->status);}
        foreach(['license_requests'=>'response_body','license_revisions'=>'payload_json','audit_events'=>'payload_json'] as $table=>$column) {
            foreach($this->app->db->all("SELECT $column AS content FROM $table") as $row)self::assertStringNotContainsString($this->issued['secret'],$row['content']);
        }
    }
    public function testChallengeExpiresAtExactBoundaryAndInvalidProofDoesNotConsumeIt(): void
    {
        $input=$this->request();$invalid=$input;$invalid['proof']=Protocol::encode(str_repeat("\0",64));
        try{$this->app->activations->execute('activate',$invalid);self::fail('Invalid proof accepted');}catch(ApiProblem $e){self::assertSame('INVALID_PROOF',$e->errorCode);}
        self::assertNull($this->app->db->one('SELECT consumed_at FROM activation_challenges')['consumed_at']);
        $this->clock->instant=$this->clock->instant->modify('+5 minutes');
        try{$this->app->activations->execute('activate',$input);self::fail('Expired challenge accepted');}catch(ApiProblem $e){self::assertSame(401,$e->status);}
        self::assertSame(0,(int)$this->app->db->execute('SELECT COUNT(*) FROM license_requests')->fetchColumn());
    }
    public function testBoundKeyAndChallengeActionCannotBeSubstituted(): void
    {
        $payload=$this->activate();$input=$this->request('refresh',null,$payload['activation_id']);
        $challenge=$this->app->db->one('SELECT nonce FROM activation_challenges WHERE challenge_id=?',[Uuid::bytes($input['challenge_id'])]);
        $attacker=$this->device();$input['proof']=Protocol::encode(sodium_crypto_sign_detached(Protocol::proofMessage('refresh',$input,$challenge['nonce']),$attacker['secret']));
        try{$this->app->activations->execute('refresh',$input);self::fail('Wrong key accepted');}catch(ApiProblem $e){self::assertSame(401,$e->status);}
        $input=$this->request('refresh',null,$payload['activation_id']);
        try{$this->app->activations->execute('deactivate',$input);self::fail('Wrong action accepted');}catch(ApiProblem $e){self::assertSame(401,$e->status);}
        self::assertSame('active',$this->app->db->execute('SELECT state FROM activations')->fetchColumn());
    }
    public function testCommercialRefusalIsPersistedAndDoesNotConsumeAnotherRequest(): void
    {
        $this->activate();$other=$this->device();$input=$this->request('activate',$other);
        $response=$this->app->activations->execute('activate',$input);self::assertSame(409,$response->status);
        self::assertSame('ACTIVATION_LIMIT',json_decode($response->body,true)['error']['code']);
        self::assertSame($response->body,$this->app->activations->execute('activate',$input)->body);
        $input['request_id']=Uuid::create();
        try{$this->app->activations->execute('activate',$input);self::fail('Reused challenge accepted');}catch(ApiProblem $e){self::assertSame(401,$e->status);}
        self::assertSame(2,(int)$this->app->db->execute('SELECT COUNT(*) FROM license_requests')->fetchColumn());
    }
    public function testUnknownKeyIsUniformAndArchivedWithoutTheSecret(): void
    {
        $input=$this->request();$input['license_key']='unrecognized-commercial-key';
        $response=$this->app->activations->execute('activate',$input);self::assertSame(404,$response->status);
        self::assertSame('LICENSE_NOT_FOUND',json_decode($response->body,true)['error']['code']);
        self::assertSame($response->body,$this->app->activations->execute('activate',$input)->body);
        self::assertStringNotContainsString($input['license_key'],Protocol::json($this->app->db->all('SELECT response_body,proof_message,input_digest FROM license_requests')));
    }
    public function testDeactivationReleasesSlotAndOldIdentityKeepsTerminalRevision(): void
    {
        $first=$this->activate();$input=$this->request('deactivate',null,$first['activation_id']);
        $ack=$this->app->activations->execute('deactivate',$input);$data=json_decode($ack->body,true);
        self::assertSame(['activation_id','deactivated_at','status'],array_keys($data));self::assertSame('deactivated',$data['status']);
        self::assertSame('issued',$this->app->read->license($this->issued['id'])['commercial_status']);
        $other=$this->device();$next=$this->payload($this->app->activations->execute('activate',$this->request('activate',$other)));
        self::assertNotSame($first['activation_id'],$next['activation_id']);self::assertSame(3,$next['license_revision']);
        self::assertSame($ack->body,$this->app->activations->execute('deactivate',$input)->body);
        self::assertSame($ack->body,$this->app->activations->execute('deactivate',$this->request('deactivate',null,$first['activation_id']))->body);
        $old=$this->payload($this->app->activations->execute('refresh',$this->request('refresh',null,$first['activation_id'])));
        self::assertSame('revoked',$old['license_status']);self::assertSame(2,$old['license_revision']);
        self::assertSame(1,(int)$this->app->db->execute("SELECT COUNT(*) FROM activations WHERE state='active'")->fetchColumn());
    }
    public function testAdministrativeRightsAndRevocationPublishAtomically(): void
    {
        $first=$this->activate();
        $this->app->licenses->change($this->actor,Uuid::create(),$this->issued['id'],'maintenance',['version'=>1,'until'=>'2027-09-25T12:00','reference'=>'PURCHASE','reason'=>'Explicit maintenance']);
        $current=$this->payload($this->app->activations->execute('refresh',$this->request('refresh',null,$first['activation_id'])));
        self::assertSame(2,$current['license_revision']);self::assertNull($current['expires_at']);self::assertNotNull($current['maintenance_until']);
        $this->app->licenses->change($this->actor,Uuid::create(),$this->issued['id'],'features',['version'=>2,'features'=>['linked_libraries'],'reason'=>'Module change']);
        $this->app->licenses->change($this->actor,Uuid::create(),$this->issued['id'],'revoke',['version'=>3,'reason'=>'Commercial revocation']);
        $revoked=$this->payload($this->app->activations->execute('refresh',$this->request('refresh',null,$first['activation_id'])));
        self::assertSame('revoked',$revoked['license_status']);self::assertSame(4,$revoked['license_revision']);self::assertFalse($revoked['features']['review_workflow']);
        self::assertSame(403,$this->app->activations->execute('activate',$this->request('activate',$this->device()))->status);
        self::assertGreaterThan(0,$this->app->audit->verify());
    }
    public function testExpiredSubscriptionRefreshDoesNotExtendDatesOrFreeSlot(): void
    {
        $this->issued=$this->issue('subscription');$first=$this->activate();
        $this->clock->instant=new \DateTimeImmutable('2026-11-01T00:00:00Z');
        $current=$this->payload($this->app->activations->execute('refresh',$this->request('refresh',null,$first['activation_id'])));
        self::assertSame($first,$current);self::assertSame(15,$current['grace_days']);self::assertSame('active',$current['license_status']);
        self::assertSame(409,$this->app->activations->execute('activate',$this->request('activate',$this->device()))->status);
        $this->app->licenses->change($this->actor,Uuid::create(),$this->issued['id'],'renew',['version'=>1,'until'=>'2026-12-01T00:00','reason'=>'Purchased renewal']);
        $renewed=$this->payload($this->app->activations->execute('refresh',$this->request('refresh',null,$first['activation_id'])));
        self::assertSame(2,$renewed['license_revision']);self::assertSame($first['activation_id'],$renewed['activation_id']);self::assertSame('2026-12-01T00:00:00.000000Z',$renewed['expires_at']);
    }
    public function testMissingSigningFileRollsBackActivationAndCommercialRenewal(): void
    {
        $key=$this->app->db->one('SELECT private_ref FROM signing_keys WHERE kid=\'random-test-a\'');
        $path=$this->fixture->values['SIGNING_KEY_DIR'].'/'.$key['private_ref'];
        $input=$this->request();rename($path,$path.'.unavailable');
        try {
            try{$this->app->activations->execute('activate',$input);self::fail('Missing signer accepted');}catch(\RuntimeException){}
            self::assertSame(0,(int)$this->app->db->execute('SELECT COUNT(*) FROM activations')->fetchColumn());
            self::assertSame(0,(int)$this->app->db->execute('SELECT COUNT(*) FROM license_requests')->fetchColumn());
            self::assertNull($this->app->db->one('SELECT consumed_at FROM activation_challenges')['consumed_at']);
        } finally {rename($path.'.unavailable',$path);}
        $this->app->activations->execute('activate',$input);rename($path,$path.'.unavailable');
        try {
            try{$this->app->licenses->change($this->actor,Uuid::create(),$this->issued['id'],'maintenance',['version'=>1,'until'=>'2027-09-25T12:00','reference'=>'ROLLBACK','reason'=>'Must roll back']);self::fail('Unsigned maintenance accepted');}catch(\RuntimeException){}
            self::assertNull($this->app->read->license($this->issued['id'])['maintenance_until']);
            self::assertSame(0,(int)$this->app->db->execute('SELECT COUNT(*) FROM maintenance_purchases')->fetchColumn());
            self::assertSame(1,(int)$this->app->db->execute('SELECT COUNT(*) FROM license_revisions')->fetchColumn());
        } finally {rename($path.'.unavailable',$path);}
    }
    public function testDatabaseEnforcesOneSlotAndPreventsIdentityOrRevisionEdits(): void
    {
        $this->activate();$activation=$this->app->db->one('SELECT * FROM activations');
        try{$this->fixture->owner->execute("INSERT INTO activations(activation_id,license_id,product_id,installation_id,installation_public_key,fingerprint_version,fingerprint_hash,state,activated_at) VALUES (?,?,?,?,?,?,?,'active',?)",[Uuid::bytes(Uuid::create()),$activation['license_id'],$activation['product_id'],$activation['installation_id'],$activation['installation_public_key'],'1',$activation['fingerprint_hash'],$this->clock->sql()]);self::fail('Duplicate active slot accepted');}catch(\PDOException $e){self::assertSame(1062,(int)$e->errorInfo[1]);}
        foreach(['UPDATE activations SET installation_public_key=installation_public_key WHERE 1=0','UPDATE license_revisions SET license_jws=license_jws WHERE 1=0','DELETE FROM license_revisions WHERE 1=0'] as $sql) {
            try{$this->app->db->execute($sql);self::fail('Immutable data can be changed');}catch(\PDOException $e){self::assertContains((int)$e->errorInfo[1],[1142,1143]);}
        }
    }
    public function testRotationPreservesOldJwsAndSeparatesManifestPurpose(): void
    {
        $first=$this->activate();$keys=$this->fixture->signingKeys($this->clock);
        $keys->generate('random-manifest','manifest');$keys->activate('random-manifest','Manifest isolation');
        self::assertSame($first,$this->payload($this->app->activations->execute('refresh',$this->request('refresh',null,$first['activation_id']))));
        $keys->generate('random-test-b','license');$keys->activate('random-test-b','Trust distributed in isolated test');
        $this->app->licenses->change($this->actor,Uuid::create(),$this->issued['id'],'features',['version'=>1,'features'=>['ocr'],'reason'=>'New rights with new signer']);
        $this->payload($this->app->activations->execute('refresh',$this->request('refresh',null,$first['activation_id'])));
        self::assertSame(['random-test-a','random-test-b'],array_column($this->app->db->all('SELECT kid FROM license_revisions ORDER BY license_revision'),'kid'));
        self::assertSame('verify_only',$this->app->db->execute('SELECT state FROM signing_keys WHERE kid=\'random-test-a\'')->fetchColumn());
    }
    public function testUppercaseUuidBytesAreUsedInProofWithoutNormalization(): void
    {
        $device=$this->device;$device['id']=strtoupper($device['id']);$input=$this->request('activate',$device);
        $nonce=$this->app->db->execute('SELECT nonce FROM activation_challenges WHERE challenge_id=?',[Uuid::bytes($input['challenge_id'])])->fetchColumn();
        $input['challenge_id']=strtoupper($input['challenge_id']);
        $input['proof']=Protocol::encode(sodium_crypto_sign_detached(Protocol::proofMessage('activate',$input,$nonce),$device['secret']));
        self::assertSame(strtolower($device['id']),$this->payload($this->app->activations->execute('activate',$input))['installation_id']);
    }
    public function testAuditFailureAfterSigningRollsBackEveryProtocolEffect(): void
    {
        $input=$this->request();
        $this->fixture->owner->execute('UPDATE audit_heads SET last_sequence=0');
        try{$this->app->activations->execute('activate',$input);self::fail('Partial effect committed');}catch(\PDOException){}
        foreach(['activations','license_revisions','license_requests'] as $table)self::assertSame(0,(int)$this->app->db->execute("SELECT COUNT(*) FROM $table")->fetchColumn());
        self::assertSame(0,(int)$this->app->db->execute('SELECT revision_counter FROM licenses')->fetchColumn());
        self::assertNull($this->app->db->one('SELECT consumed_at FROM activation_challenges')['consumed_at']);
    }
    public function testSavingUnchangedModulesDoesNotCreateAnotherSignedRevision(): void
    {
        $this->activate();
        $this->app->licenses->change($this->actor,Uuid::create(),$this->issued['id'],'features',['version'=>1,'features'=>['linked_libraries','expedientes','review_workflow'],'reason'=>'Review without a rights change']);
        self::assertSame(1,(int)$this->app->db->execute('SELECT COUNT(*) FROM license_revisions')->fetchColumn());
    }
    public function testProductionRejectsPublicFixtureKeyEvenWhenRelabeled(): void
    {
        $values=array_replace($this->fixture->values,['APP_ENV'=>'production','APP_URL'=>'https://license.example.test']);
        $production=new App(new Config($values,false),$this->fixture->owner,$this->clock);
        $production->signingKeys->generate('relabeled-fixture','license');
        $production->signingKeys->activate('relabeled-fixture','Isolated production-policy test');
        $record=$this->fixture->owner->one('SELECT * FROM signing_keys WHERE kid=\'relabeled-fixture\'');
        $pair=sodium_crypto_sign_seed_keypair(hash('sha256','AIBIDLICENSE PUBLIC TEST ONLY V1 lic-test-v1-a',true));
        $public=sodium_crypto_sign_publickey($pair);
        $path=$values['SIGNING_KEY_DIR'].'/'.$record['private_ref'];
        $file=json_decode(file_get_contents($path),true);$file['public_key']=Protocol::encode($public);$file['secret_key']=Protocol::encode(sodium_crypto_sign_secretkey($pair));
        file_put_contents($path,Protocol::json($file));
        $this->fixture->owner->execute('UPDATE signing_keys SET public_key=? WHERE kid=?',[$public,'relabeled-fixture']);
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('Public fixture key forbidden');
        $production->signingKeys->check();
    }
    public function testTwoProcessesWithDistinctRequestsCannotOccupyTwoSlots(): void { $this->race(false); }
    public function testTwoProcessesWithIdenticalRequestReturnIdenticalBytes(): void { $this->race(true); }
    private function race(bool $duplicate): void
    {
        $first=$this->request();$inputs=[$first,$duplicate?$first:$this->request('activate',$this->device())];
        $workers=[];$files=[];$owner=$this->fixture->owner;
        $owner->pdo->beginTransaction();$owner->one('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE',[Uuid::bytes($this->issued['id'])]);
        try {
            foreach($inputs as $input) {
                $file=tempnam(sys_get_temp_dir(),'aibid-api-race-');chmod($file,0600);$files[]=$file;
                file_put_contents($file,Protocol::json(['config'=>$this->fixture->values,'input'=>$input]));
                $process=proc_open([PHP_BINARY,dirname(__DIR__).'/activation-worker.php',$file],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
                self::assertIsResource($process);$workers[]=[$process,$pipes];
            }
            $deadline=microtime(true)+10;
            do{$waiting=(int)$owner->execute('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE l.OBJECT_SCHEMA=?',[$this->fixture->database])->fetchColumn();if($waiting>=2)break;usleep(10000);}while(microtime(true)<$deadline);
            self::assertGreaterThanOrEqual(2,$waiting);$owner->pdo->commit();$results=[];
            foreach($workers as [$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$error);$results[]=json_decode($output,true,512,JSON_THROW_ON_ERROR);}
            $statuses=array_column($results,'status');sort($statuses);self::assertSame($duplicate?[200,200]:[200,409],$statuses);
            if($duplicate)self::assertSame($results[0]['body'],$results[1]['body']);
            self::assertSame(1,(int)$this->app->db->execute('SELECT COUNT(*) FROM activations')->fetchColumn());
            self::assertSame(1,(int)$this->app->db->execute('SELECT COUNT(*) FROM license_revisions')->fetchColumn());
        }finally{if($owner->pdo->inTransaction())$owner->pdo->rollBack();foreach($files as $file)unlink($file);}
    }
}
