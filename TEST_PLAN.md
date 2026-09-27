# Plan verificable y contrato de pruebas

Etapas 1–5: vectores, panel administrativo, protocolo online/offline, cliente real y respaldo/restauración con pruebas ejecutables. Las secciones 8 y 9 conservan evidencia histórica; la sección 10 registra la validación local final. No se ejecutaron pruebas en el VPS por indicación del usuario.

## 1. Paquete compartido V1

`contracts/v1/fixtures/vectors.json` contiene públicas, semillas deterministas **exclusivamente de prueba**, payloads UTF-8 exactos, JWS, mensajes proof, solicitudes `.licreq` y casos esperados del cliente. Los `.lic` y `.licreq` adyacentes son ejemplos binariamente reproducibles de esos vectores. No contienen claves comerciales o privadas reales. Cualquier implementación de producción debe rechazar estas claves de prueba.

`contracts/v1/generate-fixtures.php` es una herramienta de pruebas Sodium, no el servicio de firma del producto. `contracts/v1/verify-vectors.go` es un oráculo independiente con la biblioteca estándar Go, no un backend. Ambos trabajan con los mismos bytes. La generación no utiliza hora actual, red ni aleatoriedad para que cambios accidentales aparezcan al comparar artefactos.

Cada vector es un escenario aislado; `revoked` y `rotated` son ramas alternativas, no una secuencia que rehabilita una licencia revocada. Los desafíos de referencia tampoco se insertan juntos como desafíos reales reutilizables. Sin --check, el generador sobrescribe únicamente el directorio de fixtures de prueba. Etapa 3 añade `deactivate-response.json`, acuse B-02 aceptado, sin cambiar los vectores criptográficos anteriores.

Comandos disponibles en esta entrega, desde la raíz del proyecto:

```sh
php contracts/v1/generate-fixtures.php --check
env GOTOOLCHAIN=local GOPROXY=off GOSUMDB=off GOCACHE=/private/tmp/aibidlicense-go-cache go run contracts/v1/verify-vectors.go contracts/v1/fixtures/vectors.json
```

Compartir el paquete por commit/archivo con checksum entre ambos repositorios. B-01/B-02 están aceptadas por el usuario. Antes de publicar V1, comparar el paquete con los fixtures y decodificadores reales del gestor. No reemplazar unilateralmente vectores de un cliente ya publicado. El oráculo comprueba criptografía y bytes; las semánticas de derechos, modo local y endpoints deben probarse también en los servicios reales.

## 2. Contratos y reglas

| ID | Escenario | Resultado exigido | Ejecutores futuros |
| --- | --- | --- | --- |
| C-01 | JWS conocido con Ed25519, alg/kid/typ correctos | Firma válida sobre segmentos originales | PHP y Go real |
| C-02 | Alterar payload o un bit de firma | Rechazo, sin escrituras documentales | PHP y Go real |
| C-03 | `none`, otro alg, kid desconocido, clave de otro entorno/propósito | Rechazo sin descargar claves remotas | PHP y Go real |
| C-04 | Rotación con pública antigua y nueva autorizadas | Ambas verifican; perpetua antigua continúa | PHP y Go real |
| C-05 | Base64url con padding, espacios, caracteres/longitudes inválidos, UTF-8 inválido o JSON duplicado | Rechazo determinista antes de dominio | PHP y Go real |
| C-06 | Misma semántica de JSON con orden distinto y firma sobre sus bytes originales | Verificar bytes, no reconstruir/canonicalizar JWS | PHP y Go real |
| C-07 | Proof activate/refresh/deactivate conocido | Verificar mensaje con LF y sin LF final | PHP y Go real |
| C-08 | CRLF, nonce/action/ID cambiados, desafío caducado/usado | `INVALID_PROOF`; sin activar ni consumir desafío ajeno | API/MySQL |
| C-09 | Falta de campos obligatorios, tipos incorrectos, schema incompatible | Rechazo sin coerción; extensiones de payload válidas conservadas | PHP y Go real |
| C-10 | `.licreq` activate/renew/deactivate conocidos | Firma sobre `LICREQ-V1\n` + segmento, no JSON decodificado | PHP y Go real |
| C-11 | Importación alterada, duplicada o misma ID/contenido distinto | Rechazo/resultado existente/conflicto respectivamente; cero dobles efectos | Panel/MySQL |
| C-12 | `.lic` válido entregado a otra instalación/pública/huella/producto | Rechazo aunque firma sea válida | Go real |
| C-13 | Revisión inferior y reintento que trae JWS antiguo | Conservar revisión superior; recuperación solo con flujo documentado | Go real |
| C-14 | Revisión igual con mismos bytes / con payload diferente | Repetición inocua / rechazo y diagnóstico | Go real |
| C-15 | Clave privada/de fixture habilitada por error en producción | Preflight falla; no emitir ni aceptar | PHP/Go/despliegue |

