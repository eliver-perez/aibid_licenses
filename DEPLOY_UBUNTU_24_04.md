# AIBID: instalación en aibid.adariel.com

Entrega de etapa 5, 26 de septiembre de 2026. Las pruebas se hicieron localmente; estos comandos los ejecutará el operador en su VPS Ubuntu 24.04 con Nginx. No se accedió al VPS ni se comprobaron su DNS, certificados o servicios. El servidor requiere PHP 8.3+ y **MySQL 8**, no MariaDB. Go y Node no se instalan en el VPS.

Se usa `/srv/aibidlicense/current` para el código, `shared/` para configuración/claves persistentes y `backup/` para copias privadas. No instalar sobre el directorio de otro sitio. Los comandos de instalación inicial no se deben repetir sobre una instalación con datos; para actualizar, usar la sección 10.

## 1. Preparar y copiar el paquete

Desde el proyecto en la Mac, con el PHP moderno y Composer de desarrollo disponibles:

```sh
mkdir -p var/releases
php bin/build-release.php --output="$PWD/var/releases/aibidlicense-stage5.tar.gz"
```

La entrega incluye `var/releases/aibidlicense-stage5.tar.gz` y su `.sha256`. El generador falla si el destino existe; para reconstruir, usar otro nombre. Solo empaqueta código, recursos, documentación y `composer.lock`: excluye `config/local.php`, privadas, `var/`, dependencias y pruebas. `RELEASE.json` registra hashes de cada archivo. Subir el paquete y su checksum a `/home/eliver/` mediante el método SSH habitual; reemplazar la IP por la real. No se necesita dar acceso SSH a esta sesión.

En el VPS, como `eliver` con `sudo`:

```sh
cd /home/eliver
sha256sum -c aibidlicense-stage5.tar.gz.sha256
mkdir aibidlicense-stage5-upload
tar -xzf aibidlicense-stage5.tar.gz -C aibidlicense-stage5-upload
```

## 2. Paquetes, DNS y reloj

El registro A de `aibid.adariel.com` debe apuntar al VPS. Si publicas AAAA, configura también IPv6 y añade los correspondientes `listen [::]:80`/`listen [::]:443 ssl` a los bloques. Estos ejemplos escuchan IPv4. Permitir TCP 80/443 en el firewall local y del proveedor conservando el acceso SSH. No abrir MySQL a Internet.

```sh
sudo apt update
sudo apt install nginx mysql-server mysql-client php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip composer unzip ca-certificates certbot
php8.3 -v
mysql --version
sudo systemctl enable --now mysql php8.3-fpm nginx
timedatectl status
```

