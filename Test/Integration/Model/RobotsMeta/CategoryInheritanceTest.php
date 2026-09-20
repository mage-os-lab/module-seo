<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\RobotsMeta;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Framework\Registry;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Category\ConfigRepository;
use MageOS\Seo\Model\ResourceModel\CategoryConfig\CollectionFactory;
use MageOS\Seo\Model\RobotsMeta\Provider\CategoryRobotsProvider;
use PHPUnit\Framework\TestCase;

/**
 * Category SEO settings inherited from an ancestor must reach the storefront.
 *
 * The repository has always been able to walk the ancestors — but only when handed the category
 * path, and the storefront providers passed an empty one, so a value set on a parent category was
 * shown as inherited in the admin form and then never appeared on a page. A unit test can show
 * that a provider passes a path; only this can show that the value arrives.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class CategoryInheritanceTest extends TestCase
{
    /**
     * Category IDs whose configuration this test wrote.
     *
     * @var int[]
     */
    private array $writtenCategoryIds = [];

    /**
     * Remove the rows the test wrote and the category it registered; the fixtures revert
     * themselves.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $registry = Bootstrap::getObjectManager()->get(Registry::class);
        $registry->unregister('current_category');

        if ($this->writtenCategoryIds !== []) {
            $collection = Bootstrap::getObjectManager()->create(CollectionFactory::class)->create();
            $collection->addFieldToFilter('category_id', ['in' => $this->writtenCategoryIds]);
            foreach ($collection as $config) {
                $config->delete();
            }
        }
        $this->writtenCategoryIds = [];

        parent::tearDown();
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'parent')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$parent.id$'], 'child')]
    public function testAnAncestorsSettingsReachAChildCategoryPage(): void
    {
        $parentId = $this->categoryId('parent');
        $childId  = $this->categoryId('child');

        $this->configRepository()->save($parentId, [
            'schema_template' => 'generic',
            'robots_meta'     => 'NOINDEX,FOLLOW',
        ]);

        $this->viewing($childId);

        $this->assertSame('NOINDEX,FOLLOW', $this->provider()->getRobots($this->storeId()));
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'parent')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$parent.id$'], 'child')]
    public function testAnAncestorIsInheritedFromEvenWhenItSetsNoTemplate(): void
    {
        $parentId = $this->categoryId('parent');
        $childId  = $this->categoryId('child');

        $this->configRepository()->save($parentId, ['robots_meta' => 'NOINDEX,FOLLOW']);

        $this->viewing($childId);

        // Until the InheritanceResolver was introduced this returned the store default: the walk
        // only began when an ancestor had a schema_template, so a template code decided whether
        // an unrelated setting was inherited at all. Each field now resolves on its own.
        $this->assertSame('NOINDEX,FOLLOW', $this->provider()->getRobots($this->storeId()));
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'parent')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$parent.id$'], 'child')]
    public function testACategorysOwnValueWinsOverTheOneItWouldInherit(): void
    {
        $parentId = $this->categoryId('parent');
        $childId  = $this->categoryId('child');

        $this->configRepository()->save($parentId, ['robots_meta' => 'NOINDEX,FOLLOW']);
        $this->configRepository()->save($childId, ['robots_meta' => 'INDEX,FOLLOW']);

        $this->viewing($childId);

        $this->assertSame('INDEX,FOLLOW', $this->provider()->getRobots($this->storeId()));
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'parent')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$parent.id$'], 'child')]
    public function testAnUnconfiguredTreeFallsBackToTheConfiguredDefault(): void
    {
        $this->categoryId('parent');
        $childId = $this->categoryId('child');

        $this->viewing($childId);

        // Nothing is set anywhere in the tree, so the store's configured default answers — the
        // inheritance walk must not invent a value of its own.
        $this->assertSame(
            $this->seoDefault(),
            $this->provider()->getRobots($this->storeId())
        );
    }

    /**
     * Put a category into the registry and onto the layer, as viewing it would.
     *
     * The registry alone is not enough here. Layer::getCurrentCategory() reads the registry once
     * and then caches the answer in its own data, and LayerResolver is a singleton holding that
     * layer — so anything that resolved it earlier in the process has already fixed it on the
     * root category, and a registry entry written afterwards is never looked at. Setting it on
     * the layer as well removes that ordering dependency.
     *
     * @param int $categoryId
     * @return void
     */
    private function viewing(int $categoryId): void
    {
        $category = Bootstrap::getObjectManager()
            ->get(CategoryRepositoryInterface::class)
            ->get($categoryId);

        $registry = Bootstrap::getObjectManager()->get(Registry::class);
        $registry->unregister('current_category');
        $registry->register('current_category', $category);

        Bootstrap::getObjectManager()
            ->get(LayerResolver::class)
            ->get()
            ->setCurrentCategory($category);
    }

    /**
     * The robots default the store configuration supplies, whatever it is here.
     *
     * @return string|null
     */
    private function seoDefault(): ?string
    {
        $configured = Bootstrap::getObjectManager()
            ->get(\MageOS\Seo\Model\Config::class)
            ->getRobotsCategoryDefault($this->storeId());

        return $configured === '' ? null : $configured;
    }

    /**
     * @return int
     */
    private function storeId(): int
    {
        return (int) Bootstrap::getObjectManager()
            ->get(\Magento\Store\Model\StoreManagerInterface::class)
            ->getStore()
            ->getId();
    }

    /**
     * @return CategoryRobotsProvider
     */
    private function provider(): CategoryRobotsProvider
    {
        // Created fresh: the config repository memoises per request, and each test writes rows
        // after the previous one has already read.
        return Bootstrap::getObjectManager()->create(CategoryRobotsProvider::class);
    }

    /**
     * @return ConfigRepository
     */
    private function configRepository(): ConfigRepository
    {
        return Bootstrap::getObjectManager()->create(ConfigRepository::class);
    }

    /**
     * The ID of a fixture category, remembered for cleanup.
     *
     * @param string $name
     * @return int
     */
    private function categoryId(string $name): int
    {
        $categoryId                 = (int) $this->fixture($name)->getId();
        $this->writtenCategoryIds[] = $categoryId;

        return $categoryId;
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
