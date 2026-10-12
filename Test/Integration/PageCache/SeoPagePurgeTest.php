<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\PageCache;

use Magento\Catalog\Api\CategoryManagementInterface;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\PageCache\Model\Cache\Type as FullPageCache;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\CategoryConfig;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsConfigRepository;
use MageOS\Seo\Model\Product\OverrideRepository;
use MageOS\Seo\Model\ResourceModel\CategoryConfig as CategoryConfigResource;
use PHPUnit\Framework\TestCase;

/**
 * Issue #21: a change to SEO settings purges the cached pages built from them — including pages
 * that only inherit them — once it is committed, whichever way it was written, and no other pages.
 *
 * The cached pages are seeded under the tags SeoCacheTagsOutputTest shows the real pages carry, and
 * the purge is the one the built-in cache performs on clean_cache_by_tags. The test's transaction
 * is never committed, so the purges waiting for the commit are run as the commit would run them
 * (CommitsDeferredPurges): what is purged before that is purged too early.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 * @magentoCache full_page enabled
 */
class SeoPagePurgeTest extends TestCase
{
    use CommitsDeferredPurges;

    /**
     * Changing a parent category's SEO purges its own page, its child's and the product's under
     * the child, after the commit — and not an unrelated category's.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['name' => 'Purge Parent'], 'parent')]
    #[DataFixture(CategoryFixture::class, ['name' => 'Purge Child', 'parent_id' => '$parent.id$'], 'child')]
    #[DataFixture(CategoryFixture::class, ['name' => 'Purge Other'], 'other')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$child.id$']], 'product')]
    public function testAParentsSeoChangePurgesItsDependantsOnceCommitted(): void
    {
        [$parent, $child, $other, $product] = $this->ids('parent', 'child', 'other', 'product');
        $this->seed([
            'parent'    => ['mageos_seo_cat_' . $parent],
            'child'     => ['mageos_seo_cat_' . $child, 'mageos_seo_cat_' . $parent],
            'product'   => ['mageos_seo_prod_' . $product, 'mageos_seo_cat_' . $child, 'mageos_seo_cat_' . $parent],
            'unrelated' => ['mageos_seo_cat_' . $other],
        ]);

        Bootstrap::getObjectManager()->get(CategoryConfigRepository::class)
            ->save($parent, ['robots_meta' => 'NOINDEX,FOLLOW'], 0);

        $this->assertCached(['parent', 'child', 'product', 'unrelated'], 'Nothing is purged before the commit.');

        $this->commitDeferredPurges();

        $this->assertPurged(['parent', 'child', 'product']);
        $this->assertCached(['unrelated']);
    }

    /**
     * Saving settings that are already stored changes no page, so purges none — the admin forms
     * save a category's SEO row on every category save.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['name' => 'Purge Parent'], 'parent')]
    public function testASaveThatChangesNothingPurgesNothing(): void
    {
        [$parent] = $this->ids('parent');
        $configs  = Bootstrap::getObjectManager()->get(CategoryConfigRepository::class);
        $configs->save($parent, ['robots_meta' => 'NOINDEX,FOLLOW'], 0);
        $this->commitDeferredPurges();
        $this->seed(['parent' => ['mageos_seo_cat_' . $parent]]);

        $configs->save($parent, ['robots_meta' => 'NOINDEX,FOLLOW'], 0);
        $this->commitDeferredPurges();

        $this->assertCached(['parent']);
    }

    /**
     * A row written straight through the resource model, as an import might, purges too.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['name' => 'Purge Parent'], 'parent')]
    public function testARowWrittenThroughTheResourceModelPurges(): void
    {
        [$parent] = $this->ids('parent');
        $this->seed(['parent' => ['mageos_seo_cat_' . $parent]]);

        $row = Bootstrap::getObjectManager()->create(CategoryConfig::class);
        $row->setData(['category_id' => $parent, 'store_id' => 0, 'item_list_enabled' => 0]);
        Bootstrap::getObjectManager()->get(CategoryConfigResource::class)->save($row);
        $this->commitDeferredPurges();

        $this->assertPurged(['parent']);
    }

    /**
     * A product's own SEO override purges the product's pages.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(ProductFixture::class, as: 'other_product')]
    public function testAProductOverridePurgesTheProductsPages(): void
    {
        [$product, $otherProduct] = $this->ids('product', 'other_product');
        $this->seed([
            'product'   => ['mageos_seo_prod_' . $product],
            'unrelated' => ['mageos_seo_prod_' . $otherProduct],
        ]);

        Bootstrap::getObjectManager()->get(OverrideRepository::class)
            ->save($product, 0, ['robots_meta' => 'NOINDEX,NOFOLLOW']);
        $this->commitDeferredPurges();

        $this->assertPurged(['product']);
        $this->assertCached(['unrelated']);
    }

    /**
     * A CMS page moved to another translation group purges its own pages and those of both groups:
     * the pages of each list their group's members in their heads.
     *
     * @return void
     */
    public function testACmsPageMovedToAnotherGroupPurgesBothGroups(): void
    {
        $pageId  = $this->cmsPage();
        $configs = Bootstrap::getObjectManager()->get(CmsConfigRepository::class);
        $configs->save($pageId, ['hreflang_group' => 'mageos-seo-purge-old']);
        $this->commitDeferredPurges();
        $this->seed([
            'page'      => ['mageos_seo_cms_' . $pageId],
            'old_group' => [$this->groupTag('mageos-seo-purge-old')],
            'new_group' => [$this->groupTag('mageos-seo-purge-new')],
            'unrelated' => [$this->groupTag('mageos-seo-purge-other')],
        ]);

        $configs->save($pageId, ['hreflang_group' => 'mageos-seo-purge-new']);
        $this->commitDeferredPurges();

        $this->assertPurged(['page', 'old_group', 'new_group']);
        $this->assertCached(['unrelated']);
    }

