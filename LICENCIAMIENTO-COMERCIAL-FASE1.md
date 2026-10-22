# AIDEX — Informe de implementación comercial, fase 1

21 de octubre de 2026; cierre de validación el 22 de octubre de 2026. Implementación y validación local del **servidor de licencias**. El usuario informó que el servidor ya está desplegado; esta tarea no accedió al VPS ni aplicó cambios en su base/configuración. El producto y el contrato firmado conservan sus versiones actuales.

## A. Análisis inicial

Arquitectura revisada: PHP ^8.3 sin framework, Composer/PSR-4, vistas PHP + Bootstrap/JavaScript, front controller y MySQL 8/InnoDB. Tres roles (superadmin/operator/viewer), MFA obligatorio, CSRF/Origin, sesiones MySQL, reautenticación, límites de tasa, transacciones y operaciones idempotentes. Las migraciones se ordenan y verifican por checksum; runtime utiliza grants por tabla y mantiene historias de solo inserción.

Antes de esta fase, las claves tenían prefijo AIDEX- y un token de 43 caracteres; se mostraban inicialmente y solo se conservaba HMAC. La emisión requería motivo, la referencia no tenía edición administrativa separada y no existían constancias PDF. Ya existían firma Ed25519/JWS V1, desafíos/prueba de posesión, una instalación activa, solicitudes .licreq firmadas, aprobación manual offline, revisiones históricas, traslado excepcional, auditoría y respaldo cifrado/restauración.

Se reutilizaron firma, claves/registro de firmantes, protocolos, catálogo de cinco módulos/dependencias, licencias por instalación, condiciones de usuarios ilimitados, vigencias, tolerancia de 15 días, rate limiter, MFA, cifrado Sodium, auditoría, idempotencia y recuperación. No se reconstruyó la firma ni se modificó el consumidor.

Riesgos identificados y tratados: credenciales antiguas irreversibles; separación de consulta general/revelación; divulgación indirecta mediante PDF históricos; modificación comercial que pudiera cambiar JWS; QR copiado a otro PDF; descarga sin auditoría/reautenticación; afirmaciones excesivas de borrado/identidad física en offline. Las limitaciones de aislamiento/clonación se explican en E/H/I.

## B. Formato de claves

Formato definitivo **3-4-4-4**, 15 símbolos, sin prefijo. Alfabeto de 31 caracteres `23456789ABCDEFGHJKMNPQRSTUVWXYZ`, excluyendo I/L/O/0/1. `random_int` realiza cada selección uniforme desde CSPRNG; no utiliza fechas, cliente, licencia ni secuencias. Entropía aproximada **74.31 bits**. Se evaluó un carácter de checksum y se conservó toda la entropía del secreto; se valida longitud, alfabeto y agrupación estricta. No se promete detectar cualquier sustitución por otro símbolo válido.

Normalización: mayúsculas, guiones opcionales completos y espacios exteriores; no separadores arbitrarios, Unicode parecido o caracteres ambiguos. La forma mostrada/copied es mayúscula agrupada. La emisión comprueba colisión sobre HMAC e índice UNIQUE, reintenta colisiones concurrentes hasta ocho veces y falla de forma segura si no puede generar una credencial única. En pruebas se generaron 10 000 códigos sin colisiones; esta muestra no sustituye la garantía del índice ni prueba ausencia universal de colisiones.

Se conserva HMAC-SHA256 con CREDENTIAL_KEY para búsqueda y se añade cifrado autenticado XChaCha20-Poly1305, nonce aleatorio y clave HKDF separada por propósito/contexto e identificador de credencial. Documentos/snapshots usan otro propósito/contexto. Las cinco claves de aplicación existentes no se regeneran. No se almacena la credencial en claro en tablas, resultados idempotentes, listados, auditoría, URLs o logs.

