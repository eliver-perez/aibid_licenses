<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Domain\{ApiProblem, Input, OfflineRequest, Problem, Protocol, Uuid};
use Aibid\Infrastructure\{Audit, Clock, Crypto, Database};

final class OfflineService
{
    public function __construct(private Database $db, private Operations $operations, private Audit $audit, private Clock $clock, private Crypto $crypto, private RevisionPublisher $publisher, private LicenseService $licenses) {}

    public function import(Actor $actor, string $operationId, string $envelope): array
    {
        $actor->requireRole(['superadmin','operator']);
        $verified = $this->verify($envelope);
        $payload = $verified['payload'];
        $digest = $this->digest($verified);
        return $this->operations->run($actor, ['superadmin','operator'], $operationId, 'offline.import', [$digest], function () use ($actor, $envelope, $verified, $payload, $digest): array {
            $requestId = Uuid::bytes($payload['request_id']);
            // No proof_message plaintext: the signed segment may contain a credential.
            $this->db->execute("INSERT INTO license_requests(product_id,request_id,channel,action,input_digest,installation_id,activation_id,public_key,proof_message,created_at) VALUES (?,?,'offline',?,?,?,?,?,'',?) ON DUPLICATE KEY UPDATE request_id=request_id", [$payload['product_id'],$requestId,$payload['action'],$digest,Uuid::bytes($payload['installation_id']),isset($payload['activation_id']) ? Uuid::bytes($payload['activation_id']) : null,$verified['public'],$this->clock->sql()]);
            $record = $this->db->one('SELECT * FROM license_requests WHERE product_id=? AND request_id=? FOR UPDATE', [$payload['product_id'],$requestId]);
            if ($record['channel'] !== 'offline' || (int)$record['digest_key_version'] !== 1 || !hash_equals($record['input_digest'], $digest)) { throw new Problem('El identificador ya pertenece a otra solicitud o canal.',409); }
            $existing = $this->db->one('SELECT id FROM offline_requests WHERE product_id=? AND request_id=?', [$payload['product_id'],$requestId]);
            if ($existing) { return ['id'=>Uuid::text($existing['id'])]; }
            $this->lockProduct($payload['product_id']);
            if ($payload['action'] !== 'activate') {
                $license = $this->lockLicense($payload['license_id'], $payload['product_id']);
                $this->boundActivation($payload, $license);
            }
            $id = Uuid::create();
            $encrypted = $this->crypto->encryptEvidence($envelope, 'offline:v1:' . $id);
            $this->db->execute('INSERT INTO offline_requests(id,product_id,request_id,evidence_encrypted,projection_json,imported_by,imported_at) VALUES (?,?,?,?,?,?,?)', [Uuid::bytes($id),$payload['product_id'],$requestId,$encrypted,Protocol::json($payload),Uuid::bytes($actor->id),$this->clock->sql()]);
            $this->audit->append($actor->id, 'offline.imported', 'offline_request', $id, ['product_id'=>$payload['product_id'],'request_id'=>$payload['request_id'],'action'=>$payload['action']]);
            return ['id'=>$id];
        });
    }

