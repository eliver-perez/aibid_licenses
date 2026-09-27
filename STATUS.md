# Punto de continuación · 26 de septiembre de 2026

## Entrega actual

**Etapas 1–5 completadas en el alcance local autorizado.** El servidor PHP/MySQL incluye panel comercial, API V1, operación offline, integración con el cliente real y respaldo/restauración. No se accedió al VPS. El usuario pidió todas las pruebas locales e instrucciones para instalar en **aibid.adariel.com, Ubuntu 24.04 y Nginx**.

El siguiente paso del operador es seguir [DEPLOY_UBUNTU_24_04.md](DEPLOY_UBUNTU_24_04.md). El paquete `var/releases/aibidlicense-stage5.tar.gz` lleva código/documentación y composer.lock, sin configuración real, claves, datos, vendor o dependencias de pruebas. Su `.sha256` y `RELEASE.json` permiten verificarlo; Composer instala las dependencias en el VPS.

## Resultado de etapa 5

- Cliente real localizado en `../expediente`; copia temporal del código actual, sin editar su repositorio ni sus bases. Se llaman sus métodos originales para activar, refrescar, importar/exportar solicitudes y evaluar derechos. El backend del servidor sigue siendo PHP; Go solo ejecuta el cliente/oráculo durante pruebas.
- Compatibilidad B-01/B-02 comprobada: acuse online, JWS terminal revocado, licencia comercial disponible, transferencia, identidad, replay, revisiones, rotación y fronteras exactas de tolerancia de 15 días. Consulta/exportación se conservan en estados sin escritura.
- Nginx y PHP-FPM reales en loopback por HTTPS, certificado temporal verificado por el cliente; sin deshabilitar TLS. Cookies de producción, límites JSON/multipart, errores JSON del proxy y mantenimiento comprobados.
- Respaldo cifrado por bloques con clave independiente, manifiesto, SQL consistente, cinco claves de aplicación y privadas del entorno. Restauración solo en base vacía; verificador de JWS, evidencia, contadores, plazas y auditoría/anclaje externo. Conserva claves y no habilita emisión automáticamente.
- CLI de configuración persistente y recuperación, paquete por lista explícita, pool FPM, Nginx HTTP/TLS, servicio/timer diario y guía de instalación/actualización/recuperación. No cambian las migraciones 001–004, el contrato literal ni los SVG.

## Verificación final local

**96 pruebas PHP, 571 aserciones**, sin omisiones, errores o advertencias: 40 unitarias y 56 de integración contra MySQL aislado. Incluyen el ensayo completo por CLI de respaldo, extracción y restauración (34 aserciones), con MFA, claves históricas, evidencia offline y rechazo de un destino ocupado o un anclaje posterior a la copia.

**2 pruebas de sistema, 83 aserciones**, con el cliente real por Nginx/FPM/HTTPS. También ejecutan los tests originales de contrato/seguridad seleccionados del cliente. El informe `var/client-integration-report.json` identifica los archivos fuente exactos mediante SHA-256; la prueba no presupone que el repositorio del cliente esté limpio.

Entorno: PHP/FPM 8.5.7, MySQL 8.4.11, Nginx 1.31.3, Go 1.27.1 para el cliente. Contratos/fixtures originales verificados. CLI interactiva comprobada con una pseudoterminal: entrada oculta antes de mostrar el prompt, archivos 0600, negativa a sobrescribir y conservación de raíces al recuperar. Sintaxis PHP/shell y contenido del paquete verificados. Reproducción detallada en [TEST_PLAN.md](TEST_PLAN.md#10-evidencia-de-etapa-5).

Las pruebas Chrome de escritorio y móvil de la etapa 4 se conservan como evidencia de esa entrega; no se atribuyen a una nueva ejecución en etapa 5, que no cambió la interfaz.

## Límites y comprobaciones del operador

La guía permite montar el servicio, pero DNS, certificado público, permisos efectivos, paquetes PHP 8.3/MySQL 8.0 de Ubuntu y timers deben comprobarse en el VPS. No se creó ninguna clave de producción ni se distribuyeron públicas a instalaciones reales.

La tarea diaria crea copias/anclajes locales. Falta que el operador configure almacenamiento externo y guarde la clave de recuperación separada. El RPO diario puede alcanzar 24 horas; el objetivo propuesto de 15 minutos requiere binlogs externos y ensayo adicional. No se promete un RTO medido en el VPS.

El cliente real mantiene `offline_deactivation_pending` después de importar una revisión terminal: rechaza escritura y el servidor libera la plaza, pero el indicador local sigue visible. Se documenta; no se modificó el cliente. El formato de manifiestos del actualizador continúa fuera del contrato y del alcance de este servidor.

## Entorno preservado

`config/local.php` se conservó sin leerlo ni usarlo en pruebas. XAMPP/MariaDB y bases del gestor documental no se tocaron. Las bases/usuarios sintéticos `aibid_test_*` se eliminan al terminar cada prueba. Los procesos temporales de Nginx/FPM se detienen con sus fixtures y MySQL de pruebas queda detenido al cerrar la entrega.

No repetir configure/bootstrap ni generar claves nuevas al actualizar una instalación existente. Preservar configuración/claves; aplicar solo migraciones pendientes y permisos correspondientes; verificar preflight, auditoría y recuperación antes de reabrir.
