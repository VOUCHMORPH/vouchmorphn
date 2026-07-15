<?php
declare(strict_types=1);

namespace Infrastructure\QRcodes;

use Infrastructure\QRcodes\Contracts\QrAdapterInterface;
use Infrastructure\QRcodes\Contracts\QrPayload;
use RuntimeException;

/**
 * EMVCo Merchant-Presented QR Code Specification codec.
 *
 * Format: sequence of TLV (Tag-Length-Value) fields, each tag 2 digits,
 * length 2 digits, then that many chars of value. Nested TLVs for
 * merchant account info (tags 26-51) and additional data (tag 62).
 *
 * Reference tags used here (subset - extend as needed):
 *   00 Payload Format Indicator
 *   01 Point of Initiation Method (11=static, 12=dynamic)
 *   26-51 Merchant Account Information (institution-specific sub-TLVs)
 *   52 Merchant Category Code
 *   53 Transaction Currency (ISO 4217 numeric)
 *   54 Transaction Amount
 *   58 Country Code
 *   59 Merchant Name
 *   60 Merchant City
 *   62 Additional Data Field (bill number, reference, etc.)
 *   63 CRC
 */
class EmvQrAdapter implements QrAdapterInterface
{
    private const TAG_PAYLOAD_FORMAT = '00';
    private const TAG_POI_METHOD = '01';
    private const TAG_CURRENCY = '53';
    private const TAG_AMOUNT = '54';
    private const TAG_MERCHANT_NAME = '59';
    private const TAG_ADDITIONAL_DATA = '62';
    private const TAG_CRC = '63';

    // Currency numeric codes we care about - extend per country config
    private const CURRENCY_NUMERIC_MAP = [
        'BWP' => '072',
        'ZAR' => '710',
        'USD' => '840',
        'EUR' => '978',
    ];

    public function __construct(private array $institutionTagMap = [])
    {
        // institutionTagMap: which merchant-account tag (26-51) belongs to
        // which institution, since EMVCo reserves a range for this and
        // each acquirer/bank picks their own tag in that range.
        // e.g. ['26' => 'ZURUBANK', '27' => 'CAZACOM']
    }

    public function matches(string $rawQrString): bool
    {
        $fields = $this->tryParseTlv($rawQrString);
        return $fields !== null && isset($fields[self::TAG_PAYLOAD_FORMAT]);
    }

    public function decode(string $rawQrString): QrPayload
    {
        $fields = $this->tryParseTlv($rawQrString);

        if ($fields === null) {
            throw new RuntimeException('Not a valid EMVCo QR payload');
        }

        if (!$this->verifyCrc($rawQrString)) {
            throw new RuntimeException('EMVCo QR CRC checksum mismatch - payload may be corrupted or tampered');
        }

        $poiMethod = $fields[self::TAG_POI_METHOD] ?? '11';
        $qrType = $poiMethod === '12' ? 'DYNAMIC' : 'STATIC';

        [$institution, $merchantId] = $this->resolveMerchantAccount($fields);

        $currencyNumeric = $fields[self::TAG_CURRENCY] ?? null;
        $currency = array_search($currencyNumeric, self::CURRENCY_NUMERIC_MAP, true) ?: 'BWP';

        $amount = isset($fields[self::TAG_AMOUNT]) ? (float)$fields[self::TAG_AMOUNT] : null;

        $reference = $this->extractAdditionalDataField($fields[self::TAG_ADDITIONAL_DATA] ?? null, '01');

        return new QrPayload(
            qrType: $qrType,
            merchantOrPayeeId: $merchantId,
            amount: $amount,
            currency: $currency,
            reference: $reference,
            institution: $institution,
            raw: $fields
        );
    }

