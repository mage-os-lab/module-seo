<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Cms;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\CacheContextFactory;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\Cms\TranslationGroupCache;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Which tags are purged. That the built-in full page cache actually drops the pages is covered
 * against the real cache by the translation group integration tests.
 *
 * The cache context factory is one of Magento's generated classes, so this test needs an
 * installation to have generated it. The mutation-testing run works from the module directory
 * alone and excludes this group; the unit job, which runs inside an installation, does not.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class TranslationGroupCacheTest extends TestCase
{
    public function testEveryPageOfTheGroupsIsPurgedByItsCmsPageTag(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('getPageIdsInGroups')
            ->with(['old-group', 'new-group'])
            ->willReturn([11, 12, 13]);

        $purged = null;
        $events = $this->createMock(EventManagerInterface::class);
        $events->expects($this->once())->method('dispatch')->willReturnCallback(
            static function (string $name, array $data) use (&$purged): void {
                if ($name === 'clean_cache_by_tags') {
                    $purged = $data['object']->getIdentities();
                }
            }
        );

        $this->cache($repository, $events)->purge(['old-group', 'new-group']);

        $this->assertSame(['cms_p_11', 'cms_p_12', 'cms_p_13'], $purged);
    }

    public function testNoGroupMeansNoGroupToLookUp(): void
    {
        // A page joining its first group, or deleted while in none, has a null on one side.
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('getPageIdsInGroups')->with(['new-group'])->willReturn([]);

        $this->cache($repository, $this->createStub(EventManagerInterface::class))->purge([null, 'new-group']);
    }

    public function testAGroupWithNoPagesPurgesNothing(): void
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getPageIdsInGroups')->willReturn([]);
        $events = $this->createMock(EventManagerInterface::class);
        $events->expects($this->never())->method('dispatch');

        $this->cache($repository, $events)->purge(['empty-group']);
    }

    /**
     * @param ConfigRepository $repository
     * @param EventManagerInterface $events
     * @return TranslationGroupCache
     */
    private function cache(ConfigRepository $repository, EventManagerInterface $events): TranslationGroupCache
    {
        $factory = $this->createStub(CacheContextFactory::class);
        $factory->method('create')->willReturnCallback(static fn (): CacheContext => new CacheContext());

        return new TranslationGroupCache($repository, $factory, $events);
    }
}
