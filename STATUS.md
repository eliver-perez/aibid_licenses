# Punto de continuación · 26 de septiembre de 2026

## Entrega actual

**Etapas 1, 2 y 3 completadas en el workspace.** Arquitectura, contrato literal, vectores, panel comercial y API online PHP/MySQL. No se desplegó en un VPS ni se integró con el verificador real del gestor documental.

El servidor usa PHP; Bootstrap/JavaScript resuelven la interfaz. Go solo verifica fixtures y Node/Playwright solo prueban el navegador, sin servicios de backend adicionales. Marca: AIBID — Aplicación de Indexación de Bibliotecas Digitales; `product_id` permanece `gestor_documental` y los cuatro SVG originales siguen intactos.

Decisiones aceptadas: B-01 expresa la retirada de una activación mediante JWS `revoked`, conservando la licencia comercial `issued`; B-02 usa `status: "deactivated"` en el acuse online. Ambas están implementadas en los flujos online. La compatibilidad del cliente real debe comprobarse antes de publicar V1.

## Funcionalidad de etapa 3

- Cuatro endpoints POST V1: desafío, activación, refresh y desactivación. JSON estricto, proofs Ed25519, HTTPS y límites de tasa.
- Claves firmantes por entorno/propósito, privadas fuera de public/, generación y selección mediante CLI, públicas antiguas conservadas.
- Activaciones con plaza única bajo bloqueo e índice SQL; contador global y JWS inmutables; respuestas idempotentes exactas incluso después de purgar desafíos.
- Renovación, módulos, mantenimiento y revocación de licencias activas publican revisiones dentro de la misma transacción. Fallos de firma/BD/auditoría revierten la operación.
- Desactivación online libera plaza al commit y publica evidencia terminal. El equipo retirado puede consultar su revisión revocada; otra activación recibe un UUID distinto.
- Panel con instalaciones e historial firmado, descarga del JWS archivado y autorización por rol.
- Migración `003_online_activations.sql`, nuevos GRANT y `bin/signing-key.php`; `bin/preflight.php --require-signer` comprueba disponibilidad del firmante.

## Verificación final

PHP 8.5.7 y MySQL 8.4.11 aislado: **58 pruebas, 360 aserciones**, sin fallos, errores u omisiones. Incluyen HTTP real, carreras en dos procesos, migración desde etapa 2 con datos, rollback después de firmar, rechazo de fixture en producción, rotación/propósitos y reglas comerciales.

Chrome: recorrido del panel, historial/descarga `.lic`, roles y móvil aprobados. Preflight con firmante y cadena de 20 eventos de la fixture verificados. Sintaxis de 76 archivos PHP válida. Cinco JWS, tres proofs y tres solicitudes offline siguen coincidiendo con el oráculo Go; tres negativos rechazados. Se añadió un acuse online de referencia, sin modificar el contrato literal ni los fixtures criptográficos anteriores.

Capturas sin secretos en `var/screenshots/`, incluida `activated-license-desktop.png`. Reproducción y límites en [TEST_PLAN.md](TEST_PLAN.md).

## Siguiente etapa: 4

Implementar importación/verificación/archivo protegido de `.licreq`, asignación administrativa de licencia en activación inicial y aprobación/rechazo con motivo. Integrar esos requests en el espacio compartido `(product_id, request_id)` de `license_requests` usando `channel=offline`; no duplicar efectos entre canales.

Reutilizar `RevisionPublisher` y las mismas transacciones/bloqueos para aprobación de activación, renovación y desactivación offline. La importación sola no concede derechos. Añadir transferencias autorizadas y recuperación ante equipo averiado, preservando identidad/historial y reconociendo los límites de revocación offline. Probar doble aprobación, identidad alterada, concurrencia API/offline y rollback.

La descarga actual `.lic` solo entrega revisiones existentes; no equivale al flujo offline completo. Etapa 5: integración con el cliente real, VPS PHP-FPM/TLS, respuestas del proxy, respaldo/restauración, anclajes externos y publicación de V1 completo.

## Entorno y puesta en marcha

El usuario mantiene MariaDB de XAMPP y preparará MySQL local/VPS después. La configuración `config/local.php` encontrada se conservó; no se leyó para las pruebas ni se ejecutaron migraciones sobre su BD. No se modificaron XAMPP ni bases del gestor.

Las pruebas utilizaron un MySQL 8 temporal independiente, sin TCP, y bases/usuarios sintéticos `aibid_test_*`. La fixture visual conserva configuración de pruebas en `/private/tmp/aibidlicense-panel-config.php`; no usarla como configuración real.

El servidor PHP de pruebas y MySQL temporal quedaron detenidos al finalizar. Las capturas y los resultados se conservan; para repetir el navegador se prepara una fixture nueva según TEST_PLAN.

Para actualizar su instalación: conservar configuración/secretos, ejecutar migraciones con la cuenta apropiada, aplicar nuevos GRANT, generar un firmante del entorno, distribuir su pública por el canal confiable del cliente y activarlo mediante CLI con `--trust-confirmed`. No regenerar claves de aplicación ni repetir bootstrap. Pasos en [README.md](README.md) y [OPERATIONS.md](OPERATIONS.md#8-claves-y-api-online-disponibles-en-etapa-3).
