<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Faq\Source;

use MageOS\Seo\Api\FaqSourceProviderInterface;
use MageOS\Seo\Model\Faq\GroupReader;

/**
 * FAQ source backed by the module's own mageos_faq table.
 */
class TableFaqSource implements FaqSourceProviderInterface
{
    /**
     * @param GroupReader $groupReader
     */
    public function __construct(
        private readonly GroupReader $groupReader
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getFaqs(string $identifier, int $storeId): array
    {
        return $this->groupReader->getByIdentifier($identifier, $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getIdentifiers(): array
    {
        return $this->groupReader->getIdentifiers();
    }
}
