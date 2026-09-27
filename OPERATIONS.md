# Operación del panel y despliegue previsto

Las etapas 2–4 incluyen panel administrativo, API online, gestión CLI de firmantes y operación offline con transferencias. Se verificaron con MySQL 8.4.11 aislado y PHP 8.5.7 CLI; no se modificaron XAMPP ni sus bases y no se desplegó en un VPS. La integración del cliente real, restauración operativa completa y publicación corresponden a etapa 5. Las secciones 7–9 contienen los pasos ejecutables actuales.

## 1. Entorno observado

Inspección local del 25 de septiembre de 2026:

| Componente | Observado | Implicación |
| --- | --- | --- |
| Directorio inicial | Solo el documento de requisitos; sin repositorio Git | El diseño se agregó como archivos; no hay historial Git creado |
| PHP encontrado en PATH | PHP 8.5.7 CLI, Homebrew | Supera 8.3; disponibles Sodium y PDO MySQL |
| PHP de XAMPP | PHP 8.1.6, con advertencia de carga de `intl` | No cumple el mínimo del proyecto |
| Cliente de BD de XAMPP | MariaDB 10.4.21 | No demuestra presencia de MySQL 8; no es el motor de validación requerido |
| Go | 1.25.5 | Disponible para el verificador independiente de fixtures, no para backend |
| Composer | 2.8.6; dependencias fijadas en composer.lock | OTPHP, QR y PHPUnit instalados para etapa 2 |

La verificación de etapa 2 utilizó una instancia temporal independiente de MySQL 8.4.11 por socket Unix, sin puerto TCP. Cada prueba crea sus propios datos sintéticos. El binario CLI no confirma qué versión atiende Apache/FPM; verificar PHP-FPM 8.3+ en el VPS antes del despliegue. No se reutilizaron bases del gestor documental.

## 2. Topología y configuración

VPS Ubuntu con Nginx expuesto solo por HTTPS, pool PHP-FPM dedicado por socket Unix y MySQL sin puerto público. Raíz web `/srv/aibidlicense/current/public`; versiones en directorios separados y datos persistentes fuera del árbol de releases. Desactivar listado de directorios, denegar dotfiles y servir PHP únicamente mediante el front controller previsto.

Configuración de entorno requerida: nombre de entorno, URL base, DSN/usuario/secreto MySQL, registro de claves por propósito, referencias a secretos, proxies confiables, redes autorizadas del panel, límites de tasa/tamaño y parámetros de sesión. `config/local.example.php` contiene nombres y marcadores; `bin/configure.php` genera la configuración real. Las variables de entorno prevalecen sobre ese archivo y `AIBID_CONFIG` permite indicar una ubicación externa. La configuración de producción no se sirve como archivo web ni se imprime en diagnósticos.

Permisos: código de solo lectura para FPM, acceso de lectura a sus claves protegidas, escritura únicamente a directorios temporales necesarios. MySQL con usuarios distintos para app, migración y respaldo. Cuenta app sin DDL ni permisos de modificar/borrar historia inmutable. Logs, archivos temporales y respaldos fuera de `public/`. Desactivar `display_errors`, ajustar límites de memoria/tiempo y no incluir dumps de entorno en errores.

Los assets Bootstrap/JS se publican localmente con versiones fijadas. Fuentes PHP y JavaScript legibles; minificación opcional con fuente conservada. Ningún daemon PHP propio ni cola es requisito para V1. Limpieza, exportación de auditoría y respaldo se programan con cron/timer y locks para impedir ejecución solapada.

## 3. Secuencia de despliegue

1. Probar el artefacto en entorno aislado con MySQL real y PHP-FPM de la misma familia que producción; ejecutar suites requeridas para la entrega.
2. Preflight: Sodium, PDO MySQL, Argon2id, HTTPS, permisos, zona UTC, sincronización del reloj, espacio y secretos presentes. Comprobar que las claves de fixtures no estén autorizadas.
3. Crear respaldo cifrado y comprobar que el procedimiento de restauración de esa versión fue ensayado.
4. Poner temporalmente en mantenimiento las mutaciones administrativas y API si el cambio lo requiere; devolver 503 uniforme. Las instalaciones mantienen sus derechos locales válidos.
5. Ejecutar migraciones con usuario específico, checksum y exclusión mutua. MySQL DDL no garantiza rollback global: preferir expansión compatible y contracción en una entrega posterior.
6. Publicar release, recargar FPM sin dejar procesos con una configuración de claves incoherente y verificar permisos/secretos.
7. Bootstrap inicial por CLI con entrada segura de contraseña; completar MFA por HTTPS. En actualizaciones, no recrear usuarios.
8. Comprobar login, creación de desafío, consulta administrativa y operación sintética en licencia de prueba del entorno adecuado. No contaminar producción con claves de fixtures.
9. Retirar mantenimiento y monitorizar errores de firma, bloqueos, latencias y auditoría.

