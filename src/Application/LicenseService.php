<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Domain\{Capabilities, Input, Problem, Uuid};
use Aibid\Infrastructure\{Audit, Clock, Crypto, Database};

final class LicenseService
{
    public function __construct(private Database $db, private Operations $operations, private Audit $audit, private Clock $clock, private Crypto $crypto, private RevisionPublisher $publisher) {}

    public function issue(Actor $actor, string $operationId, array $input): array
    {
        $customerId = Input::text($input, 'customer_id');
        $productId = Input::text($input, 'product_id', 64);
        $type = Input::choice($input, 'license_type', ['perpetual', 'subscription']);
        $date = Input::date(Input::text($input, $type === 'perpetual' ? 'entitled_release_until' : 'expires_at'));
        $features = $this->featureInput($input);
        $reason = Input::text($input, 'reason', 500);
        $reference = Input::text($input, 'reference', 190, false);
        if ($type === 'subscription' && $date <= $this->clock->now()) { throw new Problem('El vencimiento debe ser posterior al momento actual.'); }
        $data = [$customerId, $productId, $type, $date->format(DATE_ATOM), $features, $reason, $reference];
        return $this->operations->run($actor, ['superadmin', 'operator'], $operationId, 'license.issue', $data, function () use ($actor, $customerId, $productId, $type, $date, $features, $reason, $reference): array {
            $customer = $this->db->one('SELECT * FROM customers WHERE id=? FOR SHARE', [Uuid::bytes($customerId)]);
            $product = $this->db->one('SELECT * FROM products WHERE product_id=? FOR SHARE', [$productId]);
            if (!$customer || $customer['state'] !== 'active' || !$product || $product['state'] !== 'active') { throw new Problem('Selecciona un cliente y un producto activos.'); }
            $catalog = $this->validateFeatures($productId, $features);
            $id = Uuid::create();
            $bytes = Uuid::bytes($id);
            $instant = $date->format('Y-m-d H:i:s.u');
            $this->db->execute('INSERT INTO licenses(license_id,customer_id,product_id,license_type,expires_at,grace_days,maintenance_until,entitled_release_until,created_at,updated_at) VALUES (?,?,?,?,?,?,NULL,?,?,?)', [$bytes, Uuid::bytes($customerId), $productId, $type, $type === 'subscription' ? $instant : null, $type === 'subscription' ? 15 : 0, $instant, $this->clock->sql(), $this->clock->sql()]);
            $this->db->execute('INSERT INTO license_limits(license_id,max_installations,max_users,max_libraries,max_documents) VALUES (?,1,NULL,NULL,NULL)', [$bytes]);
            $this->writeFeatures($bytes, $productId, $catalog, $features);
            if ($type === 'subscription') { $this->period($actor, $bytes, null, $instant, $reference, $reason); }
            $secret = $this->credential($bytes);
            $this->record($actor, $id, 'license.issued', $reason, $reference);
            return ['id' => $id, 'secret' => $secret];
        });
    }

    public function change(Actor $actor, string $operationId, string $id, string $action, array $input): array
    {
        if (!in_array($action, ['features', 'renew', 'maintenance', 'reissue', 'revoke'], true)) { throw new Problem('La operación no existe.', 404); }
        $reason = Input::text($input, 'reason', 500);
        $reference = Input::text($input, 'reference', 190, $action === 'maintenance');
        $features = $action === 'features' ? $this->featureInput($input) : [];
        $date = in_array($action, ['renew', 'maintenance'], true) ? Input::date(Input::text($input, 'until')) : null;
        $version = (int) ($input['version'] ?? 0);
        $roles = $action === 'revoke' ? ['superadmin'] : ['superadmin', 'operator'];
        return $this->operations->run($actor, $roles, $operationId, 'license.' . $action, [$id, $reason, $reference, $features, $date?->format(DATE_ATOM), $version], function () use ($actor, $id, $action, $reason, $reference, $features, $date, $version): array {
            $bytes = Uuid::bytes($id);
            // Product lock precedes license locks, as in catalog dependency changes.
            $productId = $this->db->execute('SELECT product_id FROM licenses WHERE license_id=?', [$bytes])->fetchColumn();
            if ($productId === false) { throw new Problem('No se encontró la licencia.', 404); }
            $this->db->one('SELECT product_id FROM products WHERE product_id=? FOR SHARE', [$productId]);
            $license = $this->db->one('SELECT * FROM licenses WHERE license_id=? FOR UPDATE', [$bytes]);
            if ((int) $license['row_version'] !== $version) { throw new Problem('Otro operador cambió la licencia. Recarga su detalle.', 409); }
            if ($license['commercial_status'] === 'revoked') { throw new Problem('La licencia está revocada.'); }
            if ((int)$license['revision_counter'] !== 0 && !$this->db->one('SELECT revision_id FROM license_revisions WHERE license_id=? LIMIT 1', [$bytes])) { throw new Problem('El historial firmado está incompleto. Requiere recuperación administrativa.', 409); }
            $result = ['id' => $id];
            $rightsChanged = $action !== 'reissue';
            if ($action === 'features') {
                $catalog = $this->validateFeatures($productId, $features);
                $previous = array_column($this->db->all('SELECT capability_key FROM license_features WHERE license_id=? AND enabled=1 ORDER BY capability_key', [$bytes]), 'capability_key');
                $rightsChanged = $previous !== $features;
                $this->writeFeatures($bytes, $productId, $catalog, $features);
            } elseif ($action === 'renew') {
                if ($license['license_type'] !== 'subscription') { throw new Problem('Solo las suscripciones tienen renovación de vigencia.'); }
                $instant = $date->format('Y-m-d H:i:s.u');
                if ($instant <= $license['expires_at'] || $date <= $this->clock->now()) { throw new Problem('El nuevo vencimiento debe extender la vigencia anterior y ser futuro.'); }
                $this->db->execute('UPDATE licenses SET expires_at=?,entitled_release_until=? WHERE license_id=?', [$instant, $instant, $bytes]);
                $this->period($actor, $bytes, $license['expires_at'], $instant, $reference, $reason);
            } elseif ($action === 'maintenance') {
                if ($license['license_type'] !== 'perpetual') { throw new Problem('El mantenimiento separado corresponde a licencias perpetuas.'); }
                $instant = $date->format('Y-m-d H:i:s.u');
                if ($instant <= $license['entitled_release_until'] || ($license['maintenance_until'] !== null && $instant <= $license['maintenance_until'])) { throw new Problem('La compra debe extender la fecha de versiones autorizadas.'); }
                $this->db->execute('INSERT INTO maintenance_purchases(id,license_id,previous_until,new_until,purchased_at,commercial_reference,admin_id,reason) VALUES (?,?,?,?,?,?,?,?)', [Uuid::bytes(Uuid::create()), $bytes, $license['maintenance_until'], $instant, $this->clock->sql(), $reference, Uuid::bytes($actor->id), $reason]);
                $this->db->execute('UPDATE licenses SET maintenance_until=?,entitled_release_until=? WHERE license_id=?', [$instant, $instant, $bytes]);
            } elseif ($action === 'reissue') {
                $this->db->execute("UPDATE license_credentials SET state='retired',retired_at=? WHERE license_id=? AND state='active'", [$this->clock->sql(), $bytes]);
                $result['secret'] = $this->credential($bytes);
            } elseif ($action === 'revoke') {
                $this->db->execute("UPDATE licenses SET commercial_status='revoked' WHERE license_id=?", [$bytes]);
                $this->db->execute("UPDATE license_credentials SET state='retired',retired_at=? WHERE license_id=? AND state='active'", [$this->clock->sql(), $bytes]);
            }
            $this->db->execute('UPDATE licenses SET row_version=row_version+1,updated_at=? WHERE license_id=?', [$this->clock->sql(), $bytes]);
            if ($rightsChanged) { $this->publisher->commercialChange($bytes, $action === 'revoke'); }
            $this->record($actor, $id, 'license.' . $action, $reason, $reference);
            return $result;
        });
    }

