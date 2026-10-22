<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Domain\{Problem, Uuid};
use Aibid\Infrastructure\{Audit, Crypto, Database};

final class LicenseMaterials
{
    public function __construct(private Database $db, private Crypto $crypto, private Audit $audit, private Access $access, private AuthService $auth) {}

    public function authorize(Actor $actor, string $permission, string $password, string $code): void
    {
        try { $this->access->require($actor, $permission); }
        catch (Problem $error) {
            $this->db->transaction(fn()=> $this->audit->append($actor->id,'material.access_denied','admin',$actor->id,['permission'=>$permission,'result'=>'denied']));
            throw $error;
        }
        $this->auth->reauthenticate($actor, $password, $code);
    }

    /** Internal transaction primitive; callers must hold their authorization lock. */
    public function currentCredential(string $licenseId): array
    {
        if (!$this->db->pdo->inTransaction()) { throw new \LogicException('Credential retrieval requires a transaction.'); }
        $row = $this->db->one("SELECT * FROM license_credentials WHERE license_id=? AND state='active'", [Uuid::bytes($licenseId)]);
        if (!$row || $row['credential_encrypted'] === null || $row['code_format'] !== '3-4-4-4') { throw new Problem('Esta credencial anterior no es recuperable. Usa el reemplazo autorizado para emitir un código nuevo.',409); }
        $secret = $this->crypto->open($row['credential_encrypted'], 'credential:'.Uuid::text($row['credential_id']), 'activation-code');
        if (!hash_equals($row['key_digest'],$this->crypto->digest('CREDENTIAL_KEY',$secret))) { throw new \RuntimeException('Credential digest mismatch.'); }
        return ['id'=>$row['credential_id'],'secret'=>$secret];
    }

    public function reveal(Actor $actor, string $licenseId, string $password, string $code): string
    {
        $this->authorize($actor,'credentials.reveal',$password,$code);
        return $this->db->transaction(function () use ($actor,$licenseId): string {
            $this->access->require($actor,'credentials.reveal');
            $this->db->one('SELECT license_id FROM licenses WHERE license_id=? FOR SHARE',[Uuid::bytes($licenseId)]) ?? throw new Problem('No se encontró la licencia.',404);
            $credential = $this->currentCredential($licenseId);
            $this->audit->append($actor->id,'credential.revealed','license',$licenseId,['credential_id'=>Uuid::text($credential['id']),'result'=>'allowed']);
            return $credential['secret'];
        });
    }

    public function revision(Actor $actor, string $licenseId, string $revisionId, string $password, string $code): array
    {
        $this->authorize($actor,'offline.download',$password,$code);
        return $this->db->transaction(function () use ($actor,$licenseId,$revisionId): array {
            $this->access->require($actor,'offline.download');
            $row = $this->db->one('SELECT license_jws,license_revision,jws_sha256 FROM license_revisions WHERE license_id=? AND revision_id=?',[Uuid::bytes($licenseId),Uuid::bytes($revisionId)]) ?? throw new Problem('No se encontró el archivo firmado.',404);
            if (!hash_equals($row['jws_sha256'],hash('sha256',$row['license_jws']))) { throw new \RuntimeException('Artifact checksum mismatch.'); }
            $this->audit->append($actor->id,'offline.downloaded','license',$licenseId,['revision_id'=>$revisionId,'sha256'=>$row['jws_sha256'],'result'=>'allowed']);
            return $row;
        });
    }
}
