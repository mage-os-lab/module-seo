<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Hreflang;

/**
 * Normalises and structurally validates hreflang codes.
 *
 * Google accepts an ISO 639-1 language, optionally an ISO 15924 script, optionally an ISO 3166-1
 * alpha-2 region: `en`, `en-GB`, `zh-Hant`, `zh-Hant-TW`. One malformed code is not ignored on its
 * own — a cluster whose annotations do not validate can be discarded as a whole — so codes are
 * checked when they are entered rather than trusted when they are rendered.
 *
 * This class checks shape only. Whether a region actually exists (GB does, UK does not) needs the
 * country directory and is checked by Model\Config\Backend\HreflangCodes, which has it.
 *
 * x-default is not accepted here: it is not a code a store view can claim, and is configured on its
 * own.
 */
class CodeValidator
{
    /**
     * language, optional script, optional region.
     */
    private const PATTERN = '/^[a-z]{2}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2})?$/';

    /**
     * Bring a code to its canonical case and separator: `en_gb` becomes `en-GB`.
     *
     * Magento locales use underscores and people type codes in any case; the canonical form is what
     * gets stored and compared, so `en-gb` and `en-GB` are recognised as the same code.
     *
     * @param string $code
     * @return string
     */
    public function normalise(string $code): string
    {
        $parts = explode('-', str_replace('_', '-', trim($code)));

        foreach ($parts as $index => $part) {
            $parts[$index] = match (true) {
                $index === 0        => strtolower($part),
                \strlen($part) === 4 => ucfirst(strtolower($part)),
                default             => strtoupper($part),
            };
        }

        return implode('-', $parts);
    }

    /**
     * Whether a normalised code has the shape Google accepts.
     *
     * @param string $code
     * @return bool
     */
    public function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }

    /**
     * The region subtag of a normalised code, or null when it has none.
     *
     * @param string $code
     * @return string|null
     */
    public function region(string $code): ?string
    {
        $parts = explode('-', $code);
        $last  = end($parts);

        return \count($parts) > 1 && \strlen((string) $last) === 2 ? (string) $last : null;
    }
}
