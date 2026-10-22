# Actualización controlada — Licenciamiento comercial, fase 1

21 de octubre de 2026. El usuario informa que el servidor ya está desplegado y funcionando. **Esta tarea no realiza el despliegue ni modifica el VPS.** Este procedimiento actualiza una instalación existente; no ejecutar de nuevo configure, bootstrap-admin, generación de firmantes ni cambio de claves de aplicación.

## 1. Qué cambia

- Migración **006_commercial_delivery.sql**, nueva y separada; 001–005 permanecen intactas.
- Código corto 3-4-4-4 sin prefijo para credenciales nuevas/reemplazadas. No se conserva activación nueva con el formato anterior.
- Credencial nueva cifrada con XChaCha20-Poly1305 y clave derivada por HKDF desde la CREDENTIAL_KEY existente, con contexto y propósito separados. Se conserva HMAC para buscarla.
- Campos comerciales opcionales y su versión/historial; no cambian firmas o activaciones.
- RFC opcional del cliente, permisos sensibles explícitos y documentos PDF cifrados/inmutables en MySQL.
- Reautenticación de descargas/revelaciones y confirmación excepcional para liberar una activación offline.
- Dependencia `dompdf/dompdf` **^3.1.6**, bloqueada en composer.lock con cinco paquetes nuevos y sin actualizar los demás. PDF con texto seleccionable, logo oficial y QR; sin acceso remoto ni ejecución PHP/JavaScript. [Referencia del proyecto](https://github.com/dompdf/dompdf/releases).

No se incrementa versión comercial del producto ni del protocolo V1.0.

El SVG original permanece intacto. Para conservar proporciones y tipografía frente a limitaciones del renderizador PDF se incluye `public/assets/brand/logo-h-pdf.png`, derivado transparente 1440×260 renderizado por navegador desde `logo-h.svg`, con hashes de fuente/derivado en `logo-h-pdf.json`. No es un logo recreado. Si cambia el SVG, regenerar en desarrollo con `node bin/render-brand.cjs` (Playwright/Chrome; `CHROME_PATH` opcional), revisar visualmente y empaquetar ambos archivos nuevos. No instalar Node/Chrome en producción para generar documentos.

## 2. Requisitos y configuración

Conservar PHP ^8.3, MySQL 8, Sodium, PDO MySQL, mbstring, XMLWriter y Argon2id. PDF necesita además **DOM, iconv y GD**, comprobados por Composer/preflight. En Ubuntu instalar `php8.3-gd` (adaptar la versión real de PHP); el paquete php8.3-xml de la receta existente incluye DOM/XMLWriter; comprobar la CLI y FPM reales con `composer check-platform-reqs --no-dev` y la prueba del pool. Node, Playwright, Go y Poppler/pdftotext son herramientas de desarrollo/prueba, no dependencias del servicio de producción.

**No hay nuevas variables secretas obligatorias.** Conservar APP_ENV=production, APP_URL con el origen HTTPS definitivo, credenciales SQL, MFA_KEY, CREDENTIAL_KEY, AUDIT_KEY, SESSION_KEY, OPERATION_KEY, AIBID_CONFIG y SIGNING_KEY_DIR existentes. En especial, cambiar CREDENTIAL_KEY haría irrecuperables códigos/documentos/evidencia y rompería HMAC de credenciales. No regenerarla.

PDF usa `sys_get_temp_dir()` para caché/temporales de renderizado. El pool existente define una carpeta privada `shared/tmp` 0700, escribible por su usuario; conservarla. No dar escritura sobre vendor/código ni public. Los documentos finales se guardan cifrados en `license_documents`, no en public ni en una carpeta compartida. El cifrado no sustituye permisos SQL/0600 de configuración y respaldo externo separado.

## 3. Preparar el nuevo código sin tocar el servicio activo

Paquete de esta entrega: `var/releases/aidexlicense-commercial-phase1.tar.gz` y su `.sha256`. Para reconstruirlo, generar un destino nuevo mediante `bin/build-release.php`, con un nombre de archivo que no exista. No usar paquetes antiguos de identidad/prefijo. El build incluye estas instrucciones y el contrato de integración, y excluye pruebas, var, configuración y secretos.

En la carpeta de la **nueva release**, instalar desde el lockfile:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --no-plugins
composer check-platform-reqs --no-dev
```

Verificar checksum/manifiesto y propietario de solo lectura para FPM. Las rutas siguientes son las del ejemplo previo; adaptar a la instalación real. Verificar el nombre de base/cuenta antes de ejecutar cualquier comando. Todos los comandos siguientes son **manuales en el servidor**, no se ejecutan desde esta sesión.

## 4. Respaldo previo y ventana de mantenimiento

Antes de modificar el esquema:

1. Crear `shared/maintenance.flag`, detener la tarea aidex-maintenance.timer y cerrar CLI de emisión. Esperar solicitudes en curso. El indicador bloquea HTTP, no procesos CLI independientes.
2. Desde el **código anterior** funcional, crear respaldo cifrado y anclaje con las herramientas existentes; conservar configuración/5 claves/privadas y una copia externa de recovery.key separada de los archivos cifrados.
3. Ensayar recuperación de la copia previa en un MySQL aislado y directorio privado, con el código correspondiente al respaldo. No ensayar sobre producción.

Ejemplo de copia, usando la clave de respaldo **existente** y un nombre FECHA que el operador complete:

```sh
cd /srv/aidexlicense/current
sudo touch /srv/aidexlicense/shared/maintenance.flag
sudo systemctl stop aidex-maintenance.timer
sudo env AIBID_CONFIG=/srv/aidexlicense/shared/config.php php bin/audit-verify.php
sudo env AIBID_CONFIG=/srv/aidexlicense/shared/config.php php bin/backup.php --action=create --key-file=/srv/aidexlicense/backup/recovery.key --output=/srv/aidexlicense/backup/archives/FECHA-antes-fase1.aibidbackup
```

No regenerar recovery.key. Guardar también un anclaje externo mediante el procedimiento existente, no solo una impresión en terminal. Los backups anteriores no pueden contener los nuevos documentos que aún no existían.

## 5. Aplicar migración y grants

Desde la nueva carpeta de código, todavía sin cambiar current, con la configuración persistente:

```sh
sudo -u aidex env AIBID_CONFIG=/srv/aidexlicense/shared/config.php php bin/migrate.php --user=aidex_migrate
umask 077
php bin/grants.php --database=aidex_licenses --user=aidex_app > /tmp/aidex-fase1-runtime-grants.sql
sudo mysql < /tmp/aidex-fase1-runtime-grants.sql
sudo -u aidex env AIBID_CONFIG=/srv/aidexlicense/shared/config.php php bin/preflight.php --require-signer
sudo -u aidex env AIBID_CONFIG=/srv/aidexlicense/shared/config.php php bin/recovery-check.php
sudo -u aidex env AIBID_CONFIG=/srv/aidexlicense/shared/config.php php bin/audit-verify.php
```

Si se usan otros nombres/hosts SQL, generar/aplicar grants con esos valores. El secreto de migración se introduce sin eco; nunca se persiste en FPM ni se pasa en argumentos. Las tablas nuevas de historia/documentos tienen SELECT/INSERT, sin UPDATE/DELETE para runtime; preflight comprueba esta restricción. La tabla de grants administrativos permite las operaciones necesarias únicamente mediante la administración superadmin.

006 añade columnas/tablas, rellena referencia/nota inicial desde license.issued y conserva registros anteriores. No descifra ni convierte hashes antiguos. No genera claves criptográficas ni archivos de firma. MySQL DDL puede hacer commits implícitos: ante fallo parcial, conservar diagnóstico y mantener mantenimiento; no marcar una migración como completa ni editar su checksum a mano. Evaluar espacio y duración en una copia aislada antes de la ventana real.

Las credenciales anteriores quedan con credential_encrypted/code_format NULL y no se recuperan ni se usan para nuevas activaciones. Para una licencia de desarrollo que se quiera conservar, un operador autorizado puede **reemplazar explícitamente** su clave desde el panel; el código nuevo se cifra. El JWS/activación ya emitidos no se cambian por ese reemplazo. No borrar licencias o datos automáticamente.

## 6. Cambiar código, proxy y permisos administrativos

Cambiar current a la nueva release verificada y recargar PHP-FPM para OPcache. Si cambia Nginx, revisar `deploy/nginx.conf.example`: `/verify/` sirve la verificación pública, oculta la referencia en access logs y conserva HTTPS/no-store. No ponerla bajo la lista de redes del panel ni abrir public a la raíz del repositorio. Si hay otro proxy/servidor web, excluir también referencias de verificación de sus access logs y comprobar encabezados/HTTPS reales; PHP ignora encabezados X-Forwarded arbitrarios.

Ejemplo Ubuntu de validación/recarga, **adaptando versión y servicio reales**:

```sh
sudo nginx -t
sudo php-fpm8.3 -t
sudo systemctl reload php8.3-fpm
sudo systemctl reload nginx
```

Revisar permisos de cada operador desde Administradores. No se conceden automáticamente permisos sensibles existentes: el operador conserva emisión/edición/constancia de licenciamiento, pero requiere grants explícitos para revelar códigos, generar/descargar entregas y descargar .lic. Superadmin conserva todo; viewer no recibe permisos sensibles. Guardar permisos requiere contraseña/TOTP, queda auditado y cierra sesiones de la cuenta afectada.

## 7. Checklist de aceptación manual

Hacer pruebas en un entorno de aceptación aislado con datos sintéticos antes de habilitar usuarios reales:

- [ ] Emisión sin referencia ni nota; código 3-4-4-4, con alfabeto correcto y copia exacta.
- [ ] Captura online en minúsculas y sin guiones; errores/rate limit y segunda instalación rechazados.
- [ ] Operador sin permiso emite sin ver el código; revelación exige permiso, contraseña y TOTP nuevo.
- [ ] Referencia/nota se agregan, corrigen y vacían sin cambiar código, JWS, activación o derechos; historial anterior/nuevo visible.
- [ ] Constancia de licenciamiento seleccionable, logo AIDEX, folio/revisión/RFC opcional y sin código/JWS.
- [ ] Constancia de entrega online solo con ambos permisos y reautenticación; offline identifica instalación, archivo y SHA-256 exactos.
- [ ] PDF y .lic históricos se descargan idénticos; no consumen plazas ni generan revisiones técnicas.
- [ ] QR abre HTTPS oficial sin sesión; no revela titular/RFC/código/instalación; hash del PDF coincide. Revocar o reemplazar debe cambiar el estado mostrado, no el PDF histórico.
- [ ] Solicitudes A/B, alteración de firmas, conflictos de idempotencia y una plaza máxima comprobados.
- [ ] Perpetua offline sin caducidad artificial; suscripción con vigencia y 15 días de tolerancia. Validar esto en el consumidor antes de distribución.
- [ ] Desactivación offline/liberación/traslado requiere superadmin, reautenticación, motivo y aceptación; no prometer borrado físico ni revocación inmediata de un equipo aislado.
- [ ] Auditoría de operaciones y denegaciones sin códigos, contraseñas ni PDF/JWS completos.
- [ ] Respaldo nuevo y restauración aislada conservan códigos cifrados, PDF, historia, claves y firmas; recovery-check y anclaje externo pasan.
- [ ] Recursos, configuración, logs y SQL no se sirven por HTTP; producción sigue sin depuración.

Después de aceptar, retirar maintenance.flag y reactivar el timer siguiendo la operación existente. **No admitir el consumidor comercial** hasta adaptar su captura al formato nuevo y comprobar confianza, binding, estados y controles locales en Windows/Ubuntu/macOS.

## 8. Recuperación y datos de desarrollo

Conservar copia previa y release anterior. No hay down migration automática ni borrado de tablas nuevas. No restaurar una copia antigua sobre datos posteriores sin conciliación: afectaría activaciones, revisiones y constancias. Ensayar restauración en otra base vacía con restore-empty/recovery-config; no volver a ejecutar configure/bootstrap/generate. Una copia anterior a 006 se importa primero con su esquema, luego se migra al código nuevo antes de usar preflight/recovery-check nuevos. Mantener intactas las claves de aplicación y el registro/directorio del firmante.

No hace falta reiniciar datos de desarrollo para adoptar el formato. Si se desea empezar pruebas desde cero, crear **otra base** y configuración development independientes con configure.php --output a un archivo nuevo, y seleccionar esa ruta mediante AIBID_CONFIG. Aplicar migraciones/grants y crear únicamente cuentas sintéticas allí. Usar un directorio de firma de desarrollo distinto; generar su firmante solo para ese entorno nuevo. Conservar base/configuración/privadas originales y su backup; no borrar ni reutilizar material criptográfico de producción. No se proporciona ni ejecuta un comando de reset sobre la base existente.
