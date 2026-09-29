<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\TestCase\AbstractController;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsConfigRepository;
use MageOS\Seo\Model\Config;

/**
 * F4: a page whose robots directive is NOINDEX is left out of the sitemap.
 *
 * "Its directive" is the one the page is served with, so each test generates a real sitemap and
 * then renders the page in question: what is compared is the sitemap's contents and the page's
 * head, not two readings of the same configuration.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class RobotsTest extends AbstractController
{
    use GeneratesSitemaps;

    /**
     * IDs of the CMS pages created by the running test.
     *
     * @var int[]|null
     */
    private ?array $createdPageIds = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemaps();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeGeneratedSitemaps();

        // Product and category rows go with their fixtures (foreign keys); CMS page rows have none.
        $this->_objectManager->get(CmsConfigRepository::class)->deleteForPages($this->createdPageIds);
        $pageRepository = $this->_objectManager->get(PageRepositoryInterface::class);
        foreach ($this->createdPageIds as $pageId) {
            try {
                $pageRepository->deleteById($pageId);
            } catch (\Exception) {
                // Already gone.
            }
        }
        $this->createdPageIds = [];

        parent::tearDown();
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'hidden')]
    #[DataFixture(ProductFixture::class, as: 'listed')]
    public function testAProductServedNoindexIsLeftOut(): void
    {
        $hidden = DataFixtureStorageManager::getStorage()->get('hidden');
        $this->_objectManager->get(ProductOverrideRepository::class)
            ->save((int) $hidden->getId(), 0, ['robots_meta' => 'NOINDEX,FOLLOW']);

        $locs = $this->locs();

        $this->assertFalse($this->lists($locs, $hidden->getUrlKey() . '.html'), 'The NOINDEX product is left out.');
        $this->assertTrue($this->lists($locs, $this->urlKey('listed') . '.html'), 'Its neighbour is listed.');
        $this->assertTrue($this->lists($locs, ''), 'So is the home page, which has no directive of its own.');
        $this->assertSame('NOINDEX,FOLLOW', $this->robotsOf('catalog/product/view/id/' . $hidden->getId()));
    }

    /**
     * A category inherits its parent's directive — on its page and so in the sitemap.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'parent')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$parent.id$'], as: 'child')]
    #[DataFixture(CategoryFixture::class, as: 'sibling')]
    public function testACategoryInheritingNoindexFromItsParentIsLeftOut(): void
    {
        $storage = DataFixtureStorageManager::getStorage();
        $this->_objectManager->get(CategoryConfigRepository::class)
            ->save((int) $storage->get('parent')->getId(), ['robots_meta' => 'NOINDEX,FOLLOW']);

        $locs = $this->locs();

        $this->assertFalse($this->lists($locs, $this->urlKey('parent') . '.html'), 'The parent is left out.');
        $this->assertFalse($this->lists($locs, $this->urlKey('child') . '.html'), 'So is the child, inheriting.');
        $this->assertTrue($this->lists($locs, $this->urlKey('sibling') . '.html'), 'An unrelated category is listed.');
        $this->assertSame(
            'NOINDEX,FOLLOW',
            $this->robotsOf('catalog/category/view/id/' . $storage->get('child')->getId())
        );
    }

    /**
     * @return void
     */
    public function testACmsPageServedNoindexIsLeftOut(): void
    {
        [$hiddenId, $hidden] = $this->page(['robots_meta' => 'NOINDEX,FOLLOW']);
        [, $listed]          = $this->page(null);

        $locs = $this->locs();

        $this->assertFalse($this->lists($locs, $hidden), 'The NOINDEX page is left out.');
        $this->assertTrue($this->lists($locs, $listed), 'Its neighbour is listed.');
        $this->assertSame('NOINDEX,FOLLOW', $this->robotsOf('cms/page/view/page_id/' . $hiddenId));
    }

    /**
     * The home page is its CMS page: that page's directive decides whether the store's base URL is
     * listed.
     *
     * @return void
     */
    public function testTheHomePageFollowsItsCmsPage(): void
    {
        [, $home] = $this->page(['robots_meta' => 'NOINDEX,FOLLOW']);
        $this->setStoreConfig(\Magento\Cms\Helper\Page::XML_PATH_HOME_PAGE, $home);

        $this->assertFalse($this->lists($this->locs(), ''), 'The home page is left out.');
        $this->assertSame('NOINDEX,FOLLOW', $this->robotsOf('/'));
    }

    /**
     * With no directive of this module's anywhere, core's Design → Search Engine Robots decides —
     * on the pages and in the sitemap. A staging store set to NOINDEX lists nothing.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'listed')]
    public function testAStoreSetToNoindexHasAnEmptySitemap(): void
    {
        $this->setStoreConfig(Config::XML_ROBOTS_CORE_DEFAULT, 'NOINDEX,NOFOLLOW');
        $productId = (int) DataFixtureStorageManager::getStorage()->get('listed')->getId();

        $this->assertSame([], $this->locs());
        $this->assertSame('NOINDEX,NOFOLLOW', $this->robotsOf('catalog/product/view/id/' . $productId));
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'hidden')]
    public function testNoindexPagesAreListedWhenTheSettingIsOff(): void
    {
        $hidden = DataFixtureStorageManager::getStorage()->get('hidden');
        $this->_objectManager->get(ProductOverrideRepository::class)
            ->save((int) $hidden->getId(), 0, ['robots_meta' => 'NOINDEX,FOLLOW']);
        $this->setStoreConfig(Config::XML_SITEMAP_EXCLUDE_NOINDEX, '0');

        $this->assertTrue($this->lists($this->locs(), $hidden->getUrlKey() . '.html'));
    }

    /**
     * Every `<loc>` of a sitemap generated for the default store view.
     *
     * @return string[]
     */
    private function locs(): array
    {
        $locs = [];
        foreach ($this->urlRows($this->generateFor($this->defaultStoreId())) as $row) {
            preg_match('#<loc>([^<]*)</loc>#', $row, $loc);
            $locs[] = html_entity_decode($loc[1] ?? '');
        }

        return $locs;
    }

    /**
     * Whether any `<loc>` is the given path under the store's base URL ('' for the home page).
     *
     * @param string[] $locs
     * @param string $path
     * @return bool
     */
    private function lists(array $locs, string $path): bool
    {
        foreach ($locs as $loc) {
            if (str_ends_with($loc, '/index.php/' . $path) || ($path !== '' && str_ends_with($loc, '/' . $path))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render the page and return the robots directive its head carries.
     *
     * One page per test: the layout and the page configuration are shared for the whole request.
     *
     * @param string $uri
     * @return string
     */
    private function robotsOf(string $uri): string
    {
        $this->dispatch($uri);
        $body = (string) $this->getResponse()->getBody();
        $this->assertStringContainsString('</head>', $body, $uri . ' rendered no page.');

        preg_match('#<meta name="robots" content="([^"]*)"#', $body, $robots);

        return $robots[1] ?? '';
    }

    /**
     * A CMS page in every store view, with optional SEO configuration; returns its ID and identifier.
     *
     * @param mixed[]|null $config
     * @return array{int,string}
     */
    private function page(?array $config): array
    {
        $identifier = 'mageos-seo-robots-' . uniqid();

        $page = $this->_objectManager->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => $identifier,
            PageInterface::TITLE      => 'MageOS SEO sitemap robots',
            PageInterface::CONTENT    => '<p>Robots</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => [0],
        ]);
        $this->_objectManager->get(PageRepositoryInterface::class)->save($page);

        $pageId                 = (int) $page->getId();
        $this->createdPageIds[] = $pageId;
        if ($config !== null) {
            $this->_objectManager->get(CmsConfigRepository::class)->save($pageId, $config);
        }

        return [$pageId, $identifier];
    }

    /**
     * @param string $fixture
     * @return string
     */
    private function urlKey(string $fixture): string
    {
        return (string) DataFixtureStorageManager::getStorage()->get($fixture)->getUrlKey();
    }
}
