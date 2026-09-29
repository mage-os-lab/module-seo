<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Plugin\Catalog\Product\Action;

use Magento\Catalog\Model\Product\Action;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Aeo\Config;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\LlmsInvalidationPolicy;
use MageOS\Seo\Model\Rebuild\ChangeInspector;
use MageOS\Seo\Model\Rebuild\Invalidator;
use MageOS\Seo\Plugin\Catalog\Product\Action\InvalidateJsonlOnMassAttributeUpdate;
use PHPUnit\Framework\TestCase;

class InvalidateJsonlOnMassAttributeUpdateTest extends TestCase
{
    public function testAnyAttributeQueuesJsonlAndNotLlms(): void
    {
        $invalidator = $this->createMock(Invalidator::class);
        $invalidator->expects($this->once())->method('invalidate')->with(FeedRegenerator::GROUP_JSONL);

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
        $invalidator->expects($this->never())->method('invalidate');

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
     * here. isRelevantAttributeUpdate() reads no store or configuration data.
     *
     * @param Invalidator $invalidator
     * @return InvalidateJsonlOnMassAttributeUpdate
     */
    private function plugin(Invalidator $invalidator): InvalidateJsonlOnMassAttributeUpdate
    {
        $policy = new LlmsInvalidationPolicy(
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(Config::class),
            new ChangeInspector()
        );

        return new InvalidateJsonlOnMassAttributeUpdate($invalidator, $policy);
    }
}