Comprobar sincronización NTP activa; TOTP, desafíos y vencimientos dependen del reloj. Si falta, configurar el servicio de sincronización de Ubuntu que utilice el VPS. Conservar los sitios y pools existentes. Los paquetes PHP 8.3 de Noble están en los [repositorios de Ubuntu](https://packages.ubuntu.com/search?keywords=php8.3-fpm); verificar la versión instalada con los comandos y con preflight. Las pruebas locales usaron PHP/FPM 8.5.7 y MySQL 8.4.11; PHP 8.3/MySQL 8.0 del VPS requieren su comprobación final allí.

## 3. Dependencias y permisos

```sh
cd /home/eliver/aibidlicense-stage5-upload/aibidlicense
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --no-plugins
composer check-platform-reqs --no-dev
sudo adduser --system --group --home /srv/aibidlicense --no-create-home aibid
sudo install -d -o root -g root -m 0755 /srv/aibidlicense /srv/aibidlicense/releases
sudo mv /home/eliver/aibidlicense-stage5-upload/aibidlicense /srv/aibidlicense/releases/stage5
sudo chown -R root:root /srv/aibidlicense/releases/stage5
sudo chmod -R u=rwX,go=rX /srv/aibidlicense/releases/stage5
sudo ln -s /srv/aibidlicense/releases/stage5 /srv/aibidlicense/current
sudo install -d -o aibid -g aibid -m 0700 /srv/aibidlicense/shared
sudo install -d -o aibid -g aibid -m 0700 /srv/aibidlicense/shared/keys /srv/aibidlicense/shared/log /srv/aibidlicense/shared/tmp
sudo install -d -o root -g root -m 0700 /srv/aibidlicense/backup /srv/aibidlicense/backup/archives
cd /srv/aibidlicense/current
```

El código será de solo lectura para FPM. El usuario `aibid` es exclusivo de este pool y puede escribir en sus carpetas privadas. No usar permisos 777 ni ejecutar Composer como root.

## 4. Base MySQL, configuración y migraciones

Abrir `sudo mysql`. Confirmar `SELECT VERSION();` devuelve MySQL 8. En una instalación inicial:

```sql
CREATE DATABASE aibid_licenses CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aibid_migrate'@'localhost' IDENTIFIED BY RANDOM PASSWORD;
CREATE USER 'aibid_app'@'localhost' IDENTIFIED BY RANDOM PASSWORD;
GRANT ALL PRIVILEGES ON aibid_licenses.* TO 'aibid_migrate'@'localhost';
```

Guardar las dos contraseñas que imprime MySQL en el gestor de secretos. La cuenta de migración solo administra esta base; sus credenciales no se guardan en PHP-FPM. Usamos socket para que el DSN corresponda a las cuentas `localhost`.

```sh
sudo -u aibid php8.3 bin/configure.php --env=production --url=https://aibid.adariel.com --dsn='mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=aibid_licenses;charset=utf8mb4' --user=aibid_app --output=/srv/aibidlicense/shared/config.php --signing-dir=/srv/aibidlicense/shared/keys
sudo chown root:root /srv/aibidlicense/shared
sudo chmod 0755 /srv/aibidlicense/shared
sudo touch /srv/aibidlicense/shared/maintenance.flag
sudo -u aibid env AIBID_CONFIG=/srv/aibidlicense/shared/config.php php8.3 bin/migrate.php --user=aibid_migrate
php8.3 bin/grants.php --database=aibid_licenses --user=aibid_app > /home/eliver/aibid-runtime-grants.sql
sudo mysql < /home/eliver/aibid-runtime-grants.sql
sudo -u aibid env AIBID_CONFIG=/srv/aibidlicense/shared/config.php php8.3 bin/preflight.php
```

Las contraseñas se introducen sin eco en una terminal. Se crean cinco claves independientes en `shared/config.php`, con permisos 0600; conservarlas en toda actualización. `shared/` pasa a ser propiedad de root y permite a Nginx comprobar el indicador de mantenimiento; `config.php`, claves, temporales y logs permanecen privados. Las migraciones 001–004 se aplican en orden y mantienen su checksum; no hay migración 005.

Si deseas restringir el panel por red, editar `ADMIN_NETWORKS` dentro de `shared/config.php` con CIDR separados por comas. Vacío requiere igualmente autenticación/MFA, pero permite conexiones desde cualquier red. No configurar encabezados de proxy como sustituto de HTTPS; estos ejemplos suponen Nginx directamente expuesto.

## 5. Administrador y confianza del cliente

```sh
sudo -u aibid env AIBID_CONFIG=/srv/aibidlicense/shared/config.php php8.3 bin/bootstrap-admin.php --login=administrador@example.com --name='Administración AIBID'
sudo -u aibid env AIBID_CONFIG=/srv/aibidlicense/shared/config.php php8.3 bin/signing-key.php --action=generate --kid=lic-prod-2026-a --purpose=license --user=aibid_migrate
sudo -u aibid env AIBID_CONFIG=/srv/aibidlicense/shared/config.php php8.3 bin/signing-key.php --action=list
```

Reemplazar el login por el administrador real. Bootstrap pide una contraseña nueva dos veces y solo funciona en una base sin administradores. La CLI de firma crea una privada aleatoria y muestra únicamente la pública y sus metadatos; aún no la activa.

Incorporar la pública mostrada al conjunto de confianza de la distribución del cliente AIBID por su canal autenticado. En la configuración del cliente real comprobado:

```json
{
  "license": {
    "server_url": "https://aibid.adariel.com",
    "trusted_keys": [
      {"kid":"lic-prod-2026-a","public_key":"REEMPLAZAR_POR_LA_PUBLICA_BASE64URL","environment":"production","purpose":"license"}
    ],
    "refresh_hours": 24,
    "development_bypass": false
  }
}
```

Este fragmento se integra en la configuración existente del cliente; no reemplaza el resto del archivo ni las públicas antiguas. `public_key` son 32 bytes Ed25519 en base64url sin `=`. El cliente de producción rechaza claves de prueba y CA/bypass de desarrollo. Nunca distribuir privadas, aceptar una pública porque acompaña a un `.lic`, ni inventar una ruta pública `/v1/keys`.

Una vez comprobada la distribución:

```sh
sudo -u aibid env AIBID_CONFIG=/srv/aibidlicense/shared/config.php php8.3 bin/signing-key.php --action=activate --kid=lic-prod-2026-a --reason='Pública incorporada y verificada en la distribución AIBID' --trust-confirmed --user=aibid_migrate
sudo -u aibid env AIBID_CONFIG=/srv/aibidlicense/shared/config.php php8.3 bin/preflight.php --require-signer
```

Para rotar, generar otro `kid`, distribuir la nueva pública **junto con las antiguas**, verificarla y solo entonces activarla. Las firmas antiguas siguen verificándose; no borrar privadas registradas que deban entrar en el respaldo.

## 6. PHP-FPM y certificado HTTPS

```sh
sudo install -m 0644 deploy/php-fpm-pool.conf.example /etc/php/8.3/fpm/pool.d/aibidlicense.conf
sudo php-fpm8.3 -t
sudo systemctl reload php8.3-fpm
sudo install -d -o root -g root -m 0755 /var/www/letsencrypt
sudo install -m 0644 deploy/nginx-http.conf.example /etc/nginx/sites-available/aibidlicense-http
sudo ln -s /etc/nginx/sites-available/aibidlicense-http /etc/nginx/sites-enabled/aibidlicense-http
sudo nginx -t
sudo systemctl reload nginx
sudo certbot certonly --webroot -w /var/www/letsencrypt -d aibid.adariel.com
```

El bloque HTTP sirve únicamente desafíos ACME y redirige al dominio HTTPS. Mantenerlo para renovaciones. Certbot solicita el correo de contacto y sus condiciones; no cambiar los otros sitios de Nginx. El modo webroot requiere DNS correcto y puerto 80 accesible ([documentación de Ubuntu](https://ubuntu.com/server/docs/how-to/security/obtain-tls-certificates/)).

```sh
sed 's/licencias\.example\.com/aibid.adariel.com/g' deploy/nginx.conf.example > /home/eliver/aibidlicense-https.conf
sudo install -m 0644 /home/eliver/aibidlicense-https.conf /etc/nginx/sites-available/aibidlicense-https
sudo ln -s /etc/nginx/sites-available/aibidlicense-https /etc/nginx/sites-enabled/aibidlicense-https
sudo nginx -t
sudo systemctl reload nginx
sudo install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy
sudo sh -c 'printf "#!/bin/sh\nnginx -t && systemctl reload nginx\n" > /etc/letsencrypt/renewal-hooks/deploy/aibid-nginx'
sudo chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/aibid-nginx
sudo systemctl enable --now certbot.timer
sudo certbot renew --dry-run
```

No habilitar el bloque HTTPS antes de tener sus archivos de certificado. Su `map` va dentro del contexto `http` de Nginx, como ocurre con `sites-enabled` en Ubuntu. Publica solo `current/public`, limita JSON a 16 KiB y multipart a 96 KiB, sirve 413/503 del proxy como JSON para `/v1/` y preserva los errores JSON de PHP con `fastcgi_intercept_errors off`. PHP admite un `.licreq` de 64 KiB. El pool limpia el entorno y carga solo el `AIBID_CONFIG` persistente. Ver [Nginx/FastCGI](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_intercept_errors) y [configuración FPM](https://www.php.net/manual/en/install.fpm.configuration.php).

## 7. Respaldo y tarea diaria

```sh
sudo php8.3 bin/backup.php --action=keygen --key-file=/srv/aibidlicense/backup/recovery.key
sudo install -o root -g root -m 0750 deploy/aibid-maintenance.sh /usr/local/sbin/aibid-maintenance
sudo install -o root -g root -m 0644 deploy/aibid-maintenance.service /etc/systemd/system/aibid-maintenance.service
sudo install -o root -g root -m 0644 deploy/aibid-maintenance.timer /etc/systemd/system/aibid-maintenance.timer
sudo systemctl daemon-reload
sudo systemctl start aibid-maintenance.service
sudo systemctl status aibid-maintenance.service --no-pager
sudo systemctl enable --now aibid-maintenance.timer
sudo systemctl list-timers aibid-maintenance.timer
```

El script usa `/usr/bin/php` mediante PATH de systemd; confirmar que es PHP 8.3+ con las mismas extensiones. Si hay varias versiones, cambiar las tres invocaciones a `php8.3` en el script instalado. Ejecuta cleanup, exporta un anclaje y crea copia cifrada diaria a las 03:15 UTC, con exclusión `flock`. El proceso root puede leer la clave de recuperación; PHP-FPM no puede atravesar `backup/`. Las credenciales SQL usadas son las de aplicación, con SELECT suficiente para el dump configurado. Se puede usar `backup.php --mysql-options=/ruta/archivo0600` para una cuenta de respaldo con SELECT sobre esta base; los secretos nunca se pasan en argumentos.

El archivo `.aibidbackup` contiene SQL consistente, configuración con las cinco claves, privadas registradas del entorno y manifiesto de hashes/anclaje. Usa [Sodium secretstream](https://doc.libsodium.org/secret-key_cryptography/secretstream), autenticación por bloques y cierre final obligatorio. `mysqldump --single-transaction` obtiene la copia InnoDB; la aplicación mantiene bloqueados los metadatos de firma y la cabecera de auditoría durante la copia para coordinar historia y claves. No ejecutar DDL/migraciones en paralelo. En una base grande, este bloqueo puede retrasar operaciones: medir su duración ([referencia MySQL](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html)).

Conservar **otra copia de `recovery.key` fuera del VPS**, separada de las copias cifradas. Copiar `.aibidbackup`, `.receipt.json` y `.anchor.json` a almacenamiento externo protegido, bajo una credencial que no pueda borrar la retención histórica. La entrega genera estos archivos localmente; no configura un proveedor externo ni acredita que ya estén fuera del servidor. Un anclaje guardado únicamente junto a la BD no detecta una restauración conjunta antigua.

La tarea no elimina respaldos: revisar espacio y definir retención después de verificar copias externas (punto de partida: 30 diarias y 12 mensuales). Una interrupción abrupta puede dejar staging `.aibid-backup-*` con datos sin cifrar, protegido 0700 dentro de `backup/archives`; revisar después de un fallo y limpiar solo el staging de ese intento tras investigar. En caso de recuperación, la extracción también contiene secretos y debe retirarse de forma controlada cuando deje de necesitarse.

La copia diaria tiene hasta **24 horas de pérdida potencial**. El objetivo propuesto de 15 minutos requiere archivar binlogs de forma independiente y ensayar recuperación a un punto en el tiempo; eso no está configurado por este paquete. RTO de cuatro horas es un objetivo por medir, no una garantía.

## 8. Verificación antes de habilitar

Con mantenimiento activo:

```sh
curl -i https://aibid.adariel.com/v1/activations/challenge -H 'Content-Type: application/json' --data '{}'
sudo -u aibid env AIBID_CONFIG=/srv/aibidlicense/shared/config.php php8.3 bin/preflight.php --require-signer
sudo -u aibid env AIBID_CONFIG=/srv/aibidlicense/shared/config.php php8.3 bin/recovery-check.php
sudo -u aibid env AIBID_CONFIG=/srv/aibidlicense/shared/config.php php8.3 bin/audit-verify.php
```

La primera llamada debe devolver 503 JSON con `TEMPORARY_UNAVAILABLE` y UUID de correlación. Ensayar extracción/restauración según la sección 9 en otra base vacía, y comprobar que el respaldo inicial y su clave ya tienen copia externa. Después:

```sh
sudo rm /srv/aibidlicense/shared/maintenance.flag
curl -sS -D - -o /dev/null https://aibid.adariel.com/login
curl -i https://aibid.adariel.com/v1/activations/challenge -H 'Content-Type: application/json' --data '{}'
curl -i https://aibid.adariel.com/.env
curl -i https://aibid.adariel.com/index.php
```

`/login` debe cargar el panel; el POST vacío de API debe devolver 400 JSON sin cookie administrativa. Los dos archivos directos se rechazan (403/404). Comprobar cookie `__Host-…` con Secure, HttpOnly y SameSite=Lax, HSTS y certificado válido en el navegador. Entrar con el administrador y enrolar TOTP; guardar los códigos de recuperación una sola vez. No publicar `phpinfo()`.

Con una licencia e instalación de prueba de producción, verificar activar → reiniciar cliente → refresh → desactivar → activar otra instalación; después importar/aprobar `.licreq` y cargar su `.lic`. No usar claves deterministas de los tests. Confirmar rechazo de una segunda plaza simultánea. La retirada offline entrega JWS `revoked` y conserva la licencia comercial `issued`; la respuesta online mantiene `status:deactivated`.

El cliente actual acepta la revisión terminal y restringe escritura; su indicador `offline_deactivation_pending` permanece marcado tras importar esa revisión. Es una particularidad de presentación/estado local del cliente observado, no una plaza ocupada en el servidor; el repositorio del cliente no fue modificado en esta etapa.

## 9. Restaurar sin sobrescribir la base original

En un ensayo, mantener el destino sin tráfico. En un incidente real, crear `shared/maintenance.flag`, detener `aibid-maintenance.timer`, cerrar procesos/CLI que emiten o cambian licencias y dejar terminar solicitudes en curso antes de restaurar. El indicador bloquea HTTP, no otros comandos CLI. No quitar mantenimiento hasta conciliar operaciones posteriores a la copia con anclajes externos/binlogs. Un respaldo antiguo puede contener plazas o contadores antiguos aunque todas sus firmas sean válidas.

Trabajar como root en una sesión privada (`sudo -i`); los nombres de copia de abajo se sustituyen por los reales:

```sh
cd /srv/aibidlicense/current
install -d -m 0700 /srv/aibidlicense/recovery
php8.3 bin/backup.php --action=extract --key-file=/srv/aibidlicense/backup/recovery.key --archive=/srv/aibidlicense/backup/archives/FECHA.aibidbackup --directory=/srv/aibidlicense/recovery/ensayo1
mysql
```

En MySQL crear **otra base** y cuenta temporal de importación. No ejecutar migraciones antes de importar:

```sql
CREATE DATABASE aibid_restore_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aibid_restore'@'localhost' IDENTIFIED BY RANDOM PASSWORD;
GRANT ALL PRIVILEGES ON aibid_restore_check.* TO 'aibid_restore'@'localhost';
```

Guardar la contraseña temporal y salir de MySQL. Generar configuración recuperada preservando las cinco claves:

```sh
php8.3 bin/recovery-config.php --directory=/srv/aibidlicense/recovery/ensayo1 --output=/srv/aibidlicense/recovery/import.php --dsn='mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=aibid_restore_check;charset=utf8mb4' --user=aibid_restore --signing-dir=/srv/aibidlicense/recovery/ensayo1/keys
php8.3 bin/restore-empty.php --directory=/srv/aibidlicense/recovery/ensayo1 --target-config=/srv/aibidlicense/recovery/import.php
php8.3 bin/grants.php --database=aibid_restore_check --user=aibid_app > /srv/aibidlicense/recovery/grants.sql
mysql < /srv/aibidlicense/recovery/grants.sql
php8.3 bin/recovery-config.php --directory=/srv/aibidlicense/recovery/ensayo1 --output=/srv/aibidlicense/recovery/runtime.php --dsn='mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=aibid_restore_check;charset=utf8mb4' --user=aibid_app --signing-dir=/srv/aibidlicense/recovery/ensayo1/keys
env AIBID_CONFIG=/srv/aibidlicense/recovery/runtime.php php8.3 bin/preflight.php --require-signer
env AIBID_CONFIG=/srv/aibidlicense/recovery/runtime.php php8.3 bin/recovery-check.php --against=/RUTA/AL/ULTIMO-ANCLAJE-EXTERNO.json
env AIBID_CONFIG=/srv/aibidlicense/recovery/runtime.php php8.3 bin/audit-verify.php --against=/RUTA/AL/ULTIMO-ANCLAJE-EXTERNO.json
```

En un servidor nuevo, crear también `aibid_app` con contraseña aleatoria antes de sus GRANT. `restore-empty` rechaza cualquier tabla existente y no borra ni trunca tablas. Un error durante la importación puede dejar una copia parcial: conservarla para diagnóstico y crear otro destino vacío para reintentar. `recovery-config` no ejecuta el JSON del respaldo como código y no crea claves nuevas. La configuración de importación tiene permisos SQL amplios y no debe usarse como runtime/FPM.

Los verificadores comprueban auditoría, anclaje, firmas JWS históricas, referencias de activación, contadores globales, una plaza activa y evidencia offline cifrada. Un anclaje posterior al respaldo debe fallar hasta recuperar la historia faltante. Para un ensayo se puede comprobar además el anclaje de la propia copia, pero eso no demuestra continuidad hasta hoy. Conservar también JWS posteriores aportados por clientes para conciliación; no emitir números de revisión inventados ni rehabilitar automáticamente plazas. Si la historia no puede reconstruirse, mantener suspendida la emisión y resolver administrativamente antes de reabrir.

Un ensayo termina sin cambiar `shared/config.php`. Para convertir una recuperación conciliada en producción, con mantenimiento activo: instalar sus claves en una nueva carpeta privada persistente de `shared/` propiedad de `aibid` (0700/0600), generar otra configuración runtime con ese directorio y el DSN recuperado, verificarla de nuevo, sustituir `shared/config.php` conservando propiedad `aibid:aibid`/0600, recargar FPM y probar. No volver a ejecutar configure/bootstrap/generate. Las cuentas MySQL, GRANT, Nginx/TLS, paquetes y timers no forman parte del dump y se preparan con esta guía. Retirar la cuenta de importación y acceso temporal al ensayo al cerrar la recuperación; la herramienta no los borra automáticamente.

## 10. Actualizaciones y operación

Preparar cada versión en `releases/<version>` con sus dependencias y código root/solo lectura. Conservar `shared/` y `backup/`. Crear mantenimiento, detener el timer mientras dure el despliegue, hacer respaldo previo, ejecutar migraciones desde **el nuevo código** con `AIBID_CONFIG` persistente/cuenta de migración y aplicar los GRANT actualizados. Verificar preflight, auditoría y recuperación desde esa versión. Cambiar `current` al directorio aprobado y recargar `php8.3-fpm` (OPcache no revisa timestamps). Ejecutar `nginx -t` si cambió el proxy. Quitar mantenimiento tras las pruebas y reactivar el timer.

Si falla una migración, no restaurar silenciosamente un dump antiguo ni editar su checksum: conciliar el DDL pendiente. Volver a código anterior solo cuando su esquema sea compatible. Conservar la versión anterior para diagnóstico.

```sh
sudo journalctl -u php8.3-fpm -u aibid-maintenance.service --since today
sudo tail -n 100 /srv/aibidlicense/shared/log/php-error.log
sudo nginx -t
sudo systemctl list-timers certbot.timer aibid-maintenance.timer
```

Vigilar fallos de timers, espacio libre, antigüedad de copias/anclajes externos, caducidad TLS, NTP y errores 5xx. Configurar rotación del log PHP privado y los logs Nginx existentes del VPS. No habilitar registro de cuerpos, claves comerciales ni parámetros SQL. `cleanup` elimina datos temporales vencidos, pero conserva historial, decisiones e idempotencia. Los clientes offline conservan derechos según su último JWS: ninguna transferencia garantiza revocación instantánea de un equipo desconectado.
