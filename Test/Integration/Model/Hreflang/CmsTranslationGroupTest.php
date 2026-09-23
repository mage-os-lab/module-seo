<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Hreflang;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\PageCache\Model\Cache\Type as FullPageCache;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\Hreflang\UrlRewriteFetcher;
use MageOS\Seo\Model\ResourceModel\UrlRewrite as UrlRewriteResource;
use PHPUnit\Framework\TestCase;

/**
 * CMS pages in one translation group are alternates of one another.
 *
 * CMS pages are not translated in place the way products and categories are: each language is a
 * page of its own, assigned to its store view. MageOS_Hreflang linked them through
 * cms_page.meta_identifier; this module through the translation group. Which page stands for a
 * store view is decided in the query, so these tests drive the real tables.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class CmsTranslationGroupTest extends TestCase
{
    /**
     * IDs of the CMS pages created by the running test.
     *
     * @var int[]|null
     */
    private ?array $createdPageIds = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        // Deleting a page takes its SEO rows with it; the explicit delete covers a page a failed
        // test never reached.
        Bootstrap::getObjectManager()->get(ConfigRepository::class)->deleteForPages($this->createdPageIds);
        $pageRepository = Bootstrap::getObjectManager()->get(PageRepositoryInterface::class);
        foreach ($this->createdPageIds as $pageId) {
            try {
                $pageRepository->deleteById($pageId);
            } catch (\Exception) {
                // Already gone.
            }
        }
        $this->createdPageIds = [];
    }

    /**
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testEachStoreViewLinksToItsOwnTranslation(): void
    {
        [$default, $second] = $this->storeIds();
        $group = $this->newGroup();
        $en    = $this->createPage([$default], $group);
        $de    = $this->createPage([$second], $group);

        $this->assertSame(
            [$default => $en, $second => $de],
            $this->sortedByStore($this->fetcher()->fetchForCmsGroup($group))
        );
    }

    /**
     * A translation made for a store view is that store view's page, even when another page of the
     * group is shown in every store view and has the lower ID.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testAPageMadeForAStoreViewBeatsOneForAllStoreViews(): void
    {
        [$default, $second] = $this->storeIds();
        $group    = $this->newGroup();
        $everyone = $this->createPage([0], $group);
        $german   = $this->createPage([$second], $group);

        $paths = $this->fetcher()->fetchForCmsGroup($group);

        $this->assertSame($everyone, $paths[$default] ?? null);
        $this->assertSame($german, $paths[$second] ?? null);
    }

    /**
     * Two pages of a group made for the same store view is a merchant's mistake, but the answer
     * must still be stable: the lowest page ID.
     *
     * The first page's URL key is changed after the second exists, so its current rewrite is the
     * newer row — otherwise rewrite order and page order agree and the test proves nothing.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testBetweenEqualCandidatesTheLowestPageIdWins(): void
    {
        [, $second] = $this->storeIds();
        $group = $this->newGroup();
        $this->createPage([$second], $group);
        $firstPageId = end($this->createdPageIds);
        $this->createPage([$second], $group);

        $repository = Bootstrap::getObjectManager()->get(PageRepositoryInterface::class);
        $firstPage  = $repository->getById($firstPageId);
        $renamed    = 'mageos-seo-group-renamed-' . uniqid();
        $firstPage->setIdentifier($renamed);
        $repository->save($firstPage);

        $this->assertSame($renamed, $this->fetcher()->fetchForCmsGroup($group)[$second] ?? null);
    }

    /**
     * The S1 published filter still applies inside a group: an unpublished translation is not a
     * candidate, and the store view falls back to the group's all-store-views page.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testAnUnpublishedTranslationIsNotACandidate(): void
    {
        [, $second] = $this->storeIds();
        $group    = $this->newGroup();
        $everyone = $this->createPage([0], $group);
        $this->createPage([$second], $group, false);

        $this->assertSame($everyone, $this->fetcher()->fetchForCmsGroup($group)[$second] ?? null);
    }

    /**
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testTheSitemapStreamListsAGroupAsOneEntry(): void
    {
        [$default, $second] = $this->storeIds();
        $group     = $this->newGroup();
        $en        = $this->createPage([$default], $group);
        $de        = $this->createPage([$second], $group);
        $ungrouped = $this->createPage([$default]);

        $entries = iterator_to_array(
            $this->fetcher()->streamAllForType(UrlRewriteResource::TYPE_CMS_PAGE, [$default, $second]),
            false
        );

        $this->assertContains([$default => $en, $second => $de], array_map([$this, 'sortedByStore'], $entries));
        $this->assertNotContains([$second => $de], $entries, 'A translation is not also an entry of its own.');
        $this->assertContains([$default => $ungrouped], $entries, 'A page outside any group is its own entry.');
    }

    /**
     * Deleting a translation changes the alternates of the pages left in its group, so their cached
     * copies have to go. Deletion runs outside the admin form too — a REST call, an import — so this
     * goes through the repository.
     *
     * @magentoCache full_page enabled
     * @return void
     */
    public function testDeletingATranslationPurgesTheOthersFromTheFullPageCache(): void
    {
        $group = $this->newGroup();
        $this->createPage([0], $group);
        $memberId = end($this->createdPageIds);
        $this->createPage([0], $group);
        $deletedId = end($this->createdPageIds);

        $cache = Bootstrap::getObjectManager()->get(FullPageCache::class);
        $cache->save('<html>old alternates</html>', 'mageos_seo_group_member', ['cms_p_' . $memberId]);

        Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->deleteById($deletedId);

        $this->assertFalse($cache->load('mageos_seo_group_member'));
    }

    /**
     * Create a CMS page, optionally in a translation group, and return its request path.
     *
     * @param int[] $storeIds [0] assigns it to all store views
     * @param string|null $group
     * @param bool $isActive
     * @return string
     */
    private function createPage(array $storeIds, ?string $group = null, bool $isActive = true): string
    {
        $identifier = 'mageos-seo-group-' . uniqid();

        $page = Bootstrap::getObjectManager()->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => $identifier,
            PageInterface::TITLE      => 'MageOS SEO translation group page',
            PageInterface::CONTENT    => '<p>Group</p>',
            PageInterface::IS_ACTIVE  => $isActive ? 1 : 0,
            'stores'                  => $storeIds,
        ]);
        Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->save($page);

        $pageId                 = (int) $page->getId();
        $this->createdPageIds[] = $pageId;

        if ($group !== null) {
            Bootstrap::getObjectManager()->get(ConfigRepository::class)
                ->save($pageId, ['hreflang_group' => $group]);
        }

        return $identifier;
    }

    /**
     * A group no earlier run can have left pages in.
     *
     * @return string
     */
    private function newGroup(): string
    {
        return 'mageos-seo-test-' . uniqid();
    }

    /**
     * IDs of the default store view and the fixture's second one.
     *
     * @return int[]
     */
    private function storeIds(): array
    {
        return [
            (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default')->getId(),
            (int) DataFixtureStorageManager::getStorage()->get('second_store')->getId(),
        ];
    }

    /**
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    private function sortedByStore(array $paths): array
    {
        ksort($paths);

        return $paths;
    }

    /**
     * @return UrlRewriteFetcher
     */
    private function fetcher(): UrlRewriteFetcher
    {
        return Bootstrap::getObjectManager()->create(UrlRewriteFetcher::class);
    }
}
