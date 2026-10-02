<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Review;

use MageOS\Seo\Api\AggregateRatingProviderInterface;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class AggregateRatingResolverTest extends TestCase
{
    /**
     * @param array<string, string>|null $rating
     */
    private function makeProvider(?array $rating, int $priority): AggregateRatingProviderInterface&Stub
    {
        $provider = $this->createStub(AggregateRatingProviderInterface::class);
        $provider->method('getRating')->willReturn($rating);
        $provider->method('getPriority')->willReturn($priority);
        return $provider;
    }

    public function testReturnsNullWithNoProviders(): void
    {
        $resolver = new AggregateRatingResolver([]);
        $this->assertNull($resolver->resolve(1, 1));
    }

    public function testReturnsRatingFromSingleProvider(): void
    {
        $rating   = ['ratingValue' => '4.5', 'reviewCount' => '10'];
        $resolver = new AggregateRatingResolver([$this->makeProvider($rating, 100)]);
        $this->assertSame($rating, $resolver->resolve(1, 1));
    }

    public function testHighestPriorityWins(): void
    {
        $low  = $this->makeProvider(['ratingValue' => '3.0', 'reviewCount' => '5'], 100);
        $high = $this->makeProvider(['ratingValue' => '4.8', 'reviewCount' => '99'], 200);
        $resolver = new AggregateRatingResolver([$low, $high]);
        $result   = $resolver->resolve(1, 1);
        $this->assertNotNull($result);
        $this->assertSame('4.8', $result['ratingValue']);
    }

    public function testProviderReturningNullIsSkipped(): void
    {
        $null    = $this->makeProvider(null, 200);
        $nonNull = $this->makeProvider(['ratingValue' => '4.0', 'reviewCount' => '3'], 100);
        $resolver = new AggregateRatingResolver([$null, $nonNull]);
        $result   = $resolver->resolve(1, 1);
        $this->assertNotNull($result);
        $this->assertSame('4.0', $result['ratingValue']);
    }

    public function testAllProvidersNullReturnsNull(): void
    {
        $resolver = new AggregateRatingResolver([$this->makeProvider(null, 100)]);
        $this->assertNull($resolver->resolve(1, 1));
    }

    public function testNonProviderObjectsAreSkipped(): void
    {
        $resolver = new AggregateRatingResolver([new \stdClass(), 'nope']);
        $this->assertNull($resolver->resolve(1, 1));
    }
}
