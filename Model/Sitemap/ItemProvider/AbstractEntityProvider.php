<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\ItemProvider;

use Magento\Framework\DataObject;
use Magento\Sitemap\Model\ItemProvider\ConfigReaderInterface;
use MageOS\Seo\Api\Sitemap\ItemProviderInterface;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Sitemap\SitemapItemFactory;

/**
 * What core's category, CMS page, product and store URL providers do, keeping the entity.
 *
 * Each of core's providers reads its sitemap resource model and maps every row to a sitemap item
 * with the priority and change frequency its config reader gives. The resource model's rows carry
 * the entity ID; core's item has nowhere to keep it. These providers read the same resource models
 * through the same config readers — so a merchant's sitemap settings apply unchanged — and keep it.
 */
abstract class AbstractEntityProvider implements ItemProviderInterface
{
    /**
     * @param ConfigReaderInterface $configReader
     * @param SitemapItemFactory $itemFactory
     */
    public function __construct(
        private readonly ConfigReaderInterface $configReader,
        private readonly SitemapItemFactory    $itemFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getItems($storeId)
    {
        return iterator_to_array($this->toItems($this->rows((int) $storeId, false), (int) $storeId), false);
    }

    /**
     * @inheritdoc
     */
    public function iterateItems(int $storeId): iterable
    {
        yield from $this->toItems($this->rows($storeId, true), $storeId);
    }

    /**
     * The resource model's rows for the store view.
     *
     * @param int $storeId
     * @param bool $stream Whether the caller walks the rows one at a time, so they need not be
     *                     loaded at once
     * @return iterable<DataObject>|false False when the store view does not exist, as core's
     *                                    resource models answer
     */
    abstract protected function rows(int $storeId, bool $stream): iterable|false;

    /**
     * What one of core's sitemap resource models returned, as the rows it means.
     *
     * They document `array|bool`: the rows, or false when the store view — or its root category —
     * does not exist. They never return true or anything else; if one does, core has changed and
     * that is worth hearing about, not reading as an empty list.
     *
     * @param mixed $rows
     * @return iterable<DataObject>|false
     * @throws \UnexpectedValueException
     */
    protected function coreRows(mixed $rows): iterable|false
    {
        if ($rows === false || is_iterable($rows)) {
            return $rows;
        }

        throw new \UnexpectedValueException(sprintf(
            '%s: a core sitemap resource model returned %s, where rows or false were expected.',
            static::class,
            get_debug_type($rows)
        ));
    }

    /**
     * The entity type of every item this provider lists.
     *
     * @return string One of SitemapItemInterface::ENTITY_*
     */
    abstract protected function entityType(): string;

    /**
     * Map resource rows to sitemap items, as core's providers do.
     *
     * @param iterable<DataObject>|false $rows
     * @param int $storeId
     * @return \Generator<SitemapItemInterface>
     */
    private function toItems(iterable|false $rows, int $storeId): \Generator
    {
        if ($rows === false) {
            return;
        }

        $priority        = $this->configReader->getPriority($storeId);
        $changeFrequency = $this->configReader->getChangeFrequency($storeId);
        $entityType      = $this->entityType();

        foreach ($rows as $row) {
            // The keys core's providers read through the magic getters (getUrl(), getId(), …).
            $entityId = $row->getData('id');

            yield $this->itemFactory->create([
                'url'             => $row->getData('url'),
                'priority'        => $priority,
                'changeFrequency' => $changeFrequency,
                'updatedAt'       => $row->getData('updated_at'),
                'images'          => $row->getData('images'),
                'entityType'      => $entityType,
                'entityId'        => $entityId === null ? null : (int) $entityId,
            ]);
        }
    }
}
