<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\Event;
use Magento\Framework\Model\AbstractModel;
use MageOS\Seo\Model\CategoryConfig;
use MageOS\Seo\Model\CmsPageConfig;
use MageOS\Seo\Model\ProductOverride;
use MageOS\Seo\Model\Rebuild\ChangeInspector;
use MageOS\Seo\Model\Sitemap\InvalidationPolicy;
use MageOS\Seo\Model\Sitemap\RebuildableSitemaps;
use PHPUnit\Framework\TestCase;

class InvalidationPolicyTest extends TestCase
{
    public function testASitemapRebuildIsWorthQueueingOnlyWhileASitemapWouldBeRebuilt(): void
    {
        $this->assertTrue($this->policy()->isEnabled());
        $this->assertFalse($this->policy(sitemapsExist: false)->isEnabled());
    }

    public function testAProductSaveRebuildsTheProductsOnlyThroughWhatIsListed(): void
    {
        $policy = $this->policy();
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
                $expected ? ['products'] : [],
                $policy->sitemapTypesAffectedBy($this->event(InvalidationPolicy::EVENT_PRODUCT_SAVE, $product)),
                $label
            );
        }
    }

    public function testACategorySaveRebuildsTheCategoriesOnlyThroughWhatIsListed(): void
    {
        $policy = $this->policy();
        $loaded = ['url_key' => 'shirts', 'is_active' => 1, 'name' => 'Shirts'];

        $cases = [
            'name only'    => [$this->model(Category::class, $loaded, ['name' => 'All shirts']), false],
            'url key'      => [$this->model(Category::class, $loaded, ['url_key' => 'all-shirts']), true],
            'is active'    => [$this->model(Category::class, $loaded, ['is_active' => 0]), true],
            'new category' => [$this->model(Category::class, $loaded, [], true), true],
        ];
        foreach ($cases as $label => [$category, $expected]) {
            $this->assertSame(
                $expected ? ['categories'] : [],
                $policy->sitemapTypesAffectedBy($this->event(InvalidationPolicy::EVENT_CATEGORY_SAVE, $category)),
                $label
            );
        }
    }

    public function testACmsPageSaveRebuildsThePagesOnlyThroughWhatIsListed(): void
    {
        $policy = $this->policy();
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
                $expected ? ['pages'] : [],
                $policy->sitemapTypesAffectedBy($this->event(InvalidationPolicy::EVENT_CMS_PAGE_SAVE, $page)),
                $label
            );
        }
    }

    public function testProductChangesRebuildTheProductsOnlyWhenWhatIsListedChanges(): void
    {
        $policy  = $this->policy();
        $loaded  = ['url_key' => 'a', 'status' => 1, 'name' => 'A', 'website_ids' => [1]];
        $product = fn (array $changes, bool $isNew = false) => $this->event(
            InvalidationPolicy::EVENT_PRODUCT_SAVE,
            $this->model(Product::class, $isNew ? null : $loaded, $changes, $isNew)
        );

        $this->assertSame([], $policy->sitemapTypesAffectedBy($product(['name' => 'B'])), 'Not listed: a name.');
        $this->assertSame(['products'], $policy->sitemapTypesAffectedBy($product(['url_key' => 'b'])));
        $this->assertSame(['products'], $policy->sitemapTypesAffectedBy($product(['status' => 2])));
        $this->assertSame(['products'], $policy->sitemapTypesAffectedBy($product(['name' => 'N'], true)));
        $this->assertSame(['products'], $policy->sitemapTypesAffectedBy($this->event(
            'catalog_product_delete_commit_after',
            $this->model(Product::class, ['url_key' => 'a'], [])
        )));
    }

    public function testCategoryAndPageChangesRebuildTheirOwnType(): void
    {
        $policy = $this->policy();

        $this->assertSame([], $policy->sitemapTypesAffectedBy($this->event(
            InvalidationPolicy::EVENT_CATEGORY_SAVE,
            $this->model(Category::class, ['url_key' => 'a', 'description' => 'x'], ['description' => 'y'])
        )));
        $this->assertSame(['categories'], $policy->sitemapTypesAffectedBy($this->event(
            InvalidationPolicy::EVENT_CATEGORY_SAVE,
            $this->model(Category::class, ['is_active' => 1], ['is_active' => 0])
        )));
        $this->assertSame([], $policy->sitemapTypesAffectedBy($this->event(
            InvalidationPolicy::EVENT_CMS_PAGE_SAVE,
            $this->model(Page::class, ['identifier' => 'a', 'title' => 'A'], ['title' => 'B'])
        )));
        $this->assertSame(['pages'], $policy->sitemapTypesAffectedBy($this->event(
            InvalidationPolicy::EVENT_CMS_PAGE_SAVE,
            $this->model(Page::class, ['identifier' => 'a'], ['identifier' => 'b'])
        )));
    }

    /**
     * The robots directive decides whether a page is listed, a CMS translation group its
     * alternates; the other per-entity settings (titles, descriptions) are not in a sitemap.
     */
    public function testThisModulesSettingsRebuildTheirTypeWhenTheDirectiveOrGroupChanges(): void
    {
        $policy = $this->policy();

        $this->assertSame(['products'], $policy->sitemapTypesAffectedBy($this->event(
            'mageos_seo_product_override_save_after',
            $this->model(ProductOverride::class, ['robots_meta' => ''], ['robots_meta' => 'NOINDEX,FOLLOW'])
        )));
        $this->assertSame([], $policy->sitemapTypesAffectedBy($this->event(
            'mageos_seo_product_override_save_after',
            $this->model(ProductOverride::class, ['override_fields' => '{}'], ['override_fields' => '{"a":1}'])
        )));
        $this->assertSame(['categories'], $policy->sitemapTypesAffectedBy($this->event(
            'mageos_seo_category_config_delete_after',
            $this->model(CategoryConfig::class, ['robots_meta' => 'NOINDEX'], [])
        )));
        $this->assertSame(['pages'], $policy->sitemapTypesAffectedBy($this->event(
            'mageos_seo_cms_page_config_save_after',
            $this->model(CmsPageConfig::class, ['hreflang_group' => 'a'], ['hreflang_group' => 'b'])
        )));
    }

    public function testSwitchingTheGeneratorOrRebuildOnChangeIsASwitchWhicheverWayItGoes(): void
    {
        // Asked even when no sitemap is rebuilt on change: during the save that is still the
        // configuration from before it.
        $policy = $this->policy(false);

        foreach (['sitemap/generate/mageos_seo_generator', 'sitemap/generate/mageos_seo_rebuild_on_change'] as $path) {
            $this->assertTrue(
                $policy->isSitemapSwitch($this->event('config_data_save_after', $this->configValue($path, true))),
                $path
            );
            $this->assertTrue(
                $policy->isSitemapSwitch($this->event('config_data_delete_after', $this->configValue($path, false))),
                $path . ' removed with "Use Default"'
            );
            $this->assertFalse(
                $policy->isSitemapSwitch($this->event('config_data_save_after', $this->configValue($path, false))),
                $path . ' saved unchanged'
            );
        }
        $maxLines = $this->configValue('sitemap/limit/max_lines', true);
        $this->assertFalse(
            $policy->isSitemapSwitch($this->event('config_data_save_after', $maxLines)),
            'Another sitemap setting is not a switch.'
        );
    }

    public function testConfigurationRebuildsEveryTypeOnlyUnderSitemapPathsAndWhenChanged(): void
    {
        $policy = $this->policy();
        $value  = fn (string $path, bool $changed): ConfigValue => $this->configValue($path, $changed);

        $this->assertSame(['*'], $policy->sitemapTypesAffectedBy(
            $this->event('config_data_save_after', $value('web/unsecure/base_url', true))
        ));
        $this->assertSame(['*'], $policy->sitemapTypesAffectedBy(
            $this->event('config_data_save_after', $value('design/search_engine_robots/default_robots', true))
        ));
        $this->assertSame([], $policy->sitemapTypesAffectedBy(
            $this->event('config_data_save_after', $value('web/unsecure/base_url', false))
        ), 'The admin saves every field of a section; an unchanged one is not a change.');
        $this->assertSame([], $policy->sitemapTypesAffectedBy(
            $this->event('config_data_save_after', $value('contact/email/recipient_email', true))
        ));
        $this->assertSame(['*'], $policy->sitemapTypesAffectedBy(
            $this->event('config_data_delete_after', $value('sitemap/limit/max_lines', false))
        ), 'A deleted value falls back to another: a change.');
    }

    public function testStoreChangesRebuildEveryTypeAndMassUpdatesOnlyWhatIsListed(): void
    {
        $policy = $this->policy();

        $this->assertSame(['*'], $policy->sitemapTypesAffectedBy(new Event(['name' => 'store_delete_before'])));
        $this->assertSame(['categories'], $policy->sitemapTypesAffectedBy(new Event(['name' => 'category_move'])));
        $this->assertSame(['products'], $policy->sitemapTypesAffectedByAttributeUpdate(['name', 'visibility']));
        $this->assertSame([], $policy->sitemapTypesAffectedByAttributeUpdate(['name', 'description']));
    }

    /**
     * Issue #23: with a sitemap listing product images, what the image rows are made of rebuilds
     * the products — the name (image title), the base image and thumbnail, the gallery.
     *
     * @return void
     */
    public function testWithImagesListedTheNameImagesAndGalleryRebuildTheProducts(): void
    {
        $loaded = [
            'url_key' => 'shirt', 'status' => 1, 'name' => 'Shirt', 'image' => '/s/h/shirt.jpg',
            'thumbnail' => '/s/h/shirt.jpg', 'website_ids' => [1],
            'media_gallery' => ['images' => [
                7 => ['value_id' => 7, 'file' => '/s/h/shirt.jpg', 'label' => 'Front', 'disabled' => 0,
                    'position' => 1],
            ]],
        ];
        $gallery = static fn (array $image): array => ['images' => ['file_x' => $image + [
            'value_id' => '7', 'file' => '/s/h/shirt.jpg', 'label' => 'Front', 'disabled' => '0',
            'position' => '1', 'media_type' => 'image', 'removed' => '',
        ]]];

        $cases = [
            'a new name'                 => [['name' => 'Blue shirt'], true],
            'another base image'         => [['image' => '/s/h/shirt-2.jpg'], true],
            'another thumbnail'          => [['thumbnail' => '/s/h/shirt-2.jpg'], true],
            'a new caption'              => [['media_gallery' => $gallery(['label' => 'Back'])], true],
            'an image hidden'            => [['media_gallery' => $gallery(['disabled' => '1'])], true],
            'an image removed'           => [['media_gallery' => $gallery(['removed' => '1'])], true],
            'the gallery as posted, unchanged' => [['media_gallery' => $gallery([])], false],
            'a description'              => [['description' => 'New text'], false],
        ];
        $withImages    = $this->policy(listsImages: true);
        $withoutImages = $this->policy(listsImages: false);
        foreach ($cases as $label => [$changes, $expected]) {
            $event = $this->event(
                InvalidationPolicy::EVENT_PRODUCT_SAVE,
                $this->model(Product::class, $loaded, $changes)
            );
            $this->assertSame($expected ? ['products'] : [], $withImages->sitemapTypesAffectedBy($event), $label);
            $this->assertSame([], $withoutImages->sitemapTypesAffectedBy($event), $label . ', no images listed');
        }
    }

    /**
     * A mass update of the name or an image role rebuilds the products only while images are listed.
     *
     * @return void
     */
    public function testMassUpdatesOfImageFieldsRebuildTheProductsWhileImagesAreListed(): void
    {
        $withImages    = $this->policy(listsImages: true);
        $withoutImages = $this->policy(listsImages: false);
        foreach (['name', 'image', 'thumbnail'] as $field) {
            $this->assertSame(['products'], $withImages->sitemapTypesAffectedByAttributeUpdate([$field]), $field);
            $this->assertSame([], $withoutImages->sitemapTypesAffectedByAttributeUpdate([$field]), $field);
        }
        $this->assertSame([], $withImages->sitemapTypesAffectedByAttributeUpdate(['description']));
    }

    /**
     * A product import writes to the database directly, so no save event reports what changed:
     * every import rebuilds the products, once.
     *
     * @return void
     */
    public function testAProductImportRebuildsTheProducts(): void
    {
        $this->assertSame(
            ['products'],
            $this->policy()->sitemapTypesAffectedBy(new Event(['name' => 'catalog_product_import_finish_before']))
        );
    }

    /**
     * Build the policy.
     *
     * @param bool $sitemapsExist Whether a sitemap would be rebuilt
     * @param bool $listsImages Whether a sitemap a change rebuilds lists product images
     * @return InvalidationPolicy
     */
    private function policy(bool $sitemapsExist = true, bool $listsImages = false): InvalidationPolicy
    {
        $sitemaps = $this->createStub(RebuildableSitemaps::class);
        $sitemaps->method('exist')->willReturn($sitemapsExist);
        $sitemaps->method('listsProductImages')->willReturn($listsImages);

        return new InvalidationPolicy($sitemaps, new ChangeInspector());
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
