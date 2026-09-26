<?php
declare(strict_types=1);
namespace Aibid\Tests\Integration;

use Aibid\App;
use Aibid\Application\Actor;
use Aibid\Domain\{ApiProblem, OfflineRequest, Problem, Protocol, Uuid};
use Aibid\Infrastructure\Migrator;
use Aibid\Tests\{FrozenClock, MySqlFixture};
use PHPUnit\Framework\TestCase;

final class OfflineTest extends TestCase
{
    private MySqlFixture $fixture;
    private App $app;
    private FrozenClock $clock;
    private Actor $actor;
    private array $license;
    private array $device;

    protected function setUp(): void
    {
        if (!getenv('TEST_MYSQL_DSN')) { self::markTestSkipped('Requires isolated MySQL 8.'); }
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-09-25T12:00:00Z'));
        $this->fixture = new MySqlFixture($this->clock); $this->app = $this->fixture->app;
        $keys = $this->fixture->signingKeys($this->clock); $keys->generate('offline-test','license'); $keys->activate('offline-test','Isolated test');
        $this->actor = new Actor($this->app->auth->bootstrap('admin@example.test','Admin','Fixture-Password-Only-2026!'),'superadmin');
        $this->license = $this->issue(); $this->device = $this->device();
    }
    protected function tearDown(): void { if (isset($this->fixture)) { $this->fixture->destroy(); } }
    private function issue(string $type = 'perpetual'): array
    {
        $customer = $this->app->catalog->saveCustomer($this->actor,Uuid::create(),null,['name'=>'Offline tests','contact_name'=>'Test','email'=>'offline@example.test','state'=>'active']);
        return $this->app->licenses->issue($this->actor,Uuid::create(),['customer_id'=>$customer['id'],'product_id'=>'gestor_documental','license_type'=>$type,'entitled_release_until'=>'2026-09-25T12:00','expires_at'=>'2026-10-01T00:00','features'=>['expedientes','review_workflow'],'reason'=>'Offline tests']);
    }
    private function device(): array
    {
        $pair = sodium_crypto_sign_keypair();
        return ['id'=>Uuid::create(),'public'=>Protocol::encode(sodium_crypto_sign_publickey($pair)),'secret'=>sodium_crypto_sign_secretkey($pair)];
    }
    private function envelope(string $action = 'activate', ?array $activation = null, array $changes = [], ?array $device = null): string
    {
        $device ??= $this->device;
        $payload = ['action'=>$action,'request_id'=>Uuid::create(),'created_at'=>'1999-01-01T00:00:00Z','product_id'=>'gestor_documental','installation_id'=>$device['id'],'installation_public_key'=>$device['public'],'fingerprint_version'=>'1','fingerprint_hash'=>'sha256:'.str_repeat('b',64),'license_key'=>$this->license['secret']];
        if ($activation !== null) { $payload += ['license_id'=>$activation['license_id'],'activation_id'=>$activation['activation_id']]; }
        $encoded = Protocol::encode(Protocol::json(array_replace($payload,$changes)));
        return Protocol::json(['schema_version'=>'1.0','payload_b64u'=>$encoded,'signature_b64u'=>Protocol::encode(sodium_crypto_sign_detached("LICREQ-V1\n".$encoded,$device['secret']))]);
    }
    private function import(string $envelope): string { return $this->app->offline->import($this->actor,Uuid::create(),$envelope)['id']; }
    private function input(array $extra = []): array
    {
        return array_replace(['license_id'=>$this->license['id'],'version'=>$this->app->read->license($this->license['id'])['row_version'],'renewal_mode'=>'current','reason'=>'Explicit offline approval'], $extra);
    }
    private function approve(string $id, array $extra = [], ?string $operationId = null): array
    {
        return $this->app->offline->decide($this->actor,$operationId ?? Uuid::create(),$id,'approved',$this->input($extra));
    }
    private function payload(array $result): array
    {
        $jws = $this->app->read->revision($result['license_id'],$result['revision_id'])['license_jws'];
        [$header,$body,$signature] = explode('.',$jws);
        $public = $this->app->db->execute("SELECT public_key FROM signing_keys WHERE kid='offline-test'")->fetchColumn();
        self::assertTrue(sodium_crypto_sign_verify_detached(Protocol::decode($signature,64),$header.'.'.$body,$public));
        return json_decode(sodium_base642bin($body,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),true,512,JSON_THROW_ON_ERROR);
    }
    private function activate(): array { return $this->payload($this->approve($this->import($this->envelope()))); }
    private function rowCount(string $table): int { return (int)$this->app->db->execute('SELECT COUNT(*) FROM '.$table)->fetchColumn(); }

