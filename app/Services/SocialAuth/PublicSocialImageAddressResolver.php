<?php

namespace App\Services\SocialAuth;

use App\Exceptions\PermanentRemoteImageException;
use App\Exceptions\TransientRemoteImageException;

/** Resolves trusted provider hosts to public addresses before a pinned fetch. */
class PublicSocialImageAddressResolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            throw new TransientRemoteImageException('Remote image host could not be resolved.');
        }

        return $this->validateRecords($records);
    }

    /** @param list<array{ip?: string, ipv6?: string, type?: string, target?: string}> $records
     * @return list<string>
     */
    public function validateRecords(array $records): array
    {
        if ($records === []) {
            throw new TransientRemoteImageException('Remote image host returned no address records.');
        }

        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['type']) && ! in_array($record['type'], ['A', 'AAAA'], true)) {
                continue;
            }

            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($address) || ! $this->isPublicAddress($address)) {
                throw new PermanentRemoteImageException('Remote image host resolved to a non-public address.');
            }
            $addresses[] = $address;
        }

        if ($addresses === []) {
            throw new TransientRemoteImageException('Remote image host returned no address records.');
        }

        return array_values(array_unique($addresses));
    }

    public function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false && ! $this->isMulticast($address) && ! $this->isSpecialUseAddress($address);
    }

    /** @param list<string> $addresses
     * @return list<string>
     */
    public function curlResolveEntries(string $host, array $addresses): array
    {
        return array_map(
            static fn (string $address): string => sprintf('%s:443:%s', $host, str_contains($address, ':') ? '['.$address.']' : $address),
            $addresses,
        );
    }

    private function isMulticast(string $address): bool
    {
        $packed = inet_pton($address);
        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 4) {
            return (ord($packed[0]) & 0xF0) === 0xE0 || $packed === "\xff\xff\xff\xff";
        }

        return (ord($packed[0]) & 0xFF) === 0xFF;
    }

    private function isSpecialUseAddress(string $address): bool
    {
        // PHP's IP flags do not include every IANA non-global special-purpose block.
        $packed = inet_pton($address);
        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 4) {
            foreach ([
                '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24',
                '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
            ] as $range) {
                if ($this->isWithinCidr($address, $range)) {
                    return true;
                }
            }

            return false;
        }

        foreach ([
            '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '100:0:0:1::/64',
            '2001::/23', '2001:2::/48', '2001:10::/28', '2001:20::/28',
            '2001:db8::/32', '2002::/16', '3fff::/20', '5f00::/16',
        ] as $range) {
            if ($this->isWithinCidr($address, $range)) {
                return true;
            }
        }

        return ! $this->isWithinCidr($address, '2000::/3');
    }

    private function isWithinCidr(string $address, string $range): bool
    {
        [$network, $prefixLength] = explode('/', $range, 2);
        $packedAddress = inet_pton($address);
        $packedNetwork = inet_pton($network);
        if ($packedAddress === false || $packedNetwork === false || strlen($packedAddress) !== strlen($packedNetwork)) {
            return false;
        }

        $prefixLength = (int) $prefixLength;
        $wholeBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;
        if (substr($packedAddress, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packedAddress[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }
}
