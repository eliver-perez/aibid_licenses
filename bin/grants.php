<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

consoleRun(function (): void {
    $options = getopt('', ['database:', 'user:']);
    $database = $options['database'] ?? 'aibid_licenses';
    $user = $options['user'] ?? 'aibid_app';
    if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $database) || !preg_match('/\A[a-zA-Z0-9_]+\z/', $user)) { throw new Aibid\Domain\Problem('Usa identificadores SQL simples.'); }
    $permissions = [
        'schema_migrations'=>'SELECT', 'security_state'=>'SELECT,UPDATE', 'admin_users'=>'SELECT,INSERT,UPDATE',
        'admin_sessions'=>'SELECT,INSERT,UPDATE,DELETE', 'admin_recovery_codes'=>'SELECT,INSERT,UPDATE',
        'rate_limit_buckets'=>'SELECT,INSERT,UPDATE,DELETE', 'audit_heads'=>'SELECT,UPDATE', 'audit_events'=>'SELECT,INSERT',
        'admin_operations'=>'SELECT,INSERT,UPDATE', 'customers'=>'SELECT,INSERT,UPDATE', 'customer_contacts'=>'SELECT,INSERT,UPDATE',
        'products'=>'SELECT,INSERT,UPDATE', 'product_capabilities'=>'SELECT,INSERT,UPDATE', 'capability_dependencies'=>'SELECT,INSERT,DELETE',
        'licenses'=>'SELECT,INSERT,UPDATE', 'license_features'=>'SELECT,INSERT,UPDATE', 'license_limits'=>'SELECT,INSERT,UPDATE',
        'license_credentials'=>'SELECT,INSERT,UPDATE', 'license_changes'=>'SELECT,INSERT', 'subscription_periods'=>'SELECT,INSERT', 'maintenance_purchases'=>'SELECT,INSERT',
        'signing_keys'=>'SELECT', 'signing_scopes'=>'SELECT',
        'activations'=>'SELECT,INSERT,UPDATE (state,ended_at,current_revision_id)',
        'license_revisions'=>'SELECT,INSERT', 'activation_challenges'=>'SELECT,INSERT,UPDATE,DELETE',
        'license_requests'=>'SELECT,INSERT,UPDATE',
    ];
    foreach ($permissions as $table => $privileges) { fwrite(STDOUT, "GRANT $privileges ON `$database`.`$table` TO '$user'@'localhost';\n"); }
});
