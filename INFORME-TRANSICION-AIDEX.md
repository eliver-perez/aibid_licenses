> Informe histórico del 9 de octubre. La fase comercial del 21 de octubre sustituye el prefijo de 43 caracteres y amplía funciones: consultar [LICENCIAMIENTO-COMERCIAL-FASE1.md](LICENCIAMIENTO-COMERCIAL-FASE1.md). Las pruebas y afirmaciones de este documento pertenecen a la transición de identidad, no a la fase actual.

# Informe de transición AIDEX y preparación de producción

Fecha: 9 de octubre de 2026. Alcance: exclusivamente el servidor PHP de licencias. Identidad: **AIDEX — Aplicación de Indexación Digital de Expedientes**, primera versión comercial **1.0**. Destino previsto: **https://licensing.aidex.com.mx**.

## A. Cambio de identidad

Archivos de presentación actualizados:

- `templates/layout.php`: títulos, alt de logos, navegación, footer, pantalla de acceso y metadatos `application-name`/`description`. Favicon SVG existente conservado.
- `templates/dashboard.php`, `templates/products.php`: textos alternativos de marca.
- `public/index.php`: página auxiliar 503, título, alt, favicon y prefijo del log.
- `src/Http/ApiKernel.php`: prefijo de diagnóstico AIDEX; JSON/API intactos.
- `src/Infrastructure/Totp.php`: issuer visible `AIDEX Licencias`; secreto, algoritmo y verificación TOTP intactos. No requiere reenrolar cuentas existentes.
- `src/Application/CatalogService.php`: dos mensajes visibles de validación; reglas y capacidades intactas.
- `src/Application/LicenseService.php`: por instrucción posterior del usuario, las claves comerciales emitidas o reemplazadas usan `AIDEX-` en lugar del prefijo anterior. Se mantienen token aleatorio, digest, almacenamiento y validación; no hay licencias comerciales válidas anteriores que migrar. Pruebas de administración, solicitud offline y navegador actualizadas.
- `composer.json`: nombre/descripción del proyecto; namespace/autoload conservados. `composer.lock` solo cambia su content-hash calculado por Composer; ninguna dependencia se actualizó.
- `package.json`, `package-lock.json`: nombre del paquete de pruebas del panel, sin cambiar dependencias.
- `database/migrations/005_aidex_identity.sql`: migración DML limitada al nombre/descripción originales de `gestor_documental`; conserva personalizaciones. No cambia esquema SQL, capacidades, licencias ni contrato. No se aplicó a bases reales.

SVG utilizados, sin editar ningún byte recibido del usuario:

| Archivo en `public/assets/brand/` | Uso |
| --- | --- |
| `logo-h.svg` | Logo claro horizontal: versión móvil del acceso, resumen, producto, error auxiliar |
| `logo-dark-h.svg` | Logo horizontal sobre fondo oscuro del acceso |
| `logo-v.svg` | Favicon SVG existente |
| `logo-dark-v.svg` | Logo vertical de navegación sobre fondo oscuro |

Los cuatro nombres ya eran correctos; se verificaron bytes SHA-256 antes/después y carga HTTP. No se generaron derivados ni se cambiaron CSS, colores, proporciones, composición o icono. Se conservó el lema incluido en los SVG proporcionados. El diff de esos cuatro archivos respecto a Git proviene del reemplazo previo realizado por el usuario.

Documentación vigente actualizada: `README.md`, `API.md` (solo nombre comercial), `ARCHITECTURE.md`, `DECISIONS.md`, `OPERATIONS.md`, `SCHEMA.md`, `STATUS.md`, `DEPLOY_UBUNTU_24_04.md`; nuevas guías `DESPLIEGUE-PRODUCCION.md` y este informe. `STATUS.md` identifica expresamente cifras históricas de septiembre. El prompt anterior lleva aviso histórico y se excluye del paquete de producción.