| ID | Regla comercial | Resultado exigido |
| --- | --- | --- |
| B-01 | Perpetua nueva sin compra de mantenimiento | `expires_at:null`, `grace_days:0`, `maintenance_until:null`; corte explícito |
| B-02 | Compra separada de mantenimiento | Nueva revisión y corte extendido; no vence uso adquirido |
| B-03 | Suscripción justo antes/en/después de vencimiento | Activa antes; tolerancia desde instante exclusivo |
| B-04 | Suscripción justo antes/en el final de 15 días | Tolerancia antes; solo lectura en el límite exacto |
| B-05 | Falta de Internet durante tolerancia | No extiende fechas; pasar a solo lectura según reloj local |
| B-06 | Suscripción vencida con activación existente | Sigue ocupando plaza; no generar instalación automática |
| B-07 | Modo híbrido | Solo `linked_libraries` + `managed_libraries`, sin campo/módulo nuevo |
| B-08 | Review sin expedientes | Rechazo al emitir/cambiar derechos |
| B-09 | Expedientes sin managed y carga intentada | Licencia puede existir; cliente rechaza carga; conserva consulta |
| B-10 | Pérdida de módulo/revocación/vencimiento | Conserva consulta, visualización, descarga y respaldo; no borra datos |
| B-11 | Cambio de módulos/renovación | Nueva revisión, mismos IDs si no cambia instalación |
| B-12 | Límites iniciales | 1 instalación, usuarios/bibliotecas/documentos nulos |
| B-13 | Versión con `published_at` autenticado <= corte | Permitida; posterior rechazada aunque reloj actual sea anterior |
| B-14 | Mantenimiento vencido con versión ya autorizada | Continúa funcionando; no exige red |
| B-15 | Metadatos de versión falsos o sin firma confiable | No conceder instalación de versión por fecha autodeclarada; formato de manifiesto pendiente P-01 |

Ejemplo temporal fijo: `expires_at=2026-10-01T00:00:00Z`, fin de gracia `2026-10-16T00:00:00Z`. Probar el microsegundo anterior, el instante exacto y el siguiente en ambos límites. Repetir con cambio de mes/año y zonas locales distintas; el cálculo usa UTC y 1,296,000 segundos, no días de calendario local.

## 3. Integración MySQL y recuperación