    private function onlineRequest(?string $requestId = null, string $action = 'activate', ?string $activationId = null): array
    {
        $challenge = json_decode($this->app->activations->challenge(['action'=>$action,'product_id'=>'gestor_documental','installation_id'=>$this->device['id'],'activation_id'=>$activationId])->body,true);
        $input = ['request_id'=>$requestId ?? Uuid::create(),'product_id'=>'gestor_documental','installation_id'=>$this->device['id'],'challenge_id'=>$challenge['challenge_id'],'app_version'=>'test'];
        if ($action === 'activate') { $input += ['license_key'=>$this->license['secret'],'installation_public_key'=>$this->device['public'],'fingerprint_version'=>'1','fingerprint_hash'=>'sha256:'.str_repeat('b',64)]; }
        else { $input['activation_id'] = $activationId; }
        $input['proof'] = Protocol::encode(sodium_crypto_sign_detached(Protocol::proofMessage($action,$input,$challenge['nonce']),$this->device['secret']));
        return $input;
    }

    public function testImportIsPendingEncryptedAndDuplicateFormattingIsHarmless(): void
    {
        $envelope = $this->envelope(); $id = $this->import($envelope);
        self::assertSame('pending',$this->app->read->offline($id)['status']); self::assertSame(0,$this->rowCount('activations'));
        $row = $this->app->db->one('SELECT * FROM offline_requests');
        self::assertSame($envelope,$this->app->crypto->decryptEvidence($row['evidence_encrypted'],'offline:v1:'.$id));
        self::assertSame($id,$this->import(json_encode(array_reverse(json_decode($envelope,true),true),JSON_PRETTY_PRINT)));
        self::assertSame(1,$this->rowCount('offline_requests'));
        foreach (['offline_requests'=>'projection_json','license_requests'=>'proof_message','audit_events'=>'payload_json','admin_operations'=>'result_json'] as $table=>$column) {
            foreach ($this->app->db->all('SELECT '.$column.' AS content FROM '.$table) as $record) { self::assertStringNotContainsString($this->license['secret'],$record['content'] ?? ''); }
        }
        $result = $this->approve($id); $payload = $this->payload($result);
        self::assertSame(1,$payload['license_revision']); self::assertNull($payload['maintenance_until']); self::assertSame('active',$payload['license_status']);
        self::assertSame($id,$this->import($envelope)); self::assertSame(1,$this->rowCount('license_revisions'));
        self::assertSame('approved',$this->app->read->offline($id)['status']);
    }

    public function testModifiedSignedBytesConflictAndInvalidSignatureReservesNothing(): void
    {
        $envelope = $this->envelope(); $payload = OfflineRequest::verify($envelope)['payload']; $this->import($envelope);
        try { $this->import($this->envelope(changes:['request_id'=>$payload['request_id'],'created_at'=>'2000-01-01T00:00:00Z'])); self::fail('Changed request accepted'); }
        catch (Problem $error) { self::assertSame(409,$error->status); }
        $outer = json_decode($this->envelope(),true); $outer['signature_b64u'] = Protocol::encode(str_repeat("\0",64));
        try { $this->import(Protocol::json($outer)); self::fail('Bad proof accepted'); }
        catch (Problem $error) { self::assertSame(401,$error->status); }
        self::assertSame(1,$this->rowCount('license_requests'));
    }

    public function testApprovalIsIdempotentAndOppositeOrSecondDecisionCannotChangeIt(): void
    {
        $id = $this->import($this->envelope()); $operation = Uuid::create(); $first = $this->approve($id,[], $operation);
        $replay = $this->approve($id,[],$operation); self::assertTrue($replay['replayed']); self::assertSame($first['revision_id'],$replay['revision_id']);
        foreach (['approved','rejected'] as $decision) {
            try { $this->app->offline->decide($this->actor,Uuid::create(),$id,$decision,$this->input()); self::fail('Second decision accepted'); }
            catch (Problem $error) { self::assertSame(409,$error->status); }
        }
        self::assertSame(1,$this->rowCount('license_revisions')); self::assertSame(1,$this->rowCount('offline_decisions'));
    }

