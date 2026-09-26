# Registro de decisiones y puntos por acordar

Actualizado: 2026-09-26. Arquitectura de etapa 1, administración de etapa 2 y protocolo online de etapa 3. El usuario confirmó las decisiones de diseño y autorizó continuar; B-01 y B-02 están aceptadas. La compatibilidad con el cliente real todavía debe verificarse antes de publicar V1.

## 1. Prioridad de fuentes

El usuario autorizó la arquitectura inicial, confirmó las decisiones y después autorizó continuar con el panel administrativo. El documento original aporta los requisitos del proyecto. Su anexo V1.0 es la fuente de compatibilidad entre servidor y cliente. Las fuentes técnicas externas sirven para elegir mecanismos internos, no para reemplazar campos o reglas comerciales del anexo.

`LICENSE_CONTRACT.md` contiene los bytes del anexo desde su encabezado hasta el final, sin enmiendas. SHA-256 del anexo: `7a18ede09ff403d5575c4eee529c202c86e6fc3a0f5e714d034901c6f07aa8d4`. SHA-256 del documento original: `f6e0b399707531d102ad722236cff31086babf1f512596e5c56933ff17aa3f86`.

## 2. Decisiones de arquitectura

| ID | Decisión | Motivo e impacto |
| --- | --- | --- |
| D-01 | Monolito modular PHP con panel renderizado en servidor | Mismo dominio para API y panel, despliegue sencillo y stack exigido |
| D-02 | MySQL/InnoDB, fila de licencia bloqueada e índice único de activación activa | Cupo seguro frente a concurrencia, incluso desde distintos procesos FPM |
| D-03 | Licencia comercial separada de activación y revisión | Transferir conserva venta e historia; JWS siempre identifica una activación |
| D-04 | Contador global por licencia y JWS inmutable por emisión | Orden verificable, reintentos exactos y conservación de evidencia |
| D-05 | Commit único de efecto, revisión, resultado y auditoría | Evita respuestas firmadas sin estado persistido y dobles operaciones |
| D-06 | Idempotencia por producto/request, compartida entre online y offline | Reutilizar ID con contenido/canal/acción distintos produce conflicto |
| D-07 | Aprobar `.licreq` es una operación distinta de importarlo | Una firma de instalación no equivale a autorización comercial |
| D-08 | No TTL corto para resultados comerciales idempotentes | Impide reejecutar operaciones antiguas de perpetuas o solicitudes offline |
| D-09 | Clave comercial aleatoria de 256 bits y HMAC versionado | Mostrar una vez y proteger búsquedas sin guardar el secreto original |
| D-10 | Argon2id y TOTP desde bootstrap del panel | Seguridad administrativa antes de emitir derechos |
| D-11 | Claves por entorno/propósito, fuera de raíz pública | Separar licencias, manifiestos, pruebas y producción |
| D-12 | Dependencia estática review → expedientes; managed al cargar en expedientes | Respeta la dependencia condicional del anexo sin prohibir expedientes vinculados |
| D-13 | Sin módulo híbrido; límites del primer producto nulos salvo una instalación | Reproduce las reglas comerciales sin inventar otra modalidad |
| D-14 | Nueva revisión solo cuando cambian derechos, estado o firma autorizada | Refresh ordinario reutiliza JWS; mantenimiento se compra por separado |
| D-15 | No liberar plaza al vencer suscripción | El vencimiento no confirma desactivación del equipo |
| D-16 | Bootstrap CLI y sin registro público | Evita instalador o credenciales predeterminadas expuestas |

Los detalles internos pueden evolucionar conservando V1.0. TTL de desafío, tasas, expiración de sesiones, plazos de logs y objetivos de restauración son parámetros operativos propuestos, no obligaciones adicionales del contrato.

## 3. Interpretaciones aceptadas por el usuario

### B-01 — Resultado firmado de desactivación y transferencia

El anexo dice: «Respuesta `.lic`: el **mismo JWS Compact** usado en línea» y limita `license_status` a `active` o `revoked`. También permite desactivar y transferir una licencia. No define explícitamente cómo representa ese JWS el retiro de una sola activación sin revocar la licencia comercial para siempre.

