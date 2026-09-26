# AIBID · Administración de licencias

Etapas 1–3 · Actualizado el 26 de septiembre de 2026.

Servidor independiente en **PHP 8.3+ y MySQL 8**, con vistas PHP, Bootstrap 5 y JavaScript. El producto inicial se presenta como **AIBID — Aplicación de Indexación de Bibliotecas Digitales** y conserva el identificador contractual `gestor_documental`. Los cuatro logotipos SVG proporcionados se incluyen sin modificar.

El panel permite administrar clientes y su contacto principal, productos, módulos, licencias perpetuas y suscripciones, compras de mantenimiento, renovaciones, credenciales comerciales y auditoría. Incluye bootstrap por CLI, roles, MFA obligatorio, recuperación de un solo uso, protección CSRF, reautenticación para acciones sensibles y transacciones idempotentes.

**Alcance actual:** panel comercial y API online con los cuatro endpoints V1, desafíos de un solo uso, prueba Ed25519, firma JWS, una instalación activa por licencia, refresh y desactivación. Los cambios de derechos y la revocación comercial publican nuevas revisiones dentro de la misma transacción. El panel permite consultar instalaciones y descargar revisiones `.lic`. La importación/aprobación `.licreq`, transferencias forzadas, integración con el cliente real y despliegue VPS siguen pendientes en etapas 4–5; aún no constituye V1 completo.

## Ejecutar el panel

Se requiere PHP con Sodium, PDO MySQL, mbstring, XMLWriter y Argon2id; MySQL 8/InnoDB y Composer. PHP-FPM y HTTPS son necesarios para el despliegue. El PHP 8.1/MariaDB de XAMPP observado no cumple esos requisitos; sus servicios y bases no se modificaron.

1. Instalar dependencias: `composer install` (en producción: `composer install --no-dev --prefer-dist --optimize-autoloader`).
2. Crear una base vacía y cuentas separadas de migración y aplicación, siguiendo [OPERATIONS.md](OPERATIONS.md#7-instalación-disponible-del-panel).
3. Generar la configuración y sus claves mediante CLI; la contraseña SQL se solicita de forma oculta:

   ```sh
   php bin/configure.php --env=development --url=http://127.0.0.1:8088 --dsn='mysql:host=127.0.0.1;dbname=aibid_licenses;charset=utf8mb4' --user=aibid_app
   php bin/migrate.php --user=aibid_migrate
   php bin/grants.php --database=aibid_licenses --user=aibid_app
   ```

4. Aplicar los GRANT impresos con una cuenta SQL autorizada. Crear el primer administrador, cuya contraseña también se pide por terminal:

   ```sh
   php bin/bootstrap-admin.php --login=administrador@example.com --name='Administración AIBID'
   php bin/preflight.php
   php -S 127.0.0.1:8088 -t public public/router.php
   ```

5. Abrir `http://127.0.0.1:8088`, configurar TOTP y guardar los códigos de recuperación.

La raíz web debe ser **`public/`** y el sitio debe tener su propio origen, sin subdirectorio. Para producción usar `--env=production`, URL HTTPS y PHP-FPM. La configuración real `config/local.php` se genera con permisos 0600, queda fuera del directorio público y no se versiona; conservar sus claves al actualizar. No hay instalador web ni cuenta predeterminada.

## Habilitar firma y activaciones

En una instalación existente, conservar `config/local.php`, ejecutar la migración `003_online_activations.sql` mediante `bin/migrate.php` y aplicar los nuevos permisos de `bin/grants.php`. No volver a ejecutar bootstrap ni regenerar los secretos existentes.

```sh
php bin/signing-key.php --action=generate --kid=lic-dev-2026-a --purpose=license --user=aibid_migrate
php bin/signing-key.php --action=list
# Después de incorporar la pública al cliente por un canal autenticado:
php bin/signing-key.php --action=activate --kid=lic-dev-2026-a --reason='Pública distribuida a los clientes de este entorno' --trust-confirmed --user=aibid_migrate
php bin/preflight.php --require-signer
```

El entorno lo determina `APP_ENV`; usar claves y nombres distintos para desarrollo y producción. La CLI solo imprime la pública. `SIGNING_KEY_DIR` permite elegir un directorio absoluto y persistente fuera de `public/`; el valor predeterminado es `var/keys/<APP_ENV>`. Las privadas requieren permisos 0600 y acceso del usuario PHP dedicado. Sin firmante válido, las operaciones que necesitan una nueva firma fallan con 503 y se revierten; el panel y el refresh de una revisión existente siguen disponibles. Ver [OPERATIONS.md](OPERATIONS.md#8-claves-y-api-online-disponibles-en-etapa-3).

## Verificación

```sh
composer test:unit
composer test:contract
# Instancia MySQL aislada, con una cuenta autorizada a crear BD/usuarios de prueba:
env TEST_MYSQL_DSN='mysql:unix_socket=/ruta/mysql.sock;charset=utf8mb4' php vendor/bin/phpunit --testsuite integration
```

Las pruebas crean bases y usuarios aleatorios `aibid_test_*`, aplican los permisos reales de aplicación y eliminan sus propios datos al finalizar. No apuntarlas a producción. [TEST_PLAN.md](TEST_PLAN.md) contiene la preparación del navegador y la evidencia; Node/Playwright son dependencias de desarrollo únicamente. Go solo verifica los vectores criptográficos de referencia de forma opcional: **el servidor no ejecuta Go ni requiere Go en el VPS**.

## Documentación

| Documento | Contenido |
| --- | --- |
| [STATUS.md](STATUS.md) | Punto de continuación y siguiente etapa |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Arquitectura completa y separación por etapas |
| [SCHEMA.md](SCHEMA.md) | Esquema físico disponible, diseño futuro y concurrencia |
| [API.md](API.md) | Diseño de las cuatro rutas V1, firmas y errores |
| [SECURITY.md](SECURITY.md) | Controles implementados y diseño de seguridad restante |
| [LICENSE_CONTRACT.md](LICENSE_CONTRACT.md) | Anexo V1.0 literal, sin modificaciones |
| [OPERATIONS.md](OPERATIONS.md) | Instalación del panel y preparación del VPS |
| [DECISIONS.md](DECISIONS.md) | Decisiones aceptadas B-01/B-02 y coordinación pendiente |
| [TEST_PLAN.md](TEST_PLAN.md) | Comandos, evidencia y pruebas de las próximas etapas |

Los requisitos proceden del [documento proporcionado](prompt_2_servidor_licencias_php_v1_2.md); la autorización para avanzar y las decisiones confirmadas constan en la conversación. No se agregan funciones del gestor documental a este servidor.
