# Integración del consumidor AIDEX — Fase 1

Estado: contratos implementados en el servidor, 21 de octubre de 2026. No modifica el repositorio del cliente. Complementa [API.md](API.md) y [LICENSE_CONTRACT.md](LICENSE_CONTRACT.md); estos documentos siguen definiendo V1.0, Ed25519/JWS y sus campos. La primera versión comercial del producto sigue siendo 1.0; no confundirla con revisiones técnicas o documentales.

## 1. Código online definitivo

Formato: **3-4-4-4**, sin prefijo. Ejemplo ficticio: `K7M-9R3X-6TVP-8CHD`.

Alfabeto exacto: `23456789ABCDEFGHJKMNPQRSTUVWXYZ`. Son 31 símbolos; no se aceptan I, L, O, 0 ni 1. El servidor genera 15 símbolos independientes mediante `random_int`, con 74.31 bits de entropía. Ver [garantías de random_int](https://www.php.net/manual/en/function.random-int.php). No se reservaron símbolos para un checksum: reduciría la entropía del secreto; longitud, alfabeto y agrupación se validan estrictamente.

Se aceptan minúsculas/mayúsculas, 15 símbolos contiguos o los cuatro grupos exactos, con espacios exteriores opcionales. Se rechazan separadores arbitrarios, espacios interiores, símbolos Unicode parecidos y guiones en posiciones distintas. La forma canónica es mayúscula con guiones. No convertir caracteres confundibles en otros caracteres.

Ejemplos equivalentes:

```text
K7M-9R3X-6TVP-8CHD
k7m-9r3x-6tvp-8chd
k7m9r3x6tvp8chd
```

`AIDEX-…` y formatos anteriores no permiten nuevas activaciones. No hay endpoint para convertir o recuperar códigos antiguos; el administrador puede reemplazarlos expresamente. No se borran registros históricos. Una respuesta de idempotencia ya archivada pertenece a su operación histórica y no constituye compatibilidad para nuevas activaciones.

El código es una credencial opaca online: no contiene derechos, identidad del cliente ni vigencia. No validarlo por prefijo antiguo ni derivar información de sus caracteres. No sustituye el JWS de una activación offline.

## 2. API online existente

Origen configurado para producción: `https://licensing.aidex.com.mx`. Solo POST JSON UTF-8, máximo 16 KiB. HTTPS obligatorio en producción. No usa sesión administrativa, cookies ni CORS. La aplicación de escritorio no requiere permisos CORS de navegador.

| Ruta | Función |
| --- | --- |
| `/v1/activations/challenge` | Desafío de activate, refresh o deactivate |
| `/v1/activations` | Activar una instalación con código |
| `/v1/activations/refresh` | Recuperar la revisión vigente de la activación autenticada |
| `/v1/activations/deactivate` | Registrar salida voluntaria y publicar revisión terminal |

No se añadieron endpoints públicos para licencias, claves de firma, PDFs privados o credenciales. No hay `/v1/keys`. Las claves públicas confiables se distribuyen al consumidor por un canal autenticado independiente del archivo recibido.

### Secuencia de activación

1. El consumidor genera una identidad persistente: UUID v4 de instalación y par Ed25519. Mantiene la privada en almacenamiento protegido del sistema operativo.
2. Pide un desafío para su producto/instalación/acción. `activation_id` es null al activar, y obligatorio para refresh/deactivate.
3. Firma los bytes de prueba exactos con su privada y envía los parámetros del contrato.
4. Verifica el JWS recibido con una pública de confianza, su entorno/propósito y el binding local antes de habilitar derechos.

Solicitud sintética de desafío, válida en estructura:

```json
{"action":"activate","product_id":"gestor_documental","installation_id":"22222222-2222-4222-8222-222222222222","activation_id":null}
```

Respuesta 200:

```json
{"challenge_id":"44444444-4444-4444-8444-444444444444","nonce":"NONCE_BASE64URL_DE_32_BYTES","expires_at":"2026-10-21T12:05:00.000000Z"}
```

Los textos en mayúsculas de este ejemplo y los siguientes son **marcadores**, no firmas ni credenciales reales. Para vectores criptográficos completos y verificables usar `contracts/v1/fixtures/vectors.json`, `.lic` y `.licreq`; son públicos, deterministas y nunca se utilizan como firmante de producción.

Bytes firmados, UTF-8, separados por LF, sin LF final:

```text
LIC-V1
activate
44444444-4444-4444-8444-444444444444
NONCE_BASE64URL_DE_32_BYTES
gestor_documental
22222222-2222-4222-8222-222222222222
-
```

Para refresh/deactivate la última línea es el activation_id recibido, y la segunda es la acción. Ver `Protocol::proofMessage`; no reconstruir los bytes mediante reglas distintas ni normalizar UUIDs que formen parte de la prueba recibida.

Solicitud de activación:

```json
{
  "request_id":"55555555-5555-4555-8555-555555555555",
  "product_id":"gestor_documental",
  "license_key":"K7M-9R3X-6TVP-8CHD",
  "installation_id":"22222222-2222-4222-8222-222222222222",
  "installation_public_key":"PUBLICA_ED25519_BASE64URL_DE_32_BYTES",
  "fingerprint_version":"1",
  "fingerprint_hash":"sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "challenge_id":"44444444-4444-4444-8444-444444444444",
  "proof":"FIRMA_ED25519_BASE64URL_DE_64_BYTES",
  "app_version":"1.0"
}
```

Base64url sin `=` y tamaño exacto de claves/firmas. El fingerprint es una huella SHA-256 declarada por la instalación; no representa atestación de hardware. El servidor firma los valores aceptados y exige coincidencia posterior.

Respuesta de activate/refresh:

```json
{"license_jws":"CABECERA.PAYLOAD.FIRMA","server_time":"2026-10-21T12:00:00.000000Z","request_id":"55555555-5555-4555-8555-555555555555"}
```

Refresh/deactivate reciben exactamente request_id, product_id, installation_id, activation_id, challenge_id, proof y app_version. No llevan license_key ni la pública nuevamente. Un refresco autenticado de una activación retirada conserva su revisión terminal; no la vuelve a activar.

Respuesta de desactivación online:

```json
{"activation_id":"33333333-3333-4333-8333-333333333333","deactivated_at":"2026-10-21T12:00:00.000000Z","status":"deactivated"}
```

El consumidor puede obtener por refresh el JWS terminal `license_status=revoked`. Debe conservar localmente la retirada y el contador máximo de revisión para impedir reimportar una revisión anterior en operación normal. Restaurar una copia completa del estado de un equipo aislado requiere medidas del consumidor y del sistema operativo; el servidor no demuestra borrado remoto.

### Reintentos y límites

Cada operación usa un request_id UUID. Reintentar la misma operación conserva sus parámetros y prueba; el servidor guarda el resultado exacto. Otro contenido/canal bajo el mismo identificador se rechaza. La normalización del código ocurre antes de calcular la idempotencia: su representación con o sin guiones/minúsculas es equivalente.

Límites: 30 solicitudes/minuto por IP para desafíos u operaciones, 30/minuto por pública de instalación y, para activate, **10 intentos por código/producto cada 900 segundos**. Los índices de tasa usan HMAC; no almacenan códigos en claro. 429 devuelve Retry-After=60; el consumidor debe aplicar backoff y considerar que un límite por código puede durar más de ese intervalo. No crear identidades o request_id nuevos para eludir límites.

| HTTP / código | Interpretación |
| --- | --- |
| 400 INVALID_REQUEST | JSON/campos/prueba codificada no válidos |
| 401 INVALID_PROOF | Identidad/prueba no autenticada, desafío vencido o consumido |
| 404 LICENSE_NOT_FOUND | Código inválido, antiguo, desconocido, retirado o producto no autorizado |
| 409 ACTIVATION_LIMIT | Ya existe una instalación activa |
| 409 INVALID_REQUEST | Conflicto de idempotencia/canal |
| 403 REVOKED | Licencia o autorización revocada en la operación correspondiente |
| 429 RATE_LIMITED | Demasiados intentos |
| 503 TEMPORARY_UNAVAILABLE | Falla temporal de infraestructura o firmante |

Errores conservan `{ "error": { "code": "…", "message": "…", "request_id": "UUID" } }`. HTTP 405/415/413 preservan este esquema en API y los límites del proxy. Los textos pueden variar; decidir por código y estado, no por mensajes traducidos. No interpretar timeout/503/desconexión como desactivación o revocación.

## 3. Solicitud offline .licreq

Se reutiliza el formato **1.0** existente. Tamaño máximo 64 KiB; JSON sin claves duplicadas, esquema/campos exactos y UTF-8 válido. El archivo se transporta físicamente por el responsable autorizado y se importa mediante el panel; no requiere que el equipo contacte al servidor.

Sobre exterior:

```json
{"schema_version":"1.0","payload_b64u":"JSON_INTERIOR_BASE64URL","signature_b64u":"FIRMA_ED25519_BASE64URL_DE_64_BYTES"}
```

La firma cubre exactamente `LICREQ-V1\n` seguido de payload_b64u, sin nueva línea final. El interior se codifica antes de firmar; conservar sus bytes, no reserializarlo para verificar.

Interior de activación:

```json
{
  "action":"activate",
  "request_id":"55555555-5555-4555-8555-555555555555",
  "created_at":"2026-10-21T12:00:00Z",
  "product_id":"gestor_documental",
  "installation_id":"22222222-2222-4222-8222-222222222222",
  "installation_public_key":"PUBLICA_ED25519_BASE64URL_DE_32_BYTES",
  "fingerprint_version":"1",
  "fingerprint_hash":"sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
}
```

`license_key` es opcional por el contrato original y **no autoriza** por sí mismo una solicitud offline. Se recomienda omitirlo. El original, si lo contiene, se archiva cifrado; las proyecciones y el panel lo eliminan. Para renew/deactivate son obligatorios además license_id y activation_id; se cotejan producto, UUID, pública y fingerprint con la activación vigente. created_at valida calendario UTC pero no acredita vigencia ni se usa para obtener derechos mediante un reloj atrasado.

La primera activación prueba posesión de la privada que corresponde a la pública incluida. Un operador debe cotejar la solicitud con el equipo y con la autorización comercial mediante un canal confiable antes de asignar la licencia. No constituye prueba de número de serie físico ni TPM/Secure Enclave. Una simple modificación de identidad sin volver a firmar invalida la solicitud.

Importación/decisión comparten el espacio de request_id con online. Una solicitud repetida idéntica lleva al registro original, no crea otra activación. Las decisiones son únicas. Reutilizar request_id para otros bytes/identidad/canal se rechaza. La aprobación bloquea licencia/activaciones y comprueba una plaza disponible; nuevas carreras no crean dos plazas.

## 4. Archivo offline .lic

Es el **JWS compacto** existente, UTF-8 sin envolver ni agregar saltos de línea. Header `alg=EdDSA`, `typ=lic+jws`, `kid` conocido. Firma Ed25519 sobre los segmentos base64url de cabecera y payload separados por punto; no verificar una reserialización del JSON. La pública debe provenir del conjunto de confianza del consumidor, no del archivo recibido.

Campos del payload, sin añadir una caducidad offline artificial:

```json
{
  "schema_version":"1.0",
  "product_id":"gestor_documental",
  "license_id":"11111111-1111-4111-8111-111111111111",
  "activation_id":"33333333-3333-4333-8333-333333333333",
  "installation_id":"22222222-2222-4222-8222-222222222222",
  "installation_public_key":"PUBLICA_ED25519_BASE64URL_DE_32_BYTES",
  "fingerprint_version":"1",
  "fingerprint_hash":"sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "license_type":"perpetual",
  "license_status":"active",
  "license_revision":1,
  "issued_at":"2026-10-21T12:00:00.000000Z",
  "expires_at":null,
  "grace_days":0,
  "maintenance_until":null,
  "entitled_release_until":"2026-10-21T12:00:00.000000Z",
  "features":{"linked_libraries":true,"managed_libraries":false,"ocr":true,"expedientes":true,"review_workflow":false},
  "limits":{"max_installations":1,"max_users":null,"max_libraries":null,"max_documents":null}
}
```

El consumidor comprueba firma, kid/entorno/purpose, esquema, producto, tipos/fechas, límites, módulos/dependencias, installation_id, pública local, fingerprint y contador de revisión. Debe demostrar posesión de la privada local correspondiente, no aceptar solo que coincida un UUID editable. Ver vectores negativos, activación de otra instalación y conflictos de revisión en los contratos existentes.

**Perpetua:** expires_at=null, grace_days=0. El mantenimiento/fecha de versiones no caduca el uso adquirido. No existe offline_expires_at, heartbeat obligatorio, renovación periódica del archivo ni desconexión como causa de revocación. Las revisiones emitidas se conservan y las descargas futuras entregan exactamente sus bytes y SHA-256.

**Suscripción:** expires_at es exclusivo, grace_days=15. Desde el vencimiento y hasta expires_at+15 días está en tolerancia; al alcanzar ese límite expira la escritura autorizada y conserva consulta/exportación según el contrato. No se convierte en perpetua por recibir el archivo offline. La renovación comercial explícita genera una revisión nueva, transportable por medios externos, sin requerir conexión del equipo.

### Retirada, traslado y límites de protección

Un request firmado deactivate demuestra posesión de la clave; **no demuestra que se borraron archivos, clones o snapshots**. La liberación offline exige superadministrador, contraseña/TOTP nuevos, motivo y aceptación explícita del límite. El servidor publica una revisión terminal y libera la plaza de manera registrada; el cliente debe conservar esa retirada local para rechazar el archivo anterior.

Para equipo perdido/averiado hay liberación excepcional o traslado directo al aprobar la solicitud de otro equipo. Ambas requieren superadministrador, reautenticación, motivo y confirmación; se auditan y conservan las revisiones de origen. No se libera por una mera afirmación ni por falta de conexión.

Windows: usar almacén protegido del sistema/TPM cuando esté disponible; Ubuntu: almacenamiento protegido/TPM cuando exista; macOS: llavero y capacidades disponibles. Estas integraciones **corresponden al consumidor futuro**, no están implementadas por el servidor. No todas las plataformas permiten una clave Ed25519 no exportable; verificar las capacidades reales antes de prometer atestación o resistencia a clones. Proteger también contadores/retiros locales, reinstalaciones y migraciones autorizadas.

El servidor no puede impedir por sí solo que quien copie privada, huella y todo el estado de un equipo controle un clon aislado, ni que una instalación aislada ignore para siempre una revocación posterior. No se introduce conectividad obligatoria como solución. La política de una plaza se aplica al registro central; no equivale a destrucción física verificable de una copia offline.

## 5. Constancias y contratos administrativos implementados

Los siguientes endpoints pertenecen al **panel administrativo**, con su cookie, contraseña/MFA/CSRF y roles. No son una API para el cliente de escritorio. Enviar formularios `application/x-www-form-urlencoded`; session/CSRF/operation_id se obtienen del panel. No enviar credenciales en URLs.

| Método y ruta | Datos / resultado |
| --- | --- |
| POST `/admin/licenses/{id}/administrative` | reference y reason opcionales, administrative_version y operation_id; 303 al detalle |
| POST `/admin/licenses/{id}/reveal` | password, code TOTP y CSRF; página no-store con el código autorizado |
| POST `/admin/licenses/{id}/documents` | document_type=licensing o delivery; para delivery activation_method=online/offline y password/code; offline exige revision_id; devuelve 303 |
| GET `/admin/documents/{id}/download` | PDF de licenciamiento autorizado; para entrega muestra confirmación de identidad |
| POST `/admin/documents/{id}/download` | PDF de entrega histórico con password/code y CSRF; bytes inmutables |
| GET `/admin/licenses/{id}/revisions/{revision}/download` | Formulario de reautenticación, nunca el archivo directamente |
| POST misma ruta | password/code y CSRF; descarga exacta .lic, sin otra activación |
| GET/POST `/admin/offline/{id}/download` | Misma confirmación/descarga para una decisión aprobada |
| GET `/verify/{referencia_aleatoria}` | Página pública sin sesión con folio/tipo/revisión/fecha/producto/modalidad, estado actual y SHA-256 del PDF |

Una constancia licensing no contiene código, JWS ni identidad privada del equipo. Una constancia delivery online incluye el código únicamente bajo los permisos `documents.delivery` **y** `credentials.reveal`; una entrega offline requiere `documents.delivery` **y** `offline.download`. Los permisos se revalidan incluso para documentos históricos. Verificación pública no permite recuperar PDFs, credenciales o archivos .lic.

Cada tipo tiene revisiones documentales independientes por licencia y un folio aleatorio de 122 bits. Un token público de verificación de 256 bits identifica el documento, no autentica administradores; la BD conserva solo su SHA-256 fuera de los contenidos cifrados. QR apunta al origen APP_URL. Esa referencia no enumera licencias y tiene límite por IP; muestra datos mínimos, sin titular/RFC, código, IDs de instalación/licencia, notas ni datos de administrador.

El PDF no incorpora firma PAdES. La autenticidad se comprueba sobre HTTPS contra el registro del servidor, contrastando **SHA-256 del PDF recibido** con el publicado; un QR copiado a otro PDF no valida su contenido. La huella de un .lic identifica sus bytes, pero su autenticidad sigue dependiendo de Ed25519. Una constancia histórica auténtica no acredita que la licencia o su activación sigan vigentes; la verificación muestra revocación/vencimiento y señala entregas reemplazadas/retiradas.

Editar referencia/nota aumenta exclusivamente administrative_version; no incrementa license_revision, no cambia código/activación, no firma otra licencia y no obliga al consumidor a revalidar. Una constancia nueva captura los datos administrativos vigentes; los PDF previos conservan bytes, contenido, huella y permisos de descarga.

## 6. Cambios necesarios en el consumidor futuro

1. Capturar/normalizar 3-4-4-4 sin prefijo ni sustitución de caracteres ambiguos; ocultar códigos en logs/telemetría.
2. Mantener los cuatro endpoints y las pruebas de posesión V1 ya descritas; manejar 429/backoff e idempotencia.
3. Exportar/importar .licreq/.lic preservando bytes, con clave local persistente protegida y verificación íntegra del binding.
4. Conservar máximos de revisión y retiradas locales; distinguir indisponibilidad, expiración y revocación.
5. Respetar perpetuas sin caducidad offline, suscripciones/tolerancia y fecha de versiones adquiridas.
6. Preparar controles del sistema operativo y una política explícita de reinstalación/clones/recuperación; no prometer atestación que no exista.
7. Distribuir pública/kid de producción por canal autenticado y probar Windows/Ubuntu/macOS con relojes y equipos sintéticos antes de distribuir.

Las pruebas del servidor verifican contratos y firmas, pero no sustituyen ensayos del consumidor en esos sistemas operativos. La integración del cliente real requiere su toolchain/dependencias y permanece como trabajo separado.
