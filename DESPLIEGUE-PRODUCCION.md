# AIDEX 1.0 — Despliegue manual del servidor de licencias

Identidad: **AIDEX — Aplicación de Indexación Digital de Expedientes**. Producto: `aidex.com.mx`. Origen del servidor: **https://licensing.aidex.com.mx**. Actualizado el 9 de octubre de 2026.

Las claves comerciales nuevas y sus reemplazos usan el prefijo `AIDEX-`. No se requiere migrar claves comerciales anteriores: el usuario confirmó que todavía no se han otorgado licencias válidas. Este cambio no modifica el protocolo, los tokens aleatorios, firmas ni secretos existentes.

Esta guía prepara una primera instalación; no acredita un despliegue realizado. DNS, TLS, servicios y acceso remoto quedan a cargo del operador. El cliente se actualizará en una tarea separada. No habilitar clientes comerciales hasta terminar las comprobaciones de esta guía y distribuir de forma autenticada la clave pública de producción.

## 1. Elegir entorno y completar parámetros

El repositorio ya incluye un procedimiento concreto para Ubuntu 24.04 + Nginx + PHP-FPM: [DEPLOY_UBUNTU_24_04.md](DEPLOY_UBUNTU_24_04.md). Sus comandos completos cubren instalación, certificado, MySQL, permisos, administrador, firma, respaldos y restauración. No implica que ese sea el sistema operativo de tu futuro servidor. Si eliges esa alternativa, ejecutar sus secciones 1–10 junto con los controles de este documento. Sustituir `/home/eliver` por la carpeta del operador que recibirá el paquete; no es una cuenta de la aplicación.

Antes de empezar, completar:

| Parámetro | Valor o decisión manual |
| --- | --- |
| Dominio y origen | `licensing.aidex.com.mx`, `https://licensing.aidex.com.mx` |
| DNS | Proveedor de la zona, IP pública IPv4 y, solo si funciona, IPv6 |
| Servidor | Sistema operativo, servidor web, versión y servicio PHP-FPM |
| Código | Ejemplo: `/srv/aidexlicense/releases/1.0`, enlace `current` |
| Usuario del pool | Ejemplo: `aidex`; cuenta de servicio exclusiva |
| MySQL | Motor 8.x, socket/host, base, cuenta runtime y cuenta de migración |
| Secretos | Contraseñas aleatorias propias guardadas en gestor de secretos |
| Configuración | Ejemplo: `/srv/aidexlicense/shared/config.php`, permiso 0600 |
| Firma | Directorio persistente, `kid` único y distribución autenticada de la pública |
| TLS | Certificado válido, renovación y contacto de administración |
| Administrador | Correo real, nombre, contraseña personal y dispositivo TOTP |
| Redes del panel | CIDR permitidos, o acceso desde cualquier red con contraseña/MFA |
| Recuperación | Almacenamiento externo, custodio de `recovery.key`, retención y ensayo |

Los nombres SQL y las rutas de los ejemplos se pueden adaptar conjuntamente; no renombrar una base existente por motivos de marca. No reutilizar `config/local.php`, usuarios, contraseñas o claves de desarrollo para una instalación nueva de producción. Si importas una instalación existente legítima, conservar su configuración criptográfica y sus privadas; no ejecutar generación ni bootstrap otra vez.

## 2. Requisitos reales

