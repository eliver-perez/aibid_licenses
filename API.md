# API y formatos de intercambio V1

El [anexo literal](LICENSE_CONTRACT.md) es la fuente normativa. Este documento lo organiza y propone detalles operativos donde el anexo no los fija. No se agregan rutas ni campos obligatorios. Las interpretaciones B-01/B-02 están aceptadas en [DECISIONS.md](DECISIONS.md). Los cuatro endpoints online ya están implementados y verificados en etapa 3. La sección de archivos offline describe la etapa 4 pendiente.

## 1. Convenciones

Exactamente cuatro rutas de licencia, todas `POST` sobre HTTPS y JSON UTF-8. Sin cookies de panel, autenticación por sesión ni CORS abierto en la API de instalaciones. Respuestas con `Cache-Control: no-store`. La clave comercial nunca aparece en URL, respuesta de activación o logs.

Límite online implementado: cuerpos JSON de hasta 16 KiB. Para los futuros archivos `.licreq` se prevé hasta 64 KiB, profundidad JSON limitada y cadenas con límites razonables documentados en los validadores. No se admite contenido duplicado ambiguo, UTF-8 inválido, coerción de tipos ni miembros desconocidos en solicitudes de estos cuatro esquemas. Los payloads firmados de licencia sí admiten extensiones como indica V1, conservando todos sus campos obligatorios.

UUID con representación estándar; `installation_id` v4. Las identidades recibidas se validan y conservan en el mensaje proof tal como las envió el cliente, sin cambiar mayúsculas antes de verificar la firma. Para comparar identidad se utiliza el UUID decodificado. IDs generadas por el servidor se emiten en minúsculas. `fingerprint_version` es string `"1"`, no entero. `installation_public_key` es base64url estricto sin relleno que decodifica exactamente 32 bytes; `proof` y `signature_b64u` decodifican 64 bytes.

Se emiten fechas UTC RFC 3339 con `Z`; las fechas UTC de solicitudes offline admiten `Z` o `+00:00`, validación de calendario y fracción válida sin cambiar los bytes firmados. El reloj declarado por un cliente no determina caducidad de desafíos ni concede días adicionales. `created_at` offline se muestra al operador como fecha declarada, no verificada.

## 2. Rutas y campos

| Ruta | Campos de entrada V1 | Resultado |
| --- | --- | --- |
| `/v1/activations/challenge` | `action`, `product_id`, `installation_id`, `activation_id` | `challenge_id`, `nonce`, `expires_at` |
| `/v1/activations` | `request_id`, `product_id`, `license_key`, `installation_id`, `installation_public_key`, `fingerprint_version`, `fingerprint_hash`, `challenge_id`, `proof`, `app_version` | `license_jws`, `server_time`, `request_id` |
| `/v1/activations/refresh` | `request_id`, `product_id`, `installation_id`, `activation_id`, `challenge_id`, `proof`, `app_version` | JWS vigente, hora del servidor y correlación de solicitud |
| `/v1/activations/deactivate` | Los mismos campos que `refresh` | `activation_id`, `deactivated_at`, estado `deactivated` |

El cliente solicita siempre un desafío con `action` correspondiente a la ruta. `activate` lleva `activation_id:null`; `refresh` y `deactivate` llevan el UUID de activación. La activación inicial no incorpora `activation_id` a su cuerpo: se toma el valor nulo del desafío para la prueba.

### Desafío

```json
{
  "action": "activate",
  "product_id": "gestor_documental",
  "installation_id": "22222222-2222-4222-8222-222222222222",
  "activation_id": null
}
```

Salida: un `challenge_id` UUID aleatorio, un `nonce` opaco y `expires_at`. Implementado: nonce de 32 bytes de `random_bytes()` codificados en base64url sin relleno y vida de 5 minutos. El cliente firma la cadena `nonce` recibida; no la decodifica para construir el proof. La vigencia es exclusiva: debe cumplirse `now < expires_at` al comprobarlo bajo bloqueo.

Emitir un desafío no valida una licencia ni confirma que una activación exista. Para identidades sintácticamente válidas, una respuesta uniforme reduce enumeración; el endpoint final determina autenticación y autorización. Los desafíos no requieren `request_id` y cada petición crea uno nuevo.

### Prueba de posesión exacta

```text
proof = base64url(Ed25519.sign(installation_private_key,
    UTF8("LIC-V1\n" + action + "\n" + challenge_id + "\n" + nonce + "\n" +
         product_id + "\n" + installation_id + "\n" + (activation_id || "-"))))
```