### Clasificación de referencias anteriores conservadas

La búsqueda final cubrió archivos versionados, ocultos de configuración/instrucciones, fuentes, plantillas, assets, configuración de ejemplo, scripts, pruebas, contratos y documentos. Dependencias externas y artefactos históricos ignorados no se trataron como marca del producto. La configuración local se inspeccionó únicamente con indicadores no sensibles; no se imprimieron credenciales ni claves.

| Referencia | Ubicación/categoría | Motivo de conservación |
| --- | --- | --- |
| `Aibid\\` / `Aibid\\Tests\\` | Namespace, imports, PSR-4 en src/templates/bin/tests/composer | Identidad interna del código. Renombrarla exige reestructuración sin beneficio para producción; no se muestra como marca |
| `AIBID_CONFIG` | `Config`, FPM, mantenimiento, CLI, pruebas y documentación | Selector de archivo privado implementado; conservar evita romper comandos y cargas existentes |
| `aibid_admin_dev` | Cookie local en Kernel, pruebas y documentación | Cookie técnica exclusiva de desarrollo/testing; nunca la cookie de producción |
| `aibid/offline-evidence/v1` | `Crypto`, documentación de seguridad | Contexto HKDF: cambiarlo impediría descifrar evidencia existente |
| `AIBID-BACKUP-V1`, `.aibidbackup` | `EncryptedArchive`, CLI, script y documentos de recuperación | Formato versionado de archivos cifrados y convención de extensión; no cambiar respaldos existentes |
| `aibid-migrations-`, `aibid:restore:` | Locks en Migrator/MySqlFiles | Coordinar exclusión entre procesos; renombrar podría separar procesos que usan código de versiones distintas |
| `.aibid-backup-*`, `aibid-mysql-*` | Temporales privados | Identificadores internos, utilizados también en diagnóstico/limpieza; no visibles en el panel |
| `AIBIDLICENSE PUBLIC TEST ONLY V1 …` | SigningKeys y generadores/oráculo de contratos | Semillas públicas reproducibles y comprobación que impide usarlas en producción; cambiarlas alteraría fixtures y su detección |
| `002_aibid_catalog.sql` y su INSERT anterior | Migración histórica | Nombre/contenido protegidos por checksum de instalaciones existentes; 005 corrige presentación sin reescribir historia |
| Nombre/descripción anterior en WHERE de 005 y en su prueba | Migración y test de transición | Detectar exactamente el catálogo original y demostrar que las personalizaciones se conservan |
| `aibid_test_*`, rutas/cache temporales y `AIBID_BRIDGE_JOB` | Fixtures PHP/Go, pruebas y TEST_PLAN | Nombres técnicos del entorno aislado y comunicación interna del puente; no se incluyen las pruebas ni sus cuentas en producción |
| Marca anterior en prompt histórico | `prompt_2_servidor_licencias_php_v1_2.md` | Requisitos originales archivados, marcados como históricos y excluidos del release |
| Identificadores locales existentes, datos/auditoría y paquetes viejos | Configuración privada y artefactos ignorados | No se renombraron bases, usuarios, secretos, licencias ni historia real; preparar una release nueva con la guía vigente |

No quedan textos de AIBID como marca vigente en las plantillas, metadatos, logos, títulos, errores visibles o guía actual de instalación. No se cambió `gestor_documental`, capacidades, endpoints V1, bytes de firma, formatos JWS/LICREQ, parámetros ni respuestas. Las referencias antiguas conservadas en los documentos vigentes explican contratos/operación técnicos.

## B. Preparación para producción

Archivos relacionados: `src/Config.php`, `public/index.php`, `config/local.example.php`, `bin/configure.php`, `bin/grants.php`, `deploy/php-fpm-pool.conf.example`, pruebas y documentos.

Hallazgos y correcciones:

