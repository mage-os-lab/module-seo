<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The store views hreflang's x-default can point to: none, or an active one.
 *
 * "-- None --" comes first, so x-default can be left unset or cleared (issue #35), and so a stored
 * store view that is no longer listed — turned off since — shows as none, which is what the
 * storefront does with it: x-default links only to a store view in the alternate set.
 */
class XDefaultStoreViews implements OptionSourceInterface
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * "-- None --", then the active store views as id => label options.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('-- None --')]];
        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->getIsActive()) {
                continue;
            }
            $options[] = [
                'value' => (int) $store->getId(),
                'label' => \sprintf('%s (%s)', $store->getName(), $store->getCode()),
            ];
        }

        return $options;
    }
}