    public function decide(Actor $actor, string $operationId, string $id, string $decision, array $input): array
    {
        if (!in_array($decision, ['approved','rejected'], true)) { throw new Problem('Decisión no válida.'); }
        $reason = Input::text($input, 'reason', 500);
        $licenseId = $decision === 'approved' ? Input::text($input, 'license_id',36) : '';
        $version = $decision === 'approved' ? (int)($input['version'] ?? 0) : 0;
        $mode = $decision === 'approved' ? Input::choice($input, 'renewal_mode', ['current','extend']) : 'current';
        $until = $mode === 'extend' ? Input::date(Input::text($input, 'until')) : null;
        $reference = $mode === 'extend' ? Input::text($input, 'reference',190,true) : '';
        $outgoing = $decision === 'approved' ? Input::text($input, 'force_activation_id',36,false) : '';
        $accepted = ($input['accept_offline_limit'] ?? '') === '1';
        if ($outgoing !== '' && !$accepted) { throw new Problem('Confirma el límite de revocación offline.'); }
        $roles = $outgoing === '' ? ['superadmin','operator'] : ['superadmin'];
        return $this->operations->run($actor, $roles, $operationId, 'offline.' . $decision, [$id,$reason,$licenseId,$version,$mode,$until?->format(DATE_ATOM),$reference,$outgoing,$accepted], function () use ($actor,$id,$decision,$reason,$licenseId,$version,$mode,$until,$reference,$outgoing): array {
            $offline = $this->db->one('SELECT * FROM offline_requests WHERE id=?', [Uuid::bytes($id)]) ?? throw new Problem('No se encontró la solicitud.',404);
            $request = $this->db->one('SELECT * FROM license_requests WHERE product_id=? AND request_id=? FOR UPDATE', [$offline['product_id'],$offline['request_id']]);
            if ($request['completed_at'] !== null) { throw new Problem('La solicitud ya fue decidida. Consulta su resultado.',409); }
            if ($decision === 'rejected') {
                $result = $this->complete($actor, $offline, $decision, $reason, null, null);
                $this->audit->append($actor->id, 'offline.rejected', 'offline_request', $id, [], $reason);
                return $result;
            }
            $plaintext = $this->crypto->decryptEvidence($offline['evidence_encrypted'], 'offline:v1:' . Uuid::text($offline['id']));
            try { $verified = $this->verify($plaintext); } finally { sodium_memzero($plaintext); }
            if ((int)$offline['encryption_version'] !== 1 || $request['channel'] !== 'offline' || (int)$request['digest_key_version'] !== 1 || !hash_equals($request['input_digest'], $this->digest($verified))) { throw new \RuntimeException('Offline evidence does not match reservation.'); }
            $payload = $verified['payload'];
            $this->lockProduct($payload['product_id']);
            if ($payload['action'] !== 'activate' && Uuid::bytes($licenseId) !== Uuid::bytes($payload['license_id'])) { throw new Problem('La licencia debe coincidir con la solicitud firmada.'); }
            $license = $this->lockLicense($licenseId, $payload['product_id']);
            if ((int)$license['row_version'] !== $version) { throw new Problem('Los derechos cambiaron. Revisa nuevamente la licencia.',409); }
            if ($mode === 'extend' && $payload['action'] !== 'renew') { throw new Problem('La ampliación comercial requiere una solicitud de renovación.'); }
            if ($outgoing !== '' && $payload['action'] !== 'activate') { throw new Problem('La transferencia requiere una activación de destino.'); }
            if ($payload['action'] === 'activate') {
                $active = $this->db->one("SELECT * FROM activations WHERE license_id=? AND state='active' ORDER BY activation_id FOR UPDATE", [$license['license_id']]);
                if ($outgoing !== '') {
                    if (!$active || $active['activation_id'] !== Uuid::bytes($outgoing)) { throw new Problem('La instalación de origen cambió. Revisa la transferencia.',409); }
                    if ($active['installation_id'] === Uuid::bytes($payload['installation_id'])) { throw new Problem('El destino debe ser otra instalación.'); }
                    $this->retire($license['license_id'], $active['activation_id'], true);
                } elseif ($active) { throw new Problem('La licencia ya tiene una instalación activa. Desactívala o solicita transferencia.',409); }
                $activationId = Uuid::bytes(Uuid::create());
                $this->db->execute("INSERT INTO activations(activation_id,license_id,product_id,installation_id,installation_public_key,fingerprint_version,fingerprint_hash,state,activated_at) VALUES (?,?,?,?,?,?,?,'active',?)", [$activationId,$license['license_id'],$payload['product_id'],Uuid::bytes($payload['installation_id']),$verified['public'],$payload['fingerprint_version'],$payload['fingerprint_hash'],$this->clock->sql()]);
            } else {
                $activationId = $this->boundActivation($payload, $license)['activation_id'];
                if ($payload['action'] === 'deactivate') { $this->db->execute("UPDATE activations SET state='deactivated',ended_at=? WHERE activation_id=?", [$this->clock->sql(),$activationId]); }
                if ($mode === 'extend') {
                    $this->licenses->renewTerms($actor, $license, $until, $reference, $reason);
                    $this->db->execute('UPDATE licenses SET row_version=row_version+1,updated_at=? WHERE license_id=?', [$this->clock->sql(),$license['license_id']]);
                }
            }
            $revision = $this->publisher->publish($license['license_id'], $activationId);
            if ($outgoing !== '') { $this->transferRecord($actor, $license['license_id'], Uuid::bytes($outgoing), $activationId, $reason); }
            $result = $this->complete($actor, $offline, $decision, $reason, $license['license_id'], Uuid::bytes($revision['revision_id']));
            // Audit head is always the last lock; every earlier effect still rolls back on audit failure.
            if ($mode === 'extend') { $this->licenses->recordOfflineRenewal($actor, $licenseId, $reason, $reference); }
            $this->audit->append($actor->id, $outgoing === '' ? 'offline.approved' : 'offline.transferred', 'offline_request', $id, ['license_id'=>Uuid::text($license['license_id']),'activation_id'=>Uuid::text($activationId),'revision'=>$revision['revision'],'action'=>$payload['action'],'forced'=>$outgoing !== ''], $reason);
            return $result;
        });
    }

