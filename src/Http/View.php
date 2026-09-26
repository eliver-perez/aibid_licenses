<?php
declare(strict_types=1);
namespace Aibid\Http;

use Aibid\Domain\Uuid;

final class View
{
    public function render(string $name, array $data): void
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/templates/' . $name . '.php';
        $content = ob_get_clean();
        require dirname(__DIR__, 2) . '/templates/layout.php';
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function fields(string $csrf): void
    {
        echo '<input type="hidden" name="csrf" value="' . self::escape($csrf) . '">';
        echo '<input type="hidden" name="operation_id" value="' . Uuid::create() . '">';
    }

    public static function date(?string $value): string
    {
        return $value === null || $value === '' ? '—' : (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('d/m/Y · H:i') . ' UTC';
    }

    public static function role(string $role): string
    {
        return ['superadmin' => 'Superadministrador', 'operator' => 'Operador de licencias', 'viewer' => 'Consulta'][$role] ?? $role;
    }

    public static function action(string $action): string
    {
        return [
            'license.issued' => 'Licencia emitida', 'license.features' => 'Módulos actualizados',
            'license.renew' => 'Suscripción renovada', 'license.maintenance' => 'Mantenimiento registrado',
            'license.reissue' => 'Clave comercial reemplazada', 'license.revoke' => 'Licencia revocada',
            'offline.imported'=>'Solicitud offline importada', 'offline.approved'=>'Solicitud offline aprobada',
            'offline.rejected'=>'Solicitud offline rechazada', 'offline.transferred'=>'Transferencia offline autorizada',
            'license.force_release'=>'Recuperación de equipo averiado',
            'customer.created' => 'Cliente registrado', 'customer.updated' => 'Cliente actualizado',
            'product.created' => 'Producto registrado', 'product.updated' => 'Producto actualizado',
            'session.login' => 'Inicio de sesión', 'session.logout' => 'Cierre de sesión',
            'admin.mfa_enrolled' => 'Segundo factor configurado', 'admin.created' => 'Administrador registrado',
            'admin.bootstrap' => 'Cuenta inicial creada', 'session.password_verified' => 'Contraseña verificada',
        ][$action] ?? $action;
    }
}
