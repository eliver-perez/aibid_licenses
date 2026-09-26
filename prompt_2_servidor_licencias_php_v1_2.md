# PROMPT 2 — SERVIDOR INDEPENDIENTE DE LICENCIAS (ACTUALIZADO)

**Versión del prompt:** 1.2 (stack PHP confirmado). **Contrato de licencias incorporado:** V1.0, idéntico al del gestor documental. Las actualizaciones funcionales del gestor NO requieren modificar este contrato.

Actúa como arquitecto de seguridad y desarrollador backend/full stack. Desarrollaremos **un servidor central multiproducto de licencias** independiente del gestor documental local. Trabaja conmigo por etapas: primero arquitectura, modelo de datos, flujo criptográfico y amenazas, API, panel y plan verificable; después implementa cada etapa por completo con migraciones y pruebas. No construyas bibliotecas, expedientes, OCR ni watchers aquí. No inventes campos o endpoints de licencia incompatibles con el anexo: si descubres una carencia real, plantea una futura V1.1 del **contrato**, sin alterar unilateralmente V1.0.

## 1. Alcance y conocimiento del producto cliente

El primer producto técnico será `gestor_documental`, instalable en Windows, Ubuntu y macOS, con backend Go, React y SQLite. Permite bibliotecas **vinculadas** (indexación y OCR de rutas existentes), **administradas** (carga, clasificación y expedientes) e **híbridas** (ambas capacidades en la misma biblioteca; incorporación de nuevas raíces vinculadas en cualquier momento). El servidor de licencias **no necesita conocer bibliotecas concretas, sus rutas, expedientes, usuarios finales, búsquedas ni OCR**: únicamente emite las capacidades `linked_libraries`, `managed_libraries`, `ocr`, `expedientes`, `review_workflow` conforme al contrato. **No inventar módulo `hybrid`: es una combinación de capacidades existentes**. Usuarios finales ilimitados, una instalación activa por licencia, documentos/bibliotecas sin límites iniciales.

Administrar clientes, productos, licencias, periodos, activaciones, transferencias, revocaciones, módulos y mantenimiento opcional. La misma plataforma podrá emitir licencias de otros productos en el futuro. No compartir BD con el gestor documental ni recibir sus PDF, rutas de disco, texto OCR, historial de navegación o auditoría interna. Panel web privado en VPS Ubuntu detrás de Nginx/HTTPS. **Stack obligatorio: PHP 8.3+ con PHP-FPM para API REST y administración, MySQL 8, Bootstrap 5 + JavaScript para el panel y extensión Sodium para Ed25519/JWS.** Utilizar PDO con consultas preparadas y transacciones; Composer solo para dependencias justificadas. No desarrollar el backend de licencias en Go ni construir un frontend React: Go + React corresponden exclusivamente al cliente gestor documental. El servidor no se distribuye ni requiere instaladores; se despliega y actualiza en nuestro VPS.

### Decisión de implementación

El servidor se desarrolla en **PHP 8.3+ + MySQL 8 + Bootstrap 5 + JavaScript**, instalado como aplicación web en el VPS; **sin instalador** y sin proceso residente obligatorio. Organizar rutas de API separadas del panel y mantener la misma lógica de licencias como servicios PHP reutilizables. La criptografía se implementa con `ext-sodium`, codificación base64url estricta y pruebas con vectores firmados compartidos con el cliente Go. La seguridad de concurrencia se aplica en MySQL mediante transacciones y bloqueos adecuados; no depender de la naturaleza de PHP para impedir activaciones duplicadas.

## 2. Modelo de datos y panel administrativo

Entidades: clientes/contactos; productos con `product_id` estable; catálogo de capacidades/dependencias por producto; licencias comerciales y derechos; activaciones vinculadas a clave pública/huella; historial de revisiones/JWS; periodos de suscripción; mantenimiento opcional; operaciones offline e idempotencia; transferencias/revocaciones; claves públicas y `kid`; usuarios administradores, roles y auditoría no editable. Garantizar **máximo una activación activa por licencia** con transacciones e índices/locking adecuados incluso ante solicitudes concurrentes, no solo con `SELECT` + `INSERT` susceptible de carrera.

