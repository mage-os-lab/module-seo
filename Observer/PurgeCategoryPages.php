<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\Seo\Model\PageCache\Purger;
use MageOS\Seo\Model\PageCache\SeoCacheTags;

/**
 * Purges the cached pages beneath a category that was moved or deleted.
 *
 * Every page beneath a category carries its SEO tag — the categories under it, and the products
 * whose schema reads through it — because they inherit its settings. A move changes what they
 * inherit without any SEO row being written; a delete drops the category's rows by foreign key,
 * past the resource model that would purge them.
 *
 * Registered globally on category_move and catalog_category_delete_commit_after, both dispatched
 * after the change is committed, for the admin and the REST API alike.
 */
class PurgeCategoryPages implements ObserverInterface
{
    /**
     * @param SeoCacheTags $cacheTags
     * @param Purger $purger
     */
    public function __construct(
        private readonly SeoCacheTags $cacheTags,
        private readonly Purger       $purger
    ) {
    }

    /**
     * Purge the pages that read the category's SEO settings.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $category = $observer->getEvent()->getData('category');
        if (!$category instanceof CategoryInterface || (int) $category->getId() === 0) {
            return;
        }

        $this->purger->purge([$this->cacheTags->category((int) $category->getId())]);
    }
}
