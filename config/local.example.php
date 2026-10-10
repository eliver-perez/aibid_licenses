<?php
declare(strict_types=1);

// Generar con bin/configure.php para una instalación NUEVA. No reemplazar claves existentes.
// APP_ENV: production | development | testing. HTTP solo para desarrollo en loopback.
// AIBID_CONFIG permite ubicar la configuración fuera del código; variables de proceso prevalecen.
// Todos los secretos siguientes son marcadores vacíos, nunca credenciales utilizables.
return [
    'APP_ENV' => 'production',
    'APP_URL' => 'https://licensing.aidex.com.mx',
    'DB_DSN' => 'mysql:host=127.0.0.1;port=3306;dbname=aidex_licenses;charset=utf8mb4',
    'DB_USER' => 'aidex_app',
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