    public function testRejectCreatesNoActivationAndCannotBeApprovedLater(): void
    {
        $id = $this->import($this->envelope());
        $this->app->offline->decide($this->actor,Uuid::create(),$id,'rejected',['reason'=>'No commercial authorization']);
        self::assertSame('rejected',$this->app->read->offline($id)['status']); self::assertSame(0,$this->rowCount('license_revisions'));
        $this->expectException(Problem::class); $this->approve($id);
    }

    public function testRenewExtendsOnlyExplicitDatesAndKeepsIdentity(): void
    {
        $this->license = $this->issue('subscription'); $active = $this->activate();
        $id = $this->import($this->envelope('renew',$active));
        try { $this->approve($id,['renewal_mode'=>'extend','until'=>'2026-09-26T00:00','reference'=>'TEST']); self::fail('Backwards renewal accepted'); }
        catch (Problem $error) { self::assertSame(422,$error->status); }
        $result = $this->approve($id,['renewal_mode'=>'extend','until'=>'2027-10-01T00:00','reference'=>'OFFLINE-PURCHASE']);
        $renewed = $this->payload($result);
        self::assertSame($active['activation_id'],$renewed['activation_id']); self::assertSame(2,$renewed['license_revision']);
        self::assertSame('2027-10-01T00:00:00.000000Z',$renewed['expires_at']); self::assertSame(15,$renewed['grace_days']); self::assertNull($renewed['maintenance_until']);
        self::assertSame(2,(int)$this->app->read->license($this->license['id'])['row_version']);
        self::assertSame(2,$this->rowCount('subscription_periods'));
    }

    public function testPerpetualRenewUsesCurrentRightsWithoutGrantingMaintenance(): void
    {
        $active = $this->activate(); $id = $this->import($this->envelope('renew',$active));
        $renewed = $this->payload($this->approve($id));
        self::assertNull($renewed['expires_at']); self::assertNull($renewed['maintenance_until']);
        self::assertSame($active['activation_id'],$renewed['activation_id']); self::assertSame(2,$renewed['license_revision']);
    }

    public function testSignedDeactivationReleasesSlotAndLeavesTerminalRevision(): void
    {
        $active = $this->activate(); $id = $this->import($this->envelope('deactivate',$active));
        $terminal = $this->payload($this->approve($id)); self::assertSame('revoked',$terminal['license_status']);
        self::assertSame('issued',$this->app->read->license($this->license['id'])['commercial_status']);
        $this->device = $this->device(); $next = $this->activate();
        self::assertNotSame($active['activation_id'],$next['activation_id']); self::assertSame(3,$next['license_revision']);
        self::assertSame(2,(int)$this->app->db->execute('SELECT r.license_revision FROM activations a JOIN license_revisions r ON r.revision_id=a.current_revision_id WHERE a.activation_id=?',[Uuid::bytes($active['activation_id'])])->fetchColumn());
    }

    public function testBoundIdentityChecksAllSignedFieldsAndRechecksAtApproval(): void
    {
        $active = $this->activate();
        foreach (['installation_id'=>Uuid::create(),'license_id'=>Uuid::create(),'activation_id'=>Uuid::create(),'fingerprint_hash'=>'sha256:'.str_repeat('c',64)] as $field=>$value) {
            try { $this->import($this->envelope('renew',$active,[$field=>$value])); self::fail('Wrong identity accepted: '.$field); }
            catch (Problem $error) { self::assertContains($error->status,[409,422]); }
        }
        try { $this->import($this->envelope('renew',$active,['installation_id'=>$this->device['id']],$this->device())); self::fail('Different key accepted'); }
        catch (Problem $error) { self::assertSame(409,$error->status); }
        $pending = $this->import($this->envelope('renew',$active));
        $this->app->offline->release($this->actor,Uuid::create(),$this->license['id'],['activation_id'=>$active['activation_id'],'version'=>1,'reason'=>'Damaged device','accept_offline_limit'=>'1']);
        try { $this->approve($pending); self::fail('Terminal activation renewed'); }
        catch (Problem $error) { self::assertSame(409,$error->status); }
        self::assertSame('pending',$this->app->read->offline($pending)['status']);
    }

