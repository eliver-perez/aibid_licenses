<?php
declare(strict_types=1);
namespace Aibid\Tests\Integration;

use Aibid\Tests\{MySqlFixture, NativeStackFixture};
use PHPUnit\Framework\TestCase;

final class ProductionHttpTest extends TestCase
{
    private MySqlFixture $fixture;
    private NativeStackFixture $stack;

    protected function setUp(): void
    {
        if (!getenv('TEST_MYSQL_DSN')) { self::markTestSkipped('Requires isolated MySQL 8, Nginx and PHP-FPM.'); }
        $this->fixture = new MySqlFixture();
        $this->stack = new NativeStackFixture($this->fixture);
    }

    protected function tearDown(): void
    {
        if (isset($this->stack)) { $this->stack->destroy(); }
        if (isset($this->fixture)) { $this->fixture->destroy(); }
    }

    public function testProductionCookiesBrandAssetsAndAccessControls(): void
    {
        $login = $this->stack->request('/login', '', 'GET');
        self::assertSame(200, $login['status']);
        foreach (['__host-license_admin=', '; secure', 'httponly', 'samesite=lax', 'path=/', 'content-security-policy:', 'strict-transport-security:', 'cache-control: no-store'] as $value) {
            self::assertStringContainsString($value, $login['headers']);
        }
        self::assertStringNotContainsString('domain=', $login['headers']);
        self::assertStringNotContainsString('x-powered-by:', $login['headers']);
        self::assertStringNotContainsString('aibid_admin_dev', $login['headers']);
        self::assertStringNotContainsString('AIBID', $login['body']);
        self::assertStringContainsString('AIDEX Licencias', $login['body']);
        self::assertStringContainsString('Aplicación de Indexación Digital de Expedientes', $login['body']);
        foreach (['logo-h.svg','logo-v.svg','logo-dark-h.svg','logo-dark-v.svg'] as $file) {
            $asset = $this->stack->request('/assets/brand/'.$file, '', 'GET');
            self::assertSame(200, $asset['status']);
            self::assertStringContainsString('AIDEX', $asset['body']);
            self::assertSame(file_get_contents(dirname(__DIR__, 2).'/public/assets/brand/'.$file), $asset['body']);
        }
        $admin = $this->stack->request('/admin', '', 'GET');
        self::assertSame(303, $admin['status']);
        self::assertStringContainsString('location: /login', $admin['headers']);
        self::assertSame(403, $this->stack->request('/login', 'csrf=invalid', 'POST', ['Content-Type: application/x-www-form-urlencoded', 'Origin: https://foreign.example.test'])['status']);
        self::assertSame(404, $this->stack->request('/test.php', '', 'GET')['status']);
        self::assertSame(403, $this->stack->request('/.env', '', 'GET')['status']);
        $api = $this->stack->request('/v1/activations/challenge');
        self::assertSame(400, $api['status']);
        self::assertSame('INVALID_REQUEST', $api['json']['error']['code']);
        self::assertStringNotContainsString('access-control-allow-origin:', $api['headers']);
        self::assertStringNotContainsString('set-cookie:', $api['headers']);
    }

    public function testBootstrapFailureHidesSecretsAndKeepsSafeDiagnostic(): void
    {
        // Test-only bad URL: the production boot guard fails before connecting to SQL.
        $values = array_replace($this->stack->values, ['APP_URL'=>'https://private-secret@example.test']);
        $this->stack->privateFile('config.php', "<?php return ".var_export($values, true).";\n");
        $response = $this->stack->request('/login', '', 'GET');
        self::assertSame(503, $response['status']);
        self::assertStringContainsString('AIDEX · Servicio no disponible', $response['body']);
        foreach (['private-secret', 'RuntimeException', $this->stack->directory, $values['DB_PASSWORD'], $values['MFA_KEY']] as $value) {
            self::assertStringNotContainsString($value, $response['body']);
        }
        $api = $this->stack->request('/v1/activations/challenge');
        self::assertSame(503, $api['status']);
        self::assertSame('TEMPORARY_UNAVAILABLE', $api['json']['error']['code']);
        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $api['json']['error']['request_id']);
        $log = file_get_contents($this->stack->directory.'/log/php-error.log');
        self::assertStringContainsString('AIDEX [', $log);
        self::assertStringContainsString('RuntimeException at Config.php:', $log);
        foreach (['private-secret', $values['DB_PASSWORD'], $values['MFA_KEY']] as $value) {
            self::assertStringNotContainsString($value, $log);
        }
    }
}
