<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\WebsiteFactory;
use Magento\Store\Test\Fixture\Group as GroupFixture;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\Store\Test\Fixture\Website as WebsiteFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\Data\FaqInterface;
use MageOS\Seo\Api\FaqRepositoryInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Model\Category\ConfigRepository;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Model\Faq;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Hreflang\SitemapGenerator;
use PHPUnit\Framework\TestCase;

/**
 * What happens to the module's own records when the things they describe are deleted.
 *
 * The catalogue and store-scoped tables carry foreign keys with ON DELETE CASCADE, so the
 * database removes their rows. mageos_seo_organisation cannot: its scope_id points at a website
 * or a store view depending on the scope column, so an observer does it instead.
 *
 * Deleting a website or a store group is the case worth the setup here: core removes its store
 * views with a database-level cascade that dispatches no store_delete event, so nothing in PHP
 * ever hears about them.
 *
 * Database isolation is disabled because creating websites and store views is not transactional.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class ScopeDeletionCleanupTest extends TestCase
{
    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testDeletingAProductRemovesItsSeoOverrides(): void
    {
        $product   = $this->fixture('product');
        $productId = (int) $product->getId();

        $this->overrideRepository()->save($productId, 0, ['robots_meta' => 'NOINDEX,FOLLOW']);
        $this->assertSame('NOINDEX,FOLLOW', $this->robotsOverride($productId, 0));

        Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class)
            ->deleteById((string) $product->getSku());

        $this->assertNull($this->robotsOverride($productId, 0));
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testDeletingACategoryRemovesItsSeoConfig(): void
    {
        $categoryId = (int) $this->fixture('category')->getId();

        $this->configRepository()->save($categoryId, ['robots_meta' => 'NOINDEX,FOLLOW']);
        $this->assertNotSame([], $this->configRepository()->getForCategory($categoryId));

        Bootstrap::getObjectManager()->get(CategoryRepositoryInterface::class)
            ->deleteByIdentifier($categoryId);

        $this->assertSame([], $this->configRepository()->getForCategory($categoryId));
    }

    /**
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'store')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testDeletingAStoreViewRemovesEverythingScopedToIt(): void
    {
        $storeId    = (int) $this->fixture('store')->getId();
        $categoryId = (int) $this->fixture('category')->getId();
        $productId  = (int) $this->fixture('product')->getId();

        $faqId = $this->seedStoreScopedRecords($storeId, $categoryId, $productId);

        $this->deleteStore($storeId);

        $this->assertStoreScopedRecordsAreGone($storeId, $categoryId, $productId, $faqId);
    }

    /**
     * The case no observer can see: the store views go with the website, in the database.
     *
     * @return void
     */
    #[DataFixture(WebsiteFixture::class, as: 'website')]
    #[DataFixture(GroupFixture::class, ['website_id' => '$website.id$'], 'group')]
    #[DataFixture(StoreFixture::class, ['store_group_id' => '$group.id$'], 'store')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testDeletingAWebsiteRemovesTheRecordsOfItsStoreViews(): void
    {
        $websiteId  = (int) $this->fixture('website')->getId();
        $storeId    = (int) $this->fixture('store')->getId();
        $categoryId = (int) $this->fixture('category')->getId();
        $productId  = (int) $this->fixture('product')->getId();

        $faqId = $this->seedStoreScopedRecords($storeId, $categoryId, $productId);
        $this->saveOrganisation(ScopeInterface::SCOPE_WEBSITES, $websiteId);

        $this->deleteWebsite($websiteId);

        $this->assertStoreScopedRecordsAreGone($storeId, $categoryId, $productId, $faqId);
        $this->assertSame(
            0,
            $this->organisationRepository()->get(ScopeInterface::SCOPE_WEBSITES, $websiteId)->getEntityId(),
            'The website Organisation record is gone.'
        );
    }

    /**
     * Feed files of store views that disappeared with their website are swept by a full rebuild.
     *
     * @return void
     */
    #[DataFixture(WebsiteFixture::class, as: 'website')]
    #[DataFixture(GroupFixture::class, ['website_id' => '$website.id$'], 'group')]
    #[DataFixture(StoreFixture::class, ['store_group_id' => '$group.id$'], 'store')]
    public function testAFullRebuildRemovesFeedDirectoriesOfStoreViewsThatNoLongerExist(): void
    {
        $websiteId = (int) $this->fixture('website')->getId();
        $storeId   = (int) $this->fixture('store')->getId();

        $storage = Bootstrap::getObjectManager()->create(FeedStorage::class);
        $storage->write('llms.txt', $storeId, 'store that is about to go');

        $this->deleteWebsite($websiteId);

        // Nothing dispatched store_delete for it, so the files are still there.
        $this->assertSame('store that is about to go', $storage->read('llms.txt', $storeId));

        Bootstrap::getObjectManager()->create(FeedRegenerator::class)->regenerate();

        $this->assertNull($storage->read('llms.txt', $storeId), 'The orphaned directory was swept.');
        $this->assertNotContains(
            $storeId,
            Bootstrap::getObjectManager()->create(FeedStorage::class)->listStoreDirectories()
        );
    }

    /**
     * A rebuild removes the sitemap the survivors are still serving.
     *
     * Deleting the second-to-last store view leaves one, and a single store view has no
     * alternates — so the sitemap the survivor is serving, which lists the store view that has
     * just gone, has to be taken away rather than rewritten.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'store')]
    public function testARebuildRemovesTheSitemapOfTheStoreViewThatIsLeft(): void
    {
        $storeManager  = Bootstrap::getObjectManager()->get(StoreManagerInterface::class);
        $survivingId   = (int) $storeManager->getStore('default')->getId();
        $deletedId     = (int) $this->fixture('store')->getId();
        $storage       = Bootstrap::getObjectManager()->create(FeedStorage::class);

        $storage->write(SitemapGenerator::INDEX_FILE, $survivingId, '<urlset>two store views</urlset>');
        $this->deleteStore($deletedId);

        Bootstrap::getObjectManager()->create(FeedRegenerator::class)
            ->regenerate(FeedRegenerator::GROUP_HREFLANG);

        $this->assertNull(
            $storage->read(SitemapGenerator::INDEX_FILE, $survivingId),
            'A sitemap that can no longer be built is removed, not left listing a deleted store view.'
        );
    }

    /**
     * Write one record per store-scoped table and return the FAQ's ID.
     *
     * @param int $storeId
     * @param int $categoryId
     * @param int $productId
     * @return int
     */
    private function seedStoreScopedRecords(int $storeId, int $categoryId, int $productId): int
    {
        $this->configRepository()->save($categoryId, ['robots_meta' => 'NOINDEX,FOLLOW'], $storeId);
        $this->overrideRepository()->save($productId, $storeId, ['robots_meta' => 'NOINDEX,FOLLOW']);
        $this->saveOrganisation(ScopeInterface::SCOPE_STORES, $storeId);

        /** @var FaqInterface $faq */
        $faq = Bootstrap::getObjectManager()->create(Faq::class);
        $faq->setIdentifier('scope-deletion-check')
            ->setStoreId($storeId)
            ->setQuestion('Does this record survive its store view?')
            ->setAnswer('It should not.')
            ->setIsActive(true);
        Bootstrap::getObjectManager()->get(FaqRepositoryInterface::class)->save($faq);

        $this->assertNotSame([], $this->configRepository()->getForCategory($categoryId, [], $storeId));
        $this->assertSame('NOINDEX,FOLLOW', $this->robotsOverride($productId, $storeId));
        $this->assertGreaterThan(
            0,
            $this->organisationRepository()->get(ScopeInterface::SCOPE_STORES, $storeId)->getEntityId()
        );

        return $faq->getEntityId();
    }

    /**
     * Assert that nothing scoped to the store view is left.
     *
     * @param int $storeId
     * @param int $categoryId
     * @param int $productId
     * @param int $faqId
     * @return void
     */
    private function assertStoreScopedRecordsAreGone(
        int $storeId,
        int $categoryId,
        int $productId,
        int $faqId
    ): void {
        $this->assertSame(
            [],
            $this->configRepository()->getForCategory($categoryId, [], $storeId),
            'Category config went with the store view.'
        );
        $this->assertNull(
            $this->robotsOverride($productId, $storeId),
            'Product override went with the store view.'
        );
        $this->assertSame(
            0,
            $this->organisationRepository()->get(ScopeInterface::SCOPE_STORES, $storeId)->getEntityId(),
            'Organisation record went with the store view.'
        );

        try {
            Bootstrap::getObjectManager()->get(FaqRepositoryInterface::class)->getById($faqId);
            $this->fail('The FAQ entry went with the store view.');
        } catch (NoSuchEntityException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * The stored robots_meta override for a product, or null when there is none.
     *
     * @param int $productId
     * @param int $storeId
     * @return string|null
     */
    private function robotsOverride(int $productId, int $storeId): ?string
    {
        $override = $this->overrideRepository()->getForProduct($productId, $storeId);

        return $override['robots_meta'] ?? null;
    }

    /**
     * Store an Organisation record for one scope.
     *
     * @param string $scope
     * @param int $scopeId
     * @return void
     */
    private function saveOrganisation(string $scope, int $scopeId): void
    {
        $repository   = $this->organisationRepository();
        $organisation = $repository->get($scope, $scopeId);
        $organisation->setName('Scope ' . $scope . ' ' . $scopeId);
        $repository->save($organisation);
    }

    /**
     * Delete a store view through its resource, as the admin controller does.
     *
     * @param int $storeId
     * @return void
     */
    private function deleteStore(int $storeId): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $resource      = $objectManager->get(StoreResource::class);

        $store = $objectManager->get(StoreFactory::class)->create();
        $resource->load($store, $storeId);
        $resource->delete($store);
        $objectManager->get(StoreManagerInterface::class)->reinitStores();
    }

    /**
     * Delete a website through its resource; core cascades its groups and store views.
     *
     * @param int $websiteId
     * @return void
     */
    private function deleteWebsite(int $websiteId): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $resource      = $objectManager->get(WebsiteResource::class);

        $website = $objectManager->get(WebsiteFactory::class)->create();
        $resource->load($website, $websiteId);
        $resource->delete($website);
        $objectManager->get(StoreManagerInterface::class)->reinitStores();
    }

    /**
     * A category config repository with an empty read cache.
     *
     * @return ConfigRepository
     */
    private function configRepository(): ConfigRepository
    {
        return Bootstrap::getObjectManager()->create(ConfigRepository::class);
    }

    /**
     * A product override repository with an empty read cache.
     *
     * @return ProductOverrideRepository
     */
    private function overrideRepository(): ProductOverrideRepository
    {
        return Bootstrap::getObjectManager()->create(ProductOverrideRepository::class);
    }

    /**
     * @return OrganisationRepositoryInterface
     */
    private function organisationRepository(): OrganisationRepositoryInterface
    {
        return Bootstrap::getObjectManager()->get(OrganisationRepositoryInterface::class);
    }

    /**
     * An entity created by a data fixture.
     *
     * @param string $name
     * @return \Magento\Framework\DataObject
     */
    private function fixture(string $name): \Magento\Framework\DataObject
    {
        return DataFixtureStorageManager::getStorage()->get($name);
    }
}