Rollback de aplicación solo cuando sea compatible con el esquema y estados ya emitidos. Nunca restaurar una BD vieja como una simple operación de rollback de código: podría resucitar plazas o disminuir revisiones aceptadas por clientes.

## 4. Claves y rotación

Generar claves nuevas fuera del repositorio con herramienta operativa restringida. Registrar pública, `kid`, propósito, entorno y ubicación protegida. Verificar el par mediante un mensaje de prueba interno sin emitir licencias reales.

Rotación normal: registrar clave `staged` → distribuir confianza al cliente por un canal autenticado → verificar interoperabilidad → con transacción sobre el registro de claves cambiar firmante a `signing` y anterior a `verify_only` → preservar públicas antiguas y respaldar material requerido. Los emisores mantienen bloqueo compartido de metadatos hasta confirmar para coordinarse con rotación.

V1 no define endpoint público para obtener claves. Propuesta inicial: el cliente incorpora un conjunto de públicas actuales y siguientes en su distribución autorizada; para equipos offline se usa el mecanismo confiable de entrega del producto. No activar un `kid` que los clientes destino no puedan verificar. Nunca confiar en una pública porque venga junto al `.lic` o por la selección de `app_version` autoafirmada.

Las licencias perpetuas requieren conservar la verificación de firmas antiguas sin vencimiento arbitrario. El compromiso de una privada exige suspender nuevas firmas con ella, preservar evidencia, preparar confianza de reemplazo y coordinar recuperación con clientes. No invalidar indiscriminadamente todas las públicas antiguas en una rotación rutinaria.

## 5. Respaldo y restauración

Objetivos iniciales propuestos: RPO de 15 minutos mediante copias y registro de cambios/binlogs, RTO de 4 horas; ambos se deben medir, no se ofrecen como garantía. Copia completa diaria cifrada fuera del VPS, incrementales/binlogs frecuentes, 30 copias diarias y 12 mensuales como punto de partida. Restricciones de acceso y credenciales de borrado separadas del proceso que sube copias.

Respaldar BD, JWS y solicitudes archivadas, claves privadas necesarias, peppers/digests históricos, claves de MFA/evidencia offline/auditoría y configuración recuperable. Cifrar claves y datos con acceso separado a la clave de recuperación; no dejar todos los secretos junto al mismo archivo cifrado accesible por una sola cuenta comprometida. Versionar formato y procedimiento de restauración.

Ensayo antes de la primera salida y luego trimestral: restaurar en red aislada, validar checksums, abrir copia de claves, verificar JWS históricos y cadena de auditoría, comparar contadores/revisiones y comprobar una sola plaza. Ninguna prueba restaura sobre producción.

Tras un desastre, detener emisión y mutaciones hasta conciliar el punto restaurado con binlogs, historial firmado y anclajes externos. Una pérdida de operaciones posteriores al respaldo puede reabrir plazas cerradas o repetir números de revisión. Si no puede reconstruirse esa historia, marcar las licencias afectadas para recuperación administrativa y mantener la emisión suspendida para ellas; no adivinar su contador ni considerar la BD antigua autoritativa por sí sola. Los clientes que conservan un JWS válido siguen operando según el contrato.

## 6. Observabilidad y runbooks

Métricas sin secretos: latencias por ruta, errores 4xx/5xx, solicitudes rate-limited, conflictos de plaza, deadlocks, pendientes offline, antigüedad del respaldo, última exportación de auditoría y reloj. No etiquetar métricas públicas con UUID, correos, claves o huellas. Diagnósticos operativos fuera de las cuatro rutas del contrato y restringidos a administración/CLI.

| Incidente | Respuesta operativa |
| --- | --- |
| Firma o BD indisponibles | Fallar con 503 y rollback; no emitir JWS provisionales ni afectar derechos locales válidos |
| Respuesta de activación perdida | Reintentar mismo request; recuperar bytes confirmados |
| Activación ocupada | Consultar identidad/historial; transferencia autorizada con motivo, sin borrar registros |
| Equipo averiado o privada perdida | Verificar cliente comercial por canal operativo, transferencia forzada auditada y nueva identidad |
| `.licreq` inválida o alterada | Rechazar sin conceder derechos; registrar digest/código seguro y solicitar regeneración |
| Clave comercial perdida al emitir | Reemitir credencial e invalidar anterior; no revelar el HMAC ni recuperar la original |
| Pérdida de MFA del último administrador | Recuperación CLI restringida, motivo, auditoría externa y revocación de sesiones |
| Desfase del reloj del VPS | Corregir sincronización y revisar desafíos/emisiones afectadas; no extender licencias automáticamente |
| Clave privada comprometida | Detener uso, preservar evidencia y coordinar reemplazo; reconocer límite offline |