Las credenciales anteriores permanecen registradas, sin cifrado recuperable, y no permiten activación nueva con el formato anterior. La consulta devuelve una causa explícita y ofrece reemplazo autorizado; no intenta recuperar hashes. Emitir o reemplazar sin permiso de revelación no muestra el secreto. Consulta posterior exige permiso especial, contraseña, TOTP nuevo y auditoría. El panel permite copiarlo exactamente después de la revelación autorizada.

Defensas online: límites por IP e identidad existentes y nuevo límite de 10 activaciones/intentos por código/producto cada 900 segundos, usando índices HMAC. Códigos malformados, desconocidos/retirados y producto incorrecto tienen negativa uniforme. Una segunda plaza se rechaza, aun con prueba de posesión válida. La representación canónica se usa para búsqueda/idempotencia.

## C. Administración

Referencia comercial y motivo/nota de autorización son **opcionales al emitir** en HTML, backend y persistencia. Pueden agregarse, corregirse o dejarse vacíos desde el detalle por superadmin/operator. No se relajaron motivos obligatorios de revocación, mantenimiento o recuperación excepcional.

La edición tiene administrative_version independiente y control de concurrencia. Aumenta únicamente esa versión; conserva row_version técnica, código, HMAC, módulos, modalidad, límites, vigencia, instalación, contador de JWS y bytes firmados. No fuerza ninguna revalidación del consumidor. Se preservan valor anterior/nuevo, campo, usuario, licencia y fecha en historial append-only y auditoría; ambos son consultables en el detalle.

Matriz de permisos implementada:

| Capacidad | viewer | operator | superadmin |
| --- | --- | --- | --- |
| Consulta general e historial administrativo | Sí | Sí | Sí |
| Emitir, editar referencia/nota, módulos y vigencias según reglas | No | Sí | Sí |
| Generar/descargar constancia de licenciamiento | No | Sí | Sí |
| Revelar código | No | Grant credentials.reveal | Sí |
| Generar/descargar entrega online | No | Grants documents.delivery + credentials.reveal | Sí |
| Generar/descargar entrega offline | No | Grants documents.delivery + offline.download | Sí |
| Descargar .lic | No | Grant offline.download | Sí |
| Revocar, traslado/liberación excepcional, desactivar offline | No | No | Sí |
| Gestionar roles/grants | No | No | Sí |

Solo tres grants adicionales; el resto reutiliza roles. Las autorizaciones se revalidan contra el usuario/permisos actuales de la BD, dentro de la operación sensible. No basta con construir un Actor con un rol falso ni ocultar botones. Cambiar grants/rol exige superadmin, contraseña/TOTP y auditoría; revoca sesiones del destinatario. Las cuentas existentes no reciben grants nuevos automáticamente.

Revelación, descargas técnicas y constancias sensibles requieren reautenticación cada vez. Los enlaces sensibles GET muestran confirmación y POST entrega el material con CSRF; no se ponen códigos ni contraseñas en URL. Se registran éxito, denegaciones y errores administrativos sin incluir el secreto. Los originales offline, credenciales y PDF son datos protegidos de negocio, nunca cuerpos de eventos/logs.

## D. Constancias PDF

Dos tipos implementados con Dompdf 3.1.6:

1. **Constancia de licenciamiento**: marca/nombre oficial, folio aleatorio, titular/razón social/RFC si están disponibles, producto, modalidad, emisión/vencimiento, instalación, usuarios, módulos, referencia opcional, revisión y generación. No contiene código/JWS ni sirve para activar, acreditar pago o reemplazar contrato.
2. **Constancia de activación y entrega**: datos de licencia, folio/revisión, método, código online bajo permisos o identificación de instalación/archivo offline y SHA-256, instrucciones y confidencialidad. No imprime firma completa ni incrusta automáticamente .lic en la constancia administrativa. PDF y archivo de esa entrega pueden descargarse por separado desde el detalle. No se implementó ZIP: era opcional y no es necesario duplicar los artefactos.

