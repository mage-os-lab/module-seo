<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\Seo\Model\Category\Inheritance\OrderPool;

/**
 * The category inheritance strategies this installation has, as admin options.
 *
 * Read from the pool rather than listed here, so a third party that registers a strategy in
 * di.xml gets it in the dropdown without touching this class or the system.xml field.
 */
class InheritanceStrategy implements OptionSourceInterface
{
    /**
     * @param OrderPool $orderPool
     */
    public function __construct(
        private readonly OrderPool $orderPool
    ) {
    }

    /**
     * @inheritdoc
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];

        foreach ($this->orderPool->getAll() as $code => $strategy) {
            $options[] = ['value' => $code, 'label' => (string) $strategy->getLabel()];
        }

        return $options;
    }
}
