<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Feed;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\InvalidationPolicy;
use PHPUnit\Framework\TestCase;

class InvalidationPolicyTest extends TestCase
{
    public function testLlmsIsEnabledWhenAnyActiveStoreServesEitherDocument(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLlmsTxtEnabled')->willReturn(false);
        $config->method('isLlmsFullTxtEnabled')->willReturnCallback(static fn ($storeId): bool => $storeId === 2);

        $this->assertTrue($this->policy([1, 2], $config)->isGroupEnabled(FeedRegenerator::GROUP_LLMS));
        $this->assertFalse($this->policy([1], $config)->isGroupEnabled(FeedRegenerator::GROUP_LLMS));
    }

    public function testJsonlIsDisabledUnlessAStoreEnablesIt(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLlmsJsonlEnabled')->willReturnCallback(static fn ($storeId): bool => $storeId === 3);

        $this->assertFalse($this->policy([1, 2], $config)->isGroupEnabled(FeedRegenerator::GROUP_JSONL));
        $this->assertTrue($this->policy([1, 3], $config)->isGroupEnabled(FeedRegenerator::GROUP_JSONL));
    }

    public function testHreflangNeedsTwoActiveStoresTheSitemapAndAStoreWithHreflang(): void
    {
        $enabled = $this->createStub(Config::class);
        $enabled->method('isHreflangSitemapEnabled')->willReturn(true);
        $enabled->method('isHreflangEnabled')->willReturn(true);

        $this->assertFalse($this->policy([1], $enabled)->isGroupEnabled(FeedRegenerator::GROUP_HREFLANG));
        $this->assertFalse(
            $this->policy([1], $enabled, [2])->isGroupEnabled(FeedRegenerator::GROUP_HREFLANG),
            'Inactive store views do not count.'
        );
        $this->assertTrue($this->policy([1, 2], $enabled)->isGroupEnabled(FeedRegenerator::GROUP_HREFLANG));

        $sitemapOff = $this->createStub(Config::class);
        $sitemapOff->method('isHreflangSitemapEnabled')->willReturn(false);
        $sitemapOff->method('isHreflangEnabled')->willReturn(true);
        $this->assertFalse($this->policy([1, 2], $sitemapOff)->isGroupEnabled(FeedRegenerator::GROUP_HREFLANG));

        $hreflangOff = $this->createStub(Config::class);
        $hreflangOff->method('isHreflangSitemapEnabled')->willReturn(true);
        $hreflangOff->method('isHreflangEnabled')->willReturn(false);
        $this->assertFalse($this->policy([1, 2], $hreflangOff)->isGroupEnabled(FeedRegenerator::GROUP_HREFLANG));
    }

    public function testUnknownGroupIsNeverEnabled(): void
    {
        $this->assertFalse($this->policy([1, 2])->isGroupEnabled('unknown'));
    }

    public function testProductSaveAffectsHreflangOnlyThroughUrlRelevantChanges(): void
    {
        $policy = $this->policy([1, 2]);
        $loaded = ['url_key' => 'shirt', 'status' => 1, 'visibility' => 4, 'name' => 'Shirt', 'website_ids' => [1]];

        $cases = [
            'name only'        => [$this->model(Product::class, $loaded, ['name' => 'Blue shirt']), false],
            'url key'          => [$this->model(Product::class, $loaded, ['url_key' => 'blue-shirt']), true],
            'status'           => [$this->model(Product::class, $loaded, ['status' => 2]), true],
            'visibility'       => [$this->model(Product::class, $loaded, ['visibility' => 1]), true],
            'websites (diff)'  => [$this->model(Product::class, $loaded, ['website_ids' => [1, 2]]), true],
            'websites (flag)'  => [$this->model(Product::class, $loaded, ['is_changed_websites' => true]), true],
            'new product'      => [$this->model(Product::class, $loaded, [], true), true],
            'string == int'    => [$this->model(Product::class, $loaded, ['status' => '1']), false],
        ];
        foreach ($cases as $label => [$product, $expected]) {
            $this->assertSame(
                $expected,
                $policy->isRelevantChange(
                    FeedRegenerator::GROUP_HREFLANG,
                    $this->event(InvalidationPolicy::EVENT_PRODUCT_SAVE, $product)
                ),
                $label
            );
        }
    }