El RFC se incorporó como dato opcional del cliente, sin inventar datos de clientes existentes. La nota administrativa interna no se imprime automáticamente. PDF contiene texto seleccionable/copiable; solo logo y QR son imágenes. No se implementó firma PAdES ni se presenta como tal.

El original `public/assets/brand/logo-h.svg` y las otras variantes SVG permanecen byte a byte intactos. La revisión visual encontró limitaciones de dimensiones porcentuales/fuentes en php-svg-lib; se generó `logo-h-pdf.png` transparente 1440×260 por renderizado determinista del SVG oficial en Chrome, sin rediseño ni cambios de color/composición. `logo-h-pdf.json` conserva hashes de SVG/PNG; se verifican antes de renderizar. `bin/render-brand.cjs` permite regenerarlo en desarrollo. Node/Chrome no se requieren en producción. La muestra de ambos tipos, incluida entrega online/offline, se revisó en A4; los tres ejemplos sintéticos finales caben en una página y no contienen JavaScript.

Historia: una fila por constancia realmente generada, revisión por licencia/tipo, snapshot y PDF cifrados, SHA-256, fecha/actor y referencias a credencial o revisión técnica si procede. Reintentar el mismo operation_id devuelve el documento existente; generar expresamente uno nuevo conserva los anteriores. Descargar no vuelve a renderizar ni cambia bytes/huella. Un documento histórico sensible mantiene sus permisos y reautenticación, aunque la clave haya sido reemplazada o la licencia revocada.

Verificación pública: referencia aleatoria de 256 bits, folio/revisión/tipo/fecha/producto/modalidad, estado comercial actual, SHA-256 del PDF y aviso de entrega retirada/reemplazada. Sin sesión/cookie, titulares, RFC, códigos, .lic, IDs del equipo/licencia o datos del administrador. Referencias fuera de access logs en la plantilla Nginx y sin referrer. La página tiene límite por IP y no permite enumeración por folios consecutivos. **Comparar el hash del PDF recibido** con esa página impide que un QR auténtico valide automáticamente un documento alterado. La autenticidad del .lic sigue dependiendo de Ed25519, no de su hash.

## E. Activación offline

Se conservan .licreq V1.0, firma de bytes originales `LICREQ-V1\n…`, validación estricta, cifrado del original y proyección sin license_key. No se inventa una identidad para generar un JWS: hace falta una solicitud con prueba de posesión de la privada de instalación y aprobación/asignación comercial.

Las aprobaciones cotejan producto/licencia/estado/plaza y, para operaciones sobre una instalación existente, UUID, pública, fingerprint y activación. La firma del servidor cubre esos valores. Cambiar simplemente un campo invalida la firma; un equipo B no puede sustituir su identidad por la de A sin violar el binding o poseer la misma privada. Las carreras y duplicados se resuelven por transacciones, locks, idempotencia y una plaza activa. Se conservó toda esta implementación segura.

Los JWS se conservan en license_revisions con SHA-256. El historial de revisiones está paginado, incluyendo las anteriores a las primeras 50. La descarga posterior recupera esa fila exacta, verifica su hash y audita; no firma otra revisión, crea activación, consume plaza ni cambia equipo. Está disponible desde revisiones, solicitud aprobada y la constancia de entrega que la referencia.

**Perpetua:** expires_at=null, grace_days=0 y sin límite artificial offline/heartbeat. Se probó nueva descarga cincuenta años después con reloj controlado, conservando bytes y una plaza. Mantenimiento/fecha de versiones no caducan uso adquirido. **Suscripción:** conserva fecha exclusiva y quince días de tolerancia; ampliación explícita genera derechos nuevos transportables offline, nunca perpetuidad.

Una solicitud deactivate firmada prueba posesión, no borrado de copias. Para liberar la plaza offline se exige ahora superadmin, contraseña/TOTP nuevo en HTTP, motivo y aceptación explícita de esta limitación. Traslado directo y equipo inaccesible mantienen autorización reforzada, revisión terminal y auditoría. No se libera por una afirmación sin comprobar identidad ni por falta de conexión. Una revisión retirada conserva su estado terminal y una activación nueva no rehabilita la anterior.