**Decisión aceptada (B-01):** emitir un JWS `license_status:revoked` con los mismos `license_id`, `activation_id` e identidad de la activación saliente, con revisión superior. La licencia comercial conserva estado `issued` cuando solo se desactiva/transfiere. La activación entrante recibe otro `activation_id` y JWS `active`. El cliente antiguo conserva consulta y exportación; recibe su JWS terminal al reconectar o importar el `.lic`.

Esto conserva campos y valores existentes. La integración con el cliente debe verificar que el estado firmado expresa la autorización de esa activación. No incluir `deactivated` en `license_status`, no emitir `active` como confirmación de retirada y no inventar un nuevo tipo de archivo en V1.

La aprobación de diseño ya se recibió. Antes de publicar V1 en producción, ejecutar pruebas del cliente real con el flujo offline y el refresh de una activación retirada para detectar interpretaciones incompatibles; cualquier discrepancia deberá resolverse conjuntamente. Etapa 3 implementa la evidencia terminal en desactivación online y refresh; la etapa 4 implementa también esa evidencia en desactivación offline y transferencia forzada.

### B-02 — Nombre de campo en el acuse online

El anexo establece que deactivate devuelve `activation_id`, `deactivated_at` y estado `deactivated`, sin escribir un JSON que nombre la propiedad del estado.

**Decisión aceptada (B-02):** `{"activation_id":"UUID","deactivated_at":"RFC3339 UTC","status":"deactivated"}`, sin campos extra. Implementar este nombre y verificarlo en el decodificador del cliente. No usar `state` ni `license_status`. El acuse se implementó y su fixture está en `contracts/v1/fixtures/deactivate-response.json`.

## 4. Coordinación con el cliente y operación

| ID | Punto | Propuesta y momento de resolución |
| --- | --- | --- |
| P-01 | Formato de manifiesto de versiones | El anexo exige `published_at` firmado y propósito separado, pero no define bytes, payload o formato. Diseñar conjuntamente antes de descargas/actualizador; no añadir endpoint V1. Las pruebas actuales solo especifican comparación con una fecha previamente autenticada |
| P-02 | Distribución de públicas y rotación | Cliente con conjunto de públicas confiables actuales/siguientes, entregado con distribución autenticada; cerrar el mecanismo offline antes de activar nuevas claves |
| P-03 | Huella por SO | Candidatos y construcción en SECURITY; confirmar algoritmo ya existente en Go, app ID systemd, normalización y vectores. El servidor acepta hash/version como datos opacos |
| P-04 | Fixtures compartidos reales | No se proporcionó el repositorio del gestor. Este paquete ofrece vectores iniciales verificados por un programa Go independiente; aún deben correr en el verificador real del producto |
| P-05 | Entorno de integración | Pruebas administrativas ejecutadas en MySQL 8.4.11 aislado y PHP 8.5.7 CLI. El PHP-FPM/TLS del VPS se comprobará en etapa 5; XAMPP no se modificó |
| P-06 | Política operativa | Confirmar responsables de recuperación, redes privadas del panel y objetivos RPO/RTO antes del VPS; no bloquea dominio/API |

## 5. Limitaciones aceptadas por los requisitos

- Revocación offline no instantánea; transferencia forzada puede coexistir temporalmente con una copia desconectada.
- Huella y clave local no impiden a un administrador clonar una VM o modificar el cliente.
- Perpetua válida no requiere consultas diarias y no incluye mantenimiento.
- Los 15 días son 1,296,000 segundos UTC desde el vencimiento exclusivo; no dependen de meses, zona horaria o próxima reconexión.
- Proof online V1 no cubre todos los campos HTTP. Se preserva y se usa TLS; una firma del cuerpo completo sería una evolución coordinada.
- Sin pasarela de pago, actualizador completo, instalador del servidor ni funcionalidad documental en este proyecto.

## 6. Cierre de etapa 1

La entrega contiene arquitectura, esquema, matriz de estados, amenazas, rutas/firmas, panel, operación y plan verificable. B-01/B-02 quedaron aceptadas posteriormente por el usuario. Los vectores no reemplazan la integración con el cliente real. La etapa 2 ya implementa bootstrap, administración y pruebas MySQL; ver README y TEST_PLAN para el alcance comprobado.

