# Punto de continuación · 26 de septiembre de 2026

## Entrega actual

**Etapas 1, 2, 3 y 4 completadas en el workspace.** Arquitectura, contrato literal, vectores, panel comercial, API online y operación offline PHP/MySQL. No se desplegó en un VPS ni se integró con el verificador real del gestor documental.

El backend sigue siendo PHP. Bootstrap/JavaScript resuelven la interfaz; Node/Playwright solo prueban el navegador y Go es un oráculo opcional de fixtures. Marca AIBID — Aplicación de Indexación de Bibliotecas Digitales; `product_id` sigue siendo `gestor_documental` y los cuatro SVG originales permanecen intactos.

B-01 está aplicado: retirar una activación emite JWS `revoked` y conserva la licencia comercial `issued` para otra instalación. B-02 conserva el acuse online `status:"deactivated"`. La compatibilidad con el cliente real debe comprobarse antes de publicar V1.

## Funcionalidad de etapa 4

- Importación `.licreq` con JSON estricto, base64url canónico, fechas UTC/calendario y firma Ed25519 sobre los bytes LICREQ-V1 exactos. Hasta 64 KiB; importar no concede derechos.
- Original cifrado e inmutable, proyección sin clave comercial opcional y reserva compartida `(product_id,request_id)` entre online/offline. Formatear el sobre externo no duplica efectos; cambiar bytes firmados con la misma ID produce conflicto.
- Panel **Solicitudes offline**: búsqueda/filtros, importación, revisión de identidad, selección de cliente/licencia, derechos vigentes, aprobación/rechazo con motivo y descarga del `.lic` archivado.
- Renovación/desactivación vuelven a comprobar la identidad vinculada y el estado bajo bloqueo. Se pueden emitir derechos actuales o ampliar explícitamente una suscripción con fecha/referencia; no se regala mantenimiento a perpetuas.
- Transferencia forzada a una solicitud de destino: retirada firmada del origen y nueva activación en una sola transacción. Recuperación sin destino: liberar plaza por equipo averiado. Ambas requieren superadministrador, contraseña/TOTP nuevo, motivo y aceptación del límite offline.
- Evidencia, decisión y transferencia en tres tablas nuevas de solo SELECT/INSERT para runtime. Una única decisión terminal por solicitud; un fallo de firma, destino o auditoría revierte todos los efectos.
- Migración `004_offline_requests.sql`, GRANT/preflight actualizados, límite multipart de 96 KiB en el ejemplo Nginx. No se modificaron las migraciones 001–003 ni se añadieron rutas V1 públicas.

La clave de evidencia se deriva de `CREDENTIAL_KEY` mediante HKDF con propósito propio versión 1. No exige modificar secretos existentes; conservar esa raíz para recuperar tanto credenciales como evidencia. Detalle en SECURITY y OPERATIONS.

## Verificación final

PHP 8.5.7 y MySQL 8.4.11 aislado: **92 pruebas, 527 aserciones** (37 unitarias y 55 de integración), sin errores, fallos, advertencias u omisiones. Incluyen las 58 pruebas previas, concurrencia de doble aprobación/API versus offline, inmutabilidad SQL, migraciones con datos, identidades alteradas, idempotencia y rollback de transferencia/firma/auditoría.

Chrome: login/MFA, permisos, importación multipart, límites de archivo, CSRF/Origin, asignación/revisión, aprobación/rechazo, renovación, transferencia con reautenticación, desactivación y descarga exacta aprobados; sin errores de consola. Vista de escritorio y móvil de 390 px verificada. Capturas sin secretos en `var/screenshots/`, incluidas `offline-review-desktop.png`, `offline-review-mobile.png` y `offline-approved-desktop.png`.

Preflight con firmante y cadena de 35 eventos de la fixture visual verificados. Sintaxis de 84 archivos PHP válida y scripts de navegador comprobados. Fixtures originales verificados sin reescritura; prompt/anexo literal y SVG comparados intactos. Evidencia y reproducción en [TEST_PLAN.md](TEST_PLAN.md).

## Siguiente etapa: 5

Integrar las pruebas de contrato con el verificador real de AIBID, comprobar entrega de públicas y tratamiento de revisiones terminales/identidad/tolerancia/solo lectura. Después preparar el VPS con PHP-FPM 8.3+, MySQL 8, TLS, límites/respuestas del proxy, reloj, permisos, respaldo/restauración ensayada y anclajes de auditoría externos. La disponibilidad de etapas 1–4 no implica que estas comprobaciones de producción ya estén realizadas.

El repositorio/ruta del cliente y los accesos/configuración del VPS no están incorporados a este workspace. Antes de trabajo que dependa de ellos, localizar contexto disponible o solicitar únicamente la información que falte. No reemplazar claves confiables ni inventar un contrato de manifiestos de versiones.

## Entorno y actualización

El usuario tiene MariaDB de XAMPP y preparará MySQL local/VPS después. `config/local.php` se conservó, no se leyó para pruebas ni se ejecutaron migraciones sobre su BD. XAMPP y las bases del gestor no se tocaron.

Las pruebas usan MySQL 8 temporal independiente, sin TCP, y bases/usuarios sintéticos `aibid_test_*`. La fixture visual conserva configuración en `/private/tmp/aibidlicense-panel-config.php`; no usarla como configuración real. El servidor PHP de pruebas y MySQL temporal quedaron detenidos al finalizar la entrega.

Para actualizar una instalación existente, preservar configuración/claves, aplicar migración 004 con cuenta de migración y los GRANT nuevos con cuenta administrativa, luego preflight/audit-verify. No repetir configure/bootstrap ni generar otro firmante si ya hay uno válido distribuido. Pasos en [OPERATIONS.md](OPERATIONS.md#9-operación-offline-y-actualización-a-etapa-4).