El paquete V1 solo puede publicarse cuando incluya también activación/renovación offline y sus pruebas; la disponibilidad previa del panel o endpoints no constituye una V1 terminada.

## 7. Instalación disponible del panel

Preparar MySQL 8 con una base vacía `aibid_licenses` (utf8mb4/InnoDB), una cuenta `aibid_migrate` restringida a esa base con permisos DDL/DML y una cuenta `aibid_app` inicialmente sin permisos. Ambas cuentas deben tener contraseñas aleatorias propias; crearlas con la administración habitual del servidor. No incluir contraseñas reales en argumentos de shell ni en este repositorio.

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php bin/configure.php --env=production --url=https://licencias.example.com --dsn='mysql:host=127.0.0.1;dbname=aibid_licenses;charset=utf8mb4' --user=aibid_app
php bin/migrate.php --user=aibid_migrate
php bin/grants.php --database=aibid_licenses --user=aibid_app
```

`configure` pide el secreto de aplicación SQL y crea cinco claves de 32 bytes independientes. `migrate --user` pide la contraseña de migración y no la persiste. `grants` imprime SQL para la cuenta `'aibid_app'@'localhost'`; aplicar ese SQL con la cuenta administradora de MySQL, antes de iniciar el panel. El DSN y el host de la cuenta deben corresponder al despliegue; el ejemplo asume app y MySQL en el mismo equipo. Las migraciones no conceden permisos globales.

```sh
php bin/bootstrap-admin.php --login=administrador@example.com --name='Administración AIBID'
php bin/preflight.php
php bin/audit-verify.php
```

El bootstrap solo funciona cuando no hay administradores. Las contraseñas se introducen dos veces por una terminal interactiva, sin eco. El primer acceso exige enrolar TOTP y muestra ocho códigos de recuperación una sola vez. No existen credenciales de producción predeterminadas.

Publicar únicamente `public/`. El ejemplo `deploy/nginx.conf.example` contiene TLS y un socket FPM que deben adaptarse al VPS. En producción el panel lee HTTPS de Nginx/FPM directamente, sin confiar en encabezados de proxy arbitrarios. `ADMIN_NETWORKS` puede contener CIDR separados por comas; vacío permite cualquier red que supere la autenticación. Las rutas no soportan instalar el panel en `/aibidlicense/`: usar un dominio/virtual host propio.

En desarrollo se puede usar `--env=development --url=http://127.0.0.1:8088` y `php -S 127.0.0.1:8088 -t public public/router.php`. El servidor integrado de PHP sirve solo para pruebas locales, no es el servicio de producción. El panel de pruebas usa `AIBID_CONFIG` en `/private/tmp` para no crear ni alterar la configuración real.

### Actualización y recuperación administrativa

Conservar configuración, claves y BD. Instalar el nuevo código/dependencias, ejecutar migraciones con la cuenta correspondiente, aplicar nuevos permisos si los hubiera y repetir preflight/audit-verify. Cada migración guarda checksum y estado pendiente antes del DDL; si queda incompleta, el migrador se detiene y requiere conciliar el estado, sin simular rollback de DDL.

```sh
php bin/recover-admin.php --login=administrador@example.com --reason='Recuperación autorizada y documentada'
php bin/cleanup.php
php bin/audit-verify.php
```

La recuperación solicita una nueva contraseña, borra el enrolamiento MFA, invalida sesiones y códigos anteriores y deja auditoría. No rehabilita una cuenta deshabilitada. La siguiente entrada requiere volver a enrolar TOTP. `cleanup` elimina sesiones/desafíos vencidos hace más de un día y contadores de tasa de más de dos días; conserva historia comercial e idempotencia. Al programarlo en Linux, usar `flock` o el mecanismo de exclusión del sistema. La salida de `audit-verify` contiene secuencia/hash/instante para guardar un anclaje externo bajo otra credencial; ese almacenamiento no se automatizó en etapa 2.

### Dependencias y marca

