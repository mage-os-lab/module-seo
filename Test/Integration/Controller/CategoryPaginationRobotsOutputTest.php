<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Framework\App\Cache\Manager as CacheManager;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;

/**
 * Issue #34: on a category's page 2 and later, Override Robots on Paginated Pages can add
 * restrictions to the category's own directive, but never relax one.
 *
 * The robots meta is read from the rendered page, as SearchResultsRobotsOutputTest reads it.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CategoryPaginationRobotsOutputTest extends AbstractController
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
     * @magentoConfigFixture current_store mageos_seo_general/robots_meta/paginated_enabled 1
     * @magentoConfigFixture current_store mageos_seo_general/robots_meta/paginated_robots INDEX,FOLLOW
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['name' => 'Pagination Robots'], 'category')]
    public function testOnPageTwoACategorysOwnNoindexIsKept(): void
    {
        $category = $this->id('category');
        $this->categoryRobots($category, 'NOINDEX,FOLLOW');

        $this->assertSame('NOINDEX,FOLLOW', $this->robotsOf($category, 2));
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/robots_meta/paginated_enabled 1
     * @magentoConfigFixture current_store mageos_seo_general/robots_meta/paginated_robots INDEX,FOLLOW
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['name' => 'Pagination Robots Parent'], 'parent')]
    #[DataFixture(CategoryFixture::class, ['name' => 'Pagination Robots Child', 'parent_id' => '$parent.id$'], 'child')]
    public function testOnPageTwoANoindexInheritedFromTheParentIsKept(): void
    {
        $this->categoryRobots($this->id('parent'), 'NOINDEX,FOLLOW');

        $this->assertSame('NOINDEX,FOLLOW', $this->robotsOf($this->id('child'), 2));
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/robots_meta/paginated_enabled 1
     * @magentoConfigFixture current_store mageos_seo_general/robots_meta/paginated_robots NOINDEX,FOLLOW
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['name' => 'Pagination Robots'], 'category')]
    public function testOnPageTwoThePaginatedValueStillAddsItsNoindex(): void
    {
        $category = $this->id('category');
        $this->categoryRobots($category, 'INDEX,NOFOLLOW');

        $this->assertSame('NOINDEX,NOFOLLOW', $this->robotsOf($category, 2));
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/robots_meta/paginated_enabled 1
     * @magentoConfigFixture current_store mageos_seo_general/robots_meta/paginated_robots NOINDEX,FOLLOW
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['name' => 'Pagination Robots'], 'category')]
    public function testPageOneIsTheCategorysOwnDirective(): void
    {
        $category = $this->id('category');
        $this->categoryRobots($category, 'INDEX,NOFOLLOW');

        $this->assertSame('INDEX,NOFOLLOW', $this->robotsOf($category, 1));
    }

    /**
     * Set a category's robots for all store views.
     *
     * @param int $categoryId
     * @param string $robots
     * @return void
     */
    private function categoryRobots(int $categoryId, string $robots): void
    {
        Bootstrap::getObjectManager()->get(CategoryConfigRepository::class)
            ->save($categoryId, ['robots_meta' => $robots], 0);
    }

    /**
     * Render a page of a category and return its robots meta content.
     *
     * @param int $categoryId
     * @param int $page
     * @return string
     */
    private function robotsOf(int $categoryId, int $page): string
    {
        if ($page > 1) {
            $this->getRequest()->setQueryValue('p', (string) $page);
        }
        $this->dispatch('catalog/category/view/id/' . $categoryId);
        $body = (string) $this->getResponse()->getBody();

        return preg_match('#<meta name="robots" content="([^"]*)"#', $body, $match) === 1 ? $match[1] : '';
    }

    /**
     * The ID of an entity created by a data fixture.
     *
     * @param string $name
     * @return int
     */
    private function id(string $name): int
    {
        return (int) DataFixtureStorageManager::getStorage()->get($name)->getId();
    }
}
