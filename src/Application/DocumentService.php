<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Config;
use Aibid\Domain\{Input, Problem, Protocol, Uuid};
use Aibid\Infrastructure\{Audit, CertificatePdf, Clock, Crypto, Database};

final class DocumentService
{
    public function __construct(private Database $db, private Config $config, private Clock $clock, private Crypto $crypto, private Audit $audit, private Operations $operations, private Access $access, private LicenseMaterials $materials, private CertificatePdf $pdf) {}

    public function generate(Actor $actor, string $operationId, string $licenseId, array $input, string $password = '', string $code = ''): array
    {
        $type = Input::choice($input,'document_type',['licensing','delivery']);
        $method = $type === 'delivery' ? Input::choice($input,'activation_method',['online','offline']) : 'none';
        $revisionId = $method === 'offline' ? Input::text($input,'revision_id',36) : '';
        if ($type === 'delivery') { $this->materials->authorize($actor,'documents.delivery',$password,$code); }
        return $this->operations->run($actor,['superadmin','operator'],$operationId,'document.generate',[$licenseId,$type,$method,$revisionId],function () use ($actor,$licenseId,$type,$method,$revisionId): array {
            $current = $this->access->actor($actor->id);
            if ($type === 'delivery') { $current->requirePermission('documents.delivery'); }
            $license = $this->db->one('SELECT l.*,c.display_name AS holder,c.legal_name,c.tax_id,p.display_name AS product FROM licenses l JOIN customers c ON c.id=l.customer_id JOIN products p ON p.product_id=l.product_id WHERE license_id=? FOR UPDATE',[Uuid::bytes($licenseId)]) ?? throw new Problem('No se encontró la licencia.',404);
            $revision = 1 + (int)$this->db->execute('SELECT COALESCE(MAX(revision),0) FROM license_documents WHERE license_id=? AND document_type=?',[$license['license_id'],$type])->fetchColumn();
            $id = Uuid::create(); $verification = Crypto::token();
            $folio = 'ADX-'.strtoupper(str_replace('-','',$id));
            $public = ['folio'=>$folio,'type'=>$type,'revision'=>$revision,'generated_at'=>$this->clock->sql(),'product'=>$license['product'],'license_type'=>$license['license_type']];
            $limits = $this->db->one('SELECT max_installations,max_users FROM license_limits WHERE license_id=?',[$license['license_id']]);
            $features = array_column($this->db->all('SELECT c.display_name FROM license_features f JOIN product_capabilities c ON c.product_id=f.product_id AND c.capability_key=f.capability_key WHERE f.license_id=? AND f.enabled=1 ORDER BY c.capability_key',[$license['license_id']]),'display_name');
            $snapshot = $public + ['holder'=>$license['holder'],'legal_name'=>$license['legal_name'],'tax_id'=>$license['tax_id'],'license_id'=>$licenseId,'issued_at'=>$license['created_at'],'expires_at'=>$license['expires_at'],'grace_days'=>(int)$license['grace_days'],'limits'=>$limits,'features'=>$features,'reference'=>$license['commercial_reference'],'administrative_version'=>(int)$license['administrative_version'],'activation_method'=>$method,'verification_url'=>rtrim($this->config->get('APP_URL'),'/').'/verify/'.$verification];
            $credentialId = null;
            $offlineRevision = null;
            if ($method === 'online') {
                $current->requirePermission('credentials.reveal');
                $credential = $this->materials->currentCredential($licenseId);
                $credentialId = $credential['id']; $snapshot['activation_code'] = $credential['secret'];
            } elseif ($method === 'offline') {
                $current->requirePermission('offline.download');
                $offline = $this->db->one('SELECT r.*,BIN_TO_UUID(a.installation_id) AS installation FROM license_revisions r JOIN activations a ON a.activation_id=r.activation_id WHERE r.license_id=? AND r.revision_id=?',[$license['license_id'],Uuid::bytes($revisionId)]) ?? throw new Problem('Selecciona un archivo firmado de esta licencia.',404);
                if (!hash_equals($offline['jws_sha256'],hash('sha256',$offline['license_jws']))) { throw new \RuntimeException('Artifact checksum mismatch.'); }
                $offlineRevision = $offline['revision_id'];
                $snapshot += ['offline_filename'=>'license-'.$licenseId.'-r'.$offline['license_revision'].'.lic','offline_sha256'=>$offline['jws_sha256'],'installation'=>$offline['installation'],'license_revision'=>(int)$offline['license_revision'],'artifact_status'=>$offline['license_status']];
            }
            $bytes = $this->pdf->render($snapshot);
            if (strlen($bytes)>8*1024*1024) { throw new \RuntimeException('PDF size limit exceeded.'); }
            $this->db->execute('INSERT INTO license_documents(id,license_id,document_type,revision,folio,verification_digest,public_json,snapshot_encrypted,pdf_encrypted,pdf_sha256,credential_id,offline_revision_id,generated_by,generated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[Uuid::bytes($id),$license['license_id'],$type,$revision,$folio,hash('sha256',$verification),Protocol::json($public),$this->crypto->seal(Protocol::json($snapshot),'document-snapshot:'.$id,'document'),$this->crypto->seal($bytes,'document-pdf:'.$id,'document'),hash('sha256',$bytes),$credentialId,$offlineRevision,Uuid::bytes($actor->id),$this->clock->sql()]);
            $this->audit->append($actor->id,'document.generated','license',$licenseId,['document_id'=>$id,'folio'=>$folio,'type'=>$type,'revision'=>$revision,'method'=>$method,'sha256'=>hash('sha256',$bytes),'result'=>'allowed']);
            return ['id'=>$licenseId,'document_id'=>$id];
        });
    }

    public function download(Actor $actor, string $id, string $password = '', string $code = ''): array
    {
        $meta = $this->db->one('SELECT document_type FROM license_documents WHERE id=?',[Uuid::bytes($id)]) ?? throw new Problem('No se encontró la constancia.',404);
        if ($meta['document_type']==='delivery') { $this->materials->authorize($actor,'documents.delivery',$password,$code); }
        return $this->db->transaction(function () use ($actor,$id): array {
            $current = $this->access->actor($actor->id);
            $current->requireRole(['superadmin','operator']);
            $row = $this->db->one('SELECT * FROM license_documents WHERE id=?',[Uuid::bytes($id)]) ?? throw new Problem('No se encontró la constancia.',404);
            if ($row['document_type']==='delivery') {
                $current->requirePermission('documents.delivery');
                if ($row['credential_id'] !== null) { $current->requirePermission('credentials.reveal'); }
                if ($row['offline_revision_id'] !== null) { $current->requirePermission('offline.download'); }
            }
            $bytes = $this->crypto->open($row['pdf_encrypted'],'document-pdf:'.$id,'document');
            if (!hash_equals($row['pdf_sha256'],hash('sha256',$bytes))) { throw new \RuntimeException('PDF checksum mismatch.'); }
            $this->audit->append($actor->id,'document.downloaded','license',Uuid::text($row['license_id']),['document_id'=>$id,'type'=>$row['document_type'],'sha256'=>$row['pdf_sha256'],'result'=>'allowed']);
            return ['bytes'=>$bytes,'filename'=>$row['folio'].'-r'.$row['revision'].'.pdf'];
        });
    }

    public function verify(string $token): ?array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/',$token)) { return null; }
        $row = $this->db->one('SELECT d.public_json,d.pdf_sha256,l.commercial_status,l.license_type,l.expires_at,l.grace_days,d.credential_id,c.state AS credential_state,d.offline_revision_id,a.state AS activation_state,a.current_revision_id FROM license_documents d JOIN licenses l ON l.license_id=d.license_id LEFT JOIN license_credentials c ON c.credential_id=d.credential_id LEFT JOIN license_revisions r ON r.revision_id=d.offline_revision_id LEFT JOIN activations a ON a.activation_id=r.activation_id WHERE verification_digest=?',[hash('sha256',$token)]);
        if (!$row) { return null; }
        $public = json_decode($row['public_json'],true,16,JSON_THROW_ON_ERROR);
        $state = $row['commercial_status']==='revoked' ? 'revoked' : 'issued';
        if ($state==='issued' && $row['expires_at']!==null && $row['expires_at']<=$this->clock->sql()) {
            $end = (new \DateTimeImmutable($row['expires_at'],new \DateTimeZone('UTC')))->modify('+'.$row['grace_days'].' days');
            $state = $this->clock->now()<$end ? 'grace' : 'expired';
        }
        return $public + ['pdf_sha256'=>$row['pdf_sha256'],'current_state'=>$state,'delivery_current'=>($row['credential_id']===null || $row['credential_state']==='active') && ($row['offline_revision_id']===null || ($row['activation_state']==='active' && $row['offline_revision_id']===$row['current_revision_id']))];
    }
}
