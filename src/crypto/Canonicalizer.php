<?php
declare(strict_types=1);

/**
 * Canonicalizer  --  RFC 8785 (JSON Canonicalization Scheme) for the server.
 *
 * Any two implementations (this PHP one and the browser JS one in
 * public/assets/js/canonicalizer.js) MUST produce identical bytes for the same
 * logical object, or hashes and signatures will not reproduce and verification
 * will falsely fail. The two are covered by shared test vectors in
 * tests/vectors/canonical.json.
 *
 * Profile (see build spec section 7):
 *   1. UTF-8, object keys sorted by UTF-16 code unit (RFC 8785), no whitespace.
 *   2. Money / decimals are JSON strings with fixed scale, never JSON numbers.
 *      This module never emits a float; callers pass already-formatted strings.
 *   3. Binary UUIDs are lowercase hex strings of length 32.
 *   4. Timestamps are ISO-8601 UTC millisecond form (see toIso8601Utc()).
 *   5. Absent optional fields are omitted; a semantically-required field may be
 *      explicitly null. This is the caller's choice when building the array.
 *   6. Every canonical payload includes a "schema" field (the domain separator).
 *
 * IMPORTANT: this canonicalizer treats PHP floats as an error. All numeric-looking
 * values that matter (money, quantities) must arrive as strings. Integers that are
 * genuinely counts (sequence numbers, versions) are allowed and serialized as JSON
 * integers, matching JS Number integer output.
 */
final class Canonicalizer
{
    /**
     * Canonicalize a PHP value into RFC 8785 JCS bytes (UTF-8 string).
     *
     * @param mixed $value array (object or list), string, int, bool, or null.
     * @throws InvalidArgumentException on floats or unsupported types.
     */
    public static function encode($value): string
    {
        return self::serialize($value);
    }