El panel debe permitir crear/editar clientes y productos; emitir perpetua o suscripción; clave comercial aleatoria de alta entropía (guardar hash/HMAC con secreto protegido y mostrar solo al emitir); módulos seleccionables por producto con validación de dependencias; consultar/renovar; vender/registrar mantenimiento **por separado**; revocar/desactivar y transferir; importar solicitud `.licreq`, autorizar y exportar `.lic`; gestionar activación/renovación/desactivación offline y equipos averiados con auditoría y motivo; consultar historial de activación/revisión/solicitud. Filtros por cliente, producto, estado y vencimiento. No integrar cobro automático ni pasarela en V1.

## 3. Reglas comerciales inalterables

- **Perpetua:** pago único, uso indefinido; **NO INCLUYE mantenimiento de cortesía ni seis meses gratis**. `maintenance_until:null` al crear, salvo compra separada explícita. La compra de mantenimiento emite revisión firmada que extiende la fecha autorizada de versiones; nunca vence el derecho a ejecutar una versión ya adquirida por dejar de renovar. No exigir verificación diaria para permitir funcionar offline.
- **Suscripción:** fecha de vencimiento definida, **15 días completos de tolerancia** después; al terminar, cliente local en modo solo lectura (consultar/ver/descargar/respaldar). Activación y renovación **offline desde V1** con los mismos formatos firmados que online.
- **1 instalación por licencia; usuarios ilimitados; módulos configurables.** No limitar inicialmente documentos, expedientes ni bibliotecas. No crear licencias separadas por navegador o por biblioteca ni añadir cobro por modo híbrido.
- Validar dependencias entre capacidades al emitir (con el anexo idéntico para ambos desarrollos). Cambiar módulos/renovar requiere nueva `license_revision` firmada. La pérdida comercial de un módulo no autoriza borrar datos existentes.
- Revocación en equipos desconectados no es instantánea y ninguna protección local garantiza imposibilidad absoluta de copia: documentar esa limitación.

## 4. Criptografía, API y operación offline

Implementar en PHP **exactamente** las cuatro rutas, campos, representaciones JSON, firmas proof, manejo de `request_id`, errores y semántica de fechas descritas en el anexo. Usar Sodium para firmar y verificar Ed25519 y producir JWS Compact con EdDSA y `kid` interoperable con Go; privada solo en backend/almacenamiento protegido y públicas disponibles para el cliente Go. Rotación de claves conservando verificabilidad de licencias válidas anteriores, separación por propósito (licencias y manifiestos de versión) y por entorno (dev/prod), política de backup/recuperación protegida. No guardar privadas en repositorio/frontend/BD sin cifrado apropiado.

En activación online, verificar desafío de un solo uso, proof de instalación y clave comercial, asegurar una plaza por licencia, firmar respuesta; `refresh` autentica la identidad vinculada y entrega revisión o revocación firmada; `deactivate` libera plaza transaccionalmente tras autorización. En offline, verificar autenticidad/formato de `.licreq`, guardar por `request_id` y requerir aprobación administrativa; la respuesta `.lic` contiene el mismo JWS Compact. Renovación conserva `license_id`/`activation_id`, incrementa revisión. Transferencia por equipo dañado tiene flujo manual auditado. No enviar documentos ni datos privados del gestor para comprobar licencia.

Si se ofrecen descargas de versiones en una etapa posterior, los metadatos `published_at` deben poder validarse mediante firma separada conforme al anexo; no construir un actualizador automático completo en V1 salvo instrucción específica.

## 5. Seguridad del panel, trazabilidad y calidad

Bootstrap inicial seguro y roles (superadministrador, operador de licencias y consulta), Argon2id para contraseñas, MFA TOTP o WebAuthn si resulta viable en la primera etapa, cookies seguras, CSRF cuando corresponda, TLS obligatorio y restricciones de red apropiadas. Rate limiting para login y endpoints, mensajes sin enumeración de licencias ajenas, auditoría append-only de emisión, renovación, módulos, importación offline, transferencia, revocación y sesiones administrativas. Retención mínima de datos personales; respaldos cifrados con restauración ensayada. No exponer claves comerciales, privadas ni secretos en logs.

**Código fuente PHP y JavaScript legible y modificable por el propietario, SIN ofuscación intencional**. Separar controladores, servicios de licencias/criptografía, repositorios PDO, vistas Bootstrap y migraciones. Variables y funciones descriptivas (`licenseActivationRequest`, `installationPublicKey`, `renewSubscription`, `validateLicenseSignature`); funciones pequeñas, transacciones explícitas y comentarios donde expliquen decisiones criptográficas e idempotencia. No usar nombres crípticos de una letra fuera de índices triviales. Los assets de producción pueden minificarse, pero los fuentes permanecen legibles. No entregar código deliberadamente imposible de mantener.

