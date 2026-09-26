<?php
declare(strict_types=1);
namespace Aibid;

use Aibid\Application\{ActivationService, AdminService, AuthService, CatalogService, LicenseService, Operations, RevisionPublisher};
use Aibid\Infrastructure\{Audit, Clock, Crypto, Database, RateLimiter, ReadRepository, Sessions, SigningKeys, Totp};

final class App
{
    public readonly Clock $clock;
    public readonly Crypto $crypto;
    public readonly Audit $audit;
    public readonly Sessions $sessions;
    public readonly Totp $totp;
    public readonly AuthService $auth;
    public readonly CatalogService $catalog;
    public readonly LicenseService $licenses;
    public readonly AdminService $admins;
    public readonly ReadRepository $read;
    public readonly SigningKeys $signingKeys;
    public readonly RevisionPublisher $publisher;
    public readonly ActivationService $activations;
    public readonly RateLimiter $rate;

    public function __construct(public readonly Config $config, public readonly Database $db, ?Clock $clock = null)
    {
        $this->clock = $clock ?? new Clock();
        $this->crypto = new Crypto($config);
        $this->audit = new Audit($db, $this->crypto, $this->clock);
        $this->sessions = new Sessions($db, $this->crypto, $this->clock);
        $this->totp = new Totp($this->clock);
        $rate = new RateLimiter($db, $this->crypto, $this->clock);
        $this->rate = $rate;
        $this->signingKeys = new SigningKeys($config, $db, $this->clock, $this->audit);
        $this->publisher = new RevisionPublisher($db, $this->clock, $this->signingKeys);
        $this->activations = new ActivationService($db, $this->clock, $this->crypto, $this->audit, $this->publisher, $rate);
        $this->auth = new AuthService($db, $this->crypto, $this->clock, $this->audit, $this->sessions, $rate, $this->totp);
        $operations = new Operations($db, $this->crypto, $this->clock);
        $this->catalog = new CatalogService($db, $operations, $this->audit, $this->clock);
        $this->licenses = new LicenseService($db, $operations, $this->audit, $this->clock, $this->crypto, $this->publisher);
        $this->admins = new AdminService($db, $operations, $this->auth, $this->audit, $this->clock);
        $this->read = new ReadRepository($db, $this->clock);
    }

    public static function boot(): self
    {
        $config = Config::load();
        $config->validate();
        return new self($config, Database::connect($config), null);
    }
}