**Límite real:** el servidor no puede atestar hardware ni demostrar que una privada/estado nunca se clonaron, ni impedir restaurar el estado completo de un consumidor aislado bajo control del dueño del SO. Protección de privada/contadores/retiros y persistencia resistente a rollback corresponde al consumidor y a las capacidades reales de Windows/Ubuntu/macOS. Tampoco puede comunicar una revocación posterior a quien nunca recibe información nueva. No se añade conectividad obligatoria a perpetuas para aparentar resolverlo. Estas limitaciones son parte expresa del contrato y del procedimiento excepcional.

## F. Cambios técnicos

Componentes nuevos: ActivationCode, Access, LicenseMaterials, DocumentService y CertificatePdf. App los compone con servicios existentes. Kernel incorpora acciones administrativas, confirmación de descargas y verificación pública; no cambia los cuatro endpoints V1 ni sus estructuras. Crypto añade cifrado por propósito/contexto, sin reemplazar cifrado/firmas existentes. ReadRepository expone historia/grants/metadata documental sin ciphertext/credenciales en listados generales. RecoveryVerifier comprueba también autenticación/hashes de códigos, snapshots y PDF restaurados.

Migración nueva: **006_commercial_delivery.sql**. Añade credential_encrypted/code_format, commercial_reference/authorization_note/administrative_version, tax_id y tres tablas: admin_permission_grants, license_administrative_changes, license_documents. Rellena referencia/nota original desde emisión; no borra hashes, filas, claves ni firmas. 001–005 no se modifican. Grants/preflight protegen historias y documentos de UPDATE/DELETE runtime.

Dependencia nueva: dompdf/dompdf ^3.1.6, bloqueada v3.1.6, con php-font-lib 1.0.2, php-svg-lib 1.0.2, masterminds/html5 2.11.0 y sabberworm/php-css-parser 9.5.0. Ningún paquete previo se actualizó. DOM, iconv y GD se comprueban junto con extensiones anteriores. No hay nueva variable de entorno secreta ni generación de material criptográfico. Se usa el tmp privado existente de FPM; el código/vendor/public no necesitan escritura.

Endpoints/formatos exactos: [INTEGRACION-LICENCIAMIENTO.md](INTEGRACION-LICENCIAMIENTO.md). Procedimiento manual del servidor desplegado: [ACTUALIZACION-FASE1.md](ACTUALIZACION-FASE1.md). Build incluye estos documentos y este informe, recursos oficiales/derivado, código y lockfile; excluye configuración, datos, pruebas y dependencias de desarrollo.

La lista exhaustiva de archivos modificados/nuevos se incluye al final de este documento.

## G. Pruebas

Entorno: PHP CLI/FPM 8.5.7, MySQL 8.4.11 temporal por socket privado sin puertos TCP, Nginx local/TLS, Chrome headless/Playwright, Go 1.25.5 para oráculo independiente, Poppler para texto/previsualización de PDF. No se usó la base real ni la configuración de producción. La distribución temporal MySQL se obtuvo de fuente oficial y se comprobó su checksum; las pruebas crean/eliminan únicamente sus bases/cuentas aleatorias.