## 6. Orden de desarrollo y pruebas

1. Arquitectura y esquema con transacciones/índices, matriz de estados, amenaza criptográfica, API exacta y contratos de pruebas compartidos; revisar conmigo decisiones bloqueantes.
2. Bootstrap y seguridad de panel, clientes/productos, licencias, módulos, vigencias y auditoría.
3. Gestión de claves Ed25519, emisión JWS, desafíos, activación y control concurrente de cupos, refresh/desactivación.
4. Importación/aprobación `.licreq`, exportación `.lic`, renovaciones offline, transferencias y recuperación administrativa.
5. Pruebas de interoperabilidad PHP Sodium ↔ cliente Go con fixtures idénticas a las del gestor; despliegue seguro en VPS Ubuntu con PHP-FPM, MySQL 8 y Nginx (configuración de entorno, migraciones, copia de seguridad y restauración).

Pruebas obligatorias: firma JWS correcta/incorrecta, selección `kid` y rotación; proof de desafío y reintento idempotente; dos activaciones simultáneas de la misma licencia; modo híbrido como combinación de features existentes sin nuevo campo; perpetua sin mantenimiento incluido; 15 días exactos; renovación y transferencia offline; revocación recibida al reconectar; importación duplicada o alterada `.licreq`; cliente Go de otro equipo no puede importar `.lic` ajena; validación de versiones sin mantenimiento. Documenta `ARCHITECTURE.md`, `SCHEMA.md`, `API.md`, `SECURITY.md`, `LICENSE_CONTRACT.md`, `OPERATIONS.md`, `DECISIONS.md`. Desarrollar módulo por módulo con archivos completos, migraciones, pruebas y comandos reproducibles; no publicar secretos ni llaves reales.

---

# ANEXO OBLIGATORIO — CONTRATO COMPARTIDO DE LICENCIAS V1.0

Este anexo es la misma especificación para el gestor documental y el servidor de licencias. Ningún proyecto puede cambiar unilateralmente campos, semánticas, firmas ni endpoints. Si surge una incompatibilidad, registrar una propuesta de V1.1 y conservar V1.0. Elaborar pruebas de contrato con vectores de prueba compartidos.

## Decisiones comerciales inalterables

- `product_id` inicial: `gestor_documental` (identificador técnico provisional, estable aunque cambie la marca).
- Dos modalidades: `perpetual` (pago único, uso indefinido) y `subscription` (vencimiento explícito).
- **Una instalación activa por licencia**, con transferencia/desactivación administrada; **usuarios ilimitados**; sin límite inicial de documentos, expedientes ni bibliotecas. Conservar `limits` extensible para otros productos futuros.
- Los módulos se habilitan por licencia. Claves iniciales: `linked_libraries`, `managed_libraries`, `ocr`, `expedientes`, `review_workflow`. Dependencias: `review_workflow` requiere `expedientes`, y los expedientes que reciben cargas requieren `managed_libraries`. La API y el cliente validan combinaciones.
- Suscripción: **15 días completos de tolerancia** tras `expires_at`, calculados localmente incluso sin Internet; después, **solo lectura**. Durante tolerancia funciona normalmente y se informa al administrador. No renovar ni prolongar automáticamente la tolerancia por falta de conexión.
- Perpetua: **SIN mantenimiento incluido**, SIN vencimiento y SIN obligación de consultar Internet periódicamente para seguir funcionando. El mantenimiento/actualizaciones es una compra independiente y opcional. `maintenance_until` es `null` hasta que se contrate.
- Ambas modalidades: activación y renovación tanto en línea como mediante archivos **desde V1**. Un fallo temporal del servidor de licencias nunca bloquea por sí solo una perpetua válida ni una suscripción vigente o en tolerancia.
- Vencida/revocada: conservar consulta, visualización, descarga y respaldo/exportación de los documentos propios; bloquear nuevas cargas, clasificaciones, aprobaciones y modificaciones. Sin activar o firma inválida: asistente de activación/recuperación; conservar una recuperación y exportación administrativa segura, sin permitir escrituras documentales.
- El servidor de licencias **nunca recibe PDF, texto OCR, rutas de documentos ni la base de datos del cliente**.

