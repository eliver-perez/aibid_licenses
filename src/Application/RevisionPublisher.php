<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Domain\{Capabilities, Protocol, Uuid};
use Aibid\Infrastructure\{Clock, Database, SigningKeys};

final class RevisionPublisher
{
    public function __construct(private Database $db, private Clock $clock, private SigningKeys $keys) {}

    /** Caller holds the product, license and activation locks, in that order. */
    public function publish(string $licenseId, string $activationId): array
    {
        if (!$this->db->pdo->inTransaction()) { throw new \LogicException('Publication requires a transaction.'); }
        $license = $this->db->one('SELECT * FROM licenses WHERE license_id=? FOR UPDATE', [$licenseId]);
        $activation = $this->db->one('SELECT * FROM activations WHERE activation_id=? AND license_id=? FOR UPDATE', [$activationId,$licenseId]);
        if (!$license || !$activation || (int)$license['revision_counter'] >= PHP_INT_MAX - 1) { throw new \RuntimeException('Invalid publication state.'); }
        $rows = $this->db->all('SELECT c.capability_key,COALESCE(f.enabled,0) AS enabled FROM product_capabilities c LEFT JOIN license_features f ON f.product_id=c.product_id AND f.capability_key=c.capability_key AND f.license_id=? WHERE c.product_id=? ORDER BY c.capability_key', [$licenseId,$license['product_id']]);
        $features = [];
        foreach ($rows as $row) { $features[$row['capability_key']] = (bool)$row['enabled']; }
        Capabilities::validate(array_keys(array_filter($features)), array_keys($features), $this->db->all('SELECT capability_key,required_key FROM capability_dependencies WHERE product_id=?', [$license['product_id']]));
        $limits = $this->db->one('SELECT max_installations,max_users,max_libraries,max_documents FROM license_limits WHERE license_id=?', [$licenseId]);
        if (!$limits) { throw new \RuntimeException('Missing license limits.'); }
        foreach ($limits as &$value) { $value = $value === null ? null : (int)$value; } unset($value);
        $revision = (int)$license['revision_counter'] + 1;
        $now = $this->clock->sql();
        $payload = [
            'schema_version'=>'1.0','product_id'=>$license['product_id'],
            'license_id'=>Uuid::text($licenseId),'activation_id'=>Uuid::text($activationId),
            'installation_id'=>Uuid::text($activation['installation_id']),
            'installation_public_key'=>Protocol::encode($activation['installation_public_key']),
            'fingerprint_version'=>$activation['fingerprint_version'],'fingerprint_hash'=>$activation['fingerprint_hash'],
            'license_type'=>$license['license_type'],
            'license_status'=>$license['commercial_status'] === 'issued' && $activation['state'] === 'active' ? 'active' : 'revoked',
            'license_revision'=>$revision,'issued_at'=>Protocol::utc($now),
            'expires_at'=>Protocol::utc($license['expires_at']),'grace_days'=>(int)$license['grace_days'],
            'maintenance_until'=>Protocol::utc($license['maintenance_until']),
            'entitled_release_until'=>Protocol::utc($license['entitled_release_until']),
            'features'=>(object)$features,'limits'=>$limits,
        ];
        $signed = $this->keys->sign($payload);
        $revisionId = Uuid::bytes(Uuid::create());
        $this->db->execute('INSERT INTO license_revisions(revision_id,license_id,activation_id,license_revision,kid,license_status,issued_at,payload_json,license_jws,jws_sha256) VALUES (?,?,?,?,?,?,?,?,?,?)', [$revisionId,$licenseId,$activationId,$revision,$signed['kid'],$payload['license_status'],$now,Protocol::json($payload),$signed['jws'],hash('sha256',$signed['jws'])]);
        $this->db->execute('UPDATE licenses SET revision_counter=? WHERE license_id=?', [$revision,$licenseId]);
        $this->db->execute('UPDATE activations SET current_revision_id=? WHERE activation_id=?', [$revisionId,$activationId]);
        return ['revision'=>$revision,'revision_id'=>Uuid::text($revisionId),'jws'=>$signed['jws']];
    }

    public function commercialChange(string $licenseId, bool $revoke): void
    {
        foreach ($this->db->all("SELECT activation_id FROM activations WHERE license_id=? AND state='active' ORDER BY activation_id FOR UPDATE", [$licenseId]) as $activation) {
            if ($revoke) { $this->db->execute("UPDATE activations SET state='revoked',ended_at=? WHERE activation_id=?", [$this->clock->sql(),$activation['activation_id']]); }
            $this->publish($licenseId, $activation['activation_id']);
        }
        // Terminal activations keep their terminal revision forever. A renewal
        // for a later activation must never reauthorize a departed installation.
    }
}
