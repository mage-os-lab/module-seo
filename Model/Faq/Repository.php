<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Faq;

use Magento\Framework\Data\Collection as DataCollection;
use MageOS\Seo\Model\Faq as FaqModel;
use MageOS\Seo\Model\ResourceModel\Faq\CollectionFactory;

/**
 * Read access to FAQ entries by group identifier.
 *
 * Returns global (store 0) and store-specific rows for the identifier, ordered by sort order.
 */
class Repository
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * Return active FAQ entries for a group identifier and store, ordered by sort order.
     *
     * @param string $identifier
     * @param int $storeId
     * @return array<int, array{question: string, answer: string}>
     */
    public function getByIdentifier(string $identifier, int $storeId): array
    {
        if ($identifier === '') {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('identifier', ['eq' => $identifier]);
        $collection->addFieldToFilter('store_id', ['in' => [0, $storeId]]);
        $collection->addFieldToFilter('is_active', ['eq' => 1]);
        $collection->setOrder('sort_order', DataCollection::SORT_ORDER_ASC);
        // Entries sharing a sort_order would otherwise arrive in whatever order the storage engine
        // chose, and this list is rendered into FAQPage JSON-LD that the page cache then keeps.
        $collection->setOrder('entity_id', DataCollection::SORT_ORDER_ASC);

        $faqs = [];

        /** @var FaqModel $faq */
        foreach ($collection as $faq) {
            $faqs[] = [
                'question' => $faq->getQuestion(),
                'answer'   => $faq->getAnswer(),
            ];
        }

        return $faqs;
    }
}
