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
