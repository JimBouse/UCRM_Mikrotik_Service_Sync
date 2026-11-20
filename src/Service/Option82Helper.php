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
     * REST/lease field names to try for a configured search column.
     * Newer RouterOS uses active-agent-*; older ROS only has agent-*.
     *
     * @return string[]
     */
    public static function leaseFieldCandidates(string $searchColumn): array
    {
        $normalized = strtolower($searchColumn);

        if (in_array($normalized, ['active-agent-circuit-id', 'agent-circuit-id', 'circuitid'], true)) {
            return ['active-agent-circuit-id', 'agent-circuit-id'];
        }

        if (in_array($normalized, ['active-agent-remote-id', 'agent-remote-id', 'remoteid'], true)) {
            return ['active-agent-remote-id', 'agent-remote-id'];
        }

        return [$searchColumn];
    }

    /**
     * Read Option 82 (or other search) value from a DHCP lease.
     * Prefers active-agent-* when present and non-empty, else agent-*.
     */
    public static function leaseValue(array $lease, string $searchColumn): ?string
    {
        foreach (self::leaseFieldCandidates($searchColumn) as $field) {
            if (!array_key_exists($field, $lease)) {
                continue;
            }

            $value = $lease[$field];
            if ($value === null || $value === '') {
                continue;
            }

            return (string) $value;
        }

        return null;
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
     * Strip separators from a MAC/Option 82 string so UCRM `78:45:58:9A:06:0C`
     * can match a MikroTik remote ID of `7845589a060c`.
     */
    public static function normalizeForCompare(string $value): string
    {
        $stripped = preg_replace('/[:.;,\-\s]/', '', $value);

        return strtolower($stripped ?? '');
    }

    /**
     * True when a DHCP lease Option 82 field matches a UCRM search value,
     * whether MikroTik returned ASCII (older ROS) or hex (7.23+).
     * Also matches after stripping separators such as : . ; -
     */
    public static function matches(string $leaseValue, string $searchValue): bool
    {
        if ($leaseValue === $searchValue) {
            return true;
        }

        $leaseAscii = self::ascii($leaseValue);
        $searchAscii = self::ascii($searchValue);

        if ($leaseAscii === $searchValue || $leaseAscii === $searchAscii) {
            return true;
        }

        $leaseNorm = self::normalizeForCompare($leaseValue);
        $searchNorm = self::normalizeForCompare($searchValue);
        if ($leaseNorm !== '' && $leaseNorm === $searchNorm) {
            return true;
        }

        $leaseAsciiNorm = self::normalizeForCompare($leaseAscii);
        $searchAsciiNorm = self::normalizeForCompare($searchAscii);

        return $leaseAsciiNorm !== '' && ($leaseAsciiNorm === $searchNorm || $leaseAsciiNorm === $searchAsciiNorm);
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

        $stripped = preg_replace('/[:.;,\-\s]/', '', $searchValue) ?? $searchValue;
        if ($stripped !== $searchValue && $stripped !== '') {
            $variants[] = $stripped;
            $variants[] = strtolower($stripped);
            $variants[] = strtoupper($stripped);
        }

        return array_values(array_unique($variants));
    }
}