El runtime usa PHP/PDO/Sodium y dos bibliotecas Composer de propósito acotado: OTPHP 11.5.0 y BaconQrCode 3.1.1, fijadas con sus dependencias transitivas en composer.lock. PHPUnit 12 es solo de desarrollo y requiere PHP 8.3+. XMLWriter es necesario para generar QR SVG. Bootstrap 5.3.8 se conserva en `public/assets/vendor/bootstrap/`, con su licencia MIT; no hay CDN en ejecución.

Los cuatro SVG de `public/assets/brand/` se copiaron sin cambios de los archivos compartidos por el usuario. Conservan las fuentes y fallbacks del original. Node/Playwright y el oráculo Go solo se usan durante pruebas; el VPS no necesita instalarlos.

## 8. Claves y API online disponibles en etapa 3

La actualización agrega `003_online_activations.sql`. Conservar la configuración y las cinco claves de aplicación existentes; ejecutar `php bin/migrate.php --user=aibid_migrate` y aplicar el SQL actualizado que imprime `bin/grants.php`. Las migraciones 001/002 no se reescribieron. Antes del primer uso online, preparar un firmante del entorno:

```sh
php bin/signing-key.php --action=generate --kid=lic-prod-2026-a --purpose=license --user=aibid_migrate
php bin/signing-key.php --action=list
```

La cuenta de gestión debe tener INSERT/UPDATE sobre `signing_keys`/`signing_scopes` y acceso a la auditoría; una cuenta de migración restringida a esta BD sirve para la CLI. La cuenta de runtime solo recibe SELECT sobre esas tablas. `--user` pide la contraseña SQL oculta y no modifica el archivo de configuración.

El comando genera una privada aleatoria, registra una pública inicialmente `staged` e imprime solo kid/entorno/propósito/pública/estado. `APP_ENV` identifica el entorno real, no el prefijo del kid. Usar claves independientes para cada entorno y para `license`/`manifest`; la API actual firma únicamente el propósito `license` y no genera manifiestos.

Distribuir la pública al conjunto de confianza del cliente por su canal autenticado. Este servidor no añade una ruta `/v1/keys` ni permite que una licencia elija una URL de claves. Solo después de verificar esa confianza:

```sh
php bin/signing-key.php --action=activate --kid=lic-prod-2026-a --reason='Pública distribuida y verificada en clientes' --trust-confirmed --user=aibid_migrate
php bin/preflight.php --require-signer
```

`--trust-confirmed` es una confirmación explícita del operador; no automatiza la distribución ni demuestra que cada instalación haya recibido la pública. El comando selecciona la clave del ámbito bajo bloqueo exclusivo, cambia la anterior a `verify_only` y conserva el historial. Repetir la selección de la misma clave no produce otra rotación. No eliminar públicas antiguas necesarias para perpetuas.

Configurar `SIGNING_KEY_DIR` con un directorio absoluto persistente fuera de `public/`, por ejemplo `/srv/aibidlicense/secrets/production-licenses`. Por defecto se usa `var/keys/<APP_ENV>`, útil en desarrollo. Los archivos son 0600; ejecutar la generación con el usuario dedicado que podrá leerlos desde PHP-FPM o ajustar propietario/permisos mediante la administración del VPS. Evitar un directorio efímero de release para producción y respaldar las privadas por separado. El cargador rechaza archivos públicos, enlaces de archivo, permisos abiertos y metadatos/par incompatibles.

Si la BD pierde conexión durante el registro de una clave, la CLI conserva el archivo privado por posible commit ambiguo. Conciliar archivo/registro antes de reintentar; no borrar automáticamente una privada que podría estar registrada. Las operaciones online reintentan deadlocks/timeouts conocidos como máximo dos veces; ante una conexión perdida durante commit se debe repetir el mismo request, sin asumir que falló.

Las cuatro rutas V1 ya están disponibles. El panel no requiere un firmante para emitir derechos comerciales sin activación. Activar, modificar derechos activos y desactivar requieren una nueva firma; si no hay firmante o falla su archivo, el servidor devuelve 503 y revierte el efecto completo. Refresh reutiliza un JWS existente y no requiere leer la privada.

`php bin/preflight.php --require-signer` comprueba configuración, motor, migraciones/checksums, permisos inmutables en producción y firmante de licencias del entorno. No sustituye las pruebas de PHP-FPM, TLS/proxy, límites y respuestas JSON del proxy, sincronización del reloj, respaldo/restauración e integración con el cliente real previstas para el VPS.

## 9. Operación offline y actualización a etapa 4