    /**
     * Moving a category changes what everything beneath it inherits, so their pages are purged.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['name' => 'Purge Parent'], 'parent')]
    #[DataFixture(CategoryFixture::class, ['name' => 'Purge Child', 'parent_id' => '$parent.id$'], 'child')]
    #[DataFixture(CategoryFixture::class, ['name' => 'Purge Other'], 'other')]
    public function testMovingACategoryPurgesThePagesBeneathIt(): void
    {
        [$parent, $child, $other] = $this->ids('parent', 'child', 'other');
        $this->seed([
            'child'     => ['mageos_seo_cat_' . $child, 'mageos_seo_cat_' . $parent],
            'unrelated' => ['mageos_seo_cat_' . $other],
        ]);

        Bootstrap::getObjectManager()->get(CategoryManagementInterface::class)->move($child, $other);
        $this->commitDeferredPurges();

        $this->assertPurged(['child']);
        $this->assertCached(['unrelated']);
    }

    /**
     * IDs of the named fixtures.
     *
     * @param string ...$names
     * @return int[]
     */
    private function ids(string ...$names): array
    {
        $storage = DataFixtureStorageManager::getStorage();

        return array_map(static fn (string $name): int => (int) $storage->get($name)->getId(), $names);
    }

    /**
     * A CMS page, rolled back with the test's transaction.
     *
     * @return int
     */
    private function cmsPage(): int
    {
        $page = Bootstrap::getObjectManager()->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => 'mageos-seo-purge-' . uniqid(),
            PageInterface::TITLE      => 'MageOS SEO purge page',
            PageInterface::CONTENT    => '<p>Purge</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => [0],
        ]);
        Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->save($page);

        return (int) $page->getId();
    }

    /**
     * The tag of a translation group, as the pages of the group carry it.
     *
     * @param string $group
     * @return string
     */
    private function groupTag(string $group): string
    {
        return 'mageos_seo_hreflang_' . substr(sha1($group), 0, 16);
    }

    /**
     * Cache a page under each name, with the given tags.
     *
     * @param array<string,string[]> $pages
     * @return void
     */
    private function seed(array $pages): void
    {
        foreach ($pages as $name => $tags) {
            $this->cache()->save('<html>' . $name . '</html>', $this->key($name), $tags);
        }
    }

    /**
     * @param string[] $names
     * @param string $message
     * @return void
     */
    private function assertCached(array $names, string $message = ''): void
    {
        foreach ($names as $name) {
            $this->assertSame('<html>' . $name . '</html>', $this->cache()->load($this->key($name)), trim(
                $message . ' The ' . $name . ' page was purged.'
            ));
        }
    }

    /**
     * @param string[] $names
     * @return void
     */
    private function assertPurged(array $names): void
    {
        foreach ($names as $name) {
            $this->assertFalse($this->cache()->load($this->key($name)), 'The ' . $name . ' page was not purged.');
        }
    }

    /**
     * @param string $name
     * @return string
     */
    private function key(string $name): string
    {
        return 'mageos_seo_purge_test_' . $name;
    }

    /**
     * @return FullPageCache
     */
    private function cache(): FullPageCache
    {
        return Bootstrap::getObjectManager()->get(FullPageCache::class);
    }
}
