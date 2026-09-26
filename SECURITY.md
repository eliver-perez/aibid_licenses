# Seguridad y modelo de amenazas

Diseño de seguridad completo por etapas. Autenticación, MFA, sesiones, CSRF, permisos y auditoría administrativa ya se implementaron en etapa 2. Los controles online de activación/firma ya están implementados en etapa 3; offline y operación VPS siguen pendientes. El contrato público se conserva en [LICENSE_CONTRACT.md](LICENSE_CONTRACT.md); los detalles de protocolo están en [API.md](API.md).

## 1. Activos, actores y límites de confianza

Activos: claves de firma, credenciales comerciales, autorizaciones de instalación, credenciales/MFA de administradores, PII mínima de clientes, historia comercial y auditoría. Actores: cliente Go legítimo, administrador por rol, atacante anónimo, usuario con control total de su equipo, operador malicioso y administrador del VPS/BD.

Límites: Internet → Nginx; navegador → sesión del panel; controladores → dominio; app → MySQL; app → claves; VPS → respaldo externo; servidor → cliente desconectado. Tanto JSON como archivos `.licreq`, nombres, fechas declaradas y `app_version` son entradas no confiables. Los archivos se procesan como datos, nunca instrucciones, código PHP, rutas o comandos.

## 2. Matriz de amenazas

| Amenaza | Control y prueba esperada | Riesgo residual |
| --- | --- | --- |
| Falsificar o modificar una licencia | Ed25519, `kid` local autorizado, verificación sobre segmentos exactos; alterar un byte invalida | Compromiso de clave privada o cliente modificado |
| Confusión de algoritmo/clave | Permitir solo EdDSA/Ed25519, `typ:lic+jws`, propósito y entorno; rechazar `none`, `jku`, `x5u` y claves remotas | Configuración de confianza errónea |
| Copiar `.lic` a otra instalación | Comparación de producto, IDs, pública y huella; prueba negativa PHP/Go | Copia íntegra de VM, identidad y privada por administrador del equipo |
| Repetir desafío o solicitud | Caducidad, consumo bajo bloqueo, idempotencia y digest del cuerpo | Request completo capturado puede recuperar su misma respuesta; no crea otros derechos |
| Dos activaciones simultáneas | Bloqueo de licencia, índice único y commit conjunto | Compromiso directo de BD con privilegios para alterar restricciones |
| Sustituir pública en renovación offline | Firma válida más comparación con vínculo previo | Clave legítima robada del cliente |
| Manipular un `.licreq` pendiente | Bytes y firma archivados, revisión al aprobar, decisión atómica | Operador autorizado puede aprobar una solicitud comercialmente equivocada |
| Enumerar licencias/identidades | Desafíos uniformes, errores genéricos, clave de alta entropía, controles tras autenticación | Diferencias de tiempos se minimizan, no se promete tiempo constante de toda la API |
| Fuerza bruta, login abusivo y DoS | Límites por IP/cuenta/ruta, tamaños, profundidad, límites PHP-FPM y ventanas persistidas | Ataques volumétricos requieren protección de infraestructura |
| SQL injection/XSS/CSRF | PDO preparado, listas de columnas permitidas, escape contextual, CSP y tokens CSRF | Error nuevo de programación; pruebas de permisos por operación |
| Robo/fijación de sesión | Cookie segura, rotación, MFA, reautenticación, expiración y revocación | Equipo del administrador comprometido |
| Abuso de privilegios internos | Roles, motivos, MFA para acciones críticas y auditoría sin edición | Superadministrador conserva poder comercial deliberado |
| Exposición de claves en logs/backups | Redacción por allowlist, secretos fuera de web, cifrado y permisos separados | Compromiso simultáneo de proceso y almacén de secretos |
| Borrar o falsificar auditoría | INSERT-only para app, cadena HMAC y anclaje externo | Root con todos los secretos; alteraciones anteriores al último anclaje |
| Retroceder reloj/revisión local | Mayor revisión aceptada y última hora confiable persistidas por Go | Restauración completa de snapshots offline por administrador |
| Revocación offline | JWS revocado al reconectar o importar; mostrar limitación al transferir | Copia desconectada puede seguir usando un JWS anterior |
| Restaurar BD antigua | Conciliar revisiones, plazas y solicitudes antes de volver a emitir | Sin historia suficiente no se puede reconstruir certeza comercial |

## 3. Firma y secretos