    public function testForcedTransferIsAtomicAndRecordsBothInstallations(): void
    {
        $old = $this->activate(); $this->device = $this->device(); $id = $this->import($this->envelope());
        try { $this->approve($id); self::fail('Occupied slot accepted'); } catch (Problem $error) { self::assertSame(409,$error->status); }
        $input = ['force_activation_id'=>$old['activation_id'],'accept_offline_limit'=>'1'];
        $new = $this->payload($this->approve($id,$input)); self::assertSame(3,$new['license_revision']); self::assertNotSame($old['activation_id'],$new['activation_id']);
        self::assertSame('revoked',$this->app->db->execute('SELECT state FROM activations WHERE activation_id=?',[Uuid::bytes($old['activation_id'])])->fetchColumn());
        $transfer = $this->app->db->one('SELECT * FROM license_transfers');
        self::assertSame(Uuid::bytes($old['activation_id']),$transfer['outgoing_activation_id']); self::assertSame(Uuid::bytes($new['activation_id']),$transfer['incoming_activation_id']);
        self::assertSame('issued',$this->app->read->license($this->license['id'])['commercial_status']);
    }

    public function testFailureAfterOutgoingRetirementRollsBackWholeTransfer(): void
    {
        $old = $this->activate(); $this->device = $this->device(); $id = $this->import($this->envelope());
        // Fail publication of the destination after the old terminal JWS has been persisted.
        $this->fixture->owner->execute("CREATE TRIGGER test_fail_destination BEFORE INSERT ON license_revisions FOR EACH ROW BEGIN IF NEW.license_revision=3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated injected failure'; END IF; END");
        try { $this->approve($id,['force_activation_id'=>$old['activation_id'],'accept_offline_limit'=>'1']); self::fail('Failure not injected'); }
        catch (\PDOException) { self::assertSame(1,$this->rowCount('license_revisions')); }
        self::assertSame('active',$this->app->db->execute('SELECT state FROM activations')->fetchColumn());
        self::assertSame(1,$this->rowCount('activations')); self::assertSame(0,$this->rowCount('license_transfers'));
        self::assertSame('pending',$this->app->read->offline($id)['status']);
        $this->fixture->owner->execute('DROP TRIGGER test_fail_destination');
        self::assertSame(3,$this->payload($this->approve($id,['force_activation_id'=>$old['activation_id'],'accept_offline_limit'=>'1']))['license_revision']);
    }

    public function testEvidenceTamperingAndSqlMutationAreRejected(): void
    {
        $id = $this->import($this->envelope());
        foreach (['offline_requests'=>'id','offline_decisions'=>'offline_id','license_transfers'=>'id'] as $table=>$key) {
            foreach (['UPDATE '.$table.' SET '.$key.'='.$key.' WHERE 1=0','DELETE FROM '.$table.' WHERE 1=0'] as $sql) {
                try { $this->app->db->execute($sql); self::fail('Immutable data writable'); }
                catch (\PDOException $error) { self::assertContains((int)$error->errorInfo[1],[1142,1143]); }
            }
        }
        $this->fixture->owner->execute('UPDATE offline_requests SET evidence_encrypted=?',[base64_encode(str_repeat('x',80))]);
        try { $this->approve($id); self::fail('Tampered evidence accepted'); }
        catch (\RuntimeException $error) { self::assertSame('Evidence authentication failed.',$error->getMessage()); }
        self::assertSame(0,$this->rowCount('activations')); self::assertSame(0,$this->rowCount('offline_decisions'));
    }

