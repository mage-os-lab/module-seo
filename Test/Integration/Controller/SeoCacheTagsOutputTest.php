<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\App\Cache\Manager as CacheManager;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsConfigRepository;

/**
 * Issue #21: a page carries the cache tags of the SEO settings it was built from, so that a change
 * to those settings purges it — including settings it only inherits.
 *
 * A category inherits its parent's SEO settings field by field, and a product's schema reads its
 * category's, so the parent's tag has to be on the child category's page and on the product's. The
 * tags are read from the X-Magento-Tags header, which is what the built-in cache stores a page
 * under and what Varnish's bans match.
 *
 * As core's own product page test does: application isolation, and an empty page cache before each
 * dispatch. Isolation renews the objects but not the cache's storage, and a dispatch answered from
 * an earlier test's cached page leaves the test's own response empty.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 * @magentoCache full_page enabled
 */
class SeoCacheTagsOutputTest extends AbstractController
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::getObjectManager()->get(CacheManager::class)->clean(['full_page']);
    }

    /**
     * A child category's page carries its own SEO tag and its parent's.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['name' => 'SEO Tags Parent'], 'parent')]
    #[DataFixture(CategoryFixture::class, ['name' => 'SEO Tags Child', 'parent_id' => '$parent.id$'], 'child')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$child.id$']], 'product')]
    public function testAChildCategoryPageCarriesItsParentsSeoTag(): void
    {
        $storage = DataFixtureStorageManager::getStorage();
        $parent  = (int) $storage->get('parent')->getId();
        $child   = (int) $storage->get('child')->getId();

        $this->dispatch('catalog/category/view/id/' . $child);

        $tags = $this->pageTags();
        $this->assertContains('mageos_seo_cat_' . $child, $tags);
        $this->assertContains('mageos_seo_cat_' . $parent, $tags);
    }

    /**
     * A product's page carries its own SEO tag and those of its category and the category's parent.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['name' => 'SEO Tags Parent'], 'parent')]
    #[DataFixture(CategoryFixture::class, ['name' => 'SEO Tags Child', 'parent_id' => '$parent.id$'], 'child')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$child.id$']], 'product')]
    public function testAProductPageCarriesTheSeoTagsOfItsCategoryAndItsParent(): void
    {
        $storage = DataFixtureStorageManager::getStorage();
        $product = (int) $storage->get('product')->getId();

        $this->dispatch('catalog/product/view/id/' . $product);

        $tags = $this->pageTags();
        $this->assertContains('mageos_seo_prod_' . $product, $tags);
        $this->assertContains('mageos_seo_cat_' . (int) $storage->get('child')->getId(), $tags);
        $this->assertContains('mageos_seo_cat_' . (int) $storage->get('parent')->getId(), $tags);
    }

    /**
     * A CMS page in a translation group carries its own SEO tag and its group's: the other pages of
     * the group are listed in its head.
     *
     * The page is made here rather than by core's CMS page fixture, which 2.4.7 does not have.
     *
     * @return void
     */
    public function testACmsPageInATranslationGroupCarriesItsGroupsTag(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $page          = $objectManager->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => 'mageos-seo-tags-' . uniqid(),
            PageInterface::TITLE      => 'MageOS SEO cache tags page',
            PageInterface::CONTENT    => '<p>Tags</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => [0],
        ]);
        $pages = $objectManager->get(PageRepositoryInterface::class);
        $pages->save($page);
        $pageId = (int) $page->getId();
        $group  = 'mageos-seo-tags-' . uniqid();

        try {
            $objectManager->get(CmsConfigRepository::class)->save($pageId, ['hreflang_group' => $group]);
            $this->dispatch('cms/page/view/page_id/' . $pageId);
        } finally {
            // Deleting the page takes its SEO rows with it.
            $pages->deleteById($pageId);
        }

        $tags = $this->pageTags();
        $this->assertContains('mageos_seo_cms_' . $pageId, $tags);
        $this->assertContains('mageos_seo_hreflang_' . substr(sha1($group), 0, 16), $tags);
    }

    /**
     * The cache tags the response was given.
     *
     * @return string[]
     */
    private function pageTags(): array
    {
        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $header   = $response->getHeader('X-Magento-Tags');
        $this->assertNotFalse($header, \sprintf(
            'The page is cacheable and has cache tags. Status %d; headers sent: %s.',
            $response->getHttpResponseCode(),
            implode(', ', array_keys($response->getHeaders()->toArray()))
        ));

        return explode(',', (string) $header->getFieldValue());
    }
}