1. `APP_ENV` distinguía development/testing de cualquier otro valor sin validación temprana del nombre. Ahora solo admite production/development/testing; un valor desconocido aborta antes de conectar a MySQL.
2. `APP_URL` rechazaba subdirectorios/query/usuario, pero aceptaba fragmentos y un esquema no HTTP en loopback. Ahora rechaza también fragmentos, contraseña, espacios/caracteres de control y esquemas distintos de HTTP/HTTPS. Producción exige HTTPS; desarrollo local sigue admitiendo HTTP.
3. La aplicación ya ocultaba mensajes de excepciones y exigía HTTPS/MFA/roles/CSRF. Se reforzaron configuración FPM y front controller para errores de inicio, registro de errores y exclusión de argumentos de excepciones. Se comprobó una falla de bootstrap real sin mostrar ni registrar secretos de la fixture, conservando clase/archivo/línea/UUID.
4. Ejemplos y CLI de configuración/grants usan nombres AIDEX para instalaciones nuevas. Los ejemplos locales de README usan base/cuentas con sufijo de desarrollo. No se renombró ninguna base ni cuenta real.
5. La receta conserva pool dedicado, `clear_env=yes`, configuración 0600, claves fuera de public y código solo lectura. Se explica la precedencia de variables y se exige revisar overrides al desplegar.

Controles existentes comprobados por pruebas/revisión: MFA obligatorio sin flag de bypass del servidor, roles administrativos, reautenticación, CSRF/Origin, tasa/tamaño, cookie host-only segura, rechazo de HTTPS falsificado por X-Forwarded-Proto, registros de auditoría, idempotencia, permisos SQL limitados, Ed25519 y rechazo de claves públicas deterministas en producción. Ningún control fue deshabilitado.

No existen envío SMTP, instalador web, autenticación temporal por flag ni endpoint de diagnóstico implementados. No hay cuenta administrativa inicial predeterminada: bootstrap requiere contraseña elegida y no se repite si ya hay administradores. Las cuentas/credenciales conocidas están en tests, excluidos del release.

La configuración local existente permanece sin cambios: development, HTTP loopback, MySQL, cuenta distinta de root, contraseña configurada y cinco claves de longitud válida. Se inspeccionaron esos indicadores sin mostrar sus valores secretos. No se conectó a esa base ni se ejecutaron migraciones sobre ella; si se quiere ver el catálogo local actualizado, el operador aplicará 005 mediante el migrador tras su respaldo habitual.

| Aspecto | Desarrollo/testing | Producción explícita |
| --- | --- | --- |
| Transporte | HTTP solo en loopback o HTTPS | HTTPS obligatorio en panel/API |
| Cookie | `aibid_admin_dev`; Secure si origen HTTPS | `__Host-license_admin`, Secure, HttpOnly, Lax, `/`, sin Domain |
| Claves | Entorno separado; tests sintéticos | Firmante environment=production, purpose=license; claves de fixtures rechazadas |
| Diagnóstico | Logs, PHPUnit, herramientas locales | Logs privados con UUID, errores públicos genéricos |
| Autenticación | Contraseña/MFA/roles vigentes | Mismos controles, sin bypass |
| Datos | Base/cuentas locales independientes | Base nueva y usuarios dedicados; no importar fixtures |

Variables manuales: APP_ENV, APP_URL, DB_DSN, DB_USER, DB_PASSWORD, MFA_KEY, CREDENTIAL_KEY, AUDIT_KEY, SESSION_KEY, OPERATION_KEY, SIGNING_KEY_DIR, ADMIN_NETWORKS y ruta técnica AIBID_CONFIG. `configure.php` crea las cinco claves solo en la instalación nueva; respaldarlas y no regenerarlas al actualizar. El operador establece contraseñas, redes, rutas, administrador y confianza de firma. No se crearon contraseñas definitivas ni se leyeron/regeneraron privadas existentes.

## C. Dominio