    private function featureInput(array $input): array
    {
        $features = $input['features'] ?? [];
        if (!is_array($features) || count($features) > 100) { throw new Problem('La selección de módulos no es válida.'); }
        foreach ($features as $feature) { if (!is_string($feature)) { throw new Problem('La selección de módulos no es válida.'); } }
        $features = array_values(array_unique($features));
        sort($features);
        return $features;
    }

    private function validateFeatures(string $productId, array $features): array
    {
        $catalog = array_column($this->db->all('SELECT capability_key FROM product_capabilities WHERE product_id=? ORDER BY capability_key', [$productId]), 'capability_key');
        Capabilities::validate($features, $catalog, $this->db->all('SELECT capability_key,required_key FROM capability_dependencies WHERE product_id=?', [$productId]));
        return $catalog;
    }

    private function writeFeatures(string $licenseId, string $productId, array $catalog, array $enabled): void
    {
        foreach ($catalog as $feature) {
            $value = in_array($feature, $enabled, true) ? 1 : 0;
            $this->db->execute('INSERT INTO license_features(license_id,product_id,capability_key,enabled) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE enabled=?', [$licenseId, $productId, $feature, $value, $value]);
        }
    }

    private function credential(string $licenseId): string
    {
        $key = 'AIBID-' . Crypto::token();
        $this->db->execute("INSERT INTO license_credentials(credential_id,license_id,key_digest,digest_key_version,state,created_at) VALUES (?,?,?,1,'active',?)", [Uuid::bytes(Uuid::create()), $licenseId, $this->crypto->digest('CREDENTIAL_KEY', $key), $this->clock->sql()]);
        return $key;
    }

    private function period(Actor $actor, string $licenseId, ?string $previous, string $until, string $reference, string $reason): void
    {
        $this->db->execute('INSERT INTO subscription_periods(id,license_id,previous_expires_at,new_expires_at,effective_at,commercial_reference,admin_id,reason) VALUES (?,?,?,?,?,?,?,?)', [Uuid::bytes(Uuid::create()), $licenseId, $previous, $until, $this->clock->sql(), $reference, Uuid::bytes($actor->id), $reason]);
    }

    private function record(Actor $actor, string $id, string $action, string $reason, string $reference): void
    {
        $license = $this->db->one('SELECT product_id,license_type,commercial_status,expires_at,grace_days,maintenance_until,entitled_release_until,row_version FROM licenses WHERE license_id=?', [Uuid::bytes($id)]);
        $license['features'] = $this->db->all('SELECT capability_key,enabled FROM license_features WHERE license_id=? ORDER BY capability_key', [Uuid::bytes($id)]);
        $license['commercial_reference'] = $reference;
        $this->db->execute('INSERT INTO license_changes(id,license_id,version,action,admin_id,snapshot_json,reason,created_at) VALUES (?,?,?,?,?,?,?,?)', [Uuid::bytes(Uuid::create()), Uuid::bytes($id), (int) $license['row_version'], $action, Uuid::bytes($actor->id), json_encode($license, JSON_THROW_ON_ERROR), $reason, $this->clock->sql()]);
        $this->audit->append($actor->id, $action, 'license', $id, ['version' => (int) $license['row_version']], $reason);
    }
}