    private static function serialize($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            // Forbidden: floats drift. Represent decimals as strings upstream.
            throw new InvalidArgumentException(
                'Canonicalizer: float values are forbidden; pass decimals as strings.'
            );
        }
        if (is_string($value)) {
            return self::serializeString($value);
        }
        if ($value instanceof stdClass) {
            // stdClass unambiguously denotes a JSON object (even when empty).
            return self::serializeObject((array) $value);
        }
        if (is_array($value)) {
            return self::isList($value)
                ? self::serializeArray($value)
                : self::serializeObject($value);
        }
        throw new InvalidArgumentException(
            'Canonicalizer: unsupported type ' . gettype($value)
        );
    }

    private static function isList(array $arr): bool
    {
        if ($arr === []) {
            // Empty array is ambiguous; treat as empty JSON array [].
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private static function serializeArray(array $list): string
    {
        $parts = [];
        foreach ($list as $item) {
            $parts[] = self::serialize($item);
        }
        return '[' . implode(',', $parts) . ']';
    }

    private static function serializeObject(array $obj): string
    {
        // RFC 8785: sort by UTF-16 code units of the key. PHP string keys are
        // UTF-8; convert to code-unit order for a correct sort.
        $keys = array_map('strval', array_keys($obj));
        usort($keys, [self::class, 'compareCodeUnits']);
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = self::serializeString($key) . ':' . self::serialize($obj[$key]);
        }
        return '{' . implode(',', $parts) . '}';
    }

    /**
     * Compare two UTF-8 strings by UTF-16 code unit, per RFC 8785 section 3.2.3.
     */
    private static function compareCodeUnits(string $a, string $b): int
    {
        $ua = self::toUtf16CodeUnits($a);
        $ub = self::toUtf16CodeUnits($b);
        $n = min(count($ua), count($ub));
        for ($i = 0; $i < $n; $i++) {
            if ($ua[$i] !== $ub[$i]) {
                return $ua[$i] <=> $ub[$i];
            }
        }
        return count($ua) <=> count($ub);
    }

    /** Convert a UTF-8 string to an array of UTF-16 code units. */
    private static function toUtf16CodeUnits(string $s): array
    {
        $units = [];
        $cps = self::utf8ToCodePoints($s);
        foreach ($cps as $cp) {
            if ($cp <= 0xFFFF) {
                $units[] = $cp;
            } else {
                $cp -= 0x10000;
                $units[] = 0xD800 + ($cp >> 10);
                $units[] = 0xDC00 + ($cp & 0x3FF);
            }
        }
        return $units;
    }

    /** Decode UTF-8 bytes to an array of Unicode code points. */
    private static function utf8ToCodePoints(string $s): array
    {
        $cps = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $c = ord($s[$i]);
            if ($c < 0x80) {
                $cps[] = $c;
                $i += 1;
            } elseif (($c & 0xE0) === 0xC0) {
                $cps[] = (($c & 0x1F) << 6) | (ord($s[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($c & 0xF0) === 0xE0) {
                $cps[] = (($c & 0x0F) << 12) | ((ord($s[$i + 1]) & 0x3F) << 6)
                    | (ord($s[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                $cps[] = (($c & 0x07) << 18) | ((ord($s[$i + 1]) & 0x3F) << 12)
                    | ((ord($s[$i + 2]) & 0x3F) << 6) | (ord($s[$i + 3]) & 0x3F);
                $i += 4;
            }
        }
        return $cps;
    }

    /**
     * Serialize a JSON string per RFC 8785 section 3.2.2.2: minimal escaping,
     * lowercase \u escapes only for control characters that lack a short escape.
     */
    private static function serializeString(string $s): string
    {
        $out = '"';
        $cps = self::utf8ToCodePoints($s);
        foreach ($cps as $cp) {
            switch ($cp) {
                case 0x08: $out .= '\\b'; break;
                case 0x09: $out .= '\\t'; break;
                case 0x0A: $out .= '\\n'; break;
                case 0x0C: $out .= '\\f'; break;
                case 0x0D: $out .= '\\r'; break;
                case 0x22: $out .= '\\"'; break;
                case 0x5C: $out .= '\\\\'; break;
                default:
                    if ($cp < 0x20) {
                        $out .= sprintf('\\u%04x', $cp);
                    } else {
                        $out .= self::codePointToUtf8($cp);
                    }
            }
        }
        return $out . '"';
    }

    private static function codePointToUtf8(int $cp): string
    {
        if ($cp <= 0x7F) {
            return chr($cp);
        }
        if ($cp <= 0x7FF) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp <= 0xFFFF) {
            return chr(0xE0 | ($cp >> 12))
                . chr(0x80 | (($cp >> 6) & 0x3F))
                . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18))
            . chr(0x80 | (($cp >> 12) & 0x3F))
            . chr(0x80 | (($cp >> 6) & 0x3F))
            . chr(0x80 | ($cp & 0x3F));
    }

    // ---- Helpers for the canonical value forms (spec section 7) ----------

    /** 16 raw bytes -> lowercase 32-char hex string. */
    public static function uuidToHex(string $binary16): string
    {
        if (strlen($binary16) !== 16) {
            throw new InvalidArgumentException('uuidToHex: expected 16 bytes.');
        }
        return bin2hex($binary16);
    }

    /** lowercase hex string -> 16 raw bytes. */
    public static function hexToUuid(string $hex): string
    {
        $hex = strtolower($hex);
        if (strlen($hex) !== 32 || !ctype_xdigit($hex)) {
            throw new InvalidArgumentException('hexToUuid: expected 32 hex chars.');
        }
        return hex2bin($hex);
    }

    /**
     * Format a decimal string to a fixed scale (spec section 7 rule 2).
     * Input may be a numeric string; output is a plain decimal string with
     * exactly $scale fractional digits and no thousands separators, no exponent.
     */
    public static function decimalString(string $value, int $scale): string
    {
        if (!preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("decimalString: not a plain decimal: {$value}");
        }
        $neg = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        $dot = strpos($value, '.');
        if ($dot === false) {
            $intPart = $value;
            $fracPart = '';
        } else {
            $intPart = substr($value, 0, $dot);
            $fracPart = substr($value, $dot + 1);
        }
        $intPart = ltrim($intPart, '0');
        if ($intPart === '') {
            $intPart = '0';
        }
        if (strlen($fracPart) > $scale) {
            // Truncate extra precision deterministically (do not round).
            $fracPart = substr($fracPart, 0, $scale);
        } else {
            $fracPart = str_pad($fracPart, $scale, '0');
        }
        $out = $scale > 0 ? "{$intPart}.{$fracPart}" : $intPart;
        if ($neg && !preg_match('/^0(\.0*)?$/', $out)) {
            $out = '-' . $out;
        }
        return $out;
    }

    /**
     * Format a DateTimeInterface (or ISO string) to canonical ISO-8601 UTC with
     * millisecond precision and a Z suffix, e.g. 2026-08-13T07:30:00.000Z.
     */
    public static function toIso8601Utc(DateTimeInterface $dt): string
    {
        $utc = (clone $dt instanceof DateTimeImmutable
            ? DateTimeImmutable::createFromInterface($dt)
            : DateTimeImmutable::createFromInterface($dt))
            ->setTimezone(new DateTimeZone('UTC'));
        return $utc->format('Y-m-d\TH:i:s.v\Z');
    }
}
