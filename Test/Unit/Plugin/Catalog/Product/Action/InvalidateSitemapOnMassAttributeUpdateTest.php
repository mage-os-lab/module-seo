<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Plugin\Catalog\Product\Action;

use Magento\Catalog\Model\Product\Action;
use MageOS\Seo\Model\Rebuild\ChangeInspector;
use MageOS\Seo\Model\Rebuild\Invalidator;
use MageOS\Seo\Model\Sitemap\InvalidationPolicy;
use MageOS\Seo\Model\Sitemap\RebuildableSitemaps;
use MageOS\Seo\Plugin\Catalog\Product\Action\InvalidateSitemapOnMassAttributeUpdate;
use PHPUnit\Framework\TestCase;

class InvalidateSitemapOnMassAttributeUpdateTest extends TestCase
{
    public function testAUrlRelevantAttributeQueuesTheProductsSitemap(): void
    {
        $invalidator = $this->createMock(Invalidator::class);
        $invalidator->expects($this->once())->method('invalidateSitemap')->with('products');

        $this->plugin($invalidator)->afterUpdateAttributes(
            $this->createStub(Action::class),
            $this->createStub(Action::class),
            [1, 2],
            ['status' => 2],
            0
        );
    }

    public function testAnAttributeNoSitemapShowsQueuesNothing(): void
    {
        $invalidator = $this->createMock(Invalidator::class);
        $invalidator->expects($this->never())->method('invalidateSitemap');

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
        $invalidator = $this->createMock(Invalidator::class);
        $invalidator->expects($this->never())->method('invalidateSitemap');

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
            $this->plugin($this->createStub(Invalidator::class))->afterUpdateAttributes(
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
     * here. sitemapTypesAffectedByAttributeUpdate() reads no sitemap.
     *
     * @param Invalidator $invalidator
     * @return InvalidateSitemapOnMassAttributeUpdate
     */
    private function plugin(Invalidator $invalidator): InvalidateSitemapOnMassAttributeUpdate
    {
        $policy = new InvalidationPolicy($this->createStub(RebuildableSitemaps::class), new ChangeInspector());

        return new InvalidateSitemapOnMassAttributeUpdate($invalidator, $policy);
    }
}
