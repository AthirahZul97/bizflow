<?php

namespace Tests\Unit;

use App\Support\TrustedProxies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrustedProxiesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function trustEveryone(): array
    {
        return [
            'wildcard' => ['*'],
            'double wildcard' => ['**'],
            'remote addr' => ['REMOTE_ADDR'],
            'remote addr lower case' => ['remote_addr'],
            'ipv4 everything' => ['0.0.0.0/0'],
            'ipv4 everything, zero padded' => ['0.0.0.0/00'],
            'ipv6 everything' => ['::/0'],
            'ipv4 unspecified' => ['0.0.0.0'],
            'ipv6 unspecified' => ['::'],
            'ipv6 unspecified, long form' => ['0:0:0:0:0:0:0:0'],
            'ipv4 mapped unspecified' => ['::ffff:0.0.0.0'],
            'ipv4 mapped unspecified range' => ['::ffff:0.0.0.0/96'],
            'half of the internet' => ['0.0.0.0/1'],
            'a /7 of ipv4' => ['10.0.0.0/7'],
            'a /6 of ipv6' => ['fc00::/6'],
            'prefix missing' => ['10.0.0.1/'],
            'prefix not a number' => ['10.0.0.1/8x'],
            'negative prefix' => ['10.0.0.1/-1'],
            'ipv4 prefix too big' => ['10.0.0.1/33'],
            'ipv6 prefix too big' => ['2001:db8::/129'],
            'not an address' => ['proxy.internal'],
            'hostname with port' => ['10.0.0.1:443'],
            'embedded space' => ['10.0.0.1 /8'],
            'bad octet' => ['256.1.1.1'],
        ];
    }

    #[DataProvider('trustEveryone')]
    public function test_every_way_of_trusting_everyone_is_refused(string $entry): void
    {
        $parsed = TrustedProxies::parse($entry);

        $this->assertSame([], $parsed['proxies'], "{$entry} must not be trusted");
        $this->assertNotSame([], $parsed['rejected'], "{$entry} must be reported");
        $this->assertFalse($parsed['allow_all']);
        $this->assertFalse(TrustedProxies::isAcceptable($entry));
    }

    #[DataProvider('trustEveryone')]
    public function test_the_allow_all_switch_only_ever_applies_to_a_literal_wildcard(string $entry): void
    {
        $parsed = TrustedProxies::parse($entry, allowAll: true);

        if (in_array($entry, ['*', '**'], true)) {
            $this->assertSame('*', $parsed['proxies']);
            $this->assertTrue($parsed['allow_all']);

            return;
        }

        $this->assertSame([], $parsed['proxies']);
        $this->assertFalse($parsed['allow_all']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function plausibleProxies(): array
    {
        return [
            'a single ipv4 address' => ['203.0.113.10'],
            'a private ipv4 /8' => ['10.0.0.0/8'],
            'a private ipv4 /12' => ['172.16.0.0/12'],
            'a /32' => ['192.0.2.7/32'],
            'a single ipv6 address' => ['2001:db8::1'],
            'an ipv6 /32' => ['2001:db8::/32'],
            'ipv6 unique local' => ['fc00::/7'],
            'ipv6 unique local, fd00' => ['fd00::/8'],
            'ipv6 loopback' => ['::1'],
            'ipv4 loopback' => ['127.0.0.1'],
        ];
    }

    #[DataProvider('plausibleProxies')]
    public function test_plain_addresses_and_sensible_ranges_are_accepted(string $entry): void
    {
        $parsed = TrustedProxies::parse($entry);

        $this->assertSame([$entry], $parsed['proxies']);
        $this->assertSame([], $parsed['rejected']);
    }

    public function test_a_mixed_list_keeps_the_good_entries_and_reports_the_bad_ones(): void
    {
        $parsed = TrustedProxies::parse(' 10.0.0.1 , 0.0.0.0/0,172.16.0.0/12,REMOTE_ADDR,,10.0.0.1 ');

        $this->assertSame(['10.0.0.1', '172.16.0.0/12'], $parsed['proxies']);
        $this->assertSame(['0.0.0.0/0', 'REMOTE_ADDR'], $parsed['rejected']);
    }

    public function test_an_empty_setting_trusts_nobody_and_reports_nothing(): void
    {
        foreach ([null, '', '  ', ' , '] as $raw) {
            $this->assertSame(['proxies' => [], 'rejected' => [], 'allow_all' => false], TrustedProxies::parse($raw));
        }
    }

    public function test_a_wildcard_without_the_switch_is_reported_and_trusts_nobody(): void
    {
        $this->assertSame(['proxies' => [], 'rejected' => ['*'], 'allow_all' => false], TrustedProxies::parse('*'));
        $this->assertSame(['proxies' => ['10.0.0.1'], 'rejected' => ['*'], 'allow_all' => false], TrustedProxies::parse('10.0.0.1,*'));
    }
}