| ID | Escenario con procesos/conexiones independientes | Aserciones persistidas |
| --- | --- | --- |
| I-01 | Dos activaciones, misma licencia, requests distintos, barrera antes del bloqueo | Un éxito y un límite; una activa; un JWS activo emitido |
| I-02 | Dos activaciones con mismo request y cuerpo | Respuestas idénticas; una activación, revisión y auditoría de efecto |
| I-03 | Mismo request con huella/clave/app_version diferentes | Conflicto sin alterar el primer resultado |
| I-04 | Dos requests diferentes usan un mismo desafío | Un consumo y un efecto como máximo |
| I-05 | Activación API compite con aprobación offline | Misma protección de plaza; ninguna doble asignación |
| I-06 | Refresh compite con revocación | Resultado coherente con orden de commits; siguiente refresh ve revocado |
| I-07 | Renovación compite con módulos/mantenimiento | Ningún cambio perdido; revisiones estrictamente crecientes |
| I-08 | Doble clic de renovación/aprobación | Periodo y JWS se emiten una sola vez |
| I-09 | Fallo antes de firmar, tras firmar, antes de commit | Cero efectos parciales, incluida auditoría/desafío/respuesta |
| I-10 | Respuesta perdida después de commit | Reintento entrega mismos bytes aunque el desafío haya caducado |
| I-11 | Firma indisponible, deadlock, timeout o caída de BD | Rollback o recuperación por request; 503 estable sin duplicados |
| I-12 | Desactivación/transferencia y activación simultáneas | Una plaza máximo; origen terminal e historia íntegra |
| I-13 | Reintento de desactivación tras transferir | Reproduce acuse original; no desactiva la activación nueva |
| I-14 | Renovación offline de activación terminada o pública ajena | Rechazo sin extender derechos |
| I-15 | Constraint insertada directamente fuera del servicio | BD rechaza segunda activa y combinaciones temporales inválidas |
| I-16 | Rotación durante emisión | JWS verificable y registro consistente; sin pérdida de públicas antiguas |
| I-17 | Restauración de copia antigua | Emisión bloqueada hasta conciliación de revisiones/plazas; recuperación probada |

Estas pruebas deben usar MySQL 8 real: SQLite y mocks no demuestran sus bloqueos o restricciones. Las carreras se fuerzan con barreras y conexiones separadas, no con esperas arbitrarias. Medir tanto respuesta como tablas; no basta contar HTTP 200.

## 4. Panel y operación

Probar bootstrap doble/sin cuenta por defecto, MFA incorrecto/repetido, códigos de recuperación de un uso, fijación y expiración de sesión, rol insuficiente mediante HTTP directo, CSRF en cada mutación, escape de texto importado, archivos sobredimensionados y limitación de login/API. Comprobar que `license_key` y secretos no aparecen en logs, errores, sesiones o historial de navegador tras emisión.

Verificar que el usuario MySQL de app no puede editar auditoría/revisiones, que los eventos fallidos no se pierden por rollback comercial y que una ruptura de cadena es detectable con el anclaje externo. Ensayar restauración en red aislada con licencia perpetua antigua, suscripción, solicitud pendiente, transferencia y revocación ya emitidas.

## 5. Entregas y comandos

| Etapa | Entregable completo | Evidencia de aceptación |
| --- | --- | --- |
| 1 | Documentos, ambigüedades explícitas y vectores iniciales | Comparación literal del anexo, revisión de enlaces/JSON y verificación PHP ↔ Go de fixtures |
| 2 | Bootstrap seguro, roles/MFA, clientes/productos y derechos comerciales | Migración limpia y actualización, pruebas del panel, reglas comerciales y auditoría |
| 3 | Claves, JWS, desafíos, activación, refresh/desactivación online | C-01 a C-09, idempotencia, concurrencia y rollback en MySQL |
| 4 | Offline, transferencias y recuperación | C-10 a C-14, doble aprobación, identidad, flujo terminal acordado B-01 |
| 5 | Compatibilidad con Go real y despliegue VPS | Mismos fixtures en ambos repos, suites completas y restauración ensayada |

Ya existen `composer test:unit`, `composer test:integration`, `composer test:contract`, `npm run test:panel`, `php bin/migrate.php` y `php bin/preflight.php`. `composer test:contract` recalcula y compara los fixtures sin sobrescribirlos. El comando del navegador pertenece a npm únicamente en desarrollo. Las pruebas del cliente Go real siguen pendientes.

## 6. Evidencia de etapa 1

El 2026-09-25 se comprobó sintaxis PHP del generador; se verificaron con Go cinco JWS, tres proofs, tres solicitudes offline y tres rechazos de JWS alterado/kid desconocido. Las firmas recalculadas por Go coincidieron byte por byte con Sodium y se rechazó agregar LF al mensaje firmado. Regenerar los nueve archivos produjo exactamente los mismos hashes.