Los separadores son bytes LF (`0A`), no CRLF ni los caracteres literales barra y `n`. No existe salto final. Para activar, el último valor es `-`. `renew` es una acción offline; el refresh online usa exactamente `refresh`.

La activación verifica con la pública recibida; refresh/desactivación verifican con la pública previamente vinculada. El servidor compara acción, producto, instalación y activación con el desafío. El proof V1 **no firma el cuerpo completo**: no incluye `request_id`, `license_key`, huella ni `app_version`. Se preserva ese formato; TLS protege el transporte y el servidor guarda el digest completo para detectar reintentos modificados. Si se requiere firmar todo el cuerpo, habrá que acordar otro contrato.

### Activación

La clave comercial se usa para localizar y autorizar una licencia de ese producto después de comprobar formato y prueba. Se aplica la regla de plaza única. El resultado usa HTTP 200 tanto inicialmente como en un reintento idéntico:

```json
{
  "license_jws": "<JWS Compact firmado>",
  "server_time": "2026-09-25T18:30:00Z",
  "request_id": "44444444-4444-4444-8444-444444444444"
}
```

Los marcadores entre ángulos ilustran tipos; no son vectores criptográficos. `license_id` y `activation_id` se obtienen del payload firmado, sin duplicarlos en campos nuevos del sobre.

### Refresh

Comprueba la identidad vinculada y devuelve el JWS más reciente disponible para esa activación, con `server_time` y `request_id` en el mismo sobre que activación. Si la licencia fue revocada, responde con éxito y JWS `license_status:revoked`; no sustituye la evidencia firmada por un error `REVOKED`.

No renueva comercialmente ni crea una revisión por cada consulta. En una suscripción vencida devuelve su estado firmado y fecha original; el cliente deriva tolerancia o vencimiento. Las activaciones retiradas conservan su identidad para recibir la evidencia terminal; B-01 define la interpretación de retirada por transferencia/desactivación.

### Desactivación

Comprueba la pública vinculada y libera la plaza únicamente al confirmar la transacción. El anexo exige los tres valores del acuse pero no escribe la clave JSON del estado. Se utilizará el siguiente formato, **aceptado por el usuario en B-02**:

```json
{
  "activation_id": "33333333-3333-4333-8333-333333333333",
  "deactivated_at": "2026-09-25T18:30:00Z",
  "status": "deactivated"
}
```

No se agrega un JWS ni `request_id` al acuse sin acuerdo con el cliente. Un reintento idéntico recibe el mismo acuse. Un request nuevo autenticado de una activación ya `deactivated` devuelve el instante original y consume su propio desafío; no cambia revisiones. Para activaciones retiradas por otros motivos, no revelar un estado distinto antes de autenticar; se devuelve `REVOKED`; la recuperación administrativa forzada corresponde a etapa 4.

## 3. JWS Compact y payload de licencia

Cabecera protegida emitida: `{"alg":"EdDSA","kid":"<identificador registrado>","typ":"lic+jws"}`. Algoritmo efectivo: Ed25519 de Sodium. No se acepta un algoritmo alternativo indicado por el emisor no confiable. El `kid` solo selecciona una clave local autorizada para licencias y entorno; nunca construye una URL o ruta de archivos.

```text
header_segment  = base64url(UTF8(header_json))
payload_segment = base64url(UTF8(payload_json))
signing_input   = ASCII(header_segment + "." + payload_segment)
signature       = Ed25519.sign(server_private_key, signing_input)
license_jws     = header_segment + "." + payload_segment + "." + base64url(signature)
```

