<?php
declare(strict_types=1);
namespace Aibid\Application;

use Aibid\Domain\{Capabilities, Input, Problem, Uuid};
use Aibid\Infrastructure\{Audit, Clock, Database};

final class CatalogService
{
    public function __construct(private Database $db, private Operations $operations, private Audit $audit, private Clock $clock) {}

    public function saveCustomer(Actor $actor, string $operationId, ?string $id, array $input): array
    {
        $values = [
            'name' => Input::text($input, 'name'), 'legal_name' => Input::text($input, 'legal_name', 190, false),
            'contact_name' => Input::text($input, 'contact_name'),
            'email' => Input::email(Input::text($input, 'email', 190, false)),
            'phone' => Input::text($input, 'phone', 60, false),
            'state' => Input::choice($input, 'state', ['active', 'archived']),
            'version' => (int) ($input['version'] ?? 0),
        ];
        return $this->operations->run($actor, ['superadmin', 'operator'], $operationId, 'customer.save', [$id, $values], function () use ($actor, $id, $values): array {
            $customerId = $id ?? Uuid::create();
            $bytes = Uuid::bytes($customerId);
            if ($id === null) {
                $this->db->execute('INSERT INTO customers(id,display_name,legal_name,state,created_at,updated_at) VALUES (?,?,?,?,?,?)', [$bytes, $values['name'], $values['legal_name'], $values['state'], $this->clock->sql(), $this->clock->sql()]);
                $this->db->execute('INSERT INTO customer_contacts(id,customer_id,name,email,phone) VALUES (?,?,?,?,?)', [Uuid::bytes(Uuid::create()), $bytes, $values['contact_name'], $values['email'], $values['phone']]);
            } else {
                $existing = $this->db->one('SELECT * FROM customers WHERE id=? FOR UPDATE', [$bytes]);
                if (!$existing) { throw new Problem('No se encontró el cliente.', 404); }
                if ((int) $existing['row_version'] !== $values['version']) { throw new Problem('Otro operador cambió el cliente. Recarga el formulario.', 409); }
                $this->db->execute('UPDATE customers SET display_name=?,legal_name=?,state=?,row_version=row_version+1,updated_at=? WHERE id=?', [$values['name'], $values['legal_name'], $values['state'], $this->clock->sql(), $bytes]);
                $this->db->execute('UPDATE customer_contacts SET name=?,email=?,phone=? WHERE customer_id=?', [$values['contact_name'], $values['email'], $values['phone'], $bytes]);
            }
            $this->audit->append($actor->id, $id === null ? 'customer.created' : 'customer.updated', 'customer', $customerId, ['state' => $values['state']]);
            return ['id' => $customerId];
        });
    }

    public function saveProduct(Actor $actor, string $operationId, ?string $id, array $input): array
    {
        $productId = $id ?? Input::identifier(Input::text($input, 'product_id', 64));
        if (in_array($productId, ['new', 'create'], true)) { throw new Problem('Ese identificador está reservado. Elige otro identificador de producto.'); }
        $values = [
            'name' => Input::text($input, 'name'), 'description' => Input::text($input, 'description', 500, false),
            'state' => Input::choice($input, 'state', ['active', 'archived']), 'version' => (int) ($input['version'] ?? 0),
        ];
        return $this->operations->run($actor, ['superadmin'], $operationId, 'product.save', [$productId, $id === null, $values], function () use ($actor, $productId, $id, $values): array {
            if ($id === null) {
                if ($this->db->one('SELECT product_id FROM products WHERE product_id=?', [$productId])) { throw new Problem('Ese identificador de producto ya existe.'); }
                $this->db->execute('INSERT INTO products(product_id,display_name,description,state,created_at,updated_at) VALUES (?,?,?,?,?,?)', [$productId, $values['name'], $values['description'], $values['state'], $this->clock->sql(), $this->clock->sql()]);
            } else {
                $product = $this->db->one('SELECT * FROM products WHERE product_id=? FOR UPDATE', [$productId]);
                if (!$product) { throw new Problem('No se encontró el producto.', 404); }
                if ((int) $product['row_version'] !== $values['version']) { throw new Problem('El producto cambió. Recarga el formulario.', 409); }
                $this->db->execute('UPDATE products SET display_name=?,description=?,state=?,row_version=row_version+1,updated_at=? WHERE product_id=?', [$values['name'], $values['description'], $values['state'], $this->clock->sql(), $productId]);
            }
            $this->audit->append($actor->id, $id === null ? 'product.created' : 'product.updated', 'product', $productId, ['state' => $values['state']]);
            return ['id' => $productId];
        });
    }

    public function saveCapability(Actor $actor, string $operationId, string $productId, array $input): array
    {
        $feature = Input::identifier(Input::text($input, 'capability_key', 64));
        $name = Input::text($input, 'name');
        $required = array_values(array_unique(array_filter(array_map('trim', explode(',', Input::text($input, 'requires', 1000, false))))));
        sort($required);
        if ($productId === 'gestor_documental' && ($feature === 'hybrid' || ($feature === 'review_workflow' && $required !== ['expedientes']))) {
            throw new Problem('Esta combinación no respeta el catálogo del contrato V1.0.');
        }
        if ($productId === 'gestor_documental' && $feature !== 'review_workflow' && $required !== []) {
            throw new Problem('Las dependencias de AIBID deben conservar el contrato V1.0.');
        }
        return $this->operations->run($actor, ['superadmin'], $operationId, 'capability.save', [$productId, $feature, $name, $required], function () use ($actor, $productId, $feature, $name, $required): array {
            $product = $this->db->one('SELECT * FROM products WHERE product_id=? FOR UPDATE', [$productId]);
            if (!$product) { throw new Problem('No se encontró el producto.', 404); }
            $catalog = array_column($this->db->all('SELECT capability_key FROM product_capabilities WHERE product_id=?', [$productId]), 'capability_key');
            if ($productId === 'gestor_documental' && !in_array($feature, $catalog, true)) {
                throw new Problem('El catálogo V1.0 de AIBID conserva sus cinco capacidades.');
            }
            foreach ($required as $dependency) {
                if (!in_array($dependency, $catalog, true)) { throw new Problem('La capacidad requerida no existe en el producto.'); }
            }
            $dependencies = $this->db->all('SELECT capability_key,required_key FROM capability_dependencies WHERE product_id=? AND capability_key<>?', [$productId, $feature]);
            foreach ($required as $dependency) { $dependencies[] = ['capability_key' => $feature, 'required_key' => $dependency]; }
            Capabilities::acyclic($dependencies);
            foreach ($this->db->all('SELECT license_id FROM licenses WHERE product_id=? ORDER BY license_id FOR UPDATE', [$productId]) as $license) {
                $enabled = array_column($this->db->all('SELECT capability_key FROM license_features WHERE license_id=? AND enabled=1', [$license['license_id']]), 'capability_key');
                Capabilities::validate($enabled, array_unique([...$catalog, $feature]), $dependencies);
            }
            $this->db->execute('INSERT INTO product_capabilities(product_id,capability_key,display_name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE display_name=?', [$productId, $feature, $name, $name]);
            $this->db->execute('DELETE FROM capability_dependencies WHERE product_id=? AND capability_key=?', [$productId, $feature]);
            foreach ($required as $dependency) { $this->db->execute('INSERT INTO capability_dependencies VALUES (?,?,?)', [$productId, $feature, $dependency]); }
            $this->audit->append($actor->id, 'capability.updated', 'product', $productId, ['capability_key' => $feature, 'requires' => $required]);
            return ['id' => $productId];
        });
    }
}
