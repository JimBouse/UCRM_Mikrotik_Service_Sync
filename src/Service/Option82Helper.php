<?php

namespace Ubnt\Plugins\MikrotikSync\Service;

/**
 * MikroTik lease Option 82 helper.
 * REST on 7.23+ often returns hex; older ROS returned ASCII.
 */
class Option82Helper
{
    private const OPTION82_FIELDS = [
        'active-agent-circuit-id',
        'active-agent-remote-id',
        'agent-circuit-id',
        'agent-remote-id',
        'circuitid',
        'remoteid',
    ];

    public static function isOption82Field(string $field): bool
    {
        return in_array(strtolower($field), self::OPTION82_FIELDS, true);
    }

    /**
     * @return array{raw: string, encoding: string, ascii: ?string, printable: bool, bytes: string}
     */
    public static function decode(string $value): array
    {
        $value = trim($value);

        $result = [
            'raw'       => $value,
            'encoding'  => 'ascii',
            'ascii'     => $value,
            'printable' => $value === '' || ctype_print($value),
            'bytes'     => $value,
        ];

        if ($value === '') {
            return $result;
        }

        $hex = $value;
        if (str_starts_with(strtolower($hex), '0x')) {
            $hex = substr($hex, 2);
        }

        // Allow "aa:bb:cc" style only when it is clearly hex pairs, not "ether2:10"
        $colonHex = (bool) preg_match('/^(?:[0-9a-f]{2}:)+[0-9a-f]{2}$/i', $hex);
        if ($colonHex) {
            $hex = str_replace(':', '', $hex);
        }

        $looksHex = (bool) preg_match('/^[0-9a-f]+$/i', $hex)
            && (strlen($hex) % 2 === 0)
            && (str_starts_with(strtolower($value), '0x') || $colonHex || ctype_xdigit($value));

        // Do not treat short ASCII that happens to be hex-looking as hex
        // unless it was prefixed 0x or colon-separated pairs.
        $ambiguousAscii = !$colonHex
            && !str_starts_with(strtolower($value), '0x')
            && ctype_print($value)
            && strlen($value) <= 8;

        if (!$looksHex || $ambiguousAscii) {
            return $result;
        }

        $bytes = hex2bin($hex);
        if ($bytes === false) {
            return $result;
        }

        $printable = $bytes === '' || preg_match('/^[\x20-\x7E]*$/', $bytes) === 1;

        return [
            'raw'       => $value,
            'encoding'  => 'hex',
            'ascii'     => $printable ? $bytes : null,
            'printable' => $printable,
            'bytes'     => $bytes,
        ];
    }

    public static function ascii(string $value): string
    {
        $decoded = self::decode($value);
        return $decoded['ascii'] ?? $decoded['raw'];
    }

    /**
     * True when a DHCP lease Option 82 field matches a UCRM search value,
     * whether MikroTik returned ASCII (older ROS) or hex (7.23+).
     */
    public static function matches(string $leaseValue, string $searchValue): bool
    {
        if ($leaseValue === $searchValue) {
            return true;
        }

        $leaseAscii = self::ascii($leaseValue);
        $searchAscii = self::ascii($searchValue);

        return $leaseAscii === $searchValue || $leaseAscii === $searchAscii;
    }

    /**
     * REST query encodings to try against MikroTik for an ASCII UCRM value.
     *
     * @return string[]
     */
    public static function queryVariants(string $searchValue): array
    {
        $variants = [$searchValue];
        $hex = bin2hex($searchValue);
        $variants[] = $hex;
        $variants[] = strtoupper($hex);
        $variants[] = '0x' . $hex;
        $variants[] = '0x' . strtoupper($hex);

        if (strlen($hex) >= 2) {
            $colon = implode(':', str_split($hex, 2));
            $variants[] = $colon;
            $variants[] = strtoupper($colon);
        }

        return array_values(array_unique($variants));
    }
}
