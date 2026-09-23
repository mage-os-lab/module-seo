<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\ObjectManager\ConfigInterface as ObjectManagerConfig;
use Magento\Sitemap\Model\ItemProvider\Category as CoreCategoryProvider;
use Magento\Sitemap\Model\ItemProvider\CmsPage as CoreCmsPageProvider;
use Magento\Sitemap\Model\ItemProvider\Composite as CoreComposite;
use Magento\Sitemap\Model\ItemProvider\ItemProviderInterface as CoreItemProviderInterface;
use Magento\Sitemap\Model\ItemProvider\Product as CoreProductProvider;
use Magento\Sitemap\Model\ItemProvider\StoreUrl as CoreStoreUrlProvider;
use Magento\Sitemap\Model\Sitemap;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\Sitemap\ItemProviderInterface;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Sitemap\ItemProvider\Category;
use MageOS\Seo\Model\Sitemap\ItemProvider\CmsPage;
use MageOS\Seo\Model\Sitemap\ItemProvider\Composite;
use MageOS\Seo\Model\Sitemap\ItemProvider\Product;
use MageOS\Seo\Model\Sitemap\ItemProvider\StoreUrl;
use MageOS\Seo\Test\Integration\Model\Sitemap\Fixture\RegisteredElsewhereProvider;
use PHPUnit\Framework\TestCase;

/**
 * F1: this module's sitemap providers take core's place without changing what core writes.
 *
 * The two things that have to hold before any generator is built on them: a provider another module
 * registers on core's composite reaches this module's composite, and core's own generator writes
 * the same file on this module's providers as on its own.
 *
 * @magentoAppArea adminhtml
 */
