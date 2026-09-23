<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Cms;

use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\ResourceModel\CmsPageConfig\CollectionFactory;
use MageOS\Seo\Model\RobotsMeta\Provider\CmsPageRobotsProvider;
use PHPUnit\Framework\TestCase;

/**
 * A robots directive set on a single CMS page reaches that page.
 *
 * CMS pages were the one page type with no per-entity robots override — products and categories
 * have had one throughout — which is what MageOS_MetaRobotsTag's per-page flags provided. These
 * tests drive the real table and the real provider, because the store-default fallback is the
 * part that used to be the whole feature.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class PageRobotsOverrideTest extends TestCase
{
    /**
     * Page IDs this test wrote configuration for.
     *
     * @var int[]|null
     */
    private ?array $writtenPageIds = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Bootstrap::getObjectManager()
            ->get(\Magento\Framework\App\RequestInterface::class)
            ->setParams([]);

        if ($this->writtenPageIds !== []) {
            $collection = Bootstrap::getObjectManager()->create(CollectionFactory::class)->create();
            $collection->addFieldToFilter('page_id', ['in' => $this->writtenPageIds]);
            foreach ($collection as $config) {
                $config->delete();
            }
        }
        $this->writtenPageIds = [];

        parent::tearDown();
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/robots_meta/cms_page_default INDEX,FOLLOW
     * @return void
     */
    public function testAPagesOwnDirectiveWinsOverTheStoreDefault(): void
    {
        $pageId = $this->pageWithConfig(['robots_meta' => 'NOINDEX,FOLLOW,noarchive']);

        $this->assertSame('NOINDEX,FOLLOW,noarchive', $this->resolveFor($pageId));
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/robots_meta/cms_page_default INDEX,FOLLOW
     * @return void
     */
    public function testAPageWithoutOneFollowsTheStoreDefault(): void
    {
        $pageId = $this->pageWithConfig(null);

        $this->assertSame('INDEX,FOLLOW', $this->resolveFor($pageId));
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/robots_meta/cms_page_default INDEX,FOLLOW
     * @return void
     */
    public function testAnEmptyOverrideIsNotADirective(): void
    {
        // "Use Magento Default" stores an empty value; it must not read as a directive that
        // silences the store default.
        $pageId = $this->pageWithConfig(['robots_meta' => null]);

        $this->assertSame('INDEX,FOLLOW', $this->resolveFor($pageId));
    }

    /**
     * @return void
     */
    public function testConfigurationIsRemovedWithItsPage(): void
    {
        // The table carries no foreign key to cms_page, so nothing but the observer clears this —
        // and a later page reusing the ID would otherwise inherit a stranger's directive.
        $page = $this->createPage();
        $this->repository()->save((int) $page->getId(), ['robots_meta' => 'NOINDEX,FOLLOW']);
        $pageId = (int) $page->getId();

        Bootstrap::getObjectManager()->get(\Magento\Cms\Api\PageRepositoryInterface::class)->delete($page);

        $this->assertSame([], $this->repository()->getForPage($pageId));
    }

    /**
     * Create a CMS page, optionally with SEO configuration, and put it on the request.
     *
     * CmsPageResolver reads `page_id` from the request, falling back to the configured home page
     * identifier — it does not consult the registry — so the request is what decides which page
     * the provider is asked about.
     *
     * @param mixed[]|null $config
     * @return int
     */
    private function pageWithConfig(?array $config): int
    {
        $page   = $this->createPage();
        $pageId = (int) $page->getId();

        if ($config !== null) {
            $this->repository()->save($pageId, $config);
        }

        Bootstrap::getObjectManager()
            ->get(\Magento\Framework\App\RequestInterface::class)
            ->setParams(['page_id' => $pageId]);

        return $pageId;
    }

    /**
     * A saved CMS page, tracked for cleanup.
     *
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    private function createPage(): \Magento\Cms\Api\Data\PageInterface
    {
        $objectManager = Bootstrap::getObjectManager();

        /** @var \Magento\Cms\Model\Page $page */
        $page = $objectManager->create(\Magento\Cms\Model\Page::class);
        $page->setTitle('MageOS SEO robots override')
            ->setIdentifier('mageos-seo-robots-' . uniqid('', false))
            ->setIsActive(true)
            ->setContent('<p>Test</p>')
            ->setPageLayout('1column')
            ->setStores([0]);

        $page = $objectManager->get(\Magento\Cms\Api\PageRepositoryInterface::class)->save($page);

        $this->writtenPageIds[] = (int) $page->getId();

        return $page;
    }

    /**
     * What the provider resolves for the registered page.
     *
     * @param int $pageId
     * @return string|null
     */
    private function resolveFor(int $pageId): ?string
    {
        $objectManager = Bootstrap::getObjectManager();

        $storeId = (int) $objectManager
            ->get(\Magento\Store\Model\StoreManagerInterface::class)
            ->getStore()
            ->getId();

        // Both collaborators memoise — the resolver on first resolve, the repository per page and
        // store — and both are shared, so a previous test's answer would otherwise stand.
        return $objectManager->create(CmsPageRobotsProvider::class, [
            'cmsPageResolver'         => $objectManager->create(\MageOS\Seo\Model\Cms\CmsPageResolver::class),
            'cmsPageConfigRepository' => $this->repository(),
        ])->getRobots($storeId);
    }

    /**
     * @return ConfigRepository
     */
    private function repository(): ConfigRepository
    {
        return Bootstrap::getObjectManager()->create(ConfigRepository::class);
    }
}
