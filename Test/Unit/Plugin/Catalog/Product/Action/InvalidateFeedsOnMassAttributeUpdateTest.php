<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Plugin\Catalog\Product\Action;

use Magento\Catalog\Model\Product\Action;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Model\Feed\InvalidationPolicy;
use MageOS\Seo\Plugin\Catalog\Product\Action\InvalidateFeedsOnMassAttributeUpdate;
use PHPUnit\Framework\TestCase;

class InvalidateFeedsOnMassAttributeUpdateTest extends TestCase
{
    public function testAUrlRelevantAttributeQueuesJsonlAndTheSitemap(): void
    {
        $invalidator = $this->createMock(FeedInvalidator::class);
        $invalidator->expects($this->once())->method('invalidateJsonl');
        $invalidator->expects($this->once())->method('invalidateHreflangSitemap');
        $invalidator->expects($this->never())->method('invalidateLlms');

        $this->plugin($invalidator)->afterUpdateAttributes(
            $this->createStub(Action::class),
            $action = $this->createStub(Action::class),
            [1, 2],
            ['status' => 2],
            0
        );

        $this->assertInstanceOf(Action::class, $action);
    }

    public function testAnAttributeNoFeedShowsInTheSitemapQueuesOnlyJsonl(): void
    {
        $invalidator = $this->createMock(FeedInvalidator::class);
        $invalidator->expects($this->once())->method('invalidateJsonl');
        $invalidator->expects($this->never())->method('invalidateHreflangSitemap');

        $this->plugin($invalidator)->afterUpdateAttributes(
            $this->createStub(Action::class),
            $this->createStub(Action::class),
            [1],
            ['description' => 'New copy', 'meta_title' => 'New title'],
            1
        );
    }

    public function testAnEmptyUpdateQueuesNothing(): void
    {
        $invalidator = $this->createMock(FeedInvalidator::class);
        $invalidator->expects($this->never())->method('invalidateJsonl');
        $invalidator->expects($this->never())->method('invalidateHreflangSitemap');

        $this->plugin($invalidator)->afterUpdateAttributes(
            $this->createStub(Action::class),
            $this->createStub(Action::class),
            [1],
            [],
            0
        );
    }

    public function testTheSubjectsResultIsReturnedUnchanged(): void
    {
        $result = $this->createStub(Action::class);

        $this->assertSame(
            $result,
            $this->plugin($this->createStub(FeedInvalidator::class))->afterUpdateAttributes(
                $this->createStub(Action::class),
                $result,
                [1],
                ['status' => 1],
                0
            )
        );
    }

    /**
     * The plugin over the real policy, so the attribute rules are exercised rather than restated
     * here. isRelevantAttributeUpdate() reads no store or configuration data.
     *
     * @param FeedInvalidator $invalidator
     * @return InvalidateFeedsOnMassAttributeUpdate
     */
    private function plugin(FeedInvalidator $invalidator): InvalidateFeedsOnMassAttributeUpdate
    {
        $policy = new InvalidationPolicy(
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(Config::class)
        );

        return new InvalidateFeedsOnMassAttributeUpdate($invalidator, $policy);
    }
}
