<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath(__DIR__ . $path);
if ($file !== false && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) && is_file($file) && preg_match('/\.(?:css|js|map|svg|png|ico|woff2)$/', $file)) {
    return false;
}
require __DIR__ . '/index.php';
