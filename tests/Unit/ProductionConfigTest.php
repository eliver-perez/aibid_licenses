<?php
declare(strict_types=1);
namespace Aibid\Tests\Unit;

use Aibid\Config;
use Aibid\Infrastructure\{Clock, Totp};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductionConfigTest extends TestCase
{
    private function config(string $environment, string $url): Config
    {
        $values = ['APP_ENV'=>$environment, 'APP_URL'=>$url];
        foreach (['MFA_KEY','CREDENTIAL_KEY','AUDIT_KEY','SESSION_KEY','OPERATION_KEY'] as $name) {
            $values[$name] = base64_encode(random_bytes(32));
        }
        return new Config($values, false);
    }

    public static function invalidOrigins(): array
    {
        return array_map(fn($url)=>[$url], [
            'http://licensing.aidex.com.mx', 'http://127.0.0.1:8088',
            'https://user:secret@example.test', 'https://example.test?debug=1',
            'https://example.test#fragment', 'https://example.test/panel',
            "https://example.test\n", 'https://bad host.test', 'ftp://127.0.0.1',
        ]);
    }

    #[DataProvider('invalidOrigins')]
    public function testProductionRejectsInvalidOrInsecureOrigins(string $url): void
    {
        $this->expectException(\RuntimeException::class);
        $this->config('production', $url)->validate();
    }

    public function testProductionAndLocalDevelopmentRemainSeparate(): void
    {
        $production = $this->config('production', 'https://licensing.aidex.com.mx');
        $production->validate();
        self::assertFalse($production->development());
        foreach (['development','testing'] as $environment) {
            foreach (['localhost','127.0.0.1','[::1]'] as $host) {
                $config = $this->config($environment, 'http://'.$host.':8088');
                $config->validate();
                self::assertTrue($config->development());
            }
        }
    }

    public function testUnknownEnvironmentFailsBeforeDatabaseConnection(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->config('debug', 'https://example.test')->validate();
    }

    public function testLocalDevelopmentCannotUseOtherUrlSchemes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->config('development', 'ftp://127.0.0.1')->validate();
    }

    public function testProductionRequiresRealApplicationKeys(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Config(['APP_ENV'=>'production','APP_URL'=>'https://example.test'], false))->validate();
    }

    public function testAuthenticatorUsesAidexWithoutChangingTotpSecret(): void
    {
        $uri = (new Totp(new Clock()))->provisioningUri('JBSWY3DPEHPK3PXP', 'admin@example.test');
        parse_str(parse_url($uri, PHP_URL_QUERY), $parameters);
        self::assertSame('AIDEX Licencias', $parameters['issuer']);
        self::assertSame('JBSWY3DPEHPK3PXP', $parameters['secret']);
    }
}
