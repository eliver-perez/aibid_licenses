<?php
declare(strict_types=1);

// Copy via bin/configure.php; all cryptographic values below are placeholders.
return [
    'APP_ENV' => 'production',
    'APP_URL' => 'https://licencias.example.com',
    'DB_DSN' => 'mysql:host=127.0.0.1;port=3306;dbname=aibid_licenses;charset=utf8mb4',
    'DB_USER' => 'aibid_app',
    'DB_PASSWORD' => '',
    'MFA_KEY' => '',
    'CREDENTIAL_KEY' => '',
    'AUDIT_KEY' => '',
    'SESSION_KEY' => '',
    'OPERATION_KEY' => '',
    'ADMIN_NETWORKS' => '',
    // Absolute path outside public/, readable only by the dedicated PHP user.
    // Empty/unset uses var/keys/<APP_ENV>; private files are created by CLI.
    'SIGNING_KEY_DIR' => '',
];