También se comprobó igualdad literal del anexo, resolución de enlaces locales, JSON de ejemplos y presencia/tipos de campos obligatorios de los cinco payloads. Los seis casos de estado/identidad listados en `expected_client_cases` son expectativas publicadas, todavía no ejecutadas contra el cliente real. En aquella entrega inicial no existía la aplicación; las pruebas posteriores de etapa 2 se detallan a continuación.

## 7. Pruebas ejecutables de etapa 2

Requisitos: `composer install`, PHP 8.3+ con las extensiones declaradas, y una instancia MySQL 8 dedicada a pruebas. No usar una BD de producción o de otro proyecto. `TEST_MYSQL_DSN` identifica ese servidor; `TEST_MYSQL_USER` y `TEST_MYSQL_PASSWORD` indican una cuenta que pueda crear y eliminar sus propias BD/usuarios sintéticos. Si no se define el DSN, PHPUnit omite integración expresamente: una ejecución con omisiones no valida MySQL.

```sh
php vendor/bin/phpunit --testsuite unit
php contracts/v1/generate-fixtures.php --check
env TEST_MYSQL_DSN='mysql:unix_socket=/ruta/mysql.sock;charset=utf8mb4' php vendor/bin/phpunit
```

`MySqlFixture` crea una base y un usuario aleatorios `aibid_test_<10 hex>`, aplica migraciones y los mismos GRANT del panel, y usa esa cuenta limitada para los servicios. PHPUnit elimina sus propios datos al terminar cada caso. La prueba concurrente necesita lectura de `performance_schema.data_lock_waits` y `data_locks` desde la cuenta de preparación, para observar dos procesos esperando antes de liberar el bloqueo; no depende de una pausa arbitraria como prueba de concurrencia.

El 2026-09-26 se verificaron **24 pruebas y 80 aserciones**, sin omisiones, en PHP 8.5.7 y MySQL 8.4.11: nueve pruebas unitarias y quince de integración. Cubren reglas temporales, UUID, dependencias/ciclos, TOTP con vector RFC y rechazo de reuso, cifrado vinculado a la cuenta, redes, bootstrap único, MFA/recuperación/rotación de sesión, emisión sin mantenimiento, secreto comercial no archivado, idempotencia/conflictos, renovaciones y compras separadas, formularios obsoletos, roles, último superadministrador, permisos SQL de historia, rollback si falla auditoría, constraints, búsquedas y renovación concurrente en dos procesos. Esta carrera valida un periodo comercial único; aún no prueba ocupación de instalaciones ni firma JWS.

### Navegador real

Preparar una base nueva para cada ejecución: la prueba enrola MFA y consume códigos TOTP. `prepare-panel.php` conserva los datos sintéticos para poder inspeccionarlos, escribe su configuración 0600 en `/private/tmp/aibidlicense-panel-config.php` y muestra solo el nombre de BD. Nunca crea `config/local.php` ni configura un entorno productivo.

```sh
npm ci --ignore-scripts --no-audit --no-fund
env TEST_MYSQL_DSN='mysql:unix_socket=/ruta/mysql.sock;charset=utf8mb4' php tests/prepare-panel.php
env AIBID_CONFIG=/private/tmp/aibidlicense-panel-config.php php -S 127.0.0.1:8088 -t public public/router.php
# En otra terminal:
npm run test:panel
```

El script usa Google Chrome instalado en la ruta habitual de macOS. Para otro entorno, indicar el ejecutable con `CHROME_PATH=/ruta/al/chrome npm run test:panel`. Playwright no se instala en producción. Las credenciales constantes de este script pertenecen exclusivamente a datos sintéticos de la instancia aislada. Si se repite, preparar otra fixture y conservar vivo el servidor local, que carga la configuración en cada petición.

Recorrido aprobado: redirección de acceso privado, contraseña, QR y enrolamiento TOTP, ocho códigos de recuperación, cookie HttpOnly, alta de cliente con texto que debe escaparse, dependencias de módulos, emisión perpetua y secreto de una sola visualización, mantenimiento separado, emisión/renovación de suscripción, CSRF inválido y Origin ajeno rechazados, páginas administrativas, navegación móvil y usuario de consulta sin permisos de escritura ni acceso a administradores. Sin errores JavaScript. Vistas de 1440×1000 y 390×844; tabla móvil desplazable dentro de su contenedor, sin desbordamiento de la página.

