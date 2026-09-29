<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product;

/**
 * Validates GTIN values (GS1 check digit) and resolves the matching schema.org property.
 *
 * Merchants store free-form values in barcode/EAN attributes; emitting an internal SKU
 * or a value with a wrong check digit as gtin13 produces per-product Search Console
 * errors, so anything that does not validate is omitted entirely.
 */
class GtinValidator
{
    private const PROPERTY_BY_LENGTH = [
        8  => 'gtin8',
        12 => 'gtin12',
        13 => 'gtin13',
        14 => 'gtin14',
    ];

    /**
     * Return the schema.org property for a raw GTIN value, ready to merge into a node.
     *
     * `['gtin13' => '5901234123457']` for a valid value — the property matching its length, the
     * bare digits as the value — or `[]` when it does not validate, so it is left out.
     *
     * @param string $value
     * @return array<string, string>
     */
    public function toProperties(string $value): array
    {
        $property = $this->resolveProperty($value);

        return $property === null ? [] : [$property => (string) $this->normalize($value)];
    }

    /**
     * Return the schema.org property name for a raw GTIN value, or null when invalid.
     *
     * @param string $value
     * @return string|null gtin8 | gtin12 | gtin13 | gtin14, or null
     */
    public function resolveProperty(string $value): ?string
    {
        $digits = $this->normalize($value);
        if ($digits === null) {
            return null;
        }

        $property = self::PROPERTY_BY_LENGTH[\strlen($digits)] ?? null;
        if ($property === null || !$this->isChecksumValid($digits)) {
            return null;
        }

        return $property;
    }

    /**
     * Return the bare digit string for a raw GTIN value, or null when it is not a GTIN.
     *
     * @param string $value
     * @return string|null
     */
    public function normalize(string $value): ?string
    {
        // Allow common formatting (spaces, hyphens); anything else disqualifies.
        $digits = str_replace([' ', '-'], '', trim($value));
        if ($digits === '' || !ctype_digit($digits)) {
            return null;
        }

        return $digits;
    }

    /**
     * Validate the GS1 check digit (last digit; weights alternate 3,1 from the right).
     *
     * @param string $digits
     * @return bool
     */
    private function isChecksumValid(string $digits): bool
    {
        $check  = (int) substr($digits, -1);
        $body   = substr($digits, 0, -1);
        $sum    = 0;
        $weight = 3;

        for ($i = \strlen($body) - 1; $i >= 0; $i--) {
            $sum   += ((int) $body[$i]) * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10 === $check;
    }
}
