<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Faq\Source;

use MageOS\Seo\Api\FaqSourceProviderInterface;
use MageOS\Seo\Model\Faq\Repository;

/**
 * FAQ source backed by the module's own mageos_faq table.
 */
class TableFaqSource implements FaqSourceProviderInterface
{
    /**
     * @param Repository $repository
     */
    public function __construct(
        private readonly Repository $repository
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getFaqs(string $identifier, int $storeId): array
    {
        return $this->repository->getByIdentifier($identifier, $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getIdentifiers(): array
    {
        return $this->repository->getIdentifiers();
    }
}
