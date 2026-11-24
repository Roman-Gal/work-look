<?php
declare(strict_types=1);
namespace App\Service;

use MaxMind\Db\Reader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Local lookups only: visitor IPs never leave the application. */
final class IpLocation
{
    public function __construct(#[Autowire('%kernel.project_dir%/var/geoip/location.mmdb')] private string $database) {}

    public function available(): bool { return is_readable($this->database); }

    public function locate(?string $ip): array
    {
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || !$this->available()) { return ['country' => null, 'city' => null]; }
        $reader = null;
        try {
            $reader = new Reader($this->database); $record = $reader->get($ip) ?? [];
            $country = $record['country']['iso_code'] ?? null;
            return ['country' => is_string($country) && preg_match('/^[A-Z]{2}$/D', $country) ? $country : null,
                'city' => isset($record['city']['names']['en']) ? mb_substr((string) $record['city']['names']['en'], 0, 120) : null];
        } catch (\Throwable) { return ['country' => null, 'city' => null]; }
        finally { $reader?->close(); }
    }
}
