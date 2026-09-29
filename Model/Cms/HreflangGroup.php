<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Cms;

/**
 * The canonical form of a CMS translation group.
 *
 * Pages sharing a group are translations of one another. The group is matched in SQL, where the
 * default collations fold case and accents ("About" = "about", "café" = "cafe"), and grouped in
 * PHP, which compares exact strings; the two only agree when groups are already in one form. So a
 * group is stored lower-case and restricted to ASCII letters, digits, dots, hyphens and
 * underscores — the shape of an identifier, which is what it is.
 */
class HreflangGroup
{
    public const MAX_LENGTH = 255;

    private const PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

    /**
     * Trim and lower-case an entered group; null when nothing was entered.
     *
     * @param string|null $group
     * @return string|null
     */
    public function normalise(?string $group): ?string
    {
        $group = strtolower(trim((string) $group));

        return $group === '' ? null : $group;
    }

    /**
     * Whether a normalised group can be stored as it is.
     *
     * @param string $group
     * @return bool
     */
    public function isValid(string $group): bool
    {
        return \strlen($group) <= self::MAX_LENGTH && preg_match(self::PATTERN, $group) === 1;
    }

    /**
     * Turn any value into a valid group, for importing values that were never validated.
     *
     * Runs of other characters become one hyphen: "About Us" becomes "about-us". Null when nothing
     * usable is left.
     *
     * @param string|null $value
     * @return string|null
     */
    public function slugify(?string $value): ?string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9._-]+/', '-', (string) $this->normalise($value)), '-._');
        $slug = substr($slug, 0, self::MAX_LENGTH);

        return $slug === '' ? null : $slug;
    }
}
