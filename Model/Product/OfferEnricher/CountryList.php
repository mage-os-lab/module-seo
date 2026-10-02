<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\OfferEnricher;

/**
 * The country codes a country multiselect stored.
 *
 * Magento stores a multiselect as its values joined with commas, in the order the list shows them.
 * The return policy and shipping details read their countries through this one place.
 */
class CountryList
{
    /**
     * The stored codes in order, without blanks, surrounding spaces or repeats.
     *
     * @param string $stored
     * @return string[]
     */
    public function fromConfig(string $stored): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $stored)),
            static fn (string $code): bool => $code !== ''
        )));
    }
}
