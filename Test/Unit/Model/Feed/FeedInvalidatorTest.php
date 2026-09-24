<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Feed;

use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\InvalidationPolicy;
use MageOS\Seo\Model\Feed\RegenerationRequester;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use PHPUnit\Framework\TestCase;

class FeedInvalidatorTest extends TestCase
{
    public function testEachInvalidationQueuesTheMatchingRebuild(): void
    {
        $requested = [];
        $invalidator = $this->invalidator(FeedRegenerator::GROUPS, $requested);

        $invalidator->invalidateLlms();
        $invalidator->invalidateJsonl();

        $this->assertSame([FeedRegenerator::GROUP_LLMS, FeedRegenerator::GROUP_JSONL], $requested);
    }

    public function testGroupsNoStoreViewCanBuildAreNotQueued(): void
    {
        $requested = [];
        $invalidator = $this->invalidator([FeedRegenerator::GROUP_LLMS], $requested);

        $invalidator->invalidateLlms();
        $invalidator->invalidateJsonl();

        $this->assertSame([FeedRegenerator::GROUP_LLMS], $requested);
    }

    public function testASitemapTypeIsQueuedAsItsGroupWhenASitemapWouldBeRebuilt(): void
    {
        $requested   = [];
        $invalidator = $this->invalidator(['sitemap-products', 'sitemap-*'], $requested);

        $invalidator->invalidateSitemap('products');
        $invalidator->invalidateSitemap('*');
        $invalidator->invalidateSitemap('pages');

        $this->assertSame(['sitemap-products', 'sitemap-*'], $requested);
    }

    public function testInvalidationTouchesNeitherFilesNorCaches(): void
    {
        // Served files and cached responses are left alone: FeedInvalidator depends on nothing
        // that could delete or purge, so a save can no longer take a feed offline.
        $constructor = (new \ReflectionClass(FeedInvalidator::class))->getConstructor();
        $this->assertNotNull($constructor);

        $dependencies = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters()
        );
        $this->assertSame(
            [RegenerationRequester::class, InvalidationPolicy::class, RebuildGroup::class],
            $dependencies
        );
    }

    /**
     * Build an invalidator whose policy enables only the given groups, recording requests.
     *
     * @param string[] $enabledGroups
     * @param string[] $requested Receives the requested groups
     * @return FeedInvalidator
     */
    private function invalidator(array $enabledGroups, array &$requested): FeedInvalidator
    {
        $requester = $this->createStub(RegenerationRequester::class);
        $requester->method('request')->willReturnCallback(
            static function (string $group) use (&$requested): void {
                $requested[] = $group;
            }
        );
        $policy = $this->createStub(InvalidationPolicy::class);
        $policy->method('isGroupEnabled')->willReturnCallback(
            static fn (string $group): bool => \in_array($group, $enabledGroups, true)
        );

        return new FeedInvalidator($requester, $policy, new RebuildGroup());
    }
}
