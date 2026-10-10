<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use MageOS\Seo\Model\PageCache\Purger;
use MageOS\Seo\Model\PageCache\SeoCacheTags;
use MageOS\Seo\Observer\PurgeCategoryPages;
use PHPUnit\Framework\TestCase;

/**
 * A moved or deleted category purges the pages that inherit from it.
 */
class PurgeCategoryPagesTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheCategorysPagesArePurged(): void
    {
        $category = $this->createStub(CategoryInterface::class);
        $category->method('getId')->willReturn('14');
        $purger = $this->createMock(Purger::class);
        $purger->expects($this->once())->method('purge')->with(['mageos_seo_cat_14']);

        (new PurgeCategoryPages(new SeoCacheTags(), $purger))->execute($this->observer($category));
    }

    /**
     * An event without a saved category purges nothing.
     *
     * @return void
     */
    public function testNoCategoryNoPurge(): void
    {
        $unsaved = $this->createStub(CategoryInterface::class);
        $unsaved->method('getId')->willReturn(null);
        $purger = $this->createMock(Purger::class);
        $purger->expects($this->never())->method('purge');
        $observer = new PurgeCategoryPages(new SeoCacheTags(), $purger);

        $observer->execute($this->observer(null));
        $observer->execute($this->observer(new DataObject(['id' => 14])));
        $observer->execute($this->observer($unsaved));
    }

    /**
     * An observer for an event carrying the given category.
     *
     * @param mixed $category
     * @return Observer
     */
    private function observer(mixed $category): Observer
    {
        return new Observer(['event' => new Event(['category' => $category])]);
    }
}