class ItemProviderWiringTest extends TestCase
{
    /**
     * Sitemap files written by the running test, relative to pub/.
     *
     * @var string[]|null
     */
    private ?array $writtenFiles = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $pub = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::PUB);
        foreach ($this->writtenFiles as $file) {
            if ($pub->isExist($file)) {
                $pub->delete($file);
            }
        }
        $this->writtenFiles = [];
    }

    /**
     * @return void
     */
    public function testCoresProviderInterfaceResolvesToThisModulesCompositeWithTheWrappers(): void
    {
        $composite = Bootstrap::getObjectManager()->create(CoreItemProviderInterface::class);

        $this->assertInstanceOf(Composite::class, $composite);
        $providers = $composite->getProviders();
        $this->assertInstanceOf(StoreUrl::class, $providers['storeUrlProvider'] ?? null);
        $this->assertInstanceOf(Category::class, $providers['categoryProvider'] ?? null);
        $this->assertInstanceOf(CmsPage::class, $providers['cmsPageProvider'] ?? null);
        $this->assertInstanceOf(Product::class, $providers['productProvider'] ?? null);
    }

    /**
     * Other modules register on core's composite and must not have to know this module exists.
     *
     * @magentoAppIsolation enabled
     * @return void
     */
    public function testAProviderRegisteredOnCoresCompositeReachesThisModulesComposite(): void
    {
        // di.xml files are merged item by item before the object manager sees them; configure()
        // replaces a whole argument. So add the item to the arguments as merged, the way another
        // module's di.xml would.
        $objectManager = Bootstrap::getObjectManager();
        $arguments     = $objectManager->get(ObjectManagerConfig::class)->getArguments(CoreComposite::class);
        $arguments['itemProviders']['mageosSeoRegisteredElsewhere'] = [
            'instance' => RegisteredElsewhereProvider::class,
        ];
        $objectManager->configure([CoreComposite::class => ['arguments' => $arguments]]);

        $providers = $objectManager->create(CoreItemProviderInterface::class)->getProviders();

        $this->assertInstanceOf(
            RegisteredElsewhereProvider::class,
            $providers['mageosSeoRegisteredElsewhere'] ?? null,
            'A provider registered on core\'s composite did not reach this module\'s composite.'
        );
        $this->assertInstanceOf(
            ItemProviderInterface::class,
            $providers['productProvider'] ?? null,
            'Registering another provider must not undo the replacement of core\'s own.'
        );
    }

    /**
     * Core's generator, run on this module's providers and then on core's own, writes the same bytes.
     *
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$category.id$']], as: 'product')]
    public function testCoresGeneratorWritesTheSameFileOnThisModulesProviders(): void
    {
        $onThisModule = $this->generate('mageos_seo_f1_this_module.xml');

        Bootstrap::getObjectManager()->configure([
            'preferences' => [CoreItemProviderInterface::class => CoreComposite::class],
            CoreComposite::class => [
                'arguments' => [
                    'itemProviders' => [
                        'storeUrlProvider' => ['instance' => CoreStoreUrlProvider::class],
                        'categoryProvider' => ['instance' => CoreCategoryProvider::class],
                        'cmsPageProvider'  => ['instance' => CoreCmsPageProvider::class],
                        'productProvider'  => ['instance' => CoreProductProvider::class],
                    ],
                ],
            ],
        ]);
        $onCore = $this->generate('mageos_seo_f1_core.xml');

        $product = DataFixtureStorageManager::getStorage()->get('product');
        $this->assertStringContainsString(
            (string) $product->getUrlKey(),
            $onCore,
            'The comparison is only worth something if the catalogue made it into the file.'
        );
        $this->assertSame($onCore, $onThisModule);
    }

    /**
     * Streaming lists what the list lists.
     *
     * For products the two come from different core resource models — the standard one for the
     * list, the batch one for the stream — so this is where a difference between them would show.
     *
     * @magentoDbIsolation enabled
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$category.id$']], as: 'product')]
    public function testEachProviderStreamsTheSameItemsItLists(): void
    {
        $storeId = $this->defaultStoreId();

        foreach ($this->ownProviders() as $name => $provider) {
            $this->assertSame(
                $this->describe($provider->getItems($storeId)),
                $this->describe($provider->iterateItems($storeId)),
                $name . ' streams something other than it lists.'
            );
        }
    }

    /**
     * Each item says which entity it is.
     *
     * @magentoDbIsolation enabled
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$category.id$']], as: 'product')]
    public function testItemsKnowTheEntityTheyList(): void
    {
        $storeId   = $this->defaultStoreId();
        $providers = $this->ownProviders();
        $storage   = DataFixtureStorageManager::getStorage();

        $products = $this->entitiesByUrl($providers['productProvider']->iterateItems($storeId));
        $this->assertContains(
            [SitemapItemInterface::ENTITY_PRODUCT, (int) $storage->get('product')->getId()],
            $products
        );

        $categories = $this->entitiesByUrl($providers['categoryProvider']->iterateItems($storeId));
        $this->assertContains(
            [SitemapItemInterface::ENTITY_CATEGORY, (int) $storage->get('category')->getId()],
            $categories
        );

        $this->assertSame(
            [[SitemapItemInterface::ENTITY_STORE, null]],
            array_values($this->entitiesByUrl($providers['storeUrlProvider']->iterateItems($storeId)))
        );
    }

    /**
     * This module's four providers, as core's composite now holds them.
     *
     * @return array<string, ItemProviderInterface>
     */
    private function ownProviders(): array
    {
        $providers = Bootstrap::getObjectManager()->create(CoreItemProviderInterface::class)->getProviders();

        return array_intersect_key(
            $providers,
            array_flip(['storeUrlProvider', 'categoryProvider', 'cmsPageProvider', 'productProvider'])
        );
    }

    /**
     * The fields core writes, per item, in order.
     *
     * @param iterable<SitemapItemInterface> $items
     * @return array<int, mixed[]>
     */
    private function describe(iterable $items): array
    {
        $described = [];
        foreach ($items as $item) {
            $described[] = [
                $item->getUrl(),
                $item->getUpdatedAt(),
                $item->getPriority(),
                $item->getChangeFrequency(),
                $this->plain($item->getImages()),
                $item->getEntityType(),
                $item->getEntityId(),
            ];
        }

        return $described;
    }

    /**
     * A value with every DataObject in it replaced by its data, so values compare by content.
     *
     * The images are DataObjects holding a collection of DataObjects; two reads of the same images
     * are equal but never the same objects.
     *
     * @param mixed $value
     * @return mixed
     */
    private function plain(mixed $value): mixed
    {
        if ($value instanceof \Magento\Framework\DataObject) {
            $value = $value->getData();
        }

        return \is_array($value) ? array_map([$this, 'plain'], $value) : $value;
    }

    /**
     * Entity type and ID of each item, keyed by URL.
     *
     * @param iterable<SitemapItemInterface> $items
     * @return array<string, array{0: string|null, 1: int|null}>
     */
    private function entitiesByUrl(iterable $items): array
    {
        $entities = [];
        foreach ($items as $item) {
            $entities[(string) $item->getUrl()] = [$item->getEntityType(), $item->getEntityId()];
        }

        return $entities;
    }

    /**
     * @return int
     */
    private function defaultStoreId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default')->getId();
    }

    /**
     * Generate a sitemap for the default store view with core's generator, and return the file.
     *
     * @param string $fileName
     * @return string
     */
    private function generate(string $fileName): string
    {
        $objectManager = Bootstrap::getObjectManager();
        $storeId       = (int) $objectManager->get(StoreManagerInterface::class)->getStore('default')->getId();

        /** @var Sitemap $sitemap */
        $sitemap = $objectManager->create(Sitemap::class);
        $sitemap->setData([
            'sitemap_filename' => $fileName,
            'sitemap_path'     => '/media/sitemap/',
            'store_id'         => $storeId,
        ]);

        $this->writtenFiles[] = 'media/sitemap/' . $fileName;

        $emulation = $objectManager->get(Emulation::class);
        $emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $sitemap->generateXml();
        } finally {
            $emulation->stopEnvironmentEmulation();
        }

        return $objectManager->get(Filesystem::class)
            ->getDirectoryRead(DirectoryList::PUB)
            ->readFile('media/sitemap/' . $fileName);
    }
}