    public function testViewerAndOperatorCannotForceTransferAndAcknowledgementIsRequired(): void
    {
        $old = $this->activate(); $this->device = $this->device(); $id = $this->import($this->envelope());
        $operator = $this->app->admins->create($this->actor,Uuid::create(),['login'=>'operator@example.test','name'=>'Operator','role'=>'operator','new_password'=>'Fixture-Operator-Only-2026!']);
        $viewer = $this->app->admins->create($this->actor,Uuid::create(),['login'=>'viewer@example.test','name'=>'Viewer','role'=>'viewer','new_password'=>'Fixture-Viewer-Only-2026!']);
        $actor = new Actor($operator['id'],'operator');
        try { $this->app->offline->decide($actor,Uuid::create(),$id,'approved',$this->input(['force_activation_id'=>$old['activation_id'],'accept_offline_limit'=>'1'])); self::fail('Operator forced transfer'); }
        catch (Problem $error) { self::assertSame(403,$error->status); }
        try { $this->app->offline->import(new Actor($viewer['id'],'viewer'),Uuid::create(),$this->envelope()); self::fail('Viewer imported'); }
        catch (Problem $error) { self::assertSame(403,$error->status); }
        try { $this->approve($id,['force_activation_id'=>$old['activation_id']]); self::fail('Missing acknowledgement accepted'); }
        catch (Problem $error) { self::assertSame(422,$error->status); }
        self::assertSame(1,$this->rowCount('activations'));
        // Current database role wins even if an old Actor claims superadmin.
        $this->expectException(Problem::class);
        $this->app->offline->decide(new Actor($operator['id'],'superadmin'),Uuid::create(),$id,'approved',$this->input(['force_activation_id'=>$old['activation_id'],'accept_offline_limit'=>'1']));
    }

    public function testMigrationFourPreservesOnlineHistoryAndCredentials(): void
    {
        $response = $this->app->activations->execute('activate',$this->onlineRequest());
        $jws = json_decode($response->body,true)['license_jws'];
        $active = json_decode(sodium_base642bin(explode('.',$jws)[1],SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),true);
        // Reconstruct stage 3 in this isolated fixture without changing migration source files.
        $owner = $this->fixture->owner;
        foreach (['license_transfers','offline_decisions','offline_requests'] as $table) { $owner->execute('DROP TABLE '.$table); }
        $owner->execute("DELETE FROM schema_migrations WHERE name='004_offline_requests.sql'");
        $before = $owner->all('SELECT * FROM license_revisions');
        self::assertSame(['004_offline_requests.sql'],(new Migrator($owner,dirname(__DIR__,2).'/database/migrations'))->migrate());
        self::assertSame($before,$owner->all('SELECT * FROM license_revisions'));
        self::assertSame($active['activation_id'],$this->app->read->license($this->license['id'])['activations'][0]['id_text']);
    }

    public function testOnlineAndOfflineShareRequestIdNamespaceInBothDirections(): void
    {
        $envelope = $this->envelope(); $requestId = OfflineRequest::verify($envelope)['payload']['request_id'];
        $id = $this->import($envelope);
        foreach ([false,true] as $completed) {
            if ($completed) { $this->approve($id); }
            try { $this->app->activations->execute('activate',$this->onlineRequest($requestId)); self::fail('Cross-channel ID accepted'); }
            catch (ApiProblem $error) { self::assertSame(409,$error->status); }
        }
        $input = $this->onlineRequest();
        self::assertSame(409,$this->app->activations->execute('activate',$input)->status); // Occupied license, persisted refusal.
        try { $this->import($this->envelope(changes:['request_id'=>$input['request_id']])); self::fail('Online ID imported'); }
        catch (Problem $error) { self::assertSame(409,$error->status); }
        self::assertSame(1,$this->rowCount('activations')); self::assertSame(1,$this->rowCount('offline_requests'));
    }

    public function testForcedReleaseKeepsTerminalRevisionAvailableToAuthenticatedRefresh(): void
    {
        $old = $this->activate();
        $input = ['activation_id'=>$old['activation_id'],'version'=>1,'reason'=>'Unrecoverable equipment','accept_offline_limit'=>'1'];
        $operation = Uuid::create();
        $this->app->offline->release($this->actor,$operation,$this->license['id'],$input);
        self::assertTrue($this->app->offline->release($this->actor,$operation,$this->license['id'],$input)['replayed']);
        $response = $this->app->activations->execute('refresh',$this->onlineRequest(null,'refresh',$old['activation_id']));
        self::assertSame(200,$response->status);
        $jws = json_decode($response->body,true)['license_jws'];
        $payload = json_decode(sodium_base642bin(explode('.',$jws)[1],SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),true);
        self::assertSame('revoked',$payload['license_status']); self::assertSame(2,$payload['license_revision']);
        self::assertNull($this->app->db->one('SELECT incoming_activation_id FROM license_transfers')['incoming_activation_id']);
        self::assertSame('issued',$this->app->read->license($this->license['id'])['commercial_status']);
    }

