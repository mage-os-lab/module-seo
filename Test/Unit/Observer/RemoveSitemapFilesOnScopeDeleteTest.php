<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use MageOS\Seo\Model\Sitemap\FileRemover;
use MageOS\Seo\Observer\RemoveSitemapFilesOnScopeDelete;
use PHPUnit\Framework\TestCase;

/**
 * The store views whose sitemap files go, for each kind of scope deleted.
 */
class RemoveSitemapFilesOnScopeDeleteTest extends TestCase
{
    /**
     * @return void
     */
    public function testAWebsiteOrGroupTakesItsStoreViewsAndAStoreViewItself(): void
    {
        $website = $this->createStub(Website::class);
        $website->method('getStoreIds')->willReturn(['3' => '3', '5' => '5']);
        $group = $this->createStub(Group::class);
        $group->method('getStoreIds')->willReturn([7]);
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn('9');
        $newStore = $this->createStub(Store::class);
        $newStore->method('getId')->willReturn(null);

        $this->assertSame([3, 5], $this->storeIdsRemovedFor($website));
        $this->assertSame([7], $this->storeIdsRemovedFor($group));
        $this->assertSame([9], $this->storeIdsRemovedFor($store));
        $this->assertSame([], $this->storeIdsRemovedFor($newStore), 'A store view never saved has no entries.');
        $this->assertSame([], $this->storeIdsRemovedFor(new \stdClass()), 'Anything else is not a scope.');
    }

    /**
     * A website or group reporting no store views removes nothing.
     *
     * @return void
     */
    public function testAScopeWithNoStoreViewsRemovesNothing(): void
    {
        $website = $this->createStub(Website::class);
        $website->method('getStoreIds')->willReturn(null);

        $this->assertSame([], $this->storeIdsRemovedFor($website));
    }

    /**
     * The store IDs the observer hands the remover when the entity's deletion is observed.
     *
     * @param object $entity
     * @return int[]
     */
    private function storeIdsRemovedFor(object $entity): array
    {
        $removed = null;
        $remover = $this->createMock(FileRemover::class);
        $remover->expects($this->once())->method('removeFilesOfStores')->willReturnCallback(
            static function (array $storeIds) use (&$removed): void {
                $removed = $storeIds;
            }
        );

        (new RemoveSitemapFilesOnScopeDelete($remover))->execute(
            new Observer(['event' => new Event(['data_object' => $entity])])
        );
        $this->assertIsArray($removed, 'The remover was not asked.');

        return $removed;
    }
}
