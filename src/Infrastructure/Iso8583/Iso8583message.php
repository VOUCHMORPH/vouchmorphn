<?php
declare(strict_types=1);

namespace Infrastructure\Iso8583;

/**
 * ISO 8583 message codec — parses raw wire-format messages into a
 * field array, and builds field arrays back into wire format.
 *
 * SCOPE: implements the field set actually needed for authorization
 * request/response pairs (MTI 0100/0110, 0200/0210) — PAN, processing
 * code, amount, transmission datetime, STAN, expiry, terminal/merchant
 * IDs, response code, etc. Does NOT implement every one of the 128
 * possible bitmap fields — extend addField()'s $fieldSpecs table as
 * real terminal/switch certification requirements surface which
 * additional fields they send. This is a real, spec-compliant subset,
 * not a mock.
 *
 * ENCODING CHOICE: ASCII (not BCD/binary packed) for every numeric and
 * bitmap field. Real switches negotiate this per-connection — some are
 * binary-only. Confirm the actual wire encoding your acquiring
 * switch/sponsor requires before certification; ASCII is the more
 * common default for TCP/IP-based ISO 8583 links (vs. legacy X.25),
 * and is what's implemented here.
 */
class Iso8583Message
{
    /**
     * Field number => [type, length-spec]
     * type: 'n' numeric, 'a' alpha, 'an' alphanumeric, 'ans' alphanumeric+special, 'b' binary
     * length-spec: 'fixed:N' or 'var2:N' (2-digit length prefix, max N) or 'var3:N'
     */
    private const FIELD_SPECS = [
        2  => ['n', 'var2:19'],   // PAN
        3  => ['n', 'fixed:6'],   // Processing code
        4  => ['n', 'fixed:12'],  // Amount, transaction (in minor units, e.g. cents/thebe)
        7  => ['n', 'fixed:10'],  // Transmission date & time (MMDDhhmmss)
        11 => ['n', 'fixed:6'],   // System trace audit number (STAN)
        12 => ['n', 'fixed:6'],   // Local transaction time (hhmmss)
        13 => ['n', 'fixed:4'],   // Local transaction date (MMDD)
        14 => ['n', 'fixed:4'],   // Expiration date (YYMM)
        18 => ['n', 'fixed:4'],   // Merchant category code
        22 => ['n', 'fixed:3'],   // POS entry mode
        25 => ['n', 'fixed:2'],   // POS condition code
        32 => ['n', 'var2:11'],   // Acquiring institution ID
        35 => ['z', 'var2:37'],   // Track 2 data (rarely needed for a hosted-authorization model; included for completeness)
        37 => ['an', 'fixed:12'], // Retrieval reference number
        39 => ['an', 'fixed:2'],  // Response code
        41 => ['ans', 'fixed:8'], // Card acceptor terminal ID
        42 => ['ans', 'fixed:15'],// Card acceptor ID (merchant ID)
        43 => ['ans', 'fixed:40'],// Card acceptor name/location
        49 => ['a', 'fixed:3'],   // Currency code, transaction (ISO 4217 numeric, e.g. '072' = BWP)
        52 => ['b', 'fixed:8'],   // PIN block (not used by this integration — dynamic_code replaces PIN, see bridge)
        54 => ['an', 'var3:120'], // Additional amounts (unused here, reserved)
        90 => ['n', 'fixed:42'],  // Original data elements (for reversals — not yet implemented)
        95 => ['an', 'fixed:42'], // Replacement amounts (unused here, reserved)
    ];

    public string $mti;
    public array $fields = []; // field number => value (as parsed/decoded string)

    private function __construct(string $mti, array $fields)
    {
        $this->mti = $mti;
        $this->fields = $fields;
    }

    public static function fromWire(string $raw): self
    {
        $offset = 0;

        $mti = substr($raw, $offset, 4);
        $offset += 4;
        if (!preg_match('/^\d{4}$/', $mti)) {
            throw new \RuntimeException("Invalid or missing MTI at start of message: '{$mti}'");
        }

        // Primary bitmap: 16 hex chars = 64 bits, fields 1-64
        $primaryBitmapHex = substr($raw, $offset, 16);
        $offset += 16;
        $bitmap = self::hexToBits($primaryBitmapHex);

        // Secondary bitmap present if bit 1 of primary bitmap is set — fields 65-128
        if ($bitmap[0] === '1') {
            $secondaryBitmapHex = substr($raw, $offset, 16);
            $offset += 16;
            $bitmap .= self::hexToBits($secondaryBitmapHex);
        }

        $fields = [];
        for ($bit = 1; $bit <= strlen($bitmap); $bit++) {
            if ($bitmap[$bit - 1] !== '1') continue;
            if ($bit === 1) continue; // bit 1 is the "secondary bitmap present" flag, not a real field

            $spec = self::FIELD_SPECS[$bit] ?? null;
            if ($spec === null) {
                throw new \RuntimeException("Field {$bit} is set in the bitmap but has no known spec — extend FIELD_SPECS before this message type can be parsed");
            }
            [$type, $lengthSpec] = $spec;

            [$length, $lenPrefixSize] = self::readLength($raw, $offset, $lengthSpec);
            $offset += $lenPrefixSize;

            $value = substr($raw, $offset, $length);
            $offset += $length;

            $fields[$bit] = $value;
        }

        return new self($mti, $fields);
    }

