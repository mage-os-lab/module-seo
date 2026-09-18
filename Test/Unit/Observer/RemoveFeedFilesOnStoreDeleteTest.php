<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\Store;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Observer\RemoveFeedFilesOnStoreDelete;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RemoveFeedFilesOnStoreDeleteTest extends TestCase
{
    public function testTheDeletedStoresDirectoryIsRemoved(): void
    {
        $storage = $this->createMock(FeedStorage::class);
        $storage->expects($this->once())->method('deleteStoreDirectory')->with(4);

        $this->observer($storage)->execute($this->event($this->store(4)));
    }

    public function testAnEventWithoutAStoreIsIgnored(): void
    {
        $storage = $this->createMock(FeedStorage::class);
        $storage->expects($this->never())->method('deleteStoreDirectory');

        $this->observer($storage)->execute($this->event(null));
        $this->observer($storage)->execute($this->event($this->store(null)));
    }

    public function testAFailureIsLoggedRatherThanThrown(): void
    {
        $storage = $this->createStub(FeedStorage::class);
        $storage->method('deleteStoreDirectory')->willThrowException(new \RuntimeException('read-only mount'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with($this->stringContains('deleted store 4: read-only mount'));

        // The store view is already gone: throwing here would report a completed delete as failed.
        $this->observer($storage, $logger)->execute($this->event($this->store(4)));
    }

    /**
     * The observer over the given collaborators.
     *
     * @param FeedStorage $storage
     * @param LoggerInterface|null $logger
     * @return RemoveFeedFilesOnStoreDelete
     */
    private function observer(FeedStorage $storage, ?LoggerInterface $logger = null): RemoveFeedFilesOnStoreDelete
    {
        return new RemoveFeedFilesOnStoreDelete($storage, $logger ?? $this->createStub(LoggerInterface::class));
    }

    /**
     * The store_delete event as Store::afterDelete() dispatches it.
     *
     * @param Store|null $store
     * @return Observer
     */
    private function event(?Store $store): Observer
    {
        return new Observer(['event' => new Event(['name' => 'store_delete', 'store' => $store])]);
    }

    /**
     * A store model carrying an ID, without its constructor dependencies.
     *
     * @param int|null $storeId
     * @return Store
     */
    private function store(?int $storeId): Store
    {
        /** @var Store $store */
        $store = (new \ReflectionClass(Store::class))->newInstanceWithoutConstructor();
        // Store::getId() reads the store_id field, not the generic ID field.
        $store->setData(Store::STORE_ID, $storeId);

        return $store;
    }
}
