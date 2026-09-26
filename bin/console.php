<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

function hiddenInput(string $label): string
{
    if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
        throw new RuntimeException('Se requiere una terminal interactiva para introducir contraseñas sin mostrarlas.');
    }
    fwrite(STDOUT, $label);
    $terminalState = trim((string) shell_exec('stty -g'));
    if ($terminalState === '') { throw new RuntimeException('No se pudo proteger la entrada de la terminal.'); }
    system('stty -echo');
    try { $value = rtrim((string) fgets(STDIN), "\r\n"); }
    finally { system('stty ' . escapeshellarg($terminalState)); fwrite(STDOUT, PHP_EOL); }
    return $value;
}

function consoleRun(callable $operation): void
{
    try { $operation(); }
    catch (Aibid\Domain\Problem $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
    catch (Throwable $error) {
        // Never print PDO connection strings, parameters, environment, or secrets.
        fwrite(STDERR, 'No se pudo completar el comando (' . get_class($error) . '). Revisa configuración, permisos y estado de las migraciones.' . PHP_EOL);
        exit(1);
    }
}