    /** HTTP caller requires fresh password + TOTP; role and current state are rechecked here. */
    public function release(Actor $actor, string $operationId, string $licenseId, array $input): array
    {
        $reason = Input::text($input,'reason',500);
        $activationId = Input::text($input,'activation_id',36);
        $version = (int)($input['version'] ?? 0);
        if (($input['accept_offline_limit'] ?? '') !== '1') { throw new Problem('Confirma el límite de revocación offline.'); }
        return $this->operations->run($actor, ['superadmin'], $operationId, 'license.force_release', [$licenseId,$activationId,$version,$reason,true], function () use ($actor,$licenseId,$activationId,$version,$reason): array {
            $candidate = $this->db->one('SELECT product_id FROM licenses WHERE license_id=?', [Uuid::bytes($licenseId)]) ?? throw new Problem('No se encontró la licencia.',404);
            $this->lockProduct($candidate['product_id']);
            $license = $this->lockLicense($licenseId,$candidate['product_id']);
            if ((int)$license['row_version'] !== $version) { throw new Problem('Los derechos cambiaron. Recarga la licencia.',409); }
            $activation = $this->db->one('SELECT state FROM activations WHERE license_id=? AND activation_id=? FOR UPDATE', [$license['license_id'],Uuid::bytes($activationId)]);
            if (!$activation || $activation['state'] !== 'active') { throw new Problem('La instalación ya no está activa.',409); }
            $revision = $this->retire($license['license_id'],Uuid::bytes($activationId),true);
            $this->transferRecord($actor,$license['license_id'],Uuid::bytes($activationId),null,$reason);
            $this->audit->append($actor->id,'license.force_release','license',$licenseId,['activation_id'=>$activationId,'revision'=>$revision['revision'],'offline_limit_accepted'=>true],$reason);
            return ['id'=>$licenseId];
        });
    }

    private function lockProduct(string $id): void
    {
        if (!$this->db->one('SELECT product_id FROM products WHERE product_id=? FOR SHARE',[$id])) { throw new Problem('El producto de la solicitud no está registrado.'); }
    }

    private function lockLicense(string $id, string $product): array
    {
        $license = $this->db->one('SELECT * FROM licenses WHERE license_id=? AND product_id=? FOR UPDATE',[Uuid::bytes($id),$product]);
        if (!$license || $license['commercial_status'] !== 'issued') { throw new Problem('La licencia no está disponible para este producto.'); }
        return $license;
    }

    private function boundActivation(array $payload, array $license): array
    {
        $activation = $this->db->one('SELECT * FROM activations WHERE activation_id=? AND license_id=? FOR UPDATE',[Uuid::bytes($payload['activation_id']),$license['license_id']]);
        if (!$activation || $activation['state'] !== 'active' || $activation['product_id'] !== $payload['product_id'] || $activation['installation_id'] !== Uuid::bytes($payload['installation_id']) || !hash_equals($activation['installation_public_key'],Protocol::decode($payload['installation_public_key'],32)) || $activation['fingerprint_version'] !== $payload['fingerprint_version'] || $activation['fingerprint_hash'] !== $payload['fingerprint_hash']) { throw new Problem('La identidad firmada no coincide con una instalación activa de esta licencia.',409); }
        return $activation;
    }

    private function retire(string $licenseId, string $activationId, bool $forced): array
    {
        $this->db->execute('UPDATE activations SET state=?,ended_at=? WHERE activation_id=?',[$forced ? 'revoked' : 'deactivated',$this->clock->sql(),$activationId]);
        return $this->publisher->publish($licenseId,$activationId);
    }

    private function transferRecord(Actor $actor, string $licenseId, string $outgoing, ?string $incoming, string $reason): void
    {
        $this->db->execute('INSERT INTO license_transfers(id,license_id,outgoing_activation_id,incoming_activation_id,admin_id,reason,offline_limit_accepted,created_at) VALUES (?,?,?,?,?,?,1,?)',[Uuid::bytes(Uuid::create()),$licenseId,$outgoing,$incoming,Uuid::bytes($actor->id),$reason,$this->clock->sql()]);
    }

    private function complete(Actor $actor, array $offline, string $decision, string $reason, ?string $licenseId, ?string $revisionId): array
    {
        $result = ['id'=>Uuid::text($offline['id']),'decision'=>$decision,'license_id'=>$licenseId === null ? null : Uuid::text($licenseId),'revision_id'=>$revisionId === null ? null : Uuid::text($revisionId)];
        $this->db->execute('INSERT INTO offline_decisions(offline_id,decision,license_id,revision_id,admin_id,reason,decided_at) VALUES (?,?,?,?,?,?,?)',[$offline['id'],$decision,$licenseId,$revisionId,Uuid::bytes($actor->id),$reason,$this->clock->sql()]);
        $this->db->execute('UPDATE license_requests SET response_status=200,response_body=?,completed_at=? WHERE product_id=? AND request_id=?',[Protocol::json($result),$this->clock->sql(),$offline['product_id'],$offline['request_id']]);
        return $result;
    }

    private function verify(string $envelope): array
    {
        try { return OfflineRequest::verify($envelope); }
        catch (ApiProblem $error) { throw new Problem($error->getMessage(), $error->status); }
    }
    private function digest(array $verified): string
    {
        return $this->crypto->digest('OPERATION_KEY','protocol-v1:offline:' . $verified['payload']['action'] . "\n" . $verified['digest_material']);
    }
}