    public function encode(QrPayload $payload): string
{
    $tags = [];
    $tags[self::TAG_PAYLOAD_FORMAT] = '01';
    $tags[self::TAG_POI_METHOD] = $payload->qrType === 'DYNAMIC' ? '12' : '11';

    $merchantTag = $this->findTagForInstitution($payload->institution);
    $tags[(string)$merchantTag] = $this->buildMerchantAccountSubTlv($payload->merchantOrPayeeId);

    $tags[self::TAG_CURRENCY] = self::CURRENCY_NUMERIC_MAP[$payload->currency] ?? '072';

    if ($payload->amount !== null) {
        $tags[self::TAG_AMOUNT] = number_format($payload->amount, 2, '.', '');
    }

    if ($payload->reference) {
        $tags[self::TAG_ADDITIONAL_DATA] = $this->buildTlv('01', $payload->reference);
    }

    $body = '';
    foreach ($tags as $tag => $value) {
        // ✅ FIX: Cast to string at the call site
        $body .= $this->buildTlv((string)$tag, $value);
    }

    $withCrcTag = $body . self::TAG_CRC . '04';
    $crc = $this->crc16($withCrcTag);

    return $withCrcTag . $crc;
}

    public function getSpecName(): string
    {
        return 'EMVCO';
    }

    // ============================================================
    // TLV parsing helpers
    // ============================================================

    private function tryParseTlv(string $raw): ?array
    {
        $fields = [];
        $pos = 0;
        $len = strlen($raw);

        while ($pos < $len) {
            if ($pos + 4 > $len) {
                return null; // malformed
            }
            $tag = substr($raw, $pos, 2);
            $valueLen = (int)substr($raw, $pos + 2, 2);
            $value = substr($raw, $pos + 4, $valueLen);

            if (strlen($value) !== $valueLen) {
                return null; // malformed
            }

            $fields[$tag] = $value;
            $pos += 4 + $valueLen;
        }

        return $fields ?: null;
    }

    private function buildTlv(string $tag, string $value): string
    {
        $length = str_pad((string)strlen($value), 2, '0', STR_PAD_LEFT);
        return $tag . $length . $value;
    }

    private function buildMerchantAccountSubTlv(string $merchantId): string
    {
        // Sub-TLV within the merchant account tag: globally unique identifier + merchant id
        return $this->buildTlv('00', 'vouchmorph.merchant') . $this->buildTlv('01', $merchantId);
    }

    private function resolveMerchantAccount(array $fields): array
    {
        foreach ($this->institutionTagMap as $tag => $institution) {
            if (isset($fields[$tag])) {
                $subFields = $this->tryParseTlv($fields[$tag]) ?? [];
                $merchantId = $subFields['01'] ?? '';
                return [$institution, $merchantId];
            }
        }
        throw new RuntimeException('Could not resolve merchant institution from EMVCo QR - check institutionTagMap config');
    }

    private function findTagForInstitution(string $institution): string
    {
        $tag = array_search($institution, $this->institutionTagMap, true);
        if ($tag === false) {
            throw new RuntimeException("No EMVCo merchant tag configured for institution: {$institution}");
        }
        return (string)$tag;
    }

    private function extractAdditionalDataField(?string $additionalDataTlv, string $subTag): ?string
    {
        if ($additionalDataTlv === null) {
            return null;
        }
        $subFields = $this->tryParseTlv($additionalDataTlv) ?? [];
        return $subFields[$subTag] ?? null;
    }

    private function verifyCrc(string $raw): bool
    {
        if (!str_ends_with(strtoupper($raw), $this->extractCrcValue($raw) ?? '____')) {
            // fall through to explicit check below
        }
        $crcValue = $this->extractCrcValue($raw);
        if ($crcValue === null) {
            return false;
        }
        $bodyWithoutCrcValue = substr($raw, 0, -4); // strip the 4-char CRC value, keep tag+len
        $calculated = $this->crc16($bodyWithoutCrcValue);
        return strtoupper($calculated) === strtoupper($crcValue);
    }

    private function extractCrcValue(string $raw): ?string
    {
        $pos = strpos($raw, self::TAG_CRC . '04');
        if ($pos === false || $pos + 8 > strlen($raw)) {
            return null;
        }
        return substr($raw, $pos + 4, 4);
    }

    private function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc ^= (ord($data[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }
        return str_pad(strtoupper(dechex($crc)), 4, '0', STR_PAD_LEFT);
    }
}