    public function testCategorySaveAffectsHreflangOnlyThroughUrlRelevantChanges(): void
    {
        $policy = $this->policy([1, 2]);
        $loaded = ['url_key' => 'shirts', 'is_active' => 1, 'name' => 'Shirts'];

        $cases = [
            'name only'    => [$this->model(Category::class, $loaded, ['name' => 'All shirts']), false],
            'url key'      => [$this->model(Category::class, $loaded, ['url_key' => 'all-shirts']), true],
            'is active'    => [$this->model(Category::class, $loaded, ['is_active' => 0]), true],
            'new category' => [$this->model(Category::class, $loaded, [], true), true],
        ];
        foreach ($cases as $label => [$category, $expected]) {
            $this->assertSame(
                $expected,
                $policy->isRelevantChange(
                    FeedRegenerator::GROUP_HREFLANG,
                    $this->event(InvalidationPolicy::EVENT_CATEGORY_SAVE, $category)
                ),
                $label
            );
        }
    }

    public function testCmsPageSaveAffectsHreflangOnlyThroughUrlRelevantChanges(): void
    {
        $policy = $this->policy([1, 2]);
        $loaded = ['identifier' => 'about', 'is_active' => 1, 'store_id' => ['0'], 'title' => 'About'];

        $cases = [
            'title only'   => [$this->model(Page::class, $loaded, ['title' => 'About us']), false],
            'identifier'   => [$this->model(Page::class, $loaded, ['identifier' => 'about-us']), true],
            'is active'    => [$this->model(Page::class, $loaded, ['is_active' => 0]), true],
            'stores'       => [$this->model(Page::class, $loaded, ['store_id' => ['1', '2']]), true],
            'same stores'  => [$this->model(Page::class, $loaded, ['store_id' => [0]]), false],
            'never loaded' => [$this->model(Page::class, null, ['title' => 'New']), true],
        ];
        foreach ($cases as $label => [$page, $expected]) {
            $this->assertSame(
                $expected,
                $policy->isRelevantChange(
                    FeedRegenerator::GROUP_HREFLANG,
                    $this->event(InvalidationPolicy::EVENT_CMS_PAGE_SAVE, $page)
                ),
                $label
            );
        }
    }

    public function testUnrecognisedEventsAreAlwaysRelevant(): void
    {
        $policy           = $this->policy([1, 2]);
        $unchangedProduct = $this->model(Product::class, ['url_key' => 'a'], []);
        $events           = [
            'store_save_after',
            'store_delete',
            'category_move',
            'catalog_product_delete_commit_after',
        ];

        foreach ($events as $name) {
            $this->assertTrue(
                $policy->isRelevantChange(FeedRegenerator::GROUP_HREFLANG, $this->event($name, $unchangedProduct)),
                $name
            );
        }
        // A product save event that does not carry a product model is not second-guessed.
        $this->assertTrue($policy->isRelevantChange(
            FeedRegenerator::GROUP_HREFLANG,
            $this->event(InvalidationPolicy::EVENT_PRODUCT_SAVE, new DataObject())
        ));
    }

    public function testDeletionsAreRelevantToEveryFeed(): void
    {
        $policy   = $this->policy([1, 2]);
        $entities = [
            'catalog_product_delete_commit_after'  => $this->model(Product::class, ['url_key' => 'a'], []),
            'catalog_category_delete_commit_after' => $this->model(Category::class, ['url_key' => 'a'], []),
            'cms_page_delete_commit_after'         => $this->model(Page::class, ['identifier' => 'a'], []),
        ];

        // A deleted entity leaves every feed that listed it: the field-level checks that
        // filter saves must never be applied to a delete.
        foreach ($entities as $eventName => $entity) {
            foreach (FeedRegenerator::GROUPS as $group) {
                $this->assertTrue(
                    $policy->isRelevantChange($group, $this->event($eventName, $entity)),
                    $eventName . ' / ' . $group
                );
            }
        }
    }

    public function testProductSaveAffectsLlmsOnlyWhenCategoryProductCountsCanChange(): void
    {
        $policy = $this->policy([1]);
        $loaded = ['name' => 'Shirt', 'website_ids' => [1]];

        $cases = [
            'name only'          => [$this->model(Product::class, $loaded, ['name' => 'Blue shirt']), false],
            'categories changed' => [$this->model(Product::class, $loaded, ['is_changed_categories' => true]), true],
            'new product'        => [$this->model(Product::class, $loaded, [], true), true],
        ];
        foreach ($cases as $label => [$product, $expected]) {
            $this->assertSame(
                $expected,
                $policy->isRelevantChange(
                    FeedRegenerator::GROUP_LLMS,
                    $this->event(InvalidationPolicy::EVENT_PRODUCT_SAVE, $product)
                ),
                $label
            );
        }
    }