La verificación opera sobre los segmentos originales, sin reserializar JSON ni reordenar propiedades. No se añade prehash SHA-256 a Ed25519. Esta construcción corresponde a [JWS Compact, RFC 7515](https://www.rfc-editor.org/rfc/rfc7515.html) y [EdDSA en JOSE, RFC 8037](https://www.rfc-editor.org/rfc/rfc8037.html); el perfil `lic+jws` y los campos de licencia proceden del contrato del proyecto.

| Grupo | Campos obligatorios que se conservan |
| --- | --- |
| Contrato e identidad | `schema_version`, `product_id`, `license_id`, `activation_id`, `installation_id`, `installation_public_key`, `fingerprint_version`, `fingerprint_hash` |
| Derechos y tiempo | `license_type`, `license_status`, `license_revision`, `issued_at`, `expires_at`, `grace_days`, `maintenance_until`, `entitled_release_until` |
| Capacidades | Objeto `features` con las cinco claves iniciales explícitas y booleanas para `gestor_documental` |
| Límites | Objeto `limits` con `max_installations`, `max_users`, `max_libraries`, `max_documents` |

El payload exacto y sus ejemplos normativos están en [LICENSE_CONTRACT.md](LICENSE_CONTRACT.md). Los valores nulos obligatorios no se omiten. `license_revision` es entero positivo; no float ni string. `license_status` solo `active`/`revoked`. `schema_version` es `"1.0"`. El JWS no es un JWT de sesión: no se reemplaza `expires_at` por `exp`, ni se añaden `iat` o `aud` como requisitos.

Para verificar base64url: permitir solo `A-Z a-z 0-9 _ -`, sin `=`, espacios ni saltos; decodificar estrictamente y comprobar que recodificar produce exactamente el mismo segmento. Validar longitudes y JSON sin claves repetidas. Rechazar `alg:none`, claves desconocidas, cabeceras remotas y extensiones críticas no soportadas. Las extensiones ordinarias de payload no invalidan un documento V1 válido.

## 4. Archivos offline

Sobre `.licreq` exactamente:

```json
{
  "schema_version": "1.0",
  "payload_b64u": "<base64url de los bytes JSON UTF-8>",
  "signature_b64u": "<base64url de la firma Ed25519>"
}
```

El JSON interno contiene `action` (`activate`, `renew`, `deactivate`), `request_id`, `created_at`, `product_id`, `installation_id`, `installation_public_key`, `fingerprint_version`, `fingerprint_hash`. Para `renew` y `deactivate`, además `license_id` y `activation_id`. La activación inicial se asigna administrativamente a una licencia; no requiere clave comercial en el archivo. Si aparece una clave comercial opcional, no usarla para autorizar automáticamente, copiarla a logs ni mostrarla al operador. El sobre original se conserva cifrado como evidencia y se descifra solo para verificar; la vista muestra una proyección sin secretos. No alterar los bytes firmados para redactarlos ni guardar el segmento base64url sensible en claro.

```text
signature_b64u = base64url(Ed25519.sign(installation_private_key,
                                      UTF8("LICREQ-V1\n" + payload_b64u)))
```

Firmar la cadena base64url tal cual, no los bytes JSON decodificados. Verificar antes de aceptar y otra vez antes de aprobar. Para renovar/desactivar, además comprobar que la pública declarada y huella coinciden con la activación guardada: una firma válida con una clave arbitraria no basta. La versión del sobre se valida aparte; no está incluida en esa firma. Una versión incompatible se rechaza.

No fijar una caducidad universal por `created_at`: el contrato admite equipos desconectados y no define TTL. Mostrar antigüedad y diferencias de identidad para decisión administrativa; un replay nunca ejecuta otra vez una solicitud ya resuelta. Renovar no cambia identidad ni IDs, y siempre requiere una nueva solicitud con nueva ID para una nueva operación comercial.

El `.lic` de activación/renovación es exclusivamente el JWS Compact guardado, texto UTF-8 sin envoltorio JSON. El cliente verifica producto, instalación, pública, huella, firma y revisión antes de aceptarlo. La respuesta `.lic` de desactivación seguirá la interpretación aceptada B-01; no se emitirá un payload con estado `deactivated` ni una activación ficticia.

## 5. Idempotencia y errores

Espacio de unicidad implementado en `license_requests`: `(product_id, request_id)` para las tres operaciones online; el campo de canal reserva el mismo espacio para las solicitudes offline de etapa 4. El digest incluye canal y acción: no se permite reutilizar una ID entre acciones. La comparación usa todos los campos y tipos, independientemente del orden de las propiedades del JSON externo. Para `.licreq`, incluye exactamente `payload_b64u`, firma y versión; cambiar los bytes firmados cambia el request aunque el JSON interno tenga el mismo significado.

Una respuesta persistida se devuelve literalmente, incluido `server_time`. Por ello no es una nueva lectura confiable del reloj; el cliente no debe retroceder su última hora confiable al procesar reintentos. Una revisión antigua recuperada por reintento tampoco puede reemplazar una revisión superior ya aceptada. El cliente solicita un refresh nuevo para conocer cambios posteriores.

```json
{
  "error": {
    "code": "INVALID_REQUEST",
    "message": "La solicitud no es válida.",
    "request_id": "44444444-4444-4444-8444-444444444444"
  }
}
```

Las asignaciones HTTP online implementadas son compatibles con los códigos exigidos. `INCOMPATIBLE_SCHEMA` se reserva para archivos con versión de esquema en etapa 4:

| Código | HTTP | Uso |
| --- | --- | --- |
| `INVALID_REQUEST` | 400 | JSON, tipos, campos, combinación o identidad inválidos |
| `INVALID_REQUEST` | 409 | ID ya usada con contenido distinto o decisión offline incompatible |
| `INVALID_PROOF` | 401 | Firma, desafío, acción, caducidad o identidad autenticada incorrectos; mensaje uniforme |
| `LICENSE_NOT_FOUND` | 404 | Clave/producto no autorizan una licencia; no distinguir existente de ajena |
| `ACTIVATION_LIMIT` | 409 | Plaza ocupada, después de validar prueba y clave |
| `REVOKED` | 403 | Activación inicial/operación comercial no autorizada por revocación; no para sustituir un refresh revocado |
| `INCOMPATIBLE_SCHEMA` | 400 | Versión de archivo/contrato no soportada en flujos donde existe `schema_version` |
| `RATE_LIMITED` | 429 | Límite de tasa; incluir `Retry-After` sin datos de licencias |
| `TEMPORARY_UNAVAILABLE` | 503 | BD, firma, bloqueos o dependencia operativa no disponibles |

Si no se recibe un `request_id` válido (incluido challenge), se genera un UUID de correlación para el error; nunca reflejar una cadena arbitraria. No exigir ese campo en challenge. El proxy deberá producir la misma envoltura para rechazos de tamaño y tasa cuando maneje `/v1`; 413/405/415 se representan con `INVALID_REQUEST` y su HTTP correspondiente. Errores del panel tienen presentación propia.

Los mensajes externos no incluyen SQL, existencia de otras activaciones, claves o rutas del servidor. Los logs conservan código y correlación, con detalle técnico redactado. Un 404, 401 o fallo de red por sí mismo no autoriza al cliente a marcar revocada una licencia offline válida.

## 6. Comportamiento verificado de etapa 3

El transporte acepta `application/json` y opcionalmente `charset=utf-8`, exige POST y HTTPS fuera de development/testing en loopback. No usa sesión, cookie, CSRF ni CORS del panel. JSON inválido, UTF-8 inválido, miembros repetidos —también escapados—, tipos incorrectos, propiedades desconocidas y base64url no canónico se rechazan antes de ejecutar negocio.

Desafíos: 30/minuto por IP. Operaciones: 30/minuto por IP y 30/minuto por pública de instalación autenticada y producto. Ventanas fijas de 60 segundos, `Retry-After: 60`; REMOTE_ADDR no se sustituye por encabezados reenviados. La barrera del proxy y el entorno PHP-FPM/TLS real deben comprobarse en el despliegue.

Una negativa comercial autenticada (`LICENSE_NOT_FOUND`, `ACTIVATION_LIMIT`, `REVOKED`) consume su desafío y guarda la respuesta. Una firma inválida no consume el desafío; un fallo de BD, firma o auditoría revierte todas las escrituras del protocolo. Un request idéntico recupera exactamente su respuesta, incluida la hora original, aunque se haya purgado el desafío o retirado la credencial comercial. Se guardan HMAC del cuerpo y evidencia del proof, nunca la clave comercial en claro.

Refresh conserva el JWS y contador cuando no cambiaron derechos; funciona con una suscripción vencida y con una activación retirada. Una nueva activación de una suscripción comercialmente emitida conserva sus fechas contratadas incluso si vencieron: no renueva ni crea tolerancia nueva, y el cliente deriva su estado local. El vencimiento no libera la plaza. Archivar un cliente o producto impide nuevas emisiones comerciales, sin revocar por sí solo licencias existentes.

La desactivación online publica internamente una revisión `revoked` para la instalación saliente y conserva la licencia comercial `issued`. Su respuesta HTTP es únicamente el acuse B-02; la evidencia terminal se obtiene por refresh o descarga administrativa. Una activación posterior recibe otro UUID y el siguiente contador global. El acuse de referencia está en `contracts/v1/fixtures/deactivate-response.json`.

Las fechas emitidas usan RFC 3339 UTC con seis decimales y `Z`. El JWS contiene los 18 campos superiores obligatorios; `features` es siempre objeto, incluidos productos sin capacidades. Los cinco módulos de AIBID son booleanos y los límites no establecidos son null.