    public function testCommercialChangesRequireReviewAndRevocationBlocksApproval(): void
    {
        $id = $this->import($this->envelope()); $stale = $this->input();
        $this->app->licenses->change($this->actor,Uuid::create(),$this->license['id'],'features',['version'=>1,'features'=>['ocr'],'reason'=>'Commercial change']);
        try { $this->app->offline->decide($this->actor,Uuid::create(),$id,'approved',$stale); self::fail('Stale terms approved'); }
        catch (Problem $error) { self::assertSame(409,$error->status); }
        $this->app->licenses->change($this->actor,Uuid::create(),$this->license['id'],'revoke',['version'=>2,'reason'=>'Commercial revocation']);
        try { $this->approve($id); self::fail('Revoked license approved'); }
        catch (Problem $error) { self::assertSame(422,$error->status); }
        self::assertSame('pending',$this->app->read->offline($id)['status']); self::assertSame(0,$this->rowCount('activations'));
    }

    public function testMissingSignerAndAuditFailureLeaveRequestPending(): void
    {
        $id = $this->import($this->envelope());
        $key = $this->app->db->one("SELECT private_ref FROM signing_keys WHERE kid='offline-test'");
        $path = $this->fixture->values['SIGNING_KEY_DIR'].'/'.$key['private_ref'];
        rename($path,$path.'.unavailable');
        try { $this->approve($id); self::fail('Missing signer accepted'); }
        catch (\RuntimeException) { self::assertSame(0,$this->rowCount('license_revisions')); }
        finally { rename($path.'.unavailable',$path); }
        $this->fixture->owner->execute("CREATE TRIGGER test_fail_audit BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated audit failure'");
        try { $this->approve($id); self::fail('Missing audit accepted'); }
        catch (\PDOException) { self::assertSame(0,$this->rowCount('activations')); }
        self::assertSame('pending',$this->app->read->offline($id)['status']);
        self::assertSame(0,$this->rowCount('offline_decisions'));
        $this->fixture->owner->execute('DROP TRIGGER test_fail_audit');
        self::assertSame(1,$this->payload($this->approve($id))['license_revision']);
    }

    public function testConcurrentApprovalsCannotApplyTwice(): void { $this->race(false); }
    public function testOnlineAndOfflineRaceCannotOccupyTwoSlots(): void { $this->race(true); }
    private function race(bool $online): void
    {
        $id = $this->import($this->envelope());
        $job = ['kind'=>'offline','config'=>$this->fixture->values,'actor'=>$this->actor->id,'operation'=>Uuid::create(),'id'=>$id,'input'=>$this->input()];
        $jobs = [$job,$online ? ['kind'=>'online','config'=>$this->fixture->values,'input'=>$this->onlineRequest()] : array_replace($job,['operation'=>Uuid::create()])];
        $owner = $this->fixture->owner; $files = []; $workers = [];
        $owner->pdo->beginTransaction(); $owner->one('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE',[Uuid::bytes($this->license['id'])]);
        try {
            foreach ($jobs as $job) {
                $file = tempnam(sys_get_temp_dir(),'aibid-offline-race-'); chmod($file,0600); $files[] = $file;
                file_put_contents($file,Protocol::json($job));
                $process = proc_open([PHP_BINARY,dirname(__DIR__).'/offline-worker.php',$file],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
                self::assertIsResource($process); $workers[] = [$process,$pipes];
            }
            $deadline = microtime(true)+10;
            do {
                $waiting = (int)$owner->execute('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE l.OBJECT_SCHEMA=?',[$this->fixture->database])->fetchColumn();
                if ($waiting >= 2) { break; } usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertGreaterThanOrEqual(2,$waiting); $owner->pdo->commit(); $results = [];
            foreach ($workers as [$process,$pipes]) {
                $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0,proc_close($process),$error); $results[] = json_decode($output,true,512,JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results,'status'); sort($statuses); self::assertSame([200,409],$statuses);
            self::assertSame(1,$this->rowCount('activations')); self::assertSame(1,$this->rowCount('license_revisions'));
        } finally {
            if ($owner->pdo->inTransaction()) { $owner->pdo->rollBack(); }
            foreach ($files as $file) { unlink($file); }
        }
    }
}