## Identificadores, criptografía y tiempos

- `product_id`: identificador estable de producto; `license_id`: UUID de licencia comercial; `installation_id`: UUIDv4 local persistente; `activation_id`: UUID de una activación autorizada; `request_id`: UUID para idempotencia/trazabilidad.
- Al instalar, Go genera un par **Ed25519 por instalación**; guarda la clave privada de modo seguro y nunca la envía. Calcula una huella de equipo versionada y minimizada (`fingerprint_hash` = `sha256:` + hexadecimal en minúsculas, `fingerprint_version` = `1`). No depender solo de MAC ni enviar identificadores de hardware en claro. Establecer y documentar los componentes por SO y una ruta administrativa de recuperación ante reemplazos de hardware. Es una barrera comercial, no una garantía criptográfica contra administradores del equipo.
- El servidor firma licencias con su clave privada **Ed25519** en formato **JWS Compact** con `alg: EdDSA`, `kid` y `typ: lic+jws`; el cliente contiene únicamente claves públicas de verificación asociadas a `kid`. Claves de producción, desarrollo y manifiestos de actualizaciones estarán separadas por propósito/entorno. Nunca incluir claves privadas de producción ni claves comerciales reales en repositorios o instaladores.
- Todos los instantes del JSON se intercambian como RFC 3339 UTC (p. ej. `2026-09-22T18:30:00Z`), nunca hora local. `expires_at` es instante exclusivo: activa si `now < expires_at`; tolerancia mientras `expires_at <= now < expires_at + 15 días`, después solo lectura. Las licencias perpetuas tienen `expires_at: null` y `grace_days: 0`.
- El programa registra la última hora de validación confiable y avisa de retrocesos sospechosos del reloj; no prometer resistencia absoluta frente a manipulación de un equipo totalmente desconectado.

## Payload firmado EXACTO de licencia V1 (admite campos extensibles, sin cambiar ni omitir los obligatorios)

```json
{
  "schema_version": "1.0",
  "product_id": "gestor_documental",
  "license_id": "UUID",
  "activation_id": "UUID",
  "installation_id": "UUID",
  "installation_public_key": "base64url de 32 bytes Ed25519",
  "fingerprint_version": "1",
  "fingerprint_hash": "sha256:64_hex_minusculas",
  "license_type": "perpetual",
  "license_status": "active",
  "license_revision": 1,
  "issued_at": "2026-09-22T18:30:00Z",
  "expires_at": null,
  "grace_days": 0,
  "maintenance_until": null,
  "entitled_release_until": "2026-09-22T18:30:00Z",
  "features": {
    "linked_libraries": true,
    "managed_libraries": true,
    "ocr": true,
    "expedientes": true,
    "review_workflow": true
  },
  "limits": {
    "max_installations": 1,
    "max_users": null,
    "max_libraries": null,
    "max_documents": null
  }
}
```

Para `subscription`: `expires_at` obligatorio, `grace_days:15`, `maintenance_until:null`; `entitled_release_until` puede coincidir con el vencimiento contratado. Para perpetua sin mantenimiento, `entitled_release_until` es la fecha de corte de versiones incluida al comprar (no confundir con vencimiento del uso): el instalador/actualizador solo permite nuevas versiones con `published_at` firmado anterior o igual a esa fecha. Si compra mantenimiento, el servidor emite una **nueva revisión** con `maintenance_until` y `entitled_release_until` extendidos; al vencer, las versiones ya instaladas siguen funcionando. El cambio de módulos o límites también se materializa en una revisión firmada. Nunca se elimina contenido por perder un módulo.

`license_status` firmado es `active` o `revoked`. Los estados locales derivados `grace`, `expired`, `invalid`, `unactivated` no se envían como modalidad comercial. El cliente conserva la revisión más alta que haya aceptado por activación y no acepta retrocesos, salvo recuperación administrativa documentada.

## API HTTPS JSON `/v1`

