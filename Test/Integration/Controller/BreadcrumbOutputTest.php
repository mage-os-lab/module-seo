<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Model\Session as CatalogSession;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * A product page's BreadcrumbList on Luma, whose breadcrumbs block keeps its crumbs to itself.
 *
 * The trail follows the URL, as Luma's own trail does: a category-path URL gives the category trail,
 * a plain product URL gives Home › Product. It never comes from the visitor's session — core falls
 * back to the category the visitor last browsed, and the page cache would then serve that one
 * visitor's trail to everyone.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class BreadcrumbOutputTest extends AbstractController
{
    private const PRODUCT = 'MageOS SEO Crumb';

    /**
     * Forget the category the test pretended to have browsed.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Bootstrap::getObjectManager()->get(CatalogSession::class)->unsLastVisitedCategoryId();
        parent::tearDown();
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['name' => 'Crumb Shelf'], 'category')]
    #[DataFixture(
        ProductFixture::class,
        ['name' => self::PRODUCT, 'category_ids' => ['$category.id$']],
        'product'
    )]
    public function testAPlainProductUrlIsHomeThenTheProductWhateverTheSessionBrowsed(): void
    {
        $storage = DataFixtureStorageManager::getStorage();
        Bootstrap::getObjectManager()->get(CatalogSession::class)
            ->setLastVisitedCategoryId((int) $storage->get('category')->getId());

        $this->dispatch('catalog/product/view/id/' . (int) $storage->get('product')->getId());

        $this->assertSame(['Home', self::PRODUCT], $this->crumbNames());
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['name' => 'Crumb Shelf'], 'category')]
    #[DataFixture(
        ProductFixture::class,
        ['name' => self::PRODUCT, 'category_ids' => ['$category.id$']],
        'product'
    )]
    public function testACategoryPathUrlIsTheCategoryTrail(): void
    {
        $storage = DataFixtureStorageManager::getStorage();

        $this->dispatch(
            'catalog/product/view/id/' . (int) $storage->get('product')->getId()
            . '/category/' . (int) $storage->get('category')->getId()
        );

        $this->assertSame(['Home', 'Crumb Shelf', self::PRODUCT], $this->crumbNames());
    }

    /**
     * The names in the page's BreadcrumbList, in order.
     *
     * @return string[]
     */
    private function crumbNames(): array
    {
        $body = (string) $this->getResponse()->getBody();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $body, $matches);
        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);
            foreach (\is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded] as $node) {
                if (\is_array($node) && ($node['@type'] ?? null) === 'BreadcrumbList') {
                    return array_column($node['itemListElement'] ?? [], 'name');
                }
            }
        }

        return [];
    }
}
