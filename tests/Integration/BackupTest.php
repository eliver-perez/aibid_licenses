<?php
declare(strict_types=1);
namespace Aibid\Tests\Integration;

use Aibid\{App,Config};
use Aibid\Application\Actor;
use Aibid\Domain\{Protocol,Uuid};
use Aibid\Infrastructure\{Backup,Database,RecoveryVerifier};
use Aibid\Tests\{FrozenClock,MySqlFixture,NativeStackFixture};
use PHPUnit\Framework\TestCase;

final class BackupTest extends TestCase
{
    public function testEncryptedCliBackupRestoresHistoryKeysAndEvidenceOnlyIntoAnEmptyDatabase(): void
    {
        if (!getenv('TEST_MYSQL_DSN')) { self::markTestSkipped('Requires isolated MySQL 8 and its mysql/mysqldump utilities.'); }
        $clock = new FrozenClock(new \DateTimeImmutable('2026-09-26T12:00:00Z'));
        $fixture = new MySqlFixture($clock); $app = $fixture->app;
        $directory = sys_get_temp_dir().'/aibid-recovery-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
        $target = 'aibid_test_'.bin2hex(random_bytes(5)); $created = false;
        $write = static function (string $file, string $value): void { file_put_contents($file,$value); chmod($file,0600); };
        $environment = array_diff_key(getenv(),$fixture->values);
        $environment['AIBID_CONFIG'] = $directory.'/source.php';
        $cli = static fn(array $args): string => NativeStackFixture::command([PHP_BINARY,...$args],dirname(__DIR__,2),$environment);
        try {
            $write($directory.'/source.php','<?php return '.var_export($fixture->values,true).';');
            $keys = $fixture->signingKeys($clock); $keys->generate('backup-old','license'); $keys->activate('backup-old','Test');
            $actor = new Actor($app->auth->bootstrap('backup@example.test','Backup','Fixture-Password-Only-2026!'),'superadmin');
            $mfa = 'JBSWY3DPEHPK3PXP';
            $app->db->execute('UPDATE admin_users SET mfa_secret_encrypted=? WHERE id=?',[$app->crypto->encrypt($mfa,'mfa:'.$actor->id),Uuid::bytes($actor->id)]);
            $customer = $app->catalog->saveCustomer($actor,Uuid::create(),null,['name'=>'Recovery fixture','contact_name'=>'Test','email'=>'backup@example.test','state'=>'active']);
            $license = $app->licenses->issue($actor,Uuid::create(),['customer_id'=>$customer['id'],'product_id'=>'gestor_documental','license_type'=>'perpetual','entitled_release_until'=>'2026-09-26T12:00','features'=>['expedientes'],'reason'=>'Recovery fixture']);
            $pair = sodium_crypto_sign_keypair();
            $payload = ['action'=>'activate','request_id'=>Uuid::create(),'created_at'=>'2026-09-26T12:00:00Z','product_id'=>'gestor_documental','installation_id'=>Uuid::create(),'installation_public_key'=>Protocol::encode(sodium_crypto_sign_publickey($pair)),'fingerprint_version'=>'1','fingerprint_hash'=>'sha256:'.str_repeat('d',64),'license_key'=>$license['secret']];
            $envelope = static function (array $input) use ($pair): string {
                $bytes = Protocol::encode(Protocol::json($input));
                return Protocol::json(['schema_version'=>'1.0','payload_b64u'=>$bytes,'signature_b64u'=>Protocol::encode(sodium_crypto_sign_detached("LICREQ-V1\n".$bytes,sodium_crypto_sign_secretkey($pair)))]);
            };
            $original = $envelope($payload);
            $offline = $app->offline->import($actor,Uuid::create(),$original);
            $decision = $app->offline->decide($actor,Uuid::create(),$offline['id'],'approved',['license_id'=>$license['id'],'version'=>1,'renewal_mode'=>'current','reason'=>'Recovery fixture']);
            $jws = $app->read->revision($license['id'],$decision['revision_id'])['license_jws'];
            $claims = json_decode(sodium_base642bin(explode('.',$jws)[1],SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),true);
            $keys->generate('backup-new','license'); $keys->activate('backup-new','Test rotation');
            $renew = array_replace($payload,['action'=>'renew','request_id'=>Uuid::create(),'license_id'=>$license['id'],'activation_id'=>$claims['activation_id']]);
            $pending = $app->offline->import($actor,Uuid::create(),$envelope($renew));
            $app->offline->release($actor,Uuid::create(),$license['id'],['activation_id'=>$claims['activation_id'],'version'=>1,'reason'=>'Recovery fixture retirement','accept_offline_limit'=>'1']);
            $history = $app->db->all('SELECT license_revision,license_jws,kid FROM license_revisions ORDER BY license_revision');
            self::assertCount(2,$history);
            $cli(['bin/backup.php','--action=keygen','--key-file='.$directory.'/backup.key']);
            self::assertSame(0600,fileperms($directory.'/backup.key')&0777);
            $metadata = json_decode($cli(['bin/backup.php','--action=create','--key-file='.$directory.'/backup.key','--output='.$directory.'/copy.aibidbackup','--mysqldump='.(getenv('TEST_MYSQLDUMP_BIN') ?: 'mysqldump')]),true,16,JSON_THROW_ON_ERROR);
            self::assertSame(hash_file('sha256',$directory.'/copy.aibidbackup'),$metadata['archive_sha256']);
            self::assertSame(0600,fileperms($directory.'/copy.aibidbackup')&0777);
            self::assertSame([],glob($directory.'/.aibid-backup-*'));
            $extracted = json_decode($cli(['bin/backup.php','--action=extract','--key-file='.$directory.'/backup.key','--archive='.$directory.'/copy.aibidbackup','--directory='.$directory.'/restored']),true,16,JSON_THROW_ON_ERROR);
            self::assertTrue($extracted['verified']); self::assertSame(4,$extracted['files']);
            $saved = json_decode(file_get_contents($directory.'/restored/config.json'),true,16,JSON_THROW_ON_ERROR);
            foreach (['MFA_KEY','CREDENTIAL_KEY','AUDIT_KEY','SESSION_KEY','OPERATION_KEY'] as $name) { self::assertSame($fixture->values[$name],$saved[$name]); }
            self::assertStringNotContainsString('DROP TABLE',file_get_contents($directory.'/restored/database.sql'));
            $fixture->owner->execute('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
            $dsn = str_replace('dbname='.$fixture->database,'dbname='.$target,$saved['DB_DSN']);
            $ownerValues = array_replace($saved,['DB_DSN'=>$dsn,'DB_USER'=>getenv('TEST_MYSQL_USER') ?: 'root','DB_PASSWORD'=>getenv('TEST_MYSQL_PASSWORD') ?: '']);
            $write($directory.'/target.php','<?php return '.var_export($ownerValues,true).';');
            $restoreArgs = ['bin/restore-empty.php','--directory='.$directory.'/restored','--target-config='.$directory.'/target.php','--mysql='.(getenv('TEST_MYSQL_BIN') ?: 'mysql')];
            self::assertStringContainsString('completada',$cli($restoreArgs));
            $grants = $cli(['bin/grants.php','--database='.$target,'--user='.$fixture->username]);
            foreach (explode(';',$grants) as $sql) { if (trim($sql)!=='') { $fixture->owner->execute($sql); } }
            $restoredValues = array_replace($saved,['DB_DSN'=>$dsn,'SIGNING_KEY_DIR'=>$directory.'/restored/keys']);
            $config = new Config($restoredValues,false); $restored = new App($config,Database::connect($config),$clock);
            $checked = RecoveryVerifier::verify($restored,$metadata['audit']);
            self::assertSame(2,$checked['signed_revisions']); self::assertSame(2,$checked['offline_requests']);
            self::assertTrue($checked['external_anchor_checked']);
            self::assertSame($metadata['audit']['events'],$checked['audit_events']);
            $restored->signingKeys->check();
            self::assertSame($history,$restored->db->all('SELECT license_revision,license_jws,kid FROM license_revisions ORDER BY license_revision'));
            self::assertSame(2,(int)$restored->read->license($license['id'])['revision_counter']);
            self::assertSame('revoked',$restored->db->execute('SELECT state FROM activations')->fetchColumn());
            self::assertSame('issued',$restored->read->license($license['id'])['commercial_status']);
            self::assertSame('pending',$restored->read->offline($pending['id'])['status']);
            self::assertSame('approved',$restored->read->offline($offline['id'])['status']);
            self::assertSame($mfa,$restored->crypto->decrypt($restored->db->execute('SELECT mfa_secret_encrypted FROM admin_users')->fetchColumn(),'mfa:'.$actor->id));
            self::assertSame($restored->crypto->digest('CREDENTIAL_KEY',$license['secret']),$restored->db->execute('SELECT key_digest FROM license_credentials')->fetchColumn());
            $evidence = $restored->db->execute('SELECT evidence_encrypted FROM offline_requests WHERE id=?',[Uuid::bytes($offline['id'])])->fetchColumn();
            self::assertSame($original,$restored->crypto->decryptEvidence($evidence,'offline:v1:'.$offline['id']));
            self::assertSame($offline['id'],$restored->offline->import($actor,Uuid::create(),$original)['id']);
            self::assertSame(2,(int)$restored->db->execute('SELECT COUNT(*) FROM license_revisions')->fetchColumn());
            try { $cli($restoreArgs); self::fail('Nonempty restore target was accepted.'); }
            catch (\RuntimeException $error) { self::assertStringContainsString('failed',$error->getMessage()); }
            self::assertSame($history,$restored->db->all('SELECT license_revision,license_jws,kid FROM license_revisions ORDER BY license_revision'));
            $app->catalog->saveCustomer($actor,Uuid::create(),null,['name'=>'After snapshot','contact_name'=>'Test','email'=>'backup@example.test','state'=>'active']);
            $latest = $app->db->one("SELECT last_sequence,last_event_hash FROM audit_heads WHERE stream_id='admin'");
            try { RecoveryVerifier::verify($restored,['events'=>(int)$latest['last_sequence'],'last_hash'=>$latest['last_event_hash']]); self::fail('Rollback behind external anchor was accepted.'); }
            catch (\RuntimeException $error) { self::assertStringContainsString('external anchor',$error->getMessage()); }
            $owner = Database::connect(new Config($ownerValues,false));
            $owner->execute('UPDATE licenses SET revision_counter=revision_counter+1');
            try { RecoveryVerifier::verify($restored); self::fail('Incorrect revision counter was accepted.'); }
            catch (\RuntimeException $error) { self::assertStringContainsString('counter',$error->getMessage()); }
            $owner->execute('UPDATE licenses SET revision_counter=revision_counter-1');
            $owner->execute("UPDATE license_revisions SET license_jws=CONCAT(license_jws,'x') WHERE license_revision=1");
            try { RecoveryVerifier::verify($restored); self::fail('Corrupt JWS was accepted.'); }
            catch (\RuntimeException $error) { self::assertStringContainsString('JWS',$error->getMessage()); }
        } finally {
            if ($created && preg_match('/\Aaibid_test_[a-f0-9]{10}\z/',$target)) { $fixture->owner->execute('DROP DATABASE `'.$target.'`'); }
            $fixture->destroy(); NativeStackFixture::remove($directory);
        }
    }
}