Capturas generadas en `var/screenshots/`: `login-desktop.png`, `dashboard-desktop.png`, `license-form-desktop.png`, `license-detail-desktop.png` y `dashboard-mobile.png`. Contienen solo clientes sintéticos; no se capturan claves comerciales, QR, contraseñas ni códigos de recuperación.

### Comprobaciones adicionales y límites

- Sintaxis: 61 archivos PHP, cero errores; JavaScript de la prueba validado.
- Preflight aprobado sobre la cuenta restringida de la fixture: extensiones, Argon2id, MySQL y checksums de migraciones.
- `composer validate --strict --no-check-publish` aprobado; el gestor instalado emite avisos de deprecación propios al ejecutarse con PHP 8.5. La consulta de Composer no reportó avisos de vulnerabilidades para el lock en esta fecha.
- Contrato original y anexo conservan sus hashes registrados; los cuatro SVG coinciden exactamente con los originales.
- Cinco JWS, tres proofs, tres solicitudes offline y tres negativos siguen coincidiendo con el oráculo Go. El servidor sigue siendo exclusivamente PHP.
- Al cerrar etapa 2 faltaba comprobar firma/activación/offline; su evidencia está en las secciones 8 y 9. Continúan pendientes PHP-FPM/TLS del VPS, respaldo/restauración real, límites del proxy y compatibilidad con el cliente real.

## 8. Evidencia de etapa 3

El 2026-09-26 se ejecutó la suite completa en PHP 8.5.7 / MySQL 8.4.11: **58 pruebas y 360 aserciones**, sin errores, fallos u omisiones. Son 22 casos unitarios y 36 de integración. Las pruebas HTTP inician un servidor PHP en un puerto loopback aleatorio y usan una fixture SQL con los permisos restringidos de la app; no conectan a MariaDB ni a la configuración local del usuario.

Cobertura añadida:

- JWS del publicador idénticos a los cinco vectores V1; proof exacto sin normalizar mayúsculas de UUID; JSON duplicado/UTF-8/tipos/campos no admitidos y base64url no canónico rechazados.
- Activación con los 18 campos superiores del contrato, cinco módulos booleanos, mantenimiento nulo y límites correctos; refresh devuelve los mismos bytes si no cambiaron derechos.
- Reintento idéntico después de purgar desafíos y retirar credenciales; cuerpo distinto con proof válido produce conflicto. Los secretos comerciales no aparecen en requests, revisiones ni auditoría.
- Desafío en su vencimiento exacto, firma incorrecta, pública ajena, acción incorrecta y desafío usado; rechazo sin consumo de desafíos ajenos ni efectos parciales.
- Dos procesos con requests distintos compiten por una plaza: 200/409, una activación y una revisión. Dos procesos con el mismo request reciben bytes idénticos. Las barreras observan esperas reales en `performance_schema`, con límites de tiempo.
- Desactivación con acuse B-02, revisión terminal, licencia comercial disponible y activación posterior con otro UUID. Repetir una desactivación antigua no afecta al nuevo equipo; refresh antiguo conserva el JWS revocado.
- Cambios administrativos de módulos, mantenimiento, renovación y revocación publican revisiones atómicas. Guardar módulos idénticos no agrega firmas. Una suscripción vencida no libera plaza ni modifica fechas automáticamente.
- Archivo de firma ausente y fallo de auditoría después de firmar: rollback de activación, revisión, contador, request/desafío y cambios comerciales. La negativa comercial autenticada sí se conserva como resultado terminal.
- Índice único de plaza activa, restricción de edición de identidad y permisos SQL de revisiones inmutables. Actualización desde esquema de etapa 2 con datos comerciales conservados.
- Rotación mantiene JWS/públicas anteriores y separa propósito de manifiestos. Producción rechaza una clave determinista del paquete público aun con nombre y metadatos cambiados, dentro de una fixture aislada.
- Ciclo completo por HTTP real, sin cookies/sesiones de panel, con POST/JSON/TLS, errores 400/403/404/405/413/415/429, correlación válida, no CORS abierto y límites de tasa. `X-Forwarded-Proto` no elude HTTPS.