El runtime centraliza el origen en `APP_URL`; los formularios comparan Origin con ese valor y los enlaces/redirecciones administrativos son relativos al mismo origen. No existen enlaces absolutos de correo. La configuración privada seleccionada por FPM establece el origen real.

Ejemplos preparados: `config/local.example.php`, ayuda `bin/configure.php`, `deploy/nginx-http.conf.example`, `deploy/nginx.conf.example`, `deploy/php-fpm-pool.conf.example`, `deploy/aidex-maintenance.sh`, `.service`, `.timer` y ambas guías. Rutas nuevas de infraestructura: `/srv/aidexlicense`, usuario/pool/socket aidex y nombres de tareas AIDEX; no se instaló ningún servicio en esta máquina ni externamente.

Pendiente del operador: proveedor DNS, IP A/AAAA, sistema operativo/web server, directorios definitivos, socket MySQL/FPM, secreto de cuentas y certificado válido para licensing.aidex.com.mx. Nginx HTTP redirige 308, TLS publica solo public y pasa HTTPS auténtico a FPM. Las guías explican renovación TLS, Apache como alternativa y restricciones para un proxy adicional. El PHP no confía en X-Forwarded-Proto/For; no se agregó CORS ni se fijó el dominio dentro de lógica de licenciamiento.

## D. Pruebas y resultados

Entorno local: PHP CLI/FPM 8.5.7, MySQL 8.4.11 temporal exclusivamente por socket privado, Nginx local y Chrome headless/Playwright. MySQL se descargó de la distribución oficial y se comprobó contra el checksum publicado; se inicializó un directorio nuevo privado en `/private/tmp`, sin utilizar servicios/datos de XAMPP.

- PHPUnit completo, repetido después del cambio autorizado a `AIDEX-`: **113 pruebas, 649 aserciones**, incluida la migración conservadora, configuración production/development/testing y dos pruebas de HTTPS/Nginx/FPM. Sin errores ni pruebas omitidas en las suites unit/integration.
- Sintaxis: **100 archivos PHP** de aplicación, CLI, templates y tests, excluyendo configuración privada; comprobaciones de archivos añadidos/modificados posteriores también correctas. `sh -n` del script de mantenimiento y `git diff --check` correctos.
- Vectores PHP: 5 JWS, 3 pruebas de posesión, 3 solicitudes offline y 3 JWS negativos. Oráculo Go: firmas PHP/Go coinciden. Los archivos de Domain, JWS, Protocol, Activation/OfflineService y fixtures de contrato permanecen intactos. LicenseService solo cambia el prefijo comercial autorizado a `AIDEX-`.
- Navegador: `npm run test:panel`, repetido después del cambio de prefijo y comprobando el formato `AIDEX-` con 43 caracteres aleatorios, pasó login/MFA, emisión, mantenimiento, renovación, roles, CSRF/Origin, importación/revisión/aprobación/rechazo/descarga offline, transferencia con reautenticación, desactivación firmada y navegación responsive. Capturas en `var/screenshots/`; login y dashboard inspeccionados visualmente.
- Producción real local sobre TLS/FPM: cookie segura host-only, HSTS/CSP/no-store, acceso administrativo sin login rechazado, JSON sin sesión/CORS, dotfiles y PHP arbitrario bloqueados; cuatro SVG descargados con bytes idénticos. Falla de bootstrap 503 segura con correlación y log sin secretos de prueba.
- Integración existente: licencias perpetuas/suscripciones, fechas/tolerancia, una plaza activa, altas/bajas/refresh, concurrencia, firmas/revisiones, offline, roles/auditoría, cifrado y respaldo/restauración en bases vacías pasaron.
- Composer: validación de JSON/lock y requisitos de plataforma no-dev correctos; ninguna dependencia cambió. La versión de Composer instalada emite avisos de deprecación propios bajo PHP 8.5; no son errores de la aplicación.
- Integridad: SVG recibidos intactos por SHA-256 y SQL 001–004 idéntico a Git. Búsqueda final de identidad clasificada en A.
- Paquete: generador probado, manifiesto/checksum y exclusión de secretos, dependencias de pruebas, prompt histórico y datos verificados. Instalación no-dev aislada desde el lockfile y sus requisitos verificados antes de entregar.

