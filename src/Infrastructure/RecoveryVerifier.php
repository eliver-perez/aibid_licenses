<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

use Aibid\App;
use Aibid\Domain\{OfflineRequest,Protocol,StrictJson,Uuid};

final class RecoveryVerifier
{
    public static function verify(App $app, ?array $anchor = null): array
    {
        return $app->db->transaction(function () use ($app,$anchor): array {
            $app->db->one("SELECT * FROM audit_heads WHERE stream_id='admin' FOR SHARE");
            $events = $app->audit->verify();
            if ($anchor!==null) { $app->audit->assertAnchor($anchor); }
            if ($app->db->one("SELECT l.license_id FROM licenses l LEFT JOIN (SELECT license_id,MAX(license_revision) AS latest FROM license_revisions GROUP BY license_id) r ON r.license_id=l.license_id WHERE l.revision_counter<>COALESCE(r.latest,0) LIMIT 1")) { throw new \RuntimeException('Revision counter mismatch.'); }
            if ($app->db->one("SELECT license_id FROM activations WHERE state='active' GROUP BY license_id HAVING COUNT(*)>1 LIMIT 1")) { throw new \RuntimeException('More than one active installation.'); }
            $revisions = 0;
            foreach ($app->db->execute('SELECT r.*,k.public_key FROM license_revisions r JOIN signing_keys k ON k.kid=r.kid') as $row) {
                $parts = explode('.',$row['license_jws']);
                if (count($parts)!==3 || !hash_equals($row['jws_sha256'],hash('sha256',$row['license_jws']))) { throw new \RuntimeException('Stored JWS checksum mismatch.'); }
                $header = StrictJson::object(sodium_base642bin($parts[0],SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING));
                $payloadBytes = sodium_base642bin($parts[1],SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
                $payload = StrictJson::object($payloadBytes,65536);
                if (($header['alg'] ?? '')!=='EdDSA' || ($header['typ'] ?? '')!=='lic+jws' || ($header['kid'] ?? '')!==$row['kid'] || $payloadBytes!==$row['payload_json'] || ($payload['license_revision'] ?? null)!==(int)$row['license_revision'] || ($payload['activation_id'] ?? '')!==Uuid::text($row['activation_id']) || ($payload['license_id'] ?? '')!==Uuid::text($row['license_id']) || ($payload['license_status'] ?? '')!==$row['license_status'] || !sodium_crypto_sign_verify_detached(Protocol::decode($parts[2],64),$parts[0].'.'.$parts[1],$row['public_key'])) { throw new \RuntimeException('Stored JWS verification failed.'); }
                ++$revisions;
            }
            if ($app->db->one("SELECT a.activation_id FROM activations a LEFT JOIN license_revisions r ON r.revision_id=a.current_revision_id WHERE r.revision_id IS NULL OR r.activation_id<>a.activation_id OR r.license_id<>a.license_id OR (a.state<>'active' AND r.license_status<>'revoked') OR r.license_revision<>(SELECT MAX(h.license_revision) FROM license_revisions h WHERE h.activation_id=a.activation_id) LIMIT 1")) { throw new \RuntimeException('Activation history pointer mismatch.'); }
            foreach ($app->db->execute('SELECT credential_id,key_digest,credential_encrypted FROM license_credentials WHERE credential_encrypted IS NOT NULL') as $row) {
                $secret=$app->crypto->open($row['credential_encrypted'],'credential:'.Uuid::text($row['credential_id']),'activation-code');
                try { if (!hash_equals($row['key_digest'],$app->crypto->digest('CREDENTIAL_KEY',$secret))) { throw new \RuntimeException('Credential digest mismatch.'); } }
                finally { sodium_memzero($secret); }
            }
            foreach ($app->db->execute('SELECT id,pdf_encrypted,pdf_sha256,snapshot_encrypted,verification_digest FROM license_documents') as $row) {
                $id=Uuid::text($row['id']);
                $pdf=$app->crypto->open($row['pdf_encrypted'],'document-pdf:'.$id,'document');
                try { if (!hash_equals($row['pdf_sha256'],hash('sha256',$pdf))) { throw new \RuntimeException('Document checksum mismatch.'); } }
                finally { sodium_memzero($pdf); }
                $snapshot=$app->crypto->open($row['snapshot_encrypted'],'document-snapshot:'.$id,'document');
                try { $data=json_decode($snapshot,true,32,JSON_THROW_ON_ERROR); if (!hash_equals($row['verification_digest'],hash('sha256',basename($data['verification_url'])))) { throw new \RuntimeException('Document verification mismatch.'); } }
                finally { sodium_memzero($snapshot); }
            }
            $offline = 0;
            foreach ($app->db->execute('SELECT o.*,r.input_digest FROM offline_requests o JOIN license_requests r ON r.product_id=o.product_id AND r.request_id=o.request_id') as $row) {
                $original = $app->crypto->decryptEvidence($row['evidence_encrypted'],'offline:v1:'.Uuid::text($row['id']));
                try { $verified = OfflineRequest::verify($original); } finally { sodium_memzero($original); }
                $digest = $app->crypto->digest('OPERATION_KEY','protocol-v1:offline:'.$verified['payload']['action']."\n".$verified['digest_material']);
                if (!hash_equals($row['input_digest'],$digest)) { throw new \RuntimeException('Offline evidence digest mismatch.'); }
                ++$offline;
            }
            return ['audit_events'=>$events,'signed_revisions'=>$revisions,'offline_requests'=>$offline,'external_anchor_checked'=>$anchor!==null];
        });
    }
}