El recorrido de Chrome también pasó con el historial de una instalación real de prueba: descarga `.lic` con los segmentos JWS archivados, correspondencia de licencia/revisión, nombre de archivo y rechazo 403 al rol de consulta. Se mantienen las comprobaciones de MFA, CSRF, formularios, renovación y móvil. Se añadió `var/screenshots/activated-license-desktop.png`; ninguna captura contiene privadas, contraseñas, QR ni claves comerciales.

Preflight con `--require-signer` aprobado y cadena de 20 eventos de la fixture visual verificada. El oráculo Go sigue verificando cinco JWS, tres proofs, tres solicitudes offline y tres negativos; esto valida interoperabilidad de bytes con ese oráculo, no el verificador real del gestor documental.

La configuración `config/local.php` que apareció después de etapa 2 se conservó y no se usó para migrar/probar. Las pruebas crean sus propias configuraciones temporales. Al cerrar etapa 3 seguían pendientes offline y recuperación; la sección siguiente registra su implementación. Integración real del cliente, PHP-FPM/TLS del VPS, respuestas del proxy y restauración operativa siguen pendientes. La retirada de una instalación desconectada no implica borrado remoto ni revocación instantánea.

## 9. Evidencia de etapa 4

El 2026-09-26, PHP 8.5.7 / MySQL 8.4.11 aislado: **92 pruebas, 527 aserciones**, sin errores, fallos, advertencias ni omisiones. Son 37 casos unitarios y 55 de integración. La cuenta SQL de cada fixture aplica los mismos GRANT restringidos del runtime. Se validó sintaxis de 84 archivos PHP de aplicación, pruebas, CLI y contrato; los dos scripts de navegador nuevos/modificados pasan `node --check`.

Cobertura nueva:

- Firma exacta LICREQ-V1, fixtures originales, base64url, tipos, versión, tamaño, claves JSON duplicadas, UTC/calendario, UUIDv4 e identidad. Un archivo antiguo válido se importa sin conceder prórroga.
- Original cifrado, proyección sin credencial, importación equivalente, payload alterado con misma ID, firma inválida sin reserva y reserva compartida entre canales, pendiente o completada.
- Asignación explícita, rechazo, replay del mismo formulario y conflicto de una segunda decisión. Consulta no importa/descarga; operador no fuerza transferencia y un Actor desactualizado no suplanta el rol vigente en BD.
- Renovación de suscripción con fechas explícitas, periodos/historia y 15 días; renovación perpetua sin mantenimiento automático; identidad ajena/alterada y activación retirada rechazadas; versión comercial obsoleta y licencia revocada bloquean aprobación.
- Desactivación firmada seguida de nueva activación, recuperación sin destino, transferencia atómica con nueva identidad, revisión terminal recuperable por refresh y contador global sin reutilización.
- Fallo de firma por privada ausente, ciphertext manipulado, error después de retirar origen y fallo de auditoría después de publicar: rollback sin plaza perdida, revisión parcial ni decisión confirmada.
- SQL prohíbe UPDATE/DELETE de las tres tablas nuevas. Migraciones desde etapas 2/3 conservan datos. Dos procesos simultáneos prueban doble aprobación y competencia API/offline con una única plaza.

`npm run test:panel` también verifica con Chrome: upload real multipart, archivo inválido/grande, CSRF y Origin, importación duplicada, selección/revisión comercial, `.lic` descargado byte a byte, renovación offline, transferencia con contraseña/TOTP (contraseña incorrecta rechazada), desactivación, rechazo y denegación a consulta. Se comprobaron escritorio y móvil a 390 px, sin errores de consola. Capturas sin secretos en `offline-review-desktop.png`, `offline-review-mobile.png` y `offline-approved-desktop.png`; se desactivan animaciones solo al capturar para evitar imágenes a mitad de una transición de tamaño.