1. `POST /v1/activations/challenge`: recibe `action` (`activate`, `refresh`, `deactivate`), `product_id`, `installation_id` y `activation_id` (null en primera activación); devuelve `challenge_id`, `nonce` criptográficamente aleatorio y `expires_at`. Desafíos de un solo uso y corta duración.
2. `POST /v1/activations`: recibe `request_id`, `product_id`, `license_key`, `installation_id`, `installation_public_key` (base64url), `fingerprint_version`, `fingerprint_hash`, `challenge_id`, `proof` y `app_version`. Comprueba clave, capacidad de instalación, desafío y firma con la clave pública declarada; devuelve `{ "license_jws": "...", "server_time": "...", "request_id": "..." }`. La clave comercial solo se usa para activar, nunca como contraseña de cada petición.
3. `POST /v1/activations/refresh`: recibe `request_id`, `product_id`, `installation_id`, `activation_id`, `challenge_id`, `proof`, `app_version`; comprueba la clave pública previamente vinculada; devuelve el JWS actualizado y la hora del servidor, incluso si la licencia acaba de revocarse (`license_status:revoked`).
4. `POST /v1/activations/deactivate`: misma autenticación de `refresh`; devuelve `activation_id`, `deactivated_at` y estado `deactivated`. Solo una desactivación confirmada en el servidor libera la plaza. Una instalación sin Internet puede generar una solicitud firmada de desactivación para procesamiento manual; reconocer expresamente que no puede borrarse a distancia una copia desconectada.

**Firma de prueba de posesión**: `proof = base64url(Ed25519.sign(installation_private_key, UTF8("LIC-V1\n" + action + "\n" + challenge_id + "\n" + nonce + "\n" + product_id + "\n" + installation_id + "\n" + (activation_id || "-"))))`. El servidor valida la acción del desafío, caducidad, un solo uso y correspondencia de la clave vinculada. TLS obligatorio. Idempotencia por `request_id` (reintentos idénticos no generan activaciones adicionales). Errores estables: `{ "error": { "code": "...", "message": "...", "request_id": "..." } }`; incluir `INVALID_REQUEST`, `INVALID_PROOF`, `LICENSE_NOT_FOUND`, `ACTIVATION_LIMIT`, `REVOKED`, `INCOMPATIBLE_SCHEMA`, `RATE_LIMITED`, `TEMPORARY_UNAVAILABLE`. Aplicar códigos HTTP adecuados, límites de tasa y prohibición de registrar claves comerciales en logs.

## Archivos de activación/renovación/desactivación sin Internet

- Solicitud `.licreq`: JSON UTF-8 `{ "schema_version":"1.0", "payload_b64u":"...", "signature_b64u":"..." }`. `payload_b64u` es base64url sin relleno de los bytes UTF-8 de un JSON con `action` (`activate`/`renew`/`deactivate`), `request_id`, `created_at`, `product_id`, `installation_id`, `installation_public_key`, `fingerprint_version`, `fingerprint_hash` y, para renovaciones/desactivaciones, `license_id` y `activation_id`. La firma es Ed25519 sobre bytes UTF-8 `"LICREQ-V1\n" + payload_b64u`. El servidor verifica clave y firma y el administrador asigna la licencia durante la activación inicial; no incluir necesariamente la clave comercial en el archivo compartido.
- Respuesta `.lic`: el **mismo JWS Compact** usado en línea (solo texto UTF-8). El programa comprueba firma y coincidencia exacta de producto, instalación, clave pública y huella antes de importarla; para renovaciones verifica también licencia/activación y revisión. Solicitudes y respuestas archivadas con trazabilidad; la carga de la solicitud no concede una activación sin decisión del servidor/administrador.
- Para transferencias: desactivación en línea o aceptación manual de solicitud firmada; ante equipo averiado, el administrador puede forzar transferencia dejando rastro de auditoría y aceptando la limitación de revocación offline.

## Validación, consulta y privacidad

- El backend Go verifica JWS, vigencia, huella, módulos, revisión y restricciones en **cada operación protegida**; React solo muestra estado y mensajes. Comprobación programada con servidor cuando haya red (valor inicial orientativo: cada 24 h), **no requisito de operación** para licencias válidas.
- Una revocación solo se conoce al recibir un JWS revocado o confirmación autenticada; nunca insinuar revocación instantánea en instalaciones offline. Para consultas en modo solo lectura, preservar acceso y exportación tras el vencimiento.
- Publicar en ambos repositorios las mismas pruebas de contrato (payload y JWS de desarrollo conocidos, éxito/fracaso de firma, tiempos límite, licencia errónea para otro equipo, 15 días, reintentos idempotentes, renovación offline, activación duplicada, módulos). Las claves de prueba no se aceptan en producción.
