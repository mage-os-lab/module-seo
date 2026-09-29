<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Cache;

/**
 * Cache cleaning-mode identifiers, as the cache backends receive them.
 *
 * The identifier is a plain string, and the same string on every supported version: it began as
 * \Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG and Magento\Framework\Cache\CacheConstants restates
 * it on 2.4.9+. Naming either class here would tie the module to one end of the supported range —
 * CacheConstants does not exist at the 2.4.7 floor — so the value is written out, in one place,
 * with this note about where it comes from.
 */
class CleaningMode
{
    private const MATCHING_ANY_TAG = 'matchingAnyTag';

    /**
     * Cleaning mode that removes entries carrying any of the given tags.
     *
     * @return string
     */
    public function matchingAnyTag(): string
    {
        return self::MATCHING_ANY_TAG;
    }
}
