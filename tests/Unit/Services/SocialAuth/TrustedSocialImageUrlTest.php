<?php

use App\Exceptions\PermanentRemoteImageException;
use App\Exceptions\TransientRemoteImageException;
use App\Services\SocialAuth\PublicSocialImageAddressResolver;
use App\Services\SocialAuth\TrustedSocialImageUrl;
use Tests\TestCase;

uses(TestCase::class);

it('allows HTTPS avatar hosts within the verified social provider image domains', function (string $url): void {
    expect(TrustedSocialImageUrl::from($url)->url)->toBe($url);
})->with([
    'Google profile image' => ['https://lh3.googleusercontent.com/a/avatar'],
    'Facebook profile image' => ['https://platform-lookaside.fbsbx.com/platform/profilepic/avatar'],
    'Facebook image CDN redirect' => ['https://scontent-ams2-1.xx.fbcdn.net/avatar'],
    'exact Facebook graph image endpoint' => ['https://graph.facebook.com/42/picture'],
]);

it('rejects user supplied and non-HTTPS destinations before the network request', function (string $url): void {
    expect(fn () => TrustedSocialImageUrl::from($url))->toThrow(PermanentRemoteImageException::class);
})->with([
    'private address' => ['https://127.0.0.1/latest'],
    'metadata address' => ['https://169.254.169.254/latest/meta-data/'],
    'untrusted hostname' => ['https://attacker.example/avatar'],
    'lookalike suffix' => ['https://googleusercontent.com.attacker.example/avatar'],
    'trailing-dot host would bypass the cURL pin key' => ['https://lh3.googleusercontent.com./avatar'],
    'uppercase host is not canonical for the cURL pin key' => ['https://LH3.googleusercontent.com/avatar'],
    'empty DNS label' => ['https://lh3..googleusercontent.com/avatar'],
    'userinfo host confusion' => ['https://lh3.googleusercontent.com@attacker.example/avatar'],
    'unexpected service port' => ['https://lh3.googleusercontent.com:8443/avatar'],
    'plaintext scheme' => ['http://lh3.googleusercontent.com/avatar'],
    'malformed address' => ['https://[::1'],
]);

it('accepts only publicly routable DNS answers for the pinned image request', function (array $records, array $expected): void {
    expect(app(PublicSocialImageAddressResolver::class)->validateRecords($records))->toBe($expected);
})->with([
    'public IPv4 and IPv6' => [
        [['ip' => '8.8.8.8'], ['ipv6' => '2606:4700:4700::1111']],
        ['8.8.8.8', '2606:4700:4700::1111'],
    ],
    'duplicate answers' => [[['ip' => '8.8.8.8'], ['ip' => '8.8.8.8']], ['8.8.8.8']],
    'CNAME alongside public addresses' => [[['type' => 'CNAME', 'target' => 'cdn.example'], ['type' => 'A', 'ip' => '8.8.8.8']], ['8.8.8.8']],
]);

it('rejects any DNS answer that is private, reserved, multicast or malformed', function (string $address): void {
    expect(fn () => app(PublicSocialImageAddressResolver::class)->validateRecords([['ip' => $address]]))
        ->toThrow(PermanentRemoteImageException::class);
})->with([
    'loopback' => ['127.0.0.1'],
    'private IPv4' => ['10.0.0.5'],
    'metadata endpoint' => ['169.254.169.254'],
    'documentation range' => ['192.0.2.1'],
    'shared carrier-grade NAT' => ['100.64.0.1'],
    'IANA protocol assignments' => ['192.0.0.1'],
    'multicast' => ['224.0.0.1'],
    'IPv6 loopback' => ['::1'],
    'IPv6 unique local' => ['fc00::1'],
    'IPv6 link local' => ['fe80::1'],
    'IPv6 protocol assignments' => ['2001::1'],
    'IPv6 transition range' => ['2002::1'],
    'IPv6 documentation range' => ['3fff::1'],
    'malformed response' => ['not-an-ip'],
]);

it('treats DNS names with no address answers as a transient network failure', function (): void {
    expect(fn () => app(PublicSocialImageAddressResolver::class)->validateRecords([]))
        ->toThrow(TransientRemoteImageException::class);

    expect(fn () => app(PublicSocialImageAddressResolver::class)->validateRecords([
        ['type' => 'CNAME', 'target' => 'cdn.example'],
    ]))->toThrow(TransientRemoteImageException::class);
});

it('pins resolved IPv4 and IPv6 addresses to the HTTPS hostname', function (): void {
    expect(app(PublicSocialImageAddressResolver::class)->curlResolveEntries(
        'lh3.googleusercontent.com',
        ['8.8.8.8', '2606:4700:4700::1111'],
    ))
        ->toBe(['lh3.googleusercontent.com:443:8.8.8.8', 'lh3.googleusercontent.com:443:[2606:4700:4700::1111]']);
});