    public function testAChangedWebsiteAssignmentAffectsLlms(): void
    {
        // Core counts a category's products joined to catalog_product_website for the store's
        // website, so the counts in llms.txt change with the assignment.
        $policy = $this->policy([1]);
        $cases  = [
            'flagged by the resource' => $this->model(
                Product::class,
                ['website_ids' => [1]],
                ['is_changed_websites' => true]
            ),
            'website_ids differ'      => $this->model(
                Product::class,
                ['website_ids' => [1]],
                ['website_ids' => [1, 2]]
            ),
            'websites unchanged'      => $this->model(
                Product::class,
                ['website_ids' => [1]],
                ['name' => 'Blue shirt']
            ),
        ];

        $expected = [true, true, false];
        foreach (array_values($cases) as $index => $product) {
            $this->assertSame(
                $expected[$index],
                $policy->isRelevantChange(
                    FeedRegenerator::GROUP_LLMS,
                    $this->event(InvalidationPolicy::EVENT_PRODUCT_SAVE, $product)
                ),
                array_keys($cases)[$index]
            );
        }
    }

    public function testMassAttributeUpdatesAffectJsonlAlwaysAndTheSitemapByAttribute(): void
    {
        $policy = $this->policy([1, 2]);

        $this->assertTrue($policy->isRelevantAttributeUpdate(FeedRegenerator::GROUP_JSONL, ['description']));
        $this->assertTrue($policy->isRelevantAttributeUpdate(FeedRegenerator::GROUP_HREFLANG, ['status']));
        $this->assertTrue(
            $policy->isRelevantAttributeUpdate(FeedRegenerator::GROUP_HREFLANG, ['description', 'visibility'])
        );
        $this->assertFalse($policy->isRelevantAttributeUpdate(FeedRegenerator::GROUP_HREFLANG, ['description']));
        // The llms documents list categories and counts, which no attribute value changes.
        $this->assertFalse($policy->isRelevantAttributeUpdate(FeedRegenerator::GROUP_LLMS, ['status', 'url_key']));
    }

    public function testCategorySavesAlwaysAffectLlmsAndProductSavesAlwaysAffectJsonl(): void
    {
        $policy   = $this->policy([1]);
        $category = $this->model(Category::class, ['name' => 'Shirts'], []);
        $product  = $this->model(Product::class, ['name' => 'Shirt'], []);

        $this->assertTrue($policy->isRelevantChange(
            FeedRegenerator::GROUP_LLMS,
            $this->event(InvalidationPolicy::EVENT_CATEGORY_SAVE, $category)
        ));
        $this->assertTrue($policy->isRelevantChange(
            FeedRegenerator::GROUP_JSONL,
            $this->event(InvalidationPolicy::EVENT_PRODUCT_SAVE, $product)
        ));
    }

    /**
     * Build the policy over the given store views; config defaults to a stub.
     *
     * @param int[] $activeStoreIds
     * @param Config|null $config
     * @param int[] $inactiveStoreIds
     * @return InvalidationPolicy
     */
    private function policy(
        array $activeStoreIds,
        ?Config $config = null,
        array $inactiveStoreIds = []
    ): InvalidationPolicy {
        $stores = [];
        foreach ([...$activeStoreIds, ...$inactiveStoreIds] as $storeId) {
            $store = $this->createStub(Store::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getIsActive')->willReturn(\in_array($storeId, $activeStoreIds, true));
            $stores[] = $store;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        return new InvalidationPolicy($storeManager, $config ?? $this->createStub(Config::class));
    }

    /**
     * A model as a save leaves it: loaded values as original data, saved values as data.
     *
     * Built without its constructor: only the data-object behaviour is exercised.
     *
     * @param class-string<AbstractModel> $class
     * @param array<string, mixed>|null $loaded Original data, or null for a never-loaded model
     * @param array<string, mixed> $changes
     * @param bool $isNew
     * @return AbstractModel
     */
    private function model(string $class, ?array $loaded, array $changes, bool $isNew = false): AbstractModel
    {
        /** @var AbstractModel $model */
        $model = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach ($loaded ?? [] as $key => $value) {
            $model->setOrigData($key, $value);
        }
        $model->setData(array_merge($loaded ?? [], $changes));
        $model->isObjectNew($isNew);

        return $model;
    }

    /**
     * An event as the event manager dispatches it.
     *
     * @param string $name
     * @param object $entity
     * @return Event
     */
    private function event(string $name, object $entity): Event
    {
        return new Event(['name' => $name, 'data_object' => $entity]);
    }
}
