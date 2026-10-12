<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Every store view, active or not, for hreflang's Exclude Store Views.
 *
 * An inactive store view stays listed, so its exclusion survives its being turned off: a
 * multiselect drops a stored value it has no option for when the page is saved. No blank: it would
 * add an empty ID to the list. x-default has its own list (XDefaultStoreViews).
 */
class StoreViews implements OptionSourceInterface
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Every store view as id => label options.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->storeManager->getStores() as $store) {
            $options[] = [
                'value' => (int) $store->getId(),
                'label' => \sprintf('%s (%s)', $store->getName(), $store->getCode()),
            ];
        }

        return $options;
    }
}
