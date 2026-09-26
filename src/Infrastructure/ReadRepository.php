<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

use Aibid\Domain\{Problem, Uuid};

final class ReadRepository
{
    public function __construct(private Database $db, private Clock $clock) {}

    public function dashboard(): array
    {
        return [
            'licenses' => (int) $this->db->execute('SELECT COUNT(*) FROM licenses')->fetchColumn(),
            'customers' => (int) $this->db->execute("SELECT COUNT(*) FROM customers WHERE state='active'")->fetchColumn(),
            'perpetual' => (int) $this->db->execute("SELECT COUNT(*) FROM licenses WHERE license_type='perpetual' AND commercial_status='issued'")->fetchColumn(),
            'expiring' => (int) $this->db->execute("SELECT COUNT(*) FROM licenses WHERE commercial_status='issued' AND expires_at>=? AND expires_at<?", [$this->clock->sql(), $this->clock->now()->modify('+30 days')->format('Y-m-d H:i:s.u')])->fetchColumn(),
            'recent' => $this->db->all('SELECT BIN_TO_UUID(l.license_id) AS id_text,l.license_type,l.commercial_status,l.expires_at,l.created_at,c.display_name AS customer_name,p.display_name AS product_name FROM licenses l JOIN customers c ON c.id=l.customer_id JOIN products p ON p.product_id=l.product_id ORDER BY l.created_at DESC LIMIT 6'),
            'events' => $this->db->all('SELECT a.operation,a.occurred_at,u.display_name AS actor_name FROM audit_events a LEFT JOIN admin_users u ON u.id=a.actor_id ORDER BY a.sequence DESC LIMIT 5'),
        ];
    }

