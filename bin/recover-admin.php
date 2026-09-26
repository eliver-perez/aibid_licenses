<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

consoleRun(function (): void {
    $options = getopt('', ['login:', 'reason:']);
    if (!isset($options['login'], $options['reason'])) { throw new Aibid\Domain\Problem('Uso: php bin/recover-admin.php --login=administrador@example.com --reason="Motivo de recuperación". Requiere acceso operativo al servidor.'); }
    $reason = Aibid\Domain\Input::text($options, 'reason', 500);
    $password = hiddenInput('Nueva contraseña: ');
    Aibid\Domain\Input::password($password);
    if (!hash_equals($password, hiddenInput('Confirma la nueva contraseña: '))) { throw new Aibid\Domain\Problem('Las contraseñas no coinciden.'); }
    $app = Aibid\App::boot();
    $app->db->transaction(function () use ($app, $options, $reason, $password): void {
        $app->db->one('SELECT id FROM security_state WHERE id=1 FOR UPDATE');
        $user = $app->db->one('SELECT * FROM admin_users WHERE login=? FOR UPDATE', [mb_strtolower($options['login'])]);
        if (!$user) { throw new Aibid\Domain\Problem('No se encontró la cuenta.'); }
        $app->db->execute('UPDATE admin_users SET password_hash=?,mfa_secret_encrypted=NULL,last_totp_step=NULL,updated_at=? WHERE id=?', [password_hash($password, PASSWORD_ARGON2ID, ['memory_cost'=>65536,'time_cost'=>3,'threads'=>1]), $app->clock->sql(), $user['id']]);
        $app->db->execute('UPDATE admin_sessions SET revoked_at=?,enrollment_secret=NULL WHERE admin_id=?', [$app->clock->sql(), $user['id']]);
        $app->db->execute('UPDATE admin_recovery_codes SET used_at=? WHERE admin_id=? AND used_at IS NULL', [$app->clock->sql(), $user['id']]);
        $app->audit->append(null, 'admin.recovered_via_cli', 'admin', Aibid\Domain\Uuid::text($user['id']), [], $reason);
    });
    sodium_memzero($password);
    fwrite(STDOUT, "Contraseña reemplazada, sesiones y códigos anteriores invalidados. Se requiere enrolar MFA nuevamente; el estado de la cuenta se conserva.\n");
});