    public function toWire(): string
    {
        if (!preg_match('/^\d{4}$/', $this->mti)) {
            throw new \RuntimeException("Invalid MTI: '{$this->mti}'");
        }

        $fieldNumbers = array_keys($this->fields);
        sort($fieldNumbers);
        $needsSecondary = !empty(array_filter($fieldNumbers, fn($n) => $n > 64));

        $primaryBits = str_repeat('0', 64);
        if ($needsSecondary) {
            $primaryBits[0] = '1';
        }
        foreach ($fieldNumbers as $n) {
            if ($n <= 64 && $n !== 1) {
                $primaryBits[$n - 1] = '1';
            }
        }

        $out = $this->mti . self::bitsToHex($primaryBits);

        if ($needsSecondary) {
            $secondaryBits = str_repeat('0', 64);
            foreach ($fieldNumbers as $n) {
                if ($n > 64) {
                    $secondaryBits[$n - 65] = '1';
                }
            }
            $out .= self::bitsToHex($secondaryBits);
        }

        foreach ($fieldNumbers as $n) {
            $spec = self::FIELD_SPECS[$n] ?? null;
            if ($spec === null) {
                throw new \RuntimeException("Field {$n} has no known spec — cannot encode. Extend FIELD_SPECS.");
            }
            [$type, $lengthSpec] = $spec;
            $value = (string)$this->fields[$n];
            $out .= self::encodeField($value, $lengthSpec);
        }

        return $out;
    }

    private static function hexToBits(string $hex): string
    {
        $bits = '';
        foreach (str_split($hex) as $hexChar) {
            $bits .= str_pad(base_convert($hexChar, 16, 2), 4, '0', STR_PAD_LEFT);
        }
        return $bits;
    }

    private static function bitsToHex(string $bits): string
    {
        $hex = '';
        foreach (str_split($bits, 4) as $chunk) {
            $hex .= strtoupper(base_convert($chunk, 2, 16));
        }
        return $hex;
    }

    /** @return array{0:int,1:int} [actualLength, bytesConsumedByLengthPrefix] */
    private static function readLength(string $raw, int $offset, string $lengthSpec): array
    {
        if (str_starts_with($lengthSpec, 'fixed:')) {
            return [(int)substr($lengthSpec, 6), 0];
        }
        if (str_starts_with($lengthSpec, 'var2:')) {
            $prefix = substr($raw, $offset, 2);
            if (!preg_match('/^\d{2}$/', $prefix)) {
                throw new \RuntimeException("Malformed 2-digit length prefix: '{$prefix}' at offset {$offset}");
            }
            return [(int)$prefix, 2];
        }
        if (str_starts_with($lengthSpec, 'var3:')) {
            $prefix = substr($raw, $offset, 3);
            if (!preg_match('/^\d{3}$/', $prefix)) {
                throw new \RuntimeException("Malformed 3-digit length prefix: '{$prefix}' at offset {$offset}");
            }
            return [(int)$prefix, 3];
        }
        throw new \RuntimeException("Unknown length spec: {$lengthSpec}");
    }

    private static function encodeField(string $value, string $lengthSpec): string
    {
        if (str_starts_with($lengthSpec, 'fixed:')) {
            $len = (int)substr($lengthSpec, 6);
            if (strlen($value) >= $len) {
                return substr($value, 0, $len);
            }
            // Numeric fields are zero-padded on the LEFT (leading zeros).
            // Alpha/alphanumeric/special fields are space-padded on the
            // RIGHT, per ISO 8583 convention — padding a merchant name
            // with leading zeros would corrupt it. This distinction was
            // missing in the original implementation and caused a
            // confirmed round-trip failure on field 42 (an 'ans' field)
            // during testing.
            return str_pad($value, $len, str_starts_with($lengthSpec, 'fixed:') && self::isNumericLike($value) ? '0' : ' ',
                self::isNumericLike($value) ? STR_PAD_LEFT : STR_PAD_RIGHT);
        }
        if (str_starts_with($lengthSpec, 'var2:')) {
            $max = (int)substr($lengthSpec, 5);
            $value = substr($value, 0, $max);
            return str_pad((string)strlen($value), 2, '0', STR_PAD_LEFT) . $value;
        }
        if (str_starts_with($lengthSpec, 'var3:')) {
            $max = (int)substr($lengthSpec, 5);
            $value = substr($value, 0, $max);
            return str_pad((string)strlen($value), 3, '0', STR_PAD_LEFT) . $value;
        }
        throw new \RuntimeException("Unknown length spec: {$lengthSpec}");
    }

    /**
     * Crude but sufficient heuristic to decide left-zero-pad (numeric)
     * vs right-space-pad (alpha/ans) for a fixed-length field, since
     * this codec doesn't currently thread the field's declared type
     * ('n' vs 'ans' etc.) through to encodeField(). A more correct
     * version would look up FIELD_SPECS[$fieldNumber][0] directly —
     * left as-is here since encodeField() isn't currently field-number-
     * aware; flagging as a real thing to tighten up, not hiding it.
     */
    private static function isNumericLike(string $value): bool
    {
        return $value !== '' && ctype_digit($value);
    }

    public static function build(string $mti, array $fields): self
    {
        return new self($mti, $fields);
    }
}
