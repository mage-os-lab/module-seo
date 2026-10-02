<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\OfferEnricher;

use Magento\Catalog\Api\Data\ProductInterface;
use MageOS\Seo\Api\OfferEnricherInterface;
use MageOS\Seo\Model\Product\OfferEnricher\Pool;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class PoolTest extends TestCase
{
    /**
     * @var ProductInterface&Stub
     */
    private ProductInterface&Stub $product;

    protected function setUp(): void
    {
        $this->product = $this->createStub(ProductInterface::class);
    }

    /**
     * @param array<string, mixed> $fragment
     */
    private function makeEnricher(array $fragment, int $sortOrder): OfferEnricherInterface&Stub
    {
        $enricher = $this->createStub(OfferEnricherInterface::class);
        $enricher->method('enrich')->willReturn($fragment);
        $enricher->method('getSortOrder')->willReturn($sortOrder);
        return $enricher;
    }

    public function testEmptyPoolReturnsEmptyArray(): void
    {
        $pool = new Pool([]);
        $this->assertSame([], $pool->enrich($this->product, 1));
    }

    public function testMergesFragmentsFromAllEnrichers(): void
    {
        $pool = new Pool([
            $this->makeEnricher(['itemCondition' => 'New'], 100),
            $this->makeEnricher(['shippingDetails' => ['x' => 1]], 100),
        ]);
        $result = $pool->enrich($this->product, 1);
        $this->assertArrayHasKey('itemCondition', $result);
        $this->assertArrayHasKey('shippingDetails', $result);
    }

    public function testHigherSortOrderWinsOnConflictingKey(): void
    {
        $pool = new Pool([
            $this->makeEnricher(['itemCondition' => 'low'], 100),
            $this->makeEnricher(['itemCondition' => 'high'], 200),
        ]);
        $this->assertSame('high', $pool->enrich($this->product, 1)['itemCondition']);
    }

    public function testEmptyFragmentsAreIgnored(): void
    {
        $pool = new Pool([
            $this->makeEnricher([], 100),
            $this->makeEnricher(['returnFees' => 'free'], 100),
        ]);
        $this->assertSame(['returnFees' => 'free'], $pool->enrich($this->product, 1));
    }

    public function testNonEnricherObjectsAreSkipped(): void
    {
        $pool = new Pool([new \stdClass(), 'nope']);
        $this->assertSame([], $pool->enrich($this->product, 1));
    }
}