- PHP **8.3 o superior dentro de la serie 8**, según `composer.json` (`^8.3`), tanto CLI como FPM; comprobar también las restricciones del lockfile.
- Extensiones `pdo`, `pdo_mysql`, `sodium`, `mbstring`, `xmlwriter`; `iconv` lo requiere una dependencia del lockfile. JSON forma parte de PHP moderno. Algoritmo de contraseña **Argon2id** disponible. Para generar el paquete: `Phar` y compresión gzip/zlib. El runtime de producción no necesita generar paquetes.
- **MySQL 8.x**, InnoDB/utf8mb4. El migrador y preflight rechazan MariaDB y motores distintos. Los ensayos se realizan sobre la versión indicada en el informe, no sobre todas las versiones compatibles.
- Composer y el `composer.lock` incluido. Producción solo usa OTPHP y BaconQrCode con sus dependencias transitivas; PHPUnit es desarrollo. Ejecutar `composer install --no-dev`, sin resolver versiones nuevas. Node, Playwright y Go son exclusivamente herramientas de pruebas, no servicios necesarios.
- Servidor web con TLS y front controller PHP. Nginx + PHP-FPM tiene plantillas y pruebas locales. Apache 2.4 + PHP-FPM es otra posibilidad, explicada en la sección 6; necesita adaptación y validación propia. Linux con estas dependencias es adecuado; Ubuntu 24.04 tiene la receta concreta. No existe una restricción propia a una distribución Linux específica ni una matriz certificada de otros sistemas operativos.
- `mysql` y `mysqldump` de MySQL disponibles para respaldo/restauración; no usar utilidades MariaDB en su lugar. Terminal interactiva con `stty` para introducir contraseñas en las CLI. Sincronización de reloj/NTP para MFA, desafíos y vigencias.
- Solo puertos públicos 80/443 para el sitio; MySQL y el socket FPM privados. El modo `php -S` es local, no el servicio de producción.

Comprobar tras instalar dependencias:

```sh
php -v
php -m
php -r 'exit(in_array("argon2id", password_algos(), true) ? 0 : 1);'
mysql --version
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --no-plugins
composer check-platform-reqs --no-dev
```

Usar la misma versión/extensiones en FPM: `php-fpm8.3 -t` en la alternativa Ubuntu, adaptando el nombre real del binario en otro sistema. No publicar `phpinfo()` para comprobarlo.

## 3. Paquete y estructura privada

Desde la copia local del proyecto, en una carpeta nueva:

```sh
mkdir -p var/releases
php bin/build-release.php --output="$PWD/var/releases/aidexlicense-1.0.tar.gz"
```

Si existe el destino, elegir otro nombre: el generador no sobrescribe archivos. El paquete contiene `aidexlicense/`, código, plantillas, SVG originales, migraciones, ejemplos de despliegue, documentos actuales y el lockfile. Excluye configuración real, `var/`, claves, datos, pruebas, `vendor/`, Node y el prompt histórico. `RELEASE.json` contiene hashes SHA-256 por archivo. En Linux verificar `sha256sum -c aidexlicense-1.0.tar.gz.sha256` antes de extraer. Copiar manualmente paquete y checksum por un canal autenticado; esta sesión no transfiere archivos al servidor.

Estructura de ejemplo:

```text
/srv/aidexlicense/
  releases/1.0/             código y vendor; solo lectura para FPM
  current -> releases/1.0
  shared/config.php         configuración persistente privada 0600
  shared/keys/              privadas del firmante 0700; archivos 0600
  shared/log/               logs privados 0700
  shared/tmp/               temporales privados 0700
  shared/maintenance.flag   indicador creado/retirado por el operador
  backup/recovery.key       clave de copia 0600; fuera del alcance FPM
  backup/archives/          copias cifradas y anclajes, directorio 0700
```

El usuario FPM lee configuración/privadas y escribe solo `log/` y `tmp/` durante ejecución habitual. Las CLI de generación de claves escriben `keys/` con el mismo propietario. El código debe pertenecer al operador/root, sin permiso de escritura FPM. `shared/` raíz puede ser root/0755 para que Nginx lea la existencia de `maintenance.flag`; **cada archivo secreto y subdirectorio permanece privado**. `backup/` root/0700 no es accesible para FPM. No conceder 777. Si usas el directorio de claves por defecto `var/keys/<APP_ENV>`, es necesario poder escribir allí desde la CLI; en producción se recomienda configurar explícitamente `shared/keys/` para no perder privadas al cambiar de release.

## 4. Configuración y separación de entornos

