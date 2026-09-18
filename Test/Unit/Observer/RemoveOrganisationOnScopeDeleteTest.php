<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\Group;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Observer\RemoveOrganisationOnScopeDelete;
use PHPUnit\Framework\TestCase;

class RemoveOrganisationOnScopeDeleteTest extends TestCase
{
    /**
     * Every deleteForScope() call the observer made, as [scope, scopeIds].
     *
     * @var array<int, array{0: string, 1: int[]}>
     */
    private array $deleted = [];

    protected function setUp(): void
    {
        $this->deleted = [];
    }

    public function testDeletingAWebsiteRemovesItsOwnRecordAndThoseOfItsStoreViews(): void
    {
        // The store views go with the website through a database cascade that fires no event,
        // so they have to be resolved here, while the website still knows them.
        $this->observer()->execute($this->event($this->website(7, [3, 4])));

        $this->assertSame(
            [
                [ScopeInterface::SCOPE_WEBSITES, [7]],
                [ScopeInterface::SCOPE_STORES, [3, 4]],
            ],
            $this->deleted
        );
    }

    public function testDeletingAStoreGroupRemovesTheRecordsOfItsStoreViews(): void
    {
        $this->observer()->execute($this->event($this->group([5])));

        $this->assertSame([[ScopeInterface::SCOPE_STORES, [5]]], $this->deleted);
    }

    public function testDeletingAStoreViewRemovesItsRecord(): void
    {
        $this->observer()->execute($this->event($this->store(9)));

        $this->assertSame([[ScopeInterface::SCOPE_STORES, [9]]], $this->deleted);
    }

    public function testAWebsiteWithoutStoreViewsOnlyRemovesItsOwnRecord(): void
    {
        $this->observer()->execute($this->event($this->website(7, [])));

        $this->assertSame(
            [
                [ScopeInterface::SCOPE_WEBSITES, [7]],
                [ScopeInterface::SCOPE_STORES, []],
            ],
            $this->deleted
        );
    }

    public function testAnUnsavedStoreViewOrAnUnknownEntityIsIgnored(): void
    {
        $this->observer()->execute($this->event($this->store(null)));
        $this->observer()->execute($this->event(new DataObject(['id' => 1])));
        $this->observer()->execute($this->event(null));

        $this->assertSame([], $this->deleted);
    }

    /**
     * The observer over a repository that records what it was asked to delete.
     *
     * @return RemoveOrganisationOnScopeDelete
     */
    private function observer(): RemoveOrganisationOnScopeDelete
    {
        $repository = $this->createStub(OrganisationRepositoryInterface::class);
        $repository->method('deleteForScope')->willReturnCallback(
            function (string $scope, array $scopeIds): int {
                $this->deleted[] = [$scope, $scopeIds];
                return \count($scopeIds);
            }
        );

        return new RemoveOrganisationOnScopeDelete($repository);
    }

    /**
     * A *_delete_before event as AbstractModel::beforeDelete() dispatches it.
     *
     * @param object|null $entity
     * @return Observer
     */
    private function event(?object $entity): Observer
    {
        return new Observer(['event' => new Event(['name' => 'scope_delete_before', 'data_object' => $entity])]);
    }

    /**
     * @param int $websiteId
     * @param int[] $storeIds
     * @return Website
     */
    private function website(int $websiteId, array $storeIds): Website
    {
        /** @var Website $website */
        $website = (new \ReflectionClass(Website::class))->newInstanceWithoutConstructor();
        // Without its constructor the model's ID field is the generic one.
        $website->setData('id', $websiteId);
        $this->presetStores($website, $storeIds);

        return $website;
    }

    /**
     * @param int[] $storeIds
     * @return Group
     */
    private function group(array $storeIds): Group
    {
        /** @var Group $group */
        $group = (new \ReflectionClass(Group::class))->newInstanceWithoutConstructor();
        $this->presetStores($group, $storeIds);

        return $group;
    }

    /**
     * Fill in what getStoreIds() reads, so it does not try to load the store collection.
     *
     * Website and Group both return the protected $_storeIds, loading the collection when
     * $_stores is still null — which a model built without its constructor cannot do.
     *
     * @param Website|Group $model
     * @param int[] $storeIds
     * @return void
     */
    private function presetStores(Website|Group $model, array $storeIds): void
    {
        $reflection = new \ReflectionClass($model);
        $reflection->getProperty('_stores')->setValue($model, []);
        $reflection->getProperty('_storeIds')->setValue($model, $storeIds);
    }

    /**
     * @param int|null $storeId
     * @return Store
     */
    private function store(?int $storeId): Store
    {
        /** @var Store $store */
        $store = (new \ReflectionClass(Store::class))->newInstanceWithoutConstructor();
        $store->setData(Store::STORE_ID, $storeId);

        return $store;
    }
}
