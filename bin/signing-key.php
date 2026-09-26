<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

consoleRun(function (): void {
    $options = getopt('', ['action:','kid:','purpose:','reason:','trust-confirmed','user:']);
    if (isset($options['user'])) {
        putenv('DB_USER=' . $options['user']);
        putenv('DB_PASSWORD=' . hiddenInput('Contraseña de la cuenta de gestión de claves: '));
    }
    $app = Aibid\App::boot();
    $action = $options['action'] ?? '';
    if ($action === 'generate') {
        $public = $app->signingKeys->generate($options['kid'] ?? '', $options['purpose'] ?? 'license');
        echo Aibid\Domain\Protocol::json($public) . PHP_EOL;
        echo "Clave preparada. Distribuye su pública por el canal autenticado del cliente antes de activarla.\n";
    } elseif ($action === 'activate') {
        if (!array_key_exists('trust-confirmed', $options)) { throw new Aibid\Domain\Problem('Para activar, confirma que los clientes ya confían en esta pública mediante --trust-confirmed.'); }
        $app->signingKeys->activate($options['kid'] ?? '', $options['reason'] ?? '');
        echo "Clave firmante activada; las públicas anteriores se conservan.\n";
    } elseif ($action === 'list') {
        foreach ($app->db->all('SELECT kid,environment,purpose,public_key,state,created_at FROM signing_keys WHERE environment=? ORDER BY created_at', [$app->signingKeys->environment()]) as $key) {
            $key['public_key'] = Aibid\Domain\Protocol::encode($key['public_key']);
            echo Aibid\Domain\Protocol::json($key) . PHP_EOL;
        }
    } else { throw new Aibid\Domain\Problem('Usa --action=generate|activate|list y --kid para generar/activar. La gestión requiere permisos SQL de migración; usa --user si es necesario.'); }
});