Reproducción de PHPUnit, adaptando las rutas al MySQL temporal que cree el operador:

```sh
env TEST_MYSQL_DSN='mysql:unix_socket=/RUTA/PRIVADA/mysql.sock;charset=utf8mb4' TEST_MYSQLDUMP_BIN=/RUTA/mysql/bin/mysqldump TEST_MYSQL_BIN=/RUTA/mysql/bin/mysql php vendor/bin/phpunit
php contracts/v1/generate-fixtures.php --check
env GOTOOLCHAIN=local GOCACHE=/RUTA/CACHE/LOCAL go run contracts/v1/verify-vectors.go contracts/v1/fixtures/vectors.json
```

Usar exclusivamente un MySQL 8 aislado; las fixtures crean/eliminan sus propias bases/cuentas aleatorias. Las conexiones a socket y puertos localhost requieren ejecutarse fuera del sandbox de esta sesión; los intentos iniciales bloqueados por permisos se repitieron con autorización y pasaron. Para navegador, usar la fixture y el procedimiento de TEST_PLAN; sus credenciales sintéticas no se despliegan.

Limitaciones: no se ejecutó `tests/System/RealClientTest.php` en esta transición porque el cliente actual requiere Go 1.27.1 y solo está disponible Go 1.25.5; la toolchain temporal anterior ya no existe. Sí pasó el oráculo criptográfico independiente y los recorridos servidor/API/firmas. Las pruebas históricas del cliente real están diferenciadas en TEST_PLAN/STATUS, sin atribuirlas a esta ejecución. No se modificó el cliente. Apache, el servidor remoto, DNS, certificado público, red/proxy reales y versiones PHP/MySQL del destino no se han probado. La distribución de confianza pública y el flujo con el cliente comercial quedan pendientes de sus trabajos y verificación manual posteriores.

## E. Despliegue

Guía principal: **DESPLIEGUE-PRODUCCION.md**. Receta ejecutable Ubuntu 24.04/Nginx: **DEPLOY_UBUNTU_24_04.md**, actualizada al dominio final y tareas AIDEX. El build incorpora ambas y este informe, y empaqueta bajo `aidexlicense/`.

El operador debe elegir host/proveedor/web server, copiar una release nueva verificada, instalar dependencias no-dev, crear base/usuarios y carpetas privadas, generar configuración exclusivamente de producción, aplicar migraciones/grants, crear su administrador, preparar firmante/distribuir pública/activarlo, configurar DNS/TLS/FPM, comprobar panel/APIs/logs y ensayar respaldo/restauración externa. Todos los parámetros y pasos de aceptación están en la guía. No se requiere información adicional para completar el código; esas decisiones corresponden al despliegue manual.

No reutilizar paquetes antiguos: contienen código/documentación anteriores a esta transición. Los pasos y cifras de este informe describen comprobaciones locales. Antes de admitir el cliente AIDEX, completar el checklist de la sección 7 de la guía con dominio, certificado, cookie, permisos, ausencia de cuentas de prueba, firmas, flujos online/offline y recuperación verificados.

## F. Estado final

**Preparado para despliegue manual.** El código y los ejemplos de producción están preparados y verificados localmente. Falta ejecutar y validar la instalación en el servidor elegido y coordinar la confianza del cliente. El servidor no se desplegó ni se declara funcionando en producción.

No se accedió por SSH, modificó DNS/certificados públicos, alteró datos reales, regeneró claves existentes, cambió contratos o lógica de licenciamiento, ni modificó el proyecto cliente.
