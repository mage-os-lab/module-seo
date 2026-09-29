<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Feed;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Aeo\Config;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\LlmsInvalidationPolicy;
use MageOS\Seo\Model\Organization;
use MageOS\Seo\Model\Rebuild\ChangeInspector;
use PHPUnit\Framework\TestCase;

class LlmsInvalidationPolicyTest extends TestCase
{
    private const EVENT_PRODUCT_SAVE  = 'catalog_product_save_after';
    private const EVENT_CATEGORY_SAVE = 'catalog_category_save_after';

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

    public function testAnInactiveStoreViewEnablesNothing(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLlmsTxtEnabled')->willReturnCallback(static fn ($storeId): bool => $storeId === 4);

        $this->assertFalse($this->policy([1], $config, [4])->isGroupEnabled(FeedRegenerator::GROUP_LLMS));
    }

    public function testUnknownGroupIsNeverEnabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLlmsTxtEnabled')->willReturn(true);
        $config->method('isLlmsJsonlEnabled')->willReturn(true);

        $this->assertFalse($this->policy([1, 2], $config)->isGroupEnabled('unknown'));
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
                $policy->isRelevantChange(FeedRegenerator::GROUP_LLMS, $this->event($name, $unchangedProduct)),
                $name
            );
        }
        // A product save event that does not carry a product model is not second-guessed.
        $this->assertTrue($policy->isRelevantChange(
            FeedRegenerator::GROUP_LLMS,
            $this->event(self::EVENT_PRODUCT_SAVE, new DataObject())
        ));
    }

    public function testAFaqOrTheOrganizationChangingIsAlwaysRelevantToLlms(): void
    {
        // Both are shown in the documents, and their models' own events carry them. The FAQ model
        // belongs to MageOS_Faq, which this module doesn't depend on, and the policy never inspects
        // these events' payload, so a plain object stands in for it.
        $policy = $this->policy([1]);

        $faq          = new DataObject(['question' => 'Q', 'answer' => 'A']);
        $organization = $this->model(Organization::class, ['name' => 'A'], ['name' => 'B']);
        foreach ([
            'mageos_faq_save_after'                => $faq,
            'mageos_faq_delete_after'              => $faq,
            'mageos_seo_organization_save_after'   => $organization,
            'mageos_seo_organization_delete_after' => $organization,
        ] as $eventName => $entity) {
            $this->assertTrue(
                $policy->isRelevantChange(FeedRegenerator::GROUP_LLMS, $this->event($eventName, $entity)),
                $eventName
            );
        }
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
                    $this->event(self::EVENT_PRODUCT_SAVE, $product)
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
                    $this->event(self::EVENT_PRODUCT_SAVE, $product)
                ),
                array_keys($cases)[$index]
            );
        }
    }

    public function testMassAttributeUpdatesAffectJsonlAlwaysAndLlmsNever(): void
    {
        $policy = $this->policy([1, 2]);

        $this->assertTrue($policy->isRelevantAttributeUpdate(FeedRegenerator::GROUP_JSONL, ['description']));
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
            $this->event(self::EVENT_CATEGORY_SAVE, $category)
        ));
        $this->assertTrue($policy->isRelevantChange(
            FeedRegenerator::GROUP_JSONL,
            $this->event(self::EVENT_PRODUCT_SAVE, $product)
        ));
    }

    public function testConfigurationAffectsLlmsOnlyUnderThePathsItShowsAndWhenChanged(): void
    {
        $policy   = $this->policy([1]);
        $relevant = fn (string $eventName, string $path, bool $changed): bool => $policy->isRelevantChange(
            FeedRegenerator::GROUP_LLMS,
            $this->event($eventName, $this->configValue($path, $changed))
        );

        foreach ([
            'general/locale/code',
            'trans_email/ident_support/email',
            'mageos_aeo/llms_txt/faq_groups',
            'web/unsecure/base_url',
            'catalog/seo/category_url_suffix',
        ] as $path) {
            $this->assertTrue($relevant('config_data_save_after', $path, true), $path);
        }
        $this->assertFalse(
            $relevant('config_data_save_after', 'mageos_seo_general/llms_txt/faq_groups', true),
            'The llms settings moved to mageos_aeo; the old path is read by nothing.'
        );
        $this->assertFalse(
            $relevant('config_data_save_after', 'general/locale/code', false),
            'The admin saves every field of a section; an unchanged one is not a change.'
        );
        $this->assertFalse($relevant('config_data_save_after', 'contact/email/recipient_email', true));
        $this->assertFalse($relevant('config_data_save_after', 'trans_email/ident_sales/email', true));
        $this->assertTrue(
            $relevant('config_data_delete_after', 'general/locale/code', false),
            'A deleted value falls back to another: a change.'
        );
    }

    /**
     * Build the policy over the given store views; config defaults to a stub.
     *
     * @param int[] $activeStoreIds
     * @param Config|null $config
     * @param int[] $inactiveStoreIds
     * @return LlmsInvalidationPolicy
     */
    private function policy(
        array $activeStoreIds,
        ?Config $config = null,
        array $inactiveStoreIds = []
    ): LlmsInvalidationPolicy {
        $stores = [];
        foreach ([...$activeStoreIds, ...$inactiveStoreIds] as $storeId) {
            $store = $this->createStub(Store::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getIsActive')->willReturn(\in_array($storeId, $activeStoreIds, true));
            $stores[] = $store;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        return new LlmsInvalidationPolicy(
            $storeManager,
            $config ?? $this->createStub(Config::class),
            new ChangeInspector()
        );
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

    /**
     * A configuration value at a path, reporting whether the save changed it.
     *
     * @param string $path
     * @param bool $changed
     * @return ConfigValue
     */
    private function configValue(string $path, bool $changed): ConfigValue
    {
        $value = new class ($changed) extends ConfigValue {
            /**
             * @param bool $changed
             */
            public function __construct(private readonly bool $changed)
            {
            }

            /**
             * @inheritdoc
             */
            public function isValueChanged()
            {
                return $this->changed;
            }
        };
        $value->setData('path', $path);

        return $value;
    }
}