Preflight con firmante y cadena de 35 eventos de la fixture visual aprobados. `generate-fixtures.php --check` sigue verificando los cinco JWS, tres proofs, tres `.licreq` y tres negativos originales. Los hashes del prompt y anexo literal y los cuatro SVG siguen intactos. La verificación con el cliente real y las pruebas operativas del VPS pertenecen a etapa 5, no a esta evidencia.

Para repetir únicamente esta cobertura PHP, usar la instancia aislada y ejecutar:

```sh
env TEST_MYSQL_DSN='mysql:unix_socket=/ruta/mysql.sock;charset=utf8mb4' php vendor/bin/phpunit --filter Offline
```

Para el navegador, crear una fixture nueva con `tests/prepare-panel.php`, iniciar el servidor con `AIBID_CONFIG` temporal y ejecutar `npm run test:panel` según la sección 7. La fixture debe ser nueva porque el recorrido enrola MFA y consume los códigos TOTP. No cambiar el archivo real de configuración para probar.

Las fixtures de servicios usan configuración explícita sin heredar variables SQL de runtime. Los servidores HTTP de prueba eliminan esas variables heredadas; la configuración generada del panel hace lo mismo antes de abrir su conexión. Una prueba adicional verifica que DB_DSN de otra instalación no sustituya el DSN aislado.

## 10. Evidencia de etapa 5

El 2026-09-26: **96 pruebas PHP, 571 aserciones** (40 unitarias, 56 de integración) y **2 pruebas de sistema, 83 aserciones**. Todas pasan sin omisiones/advertencias. PHP/FPM 8.5.7, MySQL 8.4.11 aislado, Nginx 1.31.3, Go 1.27.1 para el cliente. No se probó ni instaló nada en el VPS por instrucción del usuario; la guía distingue esas comprobaciones pendientes.

```sh
# Utilidades del mismo MySQL 8 aislado; TEST_MYSQL_USER/PASSWORD solo si la fixture los necesita.
env TEST_MYSQL_DSN='mysql:unix_socket=/ruta/mysql.sock;charset=utf8mb4' TEST_MYSQL_BIN=/ruta/mysql TEST_MYSQLDUMP_BIN=/ruta/mysqldump php vendor/bin/phpunit

# Ejecución adicional deliberada: requiere el código real y sus módulos/toolchain ya disponibles.
env TEST_MYSQL_DSN='mysql:unix_socket=/ruta/mysql.sock;charset=utf8mb4' TEST_CLIENT_SOURCE=/ruta/expediente TEST_GO_BIN=/ruta/go TEST_GO_MODCACHE=/ruta/cache-modulos TEST_GO_CACHE=/private/tmp/aibidlicense-real-client-go-cache TEST_FPM_BIN=/ruta/php-fpm TEST_NGINX_BIN=/ruta/nginx TEST_OPENSSL_BIN=/ruta/openssl php vendor/bin/phpunit tests/System/RealClientTest.php
```

En esta Mac se utilizó el socket `/private/tmp/aibidlicense-mysql/run/mysql.sock`, binarios `/private/tmp/aibidlicense-mysql/mysql-8.4.11-macos15-arm64/bin/`, cliente `/Applications/XAMPP/xamppfiles/htdocs/expediente` y toolchain Go 1.27.1 ya almacenado en `/private/tmp/gestor-documental-go-mod/golang.org/toolchain@v0.0.1-go1.27.1.darwin-arm64/bin/go`. Los ensayos no usan MariaDB/XAMPP ni `config/local.php`. Requieren poder abrir el socket MySQL y puertos TLS de loopback. No habilitar las pruebas de sistema contra una URL/BD real: `NativeStackFixture` crea su origen y configuración propios.

