<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

consoleRun(function (): void {
    $options = getopt('', ['login:', 'name:']);
    if (!isset($options['login'], $options['name'])) { throw new Aibid\Domain\Problem('Uso: php bin/bootstrap-admin.php --login=administrador@example.com --name="Nombre del administrador"'); }
    $password = hiddenInput('Contraseña inicial (mínimo 12 caracteres): ');
    if (!hash_equals($password, hiddenInput('Confirma la contraseña: '))) { throw new Aibid\Domain\Problem('Las contraseñas no coinciden.'); }
    $app = Aibid\App::boot();
    $app->auth->bootstrap($options['login'], $options['name'], $password);
    sodium_memzero($password);
    fwrite(STDOUT, "Superadministrador creado. Ingresa al panel y configura MFA para continuar.\n");
});
