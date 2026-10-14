<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PublicUrl
{
    public static function addresses(string $url): array
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Map URL must be HTTP(S) without embedded credentials.');
        }
        $host = trim($parts['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(array_column(dns_get_record($host, DNS_A) ?: [], 'ip'), array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
        if (! $addresses) {
            throw new RuntimeException('Map URL hostname did not resolve.');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || str_starts_with(strtolower($address), '::ffff:')) {
                throw new RuntimeException('Map URLs cannot target private or reserved network addresses.');
            }
        }

        return array_values(array_unique($addresses));
    }

    public function check(string $url): void
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('Map URL verification requires the PHP curl extension.');
        }
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $ips = self::addresses($url);
            $parts = parse_url($url);
            $host = $parts['host'];
            $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
            $pins = array_map(fn ($ip) => "$host:$port:".(str_contains($ip, ':') ? "[$ip]" : $ip), $ips);
            $response = Http::connectTimeout(5)->timeout(10)->withOptions(['allow_redirects' => false, 'verify' => true, 'curl' => [CURLOPT_RESOLVE => $pins]])->head($url);
            if ($response->successful()) {
                return;
            }
            if ($response->redirect() && $redirect < 3) {
                $location = $response->header('Location');
                if (! $location) {
                    break;
                }
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }
            throw new RuntimeException('Custom map URL is unavailable (HTTP '.$response->status().').');
        }
        throw new RuntimeException('Map URL exceeded the redirect limit.');
    }
}