## 7. Concreciones de la etapa 2

- Marca pública: **AIBID — Aplicación de Indexación de Bibliotecas Digitales**. Se conservan los cuatro SVG entregados; `product_id` sigue siendo `gestor_documental`.
- Backend únicamente PHP. Go es un oráculo opcional de pruebas del contrato; Node/Playwright se usan solo para probar el navegador. Ninguno forma parte del despliegue del servidor.
- Un contacto principal por cliente y fechas de interfaz en UTC. No se agregan funciones documentales.
- `row_version` identifica cambios comerciales; `revision_counter` comienza en cero y desde etapa 3 aumenta con cada nueva firma. El publicador participa en la transacción comercial y una indisponibilidad de firma revierte el cambio completo.
- Login: ventanas fijas de 15 minutos, 5 intentos por cuenta y 20 por IP, incluyendo éxitos. MFA: 10 por cuenta y 20 por IP; reautenticación: 10 por cuenta. Se eligió una implementación acotada y verificable; la espera progresiva quedó como mejora futura.
- Bootstrap y recuperación de acceso solo por CLI. Roles: superadministrador, operador y consulta. Las acciones sensibles exigen contraseña y un TOTP nuevo.
- Dependencias PHP pequeñas y fijadas en composer.lock: OTPHP para TOTP y BaconQrCode para QR; no se usa un framework web. Bootstrap 5.3.8 se sirve localmente.

## 8. Concreciones de la etapa 3

- El usuario indicó continuar mientras prepara MySQL local/VPS; se mantuvo MySQL 8 como motor objetivo y se usó exclusivamente una instancia temporal para pruebas, sin migrar su configuración local ni usar MariaDB.
- API online implementada con JSON de 16 KiB, desafíos de cinco minutos y límites de tasa de 30/minuto por IP (separados para desafío/operación), más 30/minuto por pública autenticada/producto.
- Una negativa comercial autenticada consume el desafío y persiste su respuesta. El refresh devuelve el JWS archivado incluso después del vencimiento o de la revocación; no emite otra firma por consultar.
- Archivar catálogo/contactos no revoca derechos vendidos. Una suscripción comercialmente emitida conserva fechas al activar, aun si vencieron; la API no fabrica prórrogas y el cliente calcula tolerancia/solo lectura.
- Solo la CLI administra firmantes. Las públicas se distribuyen por el canal confiable del cliente; `--trust-confirmed` registra la confirmación operativa al seleccionar un firmante. No se añadió endpoint público de claves ni manifiestos.
- Descarga administrativa `.lic` disponible para revisar/entregar un JWS ya archivado. La recepción/aprobación de solicitudes offline y la transferencia forzada se implementaron en etapa 4.

## 9. Concreciones de la etapa 4

- El usuario autorizó continuar esta etapa. Se implementa en PHP/MySQL sin modificar la configuración local, XAMPP, el contrato literal o los SVG.
- El `.licreq` original se conserva cifrado e inmutable; la decisión reside en otra tabla con una fila máxima por solicitud. La clave opcional se excluye de todas las proyecciones. HKDF desde `CREDENTIAL_KEY`, con dominio de evidencia versión 1, evita exigir secretos nuevos durante la actualización; compartir esta raíz de recuperación queda documentado en SECURITY.
- La ID del request es compartida entre canales. El digest offline conserva payload y firma exactos; reordenar/formatear el sobre externo no crea otra solicitud. No hay TTL universal para archivos ni purga de resultados terminales.
- `renew` puede emitir derechos actuales o ampliar explícitamente una suscripción con nueva fecha y referencia. Siempre mantiene identidad e incrementa revisión. Una perpetua no recibe mantenimiento por importar o aprobar una renovación.
- La transferencia forzada desde la solicitud del destino es atómica: revisión revocada del origen, nuevo UUID de activación, revisión del destino y auditoría. También se permite liberar una plaza sin destino para recuperación posterior. Ambas exigen superadministrador, contraseña/TOTP nuevo, motivo y aceptación explícita del límite offline.
- La revisión descargada pertenece a la decisión original; consultar historial para entregas posteriores. Las cuatro rutas V1 siguen intactas y las funciones nuevas están en el panel.
