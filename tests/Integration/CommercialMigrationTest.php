<?php
declare(strict_types=1);
namespace Aibid\Tests\Integration;

use Aibid\Domain\Uuid;
use Aibid\Infrastructure\{Database, Migrator};
use Aibid\Tests\NativeStackFixture;
use PHPUnit\Framework\TestCase;

final class CommercialMigrationTest extends TestCase
{
    public function testUpgradeFromFiveMigrationsPreservesLegacyDigestAndBackfillsAdministrativeFields(): void
    {
        $dsn=getenv('TEST_MYSQL_DSN');if (!$dsn) { self::markTestSkipped('Requires explicit isolated MySQL 8.'); }
        $name='aibid_test_'.bin2hex(random_bytes(5));$directory=sys_get_temp_dir().'/'.$name.'-legacy';mkdir($directory,0700);
        $server=new \PDO($dsn,getenv('TEST_MYSQL_USER') ?: 'root',getenv('TEST_MYSQL_PASSWORD') ?: '',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $created=false;
        try {
            $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
            $databaseDsn=preg_replace('/;?dbname=[^;]*/','',$dsn).';dbname='.$name;
            $db=new Database(new \PDO($databaseDsn,getenv('TEST_MYSQL_USER') ?: 'root',getenv('TEST_MYSQL_PASSWORD') ?: ''));
            foreach (glob(dirname(__DIR__,2).'/database/migrations/00[1-5]*.sql') as $file) { copy($file,$directory.'/'.basename($file)); }
            self::assertCount(5,(new Migrator($db,$directory))->migrate());
            $admin=Uuid::bytes(Uuid::create());$customer=Uuid::bytes(Uuid::create());$license=Uuid::bytes(Uuid::create());$credential=Uuid::bytes(Uuid::create());
            $db->execute("INSERT INTO admin_users(id,login,display_name,password_hash,role,created_at,updated_at) VALUES (?,'legacy@example.test','Prueba','not-a-login-password','superadmin',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$admin]);
            $db->execute("INSERT INTO customers(id,display_name,created_at,updated_at) VALUES (?,'Legacy synthetic',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$customer]);
            $db->execute("INSERT INTO licenses(license_id,customer_id,product_id,license_type,grace_days,entitled_release_until,created_at,updated_at) VALUES (?,?,'gestor_documental','perpetual',0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$license,$customer]);
            $digest=hash_hmac('sha256','AIDEX-legacy-synthetic',random_bytes(32));
            $db->execute("INSERT INTO license_credentials(credential_id,license_id,key_digest,digest_key_version,state,created_at) VALUES (?,?,?,1,'active',UTC_TIMESTAMP(6))",[$credential,$license,$digest]);
            $db->execute("INSERT INTO license_changes(id,license_id,version,action,admin_id,snapshot_json,reason,created_at) VALUES (?,?,1,'license.issued',?,?,?,UTC_TIMESTAMP(6))",[Uuid::bytes(Uuid::create()),$license,$admin,json_encode(['commercial_reference'=>'Contrato original']),'Nota original']);
            $before=$db->one('SELECT * FROM licenses WHERE license_id=?',[$license]);
            self::assertSame(['006_commercial_delivery.sql'],(new Migrator($db,dirname(__DIR__,2).'/database/migrations'))->migrate());
            $after=$db->one('SELECT * FROM licenses WHERE license_id=?',[$license]);
            foreach ($before as $field=>$value) { self::assertSame($value,$after[$field]); }
            self::assertSame('Contrato original',$after['commercial_reference']);self::assertSame('Nota original',$after['authorization_note']);
            $saved=$db->one('SELECT * FROM license_credentials WHERE credential_id=?',[$credential]);
            self::assertSame($digest,$saved['key_digest']);self::assertNull($saved['credential_encrypted']);self::assertNull($saved['code_format']);
            self::assertSame([], (new Migrator($db,dirname(__DIR__,2).'/database/migrations'))->migrate());
        } finally {
            if ($created && preg_match('/\Aaibid_test_[a-f0-9]{10}\z/',$name)) { $server->exec('DROP DATABASE `'.$name.'`'); }
            NativeStackFixture::remove($directory);
        }
    }
}