`Config::load()` lee `config/local.php` o el archivo indicado por **`AIBID_CONFIG`**. Este nombre técnico se conserva deliberadamente. Las variables de proceso con el mismo nombre prevalecen sobre el archivo. El pool de ejemplo usa `clear_env = yes` y expone únicamente la ruta `AIBID_CONFIG`; revisar que no haya overrides `APP_ENV=testing/development` en pools, contenedores, servicios o tareas CLI.

| Variable | Producción | Desarrollo/pruebas |
| --- | --- | --- |
| `APP_ENV` | `production`; predeterminado si falta | `development` o `testing`, explícitos |
| `APP_URL` | `https://licensing.aidex.com.mx` | Ejemplo `http://127.0.0.1:8088` |
| `DB_DSN` | MySQL privado, base exclusiva, `charset=utf8mb4` | Base local separada; tests crean bases aleatorias |
| `DB_USER`, `DB_PASSWORD` | Cuenta limitada, contraseña propia | Credenciales locales separadas |
| `MFA_KEY` | Base64 estándar de 32 bytes aleatorios | Clave independiente local |
| `CREDENTIAL_KEY` | Igual; cifrado/búsqueda de credenciales y evidencia offline | Independiente local |
| `AUDIT_KEY` | Igual; cadena de auditoría | Independiente local |
| `SESSION_KEY` | Igual; CSRF y controles de tasa | Independiente local |
| `OPERATION_KEY` | Igual; idempotencia | Independiente local |
| `SIGNING_KEY_DIR` | Ruta absoluta persistente fuera de `public/` | Por defecto `var/keys/<APP_ENV>` |
| `ADMIN_NETWORKS` | CIDR IPv4/IPv6 separados por comas; vacío permite cualquier red autenticada | Según entorno |
| `AIBID_CONFIG` | Ruta al PHP privado de producción | Ruta al archivo local o de fixture |

No hay variables implementadas `APP_DEBUG`, SMTP, CORS, proxies confiables ni un flag servidor para omitir autenticación. No inventarlas. Las sesiones se almacenan en MySQL (`admin_sessions`), no mediante `session_start()` ni archivos PHP de sesión; los parámetros `session.*` de php.ini no controlan esta cookie. No hay envío de correo ni enlaces de recuperación por correo: los correos son identificadores/datos de contacto. La recuperación administrativa existe por CLI con acceso al servidor y queda auditada.

`APP_URL` solo admite un origen HTTP/HTTPS sin credenciales, query, fragmento o subdirectorio. Producción exige HTTPS. HTTP de desarrollo exige host loopback y dirección remota loopback; nunca sirve como excepción para acceso público. El puerto del origen local debe coincidir con el del navegador para que los formularios superen la validación de Origin. No se obtiene el origen de `Host` ni se aceptan encabezados `X-Forwarded-*` como prueba de HTTPS.

El servidor oculta errores al navegador en todos los entornos y conserva clase, archivo/línea y UUID en logs, sin mensajes PDO, cuerpos, contraseñas ni claves. Para depurar localmente usar los logs y PHPUnit; no es necesario habilitar errores públicos. FPM de producción fija también `display_startup_errors=off`, `log_errors=on`, `expose_php=off` y `zend.exception_ignore_args=on`. Los errores ocurridos antes de ejecutar el front controller dependen de esa configuración FPM, por eso también debe aplicarse.