`RealClientBridge` copia solo el código y fixtures necesarios a un directorio privado, agrega un adaptador de test y compila el paquete original `internal/licensing` con `GOPROXY=off`, `GOSUMDB=off`, `GOTOOLCHAIN=local`, sin descargar dependencias ni editar el original. La copia incluye el estado actual de archivos modificados, no presupone un checkout limpio. `var/client-integration-report.json` conserva sus hashes. El adaptador invoca métodos reales y solo agrega a la confianza TLS la CA temporal; no sustituye la lógica de derechos ni desactiva verificación de certificado.

Cobertura del cliente y proxy:

- Tests originales seleccionados con `^Test(JWS|Subscription|Revision|Missing|Proof|Security)`, más ciclo online por Nginx/FPM: activar, abrir de nuevo estado persistido, replay, segunda plaza denegada, cambio de derechos, refresh y desactivación B-02.
- Solicitudes offline generadas por el cliente, aprobación PHP, importación real, renovación, desactivación B-01, activación posterior y transferencia forzada. Identidad ajena y revisión anterior se rechazan.
- Refresh del origen transferido devuelve revisión revocada; clave nueva no confiable se rechaza sin perder derechos anteriores; distribuir la pública permite recibir la revisión nueva. Revocación comercial conserva consulta/exportación.
- Renovación explícita de suscripción; fronteras exactas antes del vencimiento, en el vencimiento, +1,295,999 y +1,296,000 segundos: activo/tolerancia/solo lectura correspondientes.
- HTTPS verificado, cookies `__Host-…`/Secure/HttpOnly/SameSite=Lax, acceso directo a PHP/dotfiles rechazado, JSON de 16 KiB y multipart de 96 KiB limitados, API sin cookie, errores con UUID y 503 JSON durante mantenimiento. Retirar el indicador restablece servicio.
- El estado local `offline_deactivation_pending` queda marcado tras importar JWS revocado, mientras se deniega escritura y el servidor libera plaza. Se registra como particularidad del cliente observado, cuyo código no se cambió.

Cobertura de respaldo:

- Cifrado por bloques mayores a 64 KiB y roundtrip exacto; claves incorrectas, alteración, truncamiento, bytes sobrantes, traversal y sobrescritura rechazados. Limpieza de extracción fallida y permisos privados.
- Ensayo por CLI de generación de clave, dump, cifrado, manifiesto, extracción e importación en otra base vacía (34 aserciones). Conserva las cinco raíces, dos claves de firma tras rotación, JWS históricos byte a byte, MFA, HMAC comercial, evidencia firmada cifrada, aprobación, pendiente, revisión terminal y replay.
- Reintentar importación sobre un destino con tablas falla sin cambiar su historia. Un anclaje posterior a la copia, contador alterado o JWS corrupto hace fallar la verificación.
- Prueba interactiva adicional con pseudoterminal: configure/recovery-config sin eco aun enviando la contraseña inmediatamente al aparecer el prompt; 0600, raíces independientes iniciales, configuración existente intacta y raíces preservadas al recuperar. Solo archivos sintéticos, sin conexión SQL.

El paquete de despliegue se construye por lista de entradas y se inspecciona para excluir secretos, config local, vendor, datos y tests; se verifican hashes de cada entrada. El instalador de dependencias de producción se ejecuta en una extracción temporal, no sobre las dependencias de desarrollo del workspace. Sintaxis de 99 archivos PHP y del script shell verificada; prompt/anexo, cuatro SVG y 72 archivos fuente del cliente coinciden con sus bytes registrados. Composer instaló las seis dependencias de producción y su autoload en una extracción temporal; la versión local Composer 2.8.6 mostró avisos de deprecación propios bajo PHP 8.5, sin impedir la instalación ni los requisitos de plataforma. Las pruebas Chrome de etapa 4 siguen siendo evidencia histórica; no se repitieron porque esta etapa no cambió la interfaz.

Pendiente del operador en el VPS: preflight del PHP/MySQL instalados, nginx/FPM reales del equipo, DNS/certificado público, permisos, timer/renovación TLS, distribución de públicas, prueba de licencia controlada y copia externa con custodia separada. Los archivos systemd se entregan para Ubuntu y no se ejecutaron en macOS. No se declara cumplido un RPO de 15 minutos ni un RTO medido.
