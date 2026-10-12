<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\PageCache;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\CacheContextFactory;
use MageOS\Seo\Model\PageCache\CacheTagCleaner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The purge is core's clean_cache_by_tags, carrying a CacheContext of its own.
 *
 * CacheContextFactory is generated: Infection runs this module's tests without Magento's code
 * generator, alone, and excludes this group; the unit job, which runs inside an installation, does
 * not. The purge itself is tested against the real cache by Test/Integration/PageCache.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class CacheTagCleanerTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheTagsArePurgedThroughAFreshCacheContext(): void
    {
        $shared = new CacheContext();
        $shared->registerTags(['cat_p_1']);
        $fresh   = new CacheContext();
        $factory = $this->createStub(CacheContextFactory::class);
        $factory->method('create')->willReturn($fresh);

        $events = $this->createMock(EventManagerInterface::class);
        $events->expects($this->once())->method('dispatch')->with(
            'clean_cache_by_tags',
            $this->callback(static fn (array $data): bool => $data['object'] === $fresh)
        );

        (new CacheTagCleaner($factory, $events))->clean(['mageos_seo_cat_3', 'mageos_seo_cat_2']);

        $this->assertSame(['mageos_seo_cat_3', 'mageos_seo_cat_2'], $fresh->getIdentities());
        $this->assertSame(['cat_p_1'], $shared->getIdentities(), 'What others registered is not purged with it.');
    }
}