Cookies: producción `__Host-license_admin`, `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, sin `Domain`; desarrollo `aibid_admin_dev`, sin `Secure` solo en HTTP loopback. Mantener CSP, no-store, HSTS en HTTPS, controles de rol/MFA, CSRF/Origin, límites de tamaño y tasa. No habilitar CORS: el panel usa su propio origen y el cliente de escritorio no necesita permisos CORS de un navegador. MFA, hashes, firmas y comprobaciones de licencia se mantienen idénticos entre entornos.

## 5. Base de datos, administrador y firma

En una base **nueva vacía** crear la cuenta runtime y otra cuenta de migración, con contraseñas aleatorias propias. La receta Ubuntu usa `IDENTIFIED BY RANDOM PASSWORD` y cuenta MySQL `localhost` con socket; no mezclar esa cuenta con un DSN remoto sin adaptar host y permisos. La cuenta runtime nunca usa root ni los permisos DDL de la cuenta de migración.

Después de crear carpetas/usuarios con la receta detallada, desde `current/`:

```sh
sudo -u aidex php bin/configure.php --env=production --url=https://licensing.aidex.com.mx --dsn='mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=aidex_licenses;charset=utf8mb4' --user=aidex_app --output=/srv/aidexlicense/shared/config.php --signing-dir=/srv/aidexlicense/shared/keys
sudo -u aidex env AIBID_CONFIG=/srv/aidexlicense/shared/config.php php bin/migrate.php --user=aidex_migrate
php bin/grants.php --database=aidex_licenses --user=aidex_app > /tmp/aidex-runtime-grants.sql
sudo mysql < /tmp/aidex-runtime-grants.sql
sudo -u aidex env AIBID_CONFIG=/srv/aidexlicense/shared/config.php php bin/preflight.php
sudo -u aidex env AIBID_CONFIG=/srv/aidexlicense/shared/config.php php bin/bootstrap-admin.php --login=administrador@example.com --name='Administración AIDEX'
```

Completar el correo real antes de ejecutar bootstrap. Contraseñas se solicitan sin eco en una terminal, nunca en argumentos. `configure` crea cinco claves **solo para una configuración nueva** y rechaza sobrescribir un archivo existente. Estas claves deben conservarse durante actualizaciones/restauraciones. El ejemplo `config/local.example.php` no tiene secretos válidos y no arranca hasta configurarlos. `bootstrap-admin` no tiene contraseña predeterminada, pide una elegida por el operador y solo funciona si no hay administradores. Nunca importar fixtures, `prepare-panel.php` ni bases `aibid_test_*` en producción.

Aplicar migraciones 001–005 en orden con el migrador. 001–004 y sus checksums permanecen intactos. 005 cambia solo la presentación del catálogo si coincide exactamente con el nombre/descripción original anterior; no altera estructura SQL, capacidades, licencias, revisiones ni `product_id=gestor_documental`. Si el producto tiene un nombre personalizado, revisar su presentación mediante el panel después de iniciar sesión: 005 no lo sobrescribe. No actualizar SQL histórico ni editar checksums a mano.

Preparar una clave Ed25519 **de producción** nueva solo si ese entorno todavía no tiene firmante:

```sh
sudo -u aidex env AIBID_CONFIG=/srv/aidexlicense/shared/config.php php bin/signing-key.php --action=generate --kid=lic-prod-2026-a --purpose=license --user=aidex_migrate
sudo -u aidex env AIBID_CONFIG=/srv/aidexlicense/shared/config.php php bin/signing-key.php --action=list
```

Elegir un `kid` único. Conservar privada en el directorio 0700, archivo 0600. **No regenerar ni sustituir claves existentes.** Distribuir únicamente pública base64url, `kid`, `environment=production` y `purpose=license` por canal autenticado al responsable de la distribución cliente. El dominio y su conjunto de confianza se prepararán en el trabajo del cliente; no cambiar aquí su repositorio. La primera versión comercial será 1.0; no introducir ese número como versión del protocolo ni reescribir versiones de licencia/JWS.

Solo después de confirmar confianza en el cliente:

```sh
sudo -u aidex env AIBID_CONFIG=/srv/aidexlicense/shared/config.php php bin/signing-key.php --action=activate --kid=lic-prod-2026-a --reason='Pública incorporada y verificada en la distribución AIDEX' --trust-confirmed --user=aidex_migrate
sudo -u aidex env AIBID_CONFIG=/srv/aidexlicense/shared/config.php php bin/preflight.php --require-signer
```

El firmante verifica entorno, propósito, metadatos, par pública/privada y permisos; rechaza claves deterministas públicas de fixtures en producción. La API no tiene `/v1/keys` ni endpoint de diagnóstico. Rotación y respaldo existentes se detallan en [OPERATIONS.md](OPERATIONS.md).

## 6. DNS, TLS y servidor web

En el proveedor que administra **la zona aidex.com.mx**, crear/actualizar A para el nombre `licensing` con la IP IPv4 pública del servidor elegido. Publicar AAAA solo si IPv6 llega al mismo sitio con TLS correcto; añadir listeners IPv6 al servidor web. Un CNAME puede usarse si el proveedor/infraestructura lo requiere, sin registros A/AAAA incompatibles en ese nombre. Verificar resolución desde fuera del servidor antes de pedir el certificado. Esta guía no modifica DNS.

### Alternativa Nginx + FPM

Usar `deploy/nginx-http.conf.example`, `deploy/nginx.conf.example` y `deploy/php-fpm-pool.conf.example`. Ya contienen dominio final, raíz `/srv/aidexlicense/current/public`, socket `/run/php/aidexlicense.sock` y rutas TLS. Adaptar las rutas si eliges otras. El dominio solo es fijo en configuración y ejemplos de infraestructura; el runtime PHP usa `APP_URL`.

El procedimiento de emisión/renovación Certbot webroot, validación `nginx -t`, instalación del pool y recarga se encuentra en las secciones 6–7 de la receta Ubuntu. No habilitar TLS antes de tener certificado/privada para el dominio. Conservar el bloque HTTP para ACME y redirección **308** a HTTPS. No utilizar `curl -k` ni certificados autofirmados en producción.

Solo se sirve `public/`: `/assets/` estático y demás rutas al `index.php` interno. Bloquear dotfiles y solicitudes PHP directas. JSON de API máximo 16 KiB; archivo `.licreq` máximo 64 KiB, formulario multipart máximo 96 KiB. Nginx conserva JSON 413/503 y los errores de PHP; FPM recibe `HTTPS=on` solo desde el bloque TLS. Referencias: [FastCGI de Nginx](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html), [configuración PHP-FPM](https://www.php.net/manual/en/install.fpm.configuration.php).

### Alternativa Apache

Apache 2.4 con `mod_ssl`, `mod_proxy_fcgi`, front controller y un pool PHP-FPM equivalente puede servir este código. Configurar `DocumentRoot` en `current/public`, desactivar índices y `AllowOverride`, permitir únicamente recursos estáticos y el front controller, reescribir rutas administrativas/API conservando `REQUEST_URI`, pasar HTTPS real desde TLS, denegar dotfiles/PHP arbitrario y mantener límites/errores JSON de `/v1/`. Redirigir HTTP a HTTPS y configurar certificado/renovación del dominio. El `.htaccess` de la raíz **deniega todo** como protección de instalación accidental; no quitarlo ni publicar la raíz del repositorio. El repositorio no incluye una plantilla Apache de producción ni afirma haberla probado: adaptar y validar según [Apache mod_proxy_fcgi](https://httpd.apache.org/docs/2.4/mod/mod_proxy_fcgi.html), o elegir la receta Nginx ya preparada.

### Proxy adicional o balanceador

Las plantillas suponen TLS en el propio Nginx. La aplicación ignora `X-Forwarded-Proto` y `X-Forwarded-For`. Si usas otro proxy, conservar TLS hacia Nginx o diseñar la traducción confiable exclusivamente en infraestructura, con backend inaccesible directamente y lista estricta de proxies permitidos. No fijar `HTTPS=on` en un backend HTTP accesible públicamente. `ADMIN_NETWORKS` y rate limits usan `REMOTE_ADDR`; traducir la IP únicamente desde infraestructura confiable y probarla antes de activar una restricción por red. No existe una variable PHP `TRUSTED_PROXIES` implementada.

## 7. Verificación antes de admitir clientes

Ejecutar preflight y las secciones de verificación de la receta. Las pruebas automatizadas se ejecutan en una **base de pruebas separada**, jamás sobre la base comercial. Si se necesita un flujo completo sobre el despliegue final, crear por el panel una licencia de aceptación identificada como prueba y una instalación dedicada, autorizadas por el operador; no usar datos de clientes.

- [ ] DNS A/AAAA correcto, acceso TLS y certificado válido para `licensing.aidex.com.mx`; renovación ensayada.
- [ ] HTTP redirige a HTTPS; no se entrega el panel por HTTP. Host público y `APP_URL` coinciden exactamente.
- [ ] `preflight.php --require-signer` pasa con la cuenta runtime y permisos limitados.
- [ ] `APP_ENV=production`, pool con errores/startup/debug ocultos, entorno limpio y logs privados legibles por el operador.
- [ ] Inicio de sesión con contraseña personal, enrolamiento y verificación TOTP, códigos de recuperación custodiados; sin cuentas sintéticas.
- [ ] Cookie `__Host-license_admin` con Secure/HttpOnly/Lax/Path=/ y sin Domain; no `aibid_admin_dev`. Rechazo de CSRF/Origin externos y acceso sin login.
- [ ] Cuatro SVG originales cargan en fondos correspondientes; favicon SVG, títulos, catálogo, footer y MFA muestran AIDEX. Diseño y archivos SVG preservados.
- [ ] `/.env`, `/config/local.php`, `/vendor/`, privadas, logs y PHP arbitrario no se sirven. No hay `phpinfo()` ni instalador web.
- [ ] `/v1/activations/challenge` recibe POST JSON; cuerpo `{}` devuelve **400 INVALID_REQUEST**, sin cookie administrativa. Durante mantenimiento devuelve **503 TEMPORARY_UNAVAILABLE** con `request_id`.
- [ ] Activar → refresh → desactivar → activar otra instalación; segunda plaza simultánea rechazada, reintentos idempotentes.
- [ ] Licencia perpetua, suscripción, fechas UTC, tolerancia de 15 días, módulos/dependencias y mantenimiento conservan derechos previstos.
- [ ] Importar/aprobar `.licreq` y descargar/importar `.lic`; validar firma Ed25519, `kid`, entorno, instalación y revisiones con el cliente cuya pública ya está distribuida.
- [ ] Permisos viewer/operator/superadmin y reautenticación de operaciones sensibles funcionan; auditoría verificable.
- [ ] Respaldo cifrado creado, clave externa separada y restauración ensayada en destino vacío, con anclaje externo reciente.
- [ ] Logs conservan referencias útiles sin cuerpos, SQL, contraseñas, claves comerciales, tokens o privadas; 5xx no revelan detalles técnicos.

Comprobaciones HTTP de lectura/entrada inválida, después de quitar mantenimiento cuando corresponda:

```sh
curl -I http://licensing.aidex.com.mx/login
curl -sS -D - -o /dev/null https://licensing.aidex.com.mx/login
curl -i https://licensing.aidex.com.mx/v1/activations/challenge -H 'Content-Type: application/json' --data '{}'
curl -i https://licensing.aidex.com.mx/.env
curl -i https://licensing.aidex.com.mx/index.php
```

`curl -I` al origen HTTP debe mostrar 308 y Location HTTPS. Usar GET, como en la segunda línea, para el panel: el kernel solo acepta GET/POST. Los dos accesos a archivos deben rechazarse. No interpretar el 400 de JSON vacío como falla de servicio. La prueba del cliente comercial con dominio final y confianza definitiva queda pendiente del despliegue y del trabajo independiente del cliente.

## 8. Mantenimiento, actualización y restauración

Existen CLI reales: `cleanup.php`, `audit-verify.php`, `backup.php`, `recovery-config.php`, `restore-empty.php`, `recovery-check.php` y `recover-admin.php`. No existe un servicio SMTP, restauración por panel ni sincronización remota automática.

Los ejemplos `deploy/aidex-maintenance.sh`, `.service` y `.timer` ejecutan limpieza, anclaje de auditoría y copia cifrada diaria, con `flock` y directorios privados. Instalar siguiendo la receta Ubuntu; comprobar que `php`, `mysql` y `mysqldump` de su PATH son las versiones correctas. Una copia diaria implica hasta 24 horas potenciales de pérdida; binlogs/PITR requieren configuración y ensayo independientes, no se entregan implementados.

La extensión `.aibidbackup` y el formato interno `AIBID-BACKUP-V1` permanecen técnicos; no son marca pública. Respaldar juntos SQL, configuración y cinco claves, privadas del entorno y manifiesto. Conservar `recovery.key` fuera del servidor en un lugar separado de las copias. Transferir manualmente copias/anclajes a almacenamiento externo protegido: la tarea incluida solo crea archivos locales. No borrar privadas de firma ni regenerar claves para restaurar.

Actualización: preparar nueva release con `composer install --no-dev`; activar `maintenance.flag`, detener timer, cerrar CLI de emisión, esperar peticiones en curso y generar copia previa. Ejecutar migraciones desde el código nuevo con configuración persistente y aplicar grants. Verificar preflight/firma/auditoría, cambiar `current` al release verificado, **recargar FPM** (OPcache no revisa timestamps), validar el servidor web si cambió y probar antes de retirar mantenimiento/reactivar timer. Mantener release anterior; no volver a código incompatible con el esquema ni restaurar una copia vieja sobre producción sin conciliación.

Restauración: seguir sección 9 de [DEPLOY_UBUNTU_24_04.md](DEPLOY_UBUNTU_24_04.md) con nueva base/cuenta temporal y directorio privado vacío. `backup --action=extract` verifica integridad; `recovery-config` preserva claves; `restore-empty` rechaza cualquier tabla existente. No ejecutar migraciones antes de importar. Si la copia proviene de código anterior a 005, después de importar aplicar migraciones con su cuenta de migración y el código actual **antes de preflight**; 001–004 no cambian. Ejecutar `recovery-check` y `audit-verify --against` con el anclaje externo más reciente. Si falta historia posterior, mantener mantenimiento y conciliar: las firmas válidas de una copia antigua no demuestran continuidad. No probar recuperación sobre una base activa.

Diagnóstico operativo:

| Síntoma | Comprobación |
| --- | --- |
| 503 | mantenimiento, socket FPM, configuración, log PHP privado y UUID de respuesta |
| Panel rechaza HTTPS | TLS debe llegar al bloque correcto; no solucionar con encabezado de navegador |
| Formulario 403 | origen/puerto APP_URL, cookie Secure y CSRF; no desactivar validaciones |
| MySQL falla | motor 8, socket/host, cuenta correcta y grants; no exponer contraseña en comandos |
| Firmante no disponible | entorno, purpose, directorio persistente, permisos 0600/0700 y pública distribuida |
| TOTP rechazado | NTP y código vigente/no reutilizado; recuperación por CLI autorizada, no bypass |
| Cambio de código no visible | enlace `current` y recarga del servicio FPM para OPcache |
| Restauración/anclaje falla | revisar copia e historia faltante; no editar hashes ni contadores |

Revisar periódicamente `journalctl` de FPM/timer, log PHP privado, logs del servidor web, espacio y permisos, caducidad TLS, fallos de respaldo y antigüedad de copias externas. Definir rotación de logs y retención fuera de los scripts incluidos. La receta Ubuntu contiene los comandos reales de servicios y verificación.

## 9. Referencias técnicas conservadas

El namespace/autoload `Aibid\\`, variable `AIBID_CONFIG`, cookie local `aibid_admin_dev`, prefijos temporales/locks, magic de respaldo, HKDF `aibid/offline-evidence/v1`, semillas públicas de pruebas y bytes del contrato permanecen estables para preservar código, evidencia cifrada, respaldo y verificación del cliente. `002_aibid_catalog.sql` conserva nombre/contenido por checksum histórico y 005 corrige su presentación. El prompt antiguo es histórico y no se empaqueta. Las coincidencias completas y pruebas de esta transición se documentan en [INFORME-TRANSICION-AIDEX.md](INFORME-TRANSICION-AIDEX.md).