Para una instalación de etapa 3, conservar `config/local.php`, todas sus claves y el directorio de firmantes. Aplicar `004_offline_requests.sql` con el migrador y actualizar GRANT de runtime:

```sh
php bin/migrate.php --user=aibid_migrate
php bin/grants.php --database=aibid_licenses --user=aibid_app
# Aplicar el SQL generado mediante la cuenta administradora de esta BD.
php bin/preflight.php --require-signer
php bin/audit-verify.php
```

No repetir configure/bootstrap ni generar otra clave de firma si ya hay una válida y distribuida. La evidencia usa HKDF desde el `CREDENTIAL_KEY` existente; no exige añadir secretos ni modificar la configuración. Conservar esa raíz junto con los respaldos recuperables. La migración agrega tablas sin modificar licencias/revisiones previas y se probó con historia online existente.

El ejemplo Nginx permite cuerpos de 96 KiB para el multipart; la aplicación sigue limitando archivos a 64 KiB y JSON de API a 16 KiB. En PHP-FPM configurar `file_uploads=On`, `upload_max_filesize` al menos 64 KiB y `post_max_size` al menos 96 KiB. El directorio temporal de uploads debe ser privado al usuario de PHP. Los límites de Nginx JSON/multipart se comprobaron localmente en etapa 5; repetirlos en el VPS.

1. Pedir al cliente su `.licreq`, preferentemente sin clave comercial. Entrar en **Solicitudes offline** e importarlo. Se comprueba firma/formato y queda pendiente; no se ocupan plazas.
2. Revisar producto, instalación, pública/huella y fecha declarada. Para activar, buscar cliente/licencia, seleccionarla y pulsar **Revisar derechos**. El producto debe coincidir; una clave comercial contenida en el archivo no reemplaza esta asignación.
3. Aprobar con motivo o rechazar con motivo. Para una renovación se pueden emitir derechos ya contratados; para ampliar una suscripción elegir la ampliación y registrar fecha UTC futura y referencia comercial. El mantenimiento perpetuo se compra/registra por separado en la licencia.
4. Descargar `.lic` y entregarlo únicamente a su instalación. El archivo es el JWS original de la decisión, sin envoltura y sin nueva firma al descargar. Consultar la licencia si pudo recibir revisiones posteriores. El cliente debe verificarlo con una pública previamente confiable.
5. Para mover un equipo operativo, aprobar su desactivación firmada (o usar la API online) y luego activar el nuevo equipo. La revisión de salida está revocada; la licencia comercial permanece emitida.
6. Para equipo averiado, un superadministrador puede aprobar la solicitud de destino marcando la transferencia, con contraseña/TOTP nuevo, motivo y aceptación de que una copia offline no se revoca instantáneamente. Ambas firmas y el cambio de plaza se confirman juntos. Sin archivo de destino, usar **Recuperar plaza por equipo averiado** en el detalle de licencia; la plaza queda disponible para una activación posterior.

Ante error 503, no asumir commit fallido: consultar la solicitud y su historial antes de repetir. Reutilizar la operación original cuando corresponda; si ya se decidió, descargar el resultado registrado. Ante 409, recargar/revisar la solicitud o licencia; no cambiar arbitrariamente el request firmado. Ni cleanup ni el operador pueden borrar evidencia, decisiones, transferencias o resultados de idempotencia.


## 10. Entrega de etapa 5

Para el destino confirmado por el usuario usar [DEPLOY_UBUNTU_24_04.md](DEPLOY_UBUNTU_24_04.md): aibid.adariel.com, Ubuntu 24.04, Nginx/PHP-FPM y MySQL 8. Toda la validación se realizó localmente; la guía contiene los comandos finales para el operador, sin acceso remoto desde esta sesión.

`configure --output` permite guardar la configuración fuera del release; `--signing-dir` fija las privadas persistentes. No repetir configure en una actualización. `backup.php` crea/verifica archivos cifrados; `restore-empty.php` importa solo a una base vacía; `recovery-config.php` conserva las cinco claves de la copia y solicita la contraseña del destino sin eco; `recovery-check.php --against` verifica historia contra un anclaje previo externo. La guía documenta permisos, mantenimiento, timer, copia externa, rotación y recuperación de una copia atrasada.

Las herramientas no configuran almacenamiento externo ni recuperación de binlogs. La copia diaria entregada no cumple por sí sola el RPO propuesto de 15 minutos. Un anclaje posterior al respaldo debe producir error hasta recuperar o conciliar la historia faltante. El indicador de mantenimiento bloquea tráfico HTTP; detener también tareas y CLI con capacidad de emisión durante una recuperación.
