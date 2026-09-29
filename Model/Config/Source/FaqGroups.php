<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\Seo\Model\Faq\SourcePool;

/**
 * The FAQ groups the llms documents can include: every group identifier any FAQ source has — this
 * module's FAQ table and any other registered source — plus `global`.
 *
 * `global` is offered even before any FAQ uses it, because it is the setting's default: a
 * multi-select drops a stored value its options do not list, so without it a store with no
 * `global` FAQs yet would lose the default on its next configuration save.
 */
class FaqGroups implements OptionSourceInterface
{
    public const DEFAULT_GROUP = 'global';

    /**
     * @param SourcePool $sourcePool
     */
    public function __construct(
        private readonly SourcePool $sourcePool
    ) {
    }

    /**
     * The group identifiers, sorted, each its own label.
     *
     * @return array<int,array{value:string,label:string}>
     */
    public function toOptionArray(): array
    {
        $groups = array_unique([self::DEFAULT_GROUP, ...$this->sourcePool->getIdentifiers()]);
        sort($groups, SORT_STRING);

        return array_map(
            static fn (string $group): array => ['value' => $group, 'label' => $group],
            $groups
        );
    }
}