- **127 pruebas PHPUnit, 20 802 aserciones, sin errores/omisiones.** Incluyen 10 000 códigos con comprobaciones de formato/duplicados, normalización, rechazo de antiguos/ambiguos, throttle, cifrado autenticado por contexto/propósito, recuperación y denegaciones con rol falso/contraseña incorrecta, campos opcionales, historia/edición sin cambios técnicos, PDF públicos/sensibles, hash, históricos, verificación mínima, reemplazo/revocación e idempotencia.
- Migración desde **001–005 reales** en una base nueva aislada: conserva cada campo anterior de licencia y su digest, deja cifrado antiguo NULL y rellena referencia/nota; segunda ejecución no reaplica. Nuevos grants y preflight utilizados en fixtures.
- Nuevas rutas por HTTPS/Nginx/FPM: generación/descarga de PDF, revelación autorizada, viewer sin permisos, edición denegada, verificación sin cookie ni datos personales/secretos, y guardas de producción.
- Respaldo/restauración: PDF histórico idéntico, códigos cifrados, material de aplicación/firma, revisiones/evidencia y anclajes verificables. El verificador autentica también los nuevos registros cifrados.
- `npm run test:panel` pasó emisión sin referencia/nota, código corto, edición, constancia administrativa, MFA, roles, CSRF/Origin, renovación/mantenimiento, solicitudes/traslado/desactivación offline, descargas sensibles con contraseña/TOTP y navegación responsive. Un error de include en la nueva plantilla de permisos fue detectado por navegador, corregido y el recorrido completo se repitió con fixture limpia.
- PDF: ambos tipos y variantes online/offline, texto seleccionado mediante pdftotext, logo/QR/paginación revisados, SHA-256 y bytes de descarga históricos verificados. Muestras sintéticas finales en `var/phase1-evidence/`; su base/firmante temporal se eliminaron y no son documentos comerciales válidos.
- Sintaxis de **115 archivos PHP**, JavaScript/CJS modificados y diff sin errores. Configuración privada excluida de esta comprobación.
- Vectores PHP/Go: 5 JWS, 3 pruebas de posesión, 3 solicitudes offline y 3 JWS negativos. Firmas coinciden. Signer, serialización, formatos de contrato y fixtures permanecen intactos; no se regeneraron firmas por los cambios comerciales.
- Composer JSON/lock y requisitos runtime verificados. Paquete con manifiesto/hashes, sin secretos/datos/tests/vendor: instalación aislada `--no-dev` completada desde el lockfile, requisitos de plataforma y validación estricta de Composer aprobados. El autoload de producción funciona y no incluye PHPUnit. Desde ese paquete se generaron las tres variantes de PDF (licenciamiento, entrega online y entrega offline), con texto seleccionable y una página por muestra; se verificó que solo la entrega online contiene el código. El Composer local antiguo emite deprecaciones propias bajo PHP 8.5; no corresponden a fallos de la aplicación.

Los intentos bloqueados por el sandbox se repitieron con autorización para socket/loopback/descargas oficiales. No se relajaron controles de la aplicación para hacer pasar las pruebas.

Al cierre se eliminaron las dos fixtures conservadas para el navegador, sus cuentas y claves sintéticas. Se comprobó que no quedaban bases de prueba y se cerraron el servidor HTTP del panel y la instancia MySQL temporal.

## H. Integración futura

Contrato implementado con campos, rutas, normalización, solicitudes/respuestas sintéticas, errores, .licreq/.lic, estados, límites, suscripciones/perpetuas, verificación y permisos: **INTEGRACION-LICENCIAMIENTO.md**. Mantiene claramente separada la API del consumidor de formularios administrativos y de la verificación pública.

El consumidor debe adoptar la captura sin prefijo, mantener pruebas V1 e idempotencia, verificar públicas confiables/binding/fechas/módulos, preservar revisiones y retiradas locales y proteger su privada según SO. Debe distinguir desconexión de desactivación y respetar perpetuas sin caducidad artificial. Su integración y distribución de confianza se realizan después, en su repositorio y por canal autenticado.

No se ejecutó tests/System/RealClientTest.php: el consumidor disponible exige Go 1.27.1 y la toolchain disponible es Go 1.25.5; no se modificó el cliente ni su go.mod para eludirlo. Sí se ejecutaron el oráculo criptográfico independiente, HTTP/TLS/FPM, solicitudes firmadas de equipos sintéticos, binding y estados del servidor. No se atribuyen pruebas de cliente/OS al trabajo actual que no se hayan ejecutado.

