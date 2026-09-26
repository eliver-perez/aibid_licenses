<?php
declare(strict_types=1);
namespace Aibid;

final class Config
{
    public function __construct(private array $values = [], private bool $environmentOverrides = true) {}

    public static function load(): self
    {
        $path = getenv('AIBID_CONFIG') ?: dirname(__DIR__) . '/config/local.php';
        return new self(is_file($path) ? require $path : []);
    }

    public function get(string $name, string $default = ''): string
    {
        $environment = $this->environmentOverrides ? getenv($name) : false;
        return $environment !== false ? $environment : (string) ($this->values[$name] ?? $default);
    }

    public function development(): bool
    {
        return in_array($this->get('APP_ENV', 'production'), ['development', 'testing'], true);
    }

    public function key(string $name): string
    {
        $key = base64_decode($this->get($name), true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('Falta configurar una clave de aplicación válida: ' . $name);
        }
        return $key;
    }

    public function validate(): void
    {
        foreach (['MFA_KEY', 'CREDENTIAL_KEY', 'AUDIT_KEY', 'SESSION_KEY', 'OPERATION_KEY'] as $name) {
            $this->key($name);
        }
        $url = parse_url($this->get('APP_URL'));
        if (!$url || !isset($url['host']) || isset($url['user']) || isset($url['query']) || !in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new \RuntimeException('APP_URL debe ser el origen del sitio, sin subdirectorio.');
        }
        if (($url['scheme'] ?? '') !== 'https' && !($this->development() && in_array($url['host'], ['127.0.0.1', 'localhost', '[::1]'], true))) {
            throw new \RuntimeException('HTTPS es obligatorio fuera del desarrollo local.');
        }
    }
}