La firma usa `sodium_crypto_sign_detached` y la verificación `sodium_crypto_sign_verify_detached`. No implementar Ed25519 manualmente ni usar Ed25519ph. Se valida tamaño de claves, firmas y base64url antes de llamar a Sodium. [PHP: firmas separadas con Sodium](https://www.php.net/manual/en/function.sodium-crypto-sign-detached.php).

Claves privadas solo en archivos/secretos protegidos fuera de `public/`, con acceso al usuario de PHP-FPM estrictamente necesario. En BD solo pública, `kid`, propósito, entorno y referencia opaca; si en el futuro se almacenan privadas cifradas, la clave de cifrado deberá estar fuera de BD y del mismo respaldo. El panel no descarga privadas ni recibe rutas arbitrarias para importarlas. La generación y carga se hace mediante herramienta operativa restringida.

Separar material para firma de licencias, firma de manifiestos, HMAC comercial, digest de idempotencia, cifrado de MFA, cifrado de evidencia offline, auditoría y respaldo. Separar dev/test/prod mediante credenciales y allowlists de claves distintas. Prohibir en producción las públicas y semillas de fixtures conocidas, además de exigir registro explícito de la clave de producción. `kid` identifica, no demuestra confianza.

La rotación normal conserva públicas antiguas indefinidamente mientras existan perpetuas firmadas con ellas. Primero distribuir públicamente la confianza nueva al cliente mediante un mecanismo autorizado, después comenzar a firmar con ella. No borrar la verificación histórica al retirar una privada de firma. Rotación por compromiso es un incidente diferente: no existe invalidación instantánea para clientes desconectados.

La clave comercial propuesta contiene 32 bytes aleatorios codificados de forma transportable. Guardar HMAC-SHA-256 con pepper protegido y versión, no la clave original. Mostrar solo en la respuesta de emisión tras commit; no guardarla en sesiones, idempotencia ni auditoría. Si se pierde esa respuesta, se reemite una credencial y se invalida la anterior con auditoría, sin intentar recuperarla. No se cambia la identidad ni el JWS de una activación existente por reemitir una credencial de activación.

La rotación del pepper necesita conservar versiones anteriores hasta retirar sus credenciales: sin las claves comerciales originales no es posible recalcular todos sus HMAC. La búsqueda evalúa el conjunto acotado de versiones habilitadas. Aplicar la misma disciplina a digests idempotentes; su secreto no se elimina mientras haya registros verificables que dependan de él.

## 4. Bootstrap, contraseñas y MFA

Bootstrap solo por CLI en servidor, sin endpoint público de instalación ni contraseña predeterminada. Crear el primer superadministrador en transacción si no existe ninguno, con exclusión mutua para ejecuciones simultáneas. Pedir contraseña por terminal sin eco; no pasarla en argumentos, historial o logs. El primer acceso solo permite enrolar MFA y guardar códigos de recuperación antes de operar licencias.

Argon2id mediante `password_hash`, `password_verify` y `password_needs_rehash`. Comprobar disponibilidad en el binario FPM real; si falta, fallar el preflight. Propuesta inicial: memoria de 64 MiB, coste temporal 3 y un hilo; ajustar con medición en el VPS y capacidad de concurrencia. No truncar contraseñas silenciosamente, permitir gestores de contraseñas y aplicar un máximo de longitud razonable antes del hash. [PHP: password_hash](https://www.php.net/manual/en/function.password-hash.php).

Se implementa TOTP para todos los administradores desde la etapa 2: periodos de 30 segundos, seis dígitos, ventana de tolerancia acotada y consumo atómico del paso aceptado para impedir reuso. Cifrar el secreto con clave independiente; códigos de recuperación aleatorios, hasheados y de un solo uso. Verificar reloj del VPS. La implementación y sus vectores se basan en [RFC 6238](https://www.rfc-editor.org/rfc/rfc6238.html). WebAuthn puede incorporarse después sin alterar el contrato de licencias.

Restablecer MFA requiere superadministrador reautenticado; no habilitar autorrestablecimiento por correo sin otro factor. Recuperación del último superadministrador requiere acceso operativo al servidor, motivo y auditoría externa, revocando sesiones previas. Mensajes de login uniformes y cálculo de hash de coste equivalente para usuarios inexistentes. [OWASP: autenticación](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).

## 5. Sesiones y navegador

Cookie de sesión en producción `__Host-license_admin`: `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, sin `Domain`; token aleatorio de 32 bytes y solo su hash en BD. Rotar al autenticarse y completar MFA; invalidar al salir, cambiar contraseña/MFA/rol o bloquear la cuenta. Expiración: 30 minutos de inactividad y 8 horas absolutas. Exigir reautenticación reciente para claves, revocación y transferencia forzada. [OWASP: sesiones](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).

Token CSRF impredecible ligado a sesión para todas las mutaciones del panel, incluidos login y carga de archivos; verificar origen cuando esté disponible como defensa adicional. GET no modifica estado. La API de instalaciones no usa cookies y tiene su propia prueba de posesión. [OWASP: CSRF](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html).

Escapar HTML por contexto, sin imprimir JSON o motivos en `innerHTML`. Bootstrap y JavaScript propios servidos localmente. CSP con scripts propios o nonce, `frame-ancestors 'none'`, `object-src 'none'`, `base-uri 'self'`; `X-Content-Type-Options:nosniff`, política restrictiva de referencias y HSTS después de verificar HTTPS. Sin contenido mixto ni fuentes remotas necesarias para administrar.

## 6. Límites de tasa y errores

Etapa 2: ventanas fijas de 15 minutos; login 5 intentos por cuenta y 20 por IP; MFA 10 por cuenta y 20 por IP; reautenticación 10 por cuenta. Se cuentan todos los intentos, también los correctos. No se implementó espera progresiva. La cuenta puede intentar nuevamente en la ventana siguiente o recurrir a recuperación CLI. Ajustar con medición y monitorizar falsos positivos en IP compartidas. Etapa 3 implementa desafíos y operaciones a 30/min por IP (contadores separados), más 30/min por pública autenticada/producto. La importación offline de etapa 4 mantiene como propuesta 20/min por administrador.

El despliegue final deberá configurar la barrera de Nginx por ruta/IP; en etapa 2 la app aplica ventanas atómicas por cuenta/identidad en MySQL. Los contadores de intentos se confirman fuera de la transacción de negocio para que un rollback no los borre. Etapa 2 utiliza REMOTE_ADDR e ignora encabezados reenviados; termina TLS directamente en Nginx. El soporte de proxy deberá validar explícitamente sus emisores antes de confiar en `X-Forwarded-For`. No usar `installation_id` autoafirmado como única defensa de tasa.

Los fallos de infraestructura devuelven 503 uniforme, sin traza pública. Nunca convertir un error de servidor, 404 o timeout en una prueba de revocación local. Ninguna perpetua exige conexión diaria para seguir operando.

## 7. Huella de equipo y recuperación

El servidor recibe únicamente versión y hash, los trata como datos de identidad y no puede verificar qué hardware los originó. Se propone coordinar con Go un único ancla estable por SO, combinado con producto e instalación para minimizar correlación. No es un cambio al formato V1 y no debe sustituir un algoritmo ya implementado en el cliente sin revisar sus fixtures.

| SO | Componente propuesto del lado Go | Observaciones |
| --- | --- | --- |
| Windows | UUID SMBIOS expuesto por `Win32_ComputerSystemProduct.UUID` | Rechazar UUID cero/sentinela; comprobar disponibilidad con permisos del servicio |
| Ubuntu | ID derivado para la aplicación mediante `sd_id128_get_machine_app_specific` | No transmitir `machine-id` original; clones del sistema pueden compartir su origen |
| macOS | UUID de plataforma de IOKit, `kIOPlatformUUIDKey` | Validar comportamiento en hardware y VM con la cuenta real del servicio |

Las fuentes describen esos identificadores; su uso combinado aquí es una propuesta propia para el cliente: [Microsoft](https://learn.microsoft.com/en-us/windows/win32/cimwin32prov/win32-computersystemproduct), [systemd](https://www.freedesktop.org/software/systemd/man/sd_id128_get_machine.html), [Apple IOKit](https://developer.apple.com/documentation/iokit/kioplatformuuidkey).

Propuesta de construcción para acordar: hash SHA-256 de campos UTF-8 con longitud prefijada en bytes, en orden `LIC-FP-V1`, producto, SO, UUID de instalación normalizado y ancla normalizada; salida `sha256:` más 64 hex minúsculas y `fingerprint_version:"1"`. Publicar los bytes exactos y un vector por SO antes de adoptarla. El identificador de aplicación de systemd y la normalización de cada ancla deben acordarse con el repositorio Go; pendiente P-03. No incluir MAC, usuario, hostname, rutas ni números de serie en el protocolo.

Si el ancla no está disponible, no inventar automáticamente otra huella al arrancar: presentar recuperación y no conceder una activación normal con una huella vacía. Se documentará el caso de VM o hardware sin identificador para una política explícita compartida. Cambios de equipo o pérdida de privada usan transferencia administrada con nueva identidad; conservar consulta/exportación segura según contrato. Una copia completa de VM puede clonar todas estas señales, limitación que se acepta y comunica.

## 8. Logs, datos y continuidad

Allowlist de logs: correlación, ruta, código, duración, actor interno y referencias opacas. Nunca request/response completos de activación, cabeceras Cookie/Authorization, claves comerciales, privadas, TOTP, códigos de recuperación ni PDFs/rutas/OCR. Los JWS y `.licreq` son registros protegidos de negocio, no mensajes de log.

Archivar `.licreq` con AEAD XChaCha20-Poly1305 de Sodium, nonce aleatorio por archivo y versión de clave, autenticando también producto/request como datos asociados. Esto permite conservar una firma válida si un cliente incluyó una clave comercial opcional sin almacenarla en claro ni exponerla en proyecciones. Recomendar siempre generar archivos sin esa clave. La misma disciplina de cifrado autenticado y nonce único se aplica a los secretos TOTP, con otra clave. [PHP: cifrado autenticado XChaCha20-Poly1305](https://www.php.net/manual/en/function.sodium-crypto-aead-xchacha20poly1305-ietf-encrypt.php).

Motivos administrativos tienen longitud limitada y aviso de no incluir secretos. Auditoría registra emisión, cambios de módulos, mantenimiento, periodos, importación y decisiones offline, transferencias, revocaciones, login/logout, cambios de rol, MFA y claves. Eventos de éxito se confirman con el cambio; intentos fallidos de acceso tienen registro independiente sin secretos.

Política propuesta: logs técnicos/IP 30 días, auditoría y evidencia de derechos durante la vida de la licencia; contactos mínimos y eliminación/anonimización cuando no afecte trazabilidad necesaria. No se afirma un plazo legal: el propietario debe definir su política aplicable. Respaldos cifrados y restauraciones ensayadas según [OPERATIONS.md](OPERATIONS.md).

## 9. Controles HTTP y secretos de etapa 2

CSP sirve scripts y estilos propios; permite imágenes data únicamente para el QR. Las respuestas dinámicas llevan `Cache-Control: no-store`, `nosniff`, prohibición de framing y `Referrer-Policy: same-origin`: conserva Origin en formularios del propio sitio sin enviar referencias a sitios externos. Cada POST valida CSRF y, si viene Origin, exige el origen exacto configurado. Se rechaza `Origin: null`.

Producción exige HTTPS y cookie `__Host-license_admin`; solo development/testing permite HTTP de loopback y usa `aibid_admin_dev`. APP_URL es un origen sin subdirectorio. `config/local.php` se genera con permisos 0600 y cinco secretos independientes; el repositorio no contiene configuración real. No reemplazar esas claves al actualizar: MFA, búsquedas comerciales, idempotencia y auditoría dependen de ellas.

La cuenta SQL de aplicación tiene SELECT/INSERT, sin UPDATE/DELETE, sobre auditoría, historial, periodos y mantenimiento. La verificación de cadena por CLI permite exportar un anclaje; su almacenamiento externo y los respaldos del VPS aún deben configurarse. No se afirma resistencia frente a un administrador de BD con acceso conjunto a todos los secretos.

## 10. Firma y API de etapa 3

La API no reutiliza autenticación del panel. Verifica Ed25519 sobre los bytes exactos de LIC-V1; mantiene el texto recibido de UUID para la firma y compara identidad en binario. El proof no cubre todos los campos del cuerpo por diseño V1; se exige TLS y el HMAC de idempotencia cubre todos sus campos y tipos. El parser rechaza JSON duplicado, tipos ambiguos, caracteres de control, base64url no canónico y datos fuera de los límites documentados.

Las claves privadas están en archivos 0600 bajo un directorio absoluto fuera de public/. El registro guarda una referencia aleatoria, sin construir rutas a partir de un kid recibido por HTTP. El cargador comprueba ubicación, permisos, par Ed25519 y coincidencia de kid/entorno/propósito/pública entre archivo y BD. Producción rechaza las cuatro claves deterministas públicas del paquete V1 aunque se les cambie el nombre. La app solo consulta el registro; generación/selección por CLI requieren permisos SQL de gestión separados.

La publicación mantiene bloqueo compartido del ámbito firmante hasta commit. La rotación selecciona la nueva clave bajo bloqueo exclusivo, conserva las públicas antiguas y no cambia JWS ya archivados. Un refresh existente puede responder aunque no esté accesible la privada: no necesita refirmar. Activar, cambiar derechos o retirar una activación requiere guardar su nueva firma; si falla, se revierte incluso la liberación de plaza.

No hay claves de producción generadas en esta entrega ni configuración local reemplazada. Los tests crean claves aleatorias temporales y usuarios SQL aislados. La prueba de rechazo en producción solo cambia la etiqueta de entorno dentro de una fixture; no utiliza servicios productivos.