## I. Pendientes y estado

**Implementado y probado en el servidor local.** No quedan funcionalidades solicitadas del servidor sustituidas por una API hipotética. Se entregan código, migración, documentos y actualización manual. No se ejecutó despliegue ni migración sobre el VPS/base existente, no se borraron registros reales ni se modificaron claves existentes.

Pendiente del operador: respaldo/ensayo, ventana de mantenimiento, aplicar 006/grants/dependencias, actualizar pool/proxy si corresponde, asignar grants a operadores, probar aceptación en el destino y habilitar después. No repetir configure/bootstrap/generate. [ACTUALIZACION-FASE1.md](ACTUALIZACION-FASE1.md) contiene pasos y checklist.

Pendiente del consumidor: integración/ensayos Windows/Ubuntu/macOS, protección local de identidad/revisiones/retiros, pruebas de instalación/reinstalación/clones y confianza pública final. No se acredita borrado de un equipo aislado ni revocación inmediata sin información nueva; mantener aprobación excepcional cuando esa prueba no exista.

Pendiente manual de aceptación: explorar PDFs con datos comerciales reales autorizados (campos máximos pueden ocupar más de una página), escanear QR con dispositivos elegidos, comprobar TLS/dominio/proxy reales, reloj/NTP, cuentas, logs y restauración externa. Las muestras PDF usan origen local sintético y su verificación no queda publicada después de eliminar la fixture.

El ZIP opcional y la firma PAdES no se implementaron ni se presentan como funciones existentes. PDFs y .lic se descargan por separado con sus controles. No se necesita intervención adicional para cerrar el código; las decisiones de despliegue y custodia se detallan para el administrador.

### Archivos de esta fase

```text
ACTUALIZACION-FASE1.md
API.md
ARCHITECTURE.md
DESPLIEGUE-PRODUCCION.md
INFORME-TRANSICION-AIDEX.md
INTEGRACION-LICENCIAMIENTO.md
LICENCIAMIENTO-COMERCIAL-FASE1.md
OPERATIONS.md
README.md
SCHEMA.md
SECURITY.md
TEST_PLAN.md
bin/build-release.php
bin/grants.php
bin/preflight.php
bin/render-brand.cjs
composer.json
composer.lock
database/migrations/006_commercial_delivery.sql
deploy/nginx.conf.example
public/assets/brand/logo-h-pdf.json
public/assets/brand/logo-h-pdf.png
src/App.php
src/Application/Access.php
src/Application/ActivationService.php
src/Application/Actor.php
src/Application/AdminService.php
src/Application/CatalogService.php
src/Application/DocumentService.php
src/Application/LicenseMaterials.php
src/Application/LicenseService.php
src/Application/OfflineService.php
src/Domain/ActivationCode.php
src/Http/Kernel.php
src/Http/View.php
src/Infrastructure/CertificatePdf.php
src/Infrastructure/Crypto.php
src/Infrastructure/ReadRepository.php
src/Infrastructure/RecoveryVerifier.php
templates/certificate-pdf.php
templates/customer-form.php
templates/issued.php
templates/license-activations.php
templates/license-commercial.php
templates/license-detail.php
templates/license-form.php
templates/material-confirm.php
templates/offline-detail.php
templates/permission-fields.php
templates/reauth-fields.php
templates/users.php
templates/verify-document.php
tests/Integration/ActivationsTest.php
tests/Integration/AdministrationTest.php
tests/Integration/BackupTest.php
tests/Integration/CommercialDeliveryTest.php
tests/Integration/CommercialMigrationTest.php
tests/Integration/OfflineTest.php
tests/Integration/ProductionHttpTest.php
tests/System/RealClientTest.php
tests/Unit/ActivationCodeTest.php
tests/Unit/ProtectedRecordTest.php
tests/offline-panel.cjs
tests/panel.cjs
```