    public function page(string $kind, array $filters, int $page): array
    {
        $where = ['1=1'];
        $parameters = [];
        $query = trim((string) ($filters['q'] ?? ''));
        if ($kind === 'customers') {
            $from = 'customers c LEFT JOIN customer_contacts t ON t.customer_id=c.id';
            $select = 'c.*,BIN_TO_UUID(c.id) AS id_text,t.name AS contact_name,t.email,t.phone';
            $order = 'c.created_at DESC';
            if ($query !== '') { $where[] = '(c.display_name LIKE ? OR t.email LIKE ?)'; $parameters = ['%' . $query . '%', '%' . $query . '%']; }
            if (($filters['state'] ?? '') !== '') { $where[] = 'c.state=?'; $parameters[] = $filters['state']; }
        } elseif ($kind === 'licenses') {
            $from = 'licenses l JOIN customers c ON c.id=l.customer_id JOIN products p ON p.product_id=l.product_id';
            $select = 'l.*,BIN_TO_UUID(l.license_id) AS id_text,c.display_name AS customer_name,p.display_name AS product_name';
            $order = 'l.created_at DESC';
            if ($query !== '') { $where[] = '(c.display_name LIKE ? OR BIN_TO_UUID(l.license_id) LIKE ?)'; $parameters = ['%' . $query . '%', '%' . $query . '%']; }
            foreach (['product_id' => 'l.product_id', 'status' => 'l.commercial_status', 'type' => 'l.license_type'] as $key => $column) {
                if (($filters[$key] ?? '') !== '') { $where[] = $column . '=?'; $parameters[] = $filters[$key]; }
            }
            if (($filters['expiry'] ?? '') === 'soon') { $where[] = 'l.expires_at>=? AND l.expires_at<?'; $parameters[] = $this->clock->sql(); $parameters[] = $this->clock->now()->modify('+30 days')->format('Y-m-d H:i:s.u'); }
            if (($filters['expiry'] ?? '') === 'expired') { $where[] = 'l.expires_at<=?'; $parameters[] = $this->clock->sql(); }
        } elseif ($kind === 'offline') {
            $from = 'offline_requests o JOIN license_requests r ON r.product_id=o.product_id AND r.request_id=o.request_id LEFT JOIN offline_decisions d ON d.offline_id=o.id LEFT JOIN licenses l ON l.license_id=d.license_id LEFT JOIN customers c ON c.id=l.customer_id';
            $select = "BIN_TO_UUID(o.id) AS id_text,BIN_TO_UUID(o.request_id) AS request_text,o.product_id,o.imported_at,r.action,COALESCE(d.decision,'pending') AS status,c.display_name AS customer_name";
            $order = 'o.imported_at DESC,o.id';
            if ($query !== '') { $where[] = '(BIN_TO_UUID(o.request_id) LIKE ? OR c.display_name LIKE ?)'; $parameters = ['%'.$query.'%','%'.$query.'%']; }
            if (($filters['status'] ?? '') !== '') { $where[] = "COALESCE(d.decision,'pending')=?"; $parameters[] = $filters['status']; }
            if (($filters['product_id'] ?? '') !== '') { $where[] = 'o.product_id=?'; $parameters[] = $filters['product_id']; }
        } elseif ($kind === 'audit') {
            $from = 'audit_events a LEFT JOIN admin_users u ON u.id=a.actor_id';
            $select = 'a.*,u.display_name AS actor_name';
            $order = 'a.sequence DESC';
            if ($query !== '') { $where[] = '(a.operation LIKE ? OR a.target_id LIKE ?)'; $parameters = ['%' . $query . '%', '%' . $query . '%']; }
        } else { throw new \InvalidArgumentException('Unknown listing.'); }
        $clause = implode(' AND ', $where);
        $total = (int) $this->db->execute('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $clause, $parameters)->fetchColumn();
        $pages = max(1, (int) ceil($total / 20));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->all('SELECT ' . $select . ' FROM ' . $from . ' WHERE ' . $clause . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?', [...$parameters, 20, ($page - 1) * 20]);
        return compact('rows', 'total', 'page', 'pages');
    }

    public function customers(): array { return $this->db->all('SELECT BIN_TO_UUID(id) AS id_text,display_name FROM customers WHERE state=\'active\' ORDER BY display_name'); }

    public function customer(string $id): array
    {
        return $this->db->one('SELECT c.*,BIN_TO_UUID(c.id) AS id_text,t.name AS contact_name,t.email,t.phone FROM customers c LEFT JOIN customer_contacts t ON t.customer_id=c.id WHERE c.id=?', [Uuid::bytes($id)]) ?? throw new Problem('No se encontró el cliente.', 404);
    }

    public function products(): array { return $this->db->all('SELECT p.*,(SELECT COUNT(*) FROM licenses l WHERE l.product_id=p.product_id) AS license_count FROM products p ORDER BY p.display_name'); }

    public function product(string $id): array
    {
        return $this->db->one('SELECT * FROM products WHERE product_id=?', [$id]) ?? throw new Problem('No se encontró el producto.', 404);
    }

    public function capabilities(?string $productId = null): array
    {
        return $this->db->all('SELECT c.*,COALESCE((SELECT GROUP_CONCAT(d.required_key ORDER BY d.required_key SEPARATOR \',\') FROM capability_dependencies d WHERE d.product_id=c.product_id AND d.capability_key=c.capability_key),\'\') AS requires_keys FROM product_capabilities c' . ($productId === null ? '' : ' WHERE c.product_id=?') . ' ORDER BY c.product_id,c.capability_key', $productId === null ? [] : [$productId]);
    }

    public function license(string $id): array
    {
        $license = $this->db->one('SELECT l.*,BIN_TO_UUID(l.license_id) AS id_text,c.display_name AS customer_name,p.display_name AS product_name FROM licenses l JOIN customers c ON c.id=l.customer_id JOIN products p ON p.product_id=l.product_id WHERE l.license_id=?', [Uuid::bytes($id)]) ?? throw new Problem('No se encontró la licencia.', 404);
        $license['features'] = $this->db->all('SELECT f.capability_key,f.enabled,c.display_name FROM license_features f JOIN product_capabilities c ON c.product_id=f.product_id AND c.capability_key=f.capability_key WHERE f.license_id=? ORDER BY f.capability_key', [Uuid::bytes($id)]);
        $license['history'] = $this->db->all('SELECT c.*,u.display_name AS actor_name FROM license_changes c JOIN admin_users u ON u.id=c.admin_id WHERE c.license_id=? ORDER BY c.version DESC', [Uuid::bytes($id)]);
        $license['activations'] = $this->db->all('SELECT BIN_TO_UUID(activation_id) AS id_text,BIN_TO_UUID(installation_id) AS installation_text,state,activated_at,ended_at FROM activations WHERE license_id=? ORDER BY activated_at DESC LIMIT 50', [Uuid::bytes($id)]);
        $license['revisions'] = $this->db->all('SELECT BIN_TO_UUID(revision_id) AS id_text,BIN_TO_UUID(activation_id) AS activation_text,license_revision,kid,license_status,issued_at FROM license_revisions WHERE license_id=? ORDER BY license_revision DESC LIMIT 50', [Uuid::bytes($id)]);
        $license['offline'] = $this->db->all("SELECT BIN_TO_UUID(o.id) AS id_text,r.action,COALESCE(d.decision,'pending') AS status,o.imported_at FROM offline_requests o JOIN license_requests r ON r.product_id=o.product_id AND r.request_id=o.request_id LEFT JOIN offline_decisions d ON d.offline_id=o.id WHERE d.license_id=? OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(o.projection_json,'$.license_id')))=? ORDER BY o.imported_at DESC LIMIT 50", [Uuid::bytes($id),strtolower($id)]);
        $license['transfers'] = $this->db->all('SELECT BIN_TO_UUID(t.outgoing_activation_id) AS outgoing_text,BIN_TO_UUID(t.incoming_activation_id) AS incoming_text,t.reason,t.created_at,u.display_name AS actor_name FROM license_transfers t JOIN admin_users u ON u.id=t.admin_id WHERE t.license_id=? ORDER BY t.created_at DESC LIMIT 50',[Uuid::bytes($id)]);
        return $license;
    }

    public function offline(string $id): array
    {
        $row = $this->db->one("SELECT BIN_TO_UUID(o.id) AS id_text,o.product_id,o.projection_json,o.imported_at,u.display_name AS importer_name,COALESCE(d.decision,'pending') AS status,BIN_TO_UUID(d.license_id) AS license_text,BIN_TO_UUID(d.revision_id) AS revision_text,d.reason,d.decided_at,a.display_name AS decider_name FROM offline_requests o JOIN admin_users u ON u.id=o.imported_by LEFT JOIN offline_decisions d ON d.offline_id=o.id LEFT JOIN admin_users a ON a.id=d.admin_id WHERE o.id=?",[Uuid::bytes($id)]) ?? throw new Problem('No se encontró la solicitud.',404);
        $row['payload'] = json_decode($row['projection_json'],true,16,JSON_THROW_ON_ERROR);
        unset($row['projection_json']);
        return $row;
    }

    public function offlineCandidates(string $productId, string $query = ''): array
    {
        return $this->db->all("SELECT BIN_TO_UUID(l.license_id) AS id_text,l.license_type,l.expires_at,c.display_name AS customer_name FROM licenses l JOIN customers c ON c.id=l.customer_id WHERE l.product_id=? AND l.commercial_status='issued' AND (c.display_name LIKE ? OR BIN_TO_UUID(l.license_id) LIKE ?) ORDER BY c.display_name,l.license_id LIMIT 100",[$productId,'%'.$query.'%','%'.$query.'%']);
    }

    public function revision(string $licenseId, string $revisionId): array
    {
        return $this->db->one('SELECT license_jws,license_revision FROM license_revisions WHERE license_id=? AND revision_id=?', [Uuid::bytes($licenseId),Uuid::bytes($revisionId)]) ?? throw new Problem('No se encontró la revisión.',404);
    }

    public function users(): array
    {
        return $this->db->all('SELECT BIN_TO_UUID(id) AS id_text,login,display_name,role,state,mfa_secret_encrypted IS NOT NULL AS has_mfa,created_at FROM admin_users ORDER BY created_at');
    }
}
