<?php
declare(strict_types=1);

ini_set('display_errors', '0');
date_default_timezone_set('UTC');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
// Preserve the origin on same-site form POSTs; no-referrer makes Chrome send
// Origin: null, which correctly fails our origin check even for a valid form.
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'");
if (($_SERVER['HTTPS'] ?? '') === 'on') { header('Strict-Transport-Security: max-age=31536000'); }
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    (new Aibid\Http\Kernel(Aibid\App::boot()))->handle();
} catch (Throwable $error) {
    // Keep correlation IDs valid even when Composer/bootstrap is unavailable.
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    $reference = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    error_log('AIBID [' . $reference . '] ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine());
    http_response_code(503);
    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/v1/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => 'TEMPORARY_UNAVAILABLE', 'message' => 'El servicio no está disponible temporalmente.', 'request_id' => $reference]]);
    } else {
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AIBID · Servicio no disponible</title><link rel="stylesheet" href="/assets/app.css"><main class="service-message"><img src="/assets/brand/logo-h.svg" alt="AIBID"><h1>El panel no está disponible temporalmente</h1><p>Intenta nuevamente en unos minutos. Si persiste, consulta al administrador del servidor.</p><small>Referencia: ' . $reference . '</small></main></html>';
    }
}
