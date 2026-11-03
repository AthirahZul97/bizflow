<?php

namespace App\Support;

/**
 * Reads the TRUSTED_PROXIES setting: which reverse proxies the application may believe when they
 * say what the client's IP, scheme, host and port were.
 *
 * Fail closed: an entry that is not a plain IP address or a CIDR range of a plausible size is
 * dropped and reported, never guessed at. "Trust everything" has many spellings, and each one is
 * refused: `*`, `**`, `REMOTE_ADDR` (Laravel's own spelling for "whoever is connecting"), the
 * unspecified addresses (`0.0.0.0`, `::`) and any range so wide it covers most of the Internet
 * (IPv4 narrower than /8, IPv6 narrower than /7). The only way to trust every peer is
 * TRUSTED_PROXIES_ALLOW_ALL together with `*`, and the demo and production preflight checks reject that.
 */
final class TrustedProxies
{
    /** The widest range accepted: a /8 of IPv4 (a private 10.0.0.0/8 network) and the IPv6 unique-local /7. */
    public const MIN_PREFIX_V4 = 8;

    public const MIN_PREFIX_V6 = 7;

    /**
     * `proxies` is what is handed to Laravel (the list, or `*` only when explicitly allowed);
     * `rejected` holds the entries that were dropped, so they can be reported and never trusted.
     *
     * @return array{proxies: list<string>|string, rejected: list<string>, allow_all: bool}
     */
    public static function parse(?string $raw, bool $allowAll = false): array
    {
        $proxies = [];
        $rejected = [];
        $wildcard = false;

        foreach (explode(',', (string) $raw) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (in_array($entry, ['*', '**'], true)) {
                $wildcard = true;

                continue;
            }

            if (self::isAcceptable($entry)) {
                $proxies[] = $entry;
            } else {
                $rejected[] = $entry;
            }
        }

        if ($wildcard && ! $allowAll) {
            $rejected[] = '*';
        }

        $everyone = $wildcard && $allowAll;

        return [
            'proxies' => $everyone ? '*' : array_values(array_unique($proxies)),
            'rejected' => array_values(array_unique($rejected)),
            'allow_all' => $everyone,
        ];
    }

    public static function isAcceptable(string $entry): bool
    {
        if ($entry === '' || strtoupper($entry) === 'REMOTE_ADDR' || preg_match('/\s/', $entry)) {
            return false;
        }

        if (! str_contains($entry, '/')) {
            return self::isUsableAddress($entry);
        }

        [$address, $prefix] = explode('/', $entry, 2);

        if (! preg_match('/^\d{1,3}$/', $prefix) || ! self::isUsableAddress($address)) {
            return false;
        }

        $prefix = (int) $prefix;
        $isV6 = str_contains($address, ':');

        return $isV6
            ? $prefix >= self::MIN_PREFIX_V6 && $prefix <= 128
            : $prefix >= self::MIN_PREFIX_V4 && $prefix <= 32;
    }

    /**
     * A real IP address that is not the "unspecified" one in any spelling (0.0.0.0, ::, ::ffff:0.0.0.0).
     */
    private static function isUsableAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $bytes = inet_pton($address);

        if ($bytes === false) {
            return false;
        }

        // An IPv4-mapped IPv6 address is judged by the IPv4 address inside it.
        if (strlen($bytes) === 16 && str_starts_with($bytes, str_repeat("\0", 10)."\xff\xff")) {
            $bytes = substr($bytes, 12);
        }

        return trim($bytes, "\0") !== '';
    }
}
