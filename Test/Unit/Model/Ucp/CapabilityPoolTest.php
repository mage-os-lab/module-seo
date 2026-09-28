<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Ucp;

use MageOS\Seo\Api\UcpCapabilityProviderInterface;
use MageOS\Seo\Model\Ucp\CapabilityPool;
use MageOS\Seo\Model\Ucp\EntryValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CapabilityPoolTest extends TestCase
{
    private const CHECKOUT = [
        'version' => '2026-08-25',
        'schema'  => 'https://ucp.dev/2026-08-25/schemas/shopping/checkout.json',
    ];

    /**
     * @return void
     */
    public function testWithNoProvidersThereAreNoCapabilities(): void
    {
        $this->assertSame([], $this->pool([])->getEnabledCapabilities());
    }

    /**
     * @return void
     */
    public function testEnabledProvidersAreListedUnderTheirNameAsEntries(): void
    {
        $cart = ['version' => '2026-08-25', 'schema' => 'https://ucp.dev/2026-08-25/schemas/shopping/cart.json'];

        $pool = $this->pool([
            $this->provider('dev.ucp.shopping.checkout', true, self::CHECKOUT),
            $this->provider('dev.ucp.shopping.cart', false, $cart),
        ]);

        $this->assertSame(['dev.ucp.shopping.checkout' => [self::CHECKOUT]], $pool->getEnabledCapabilities());
    }

    /**
     * @return void
     */
    public function testProvidersSharingANameAddEntriesToOneList(): void
    {
        $older = ['version' => '2026-04-08'] + self::CHECKOUT;

        $pool = $this->pool([
            $this->provider('dev.ucp.shopping.checkout', true, self::CHECKOUT),
            $this->provider('dev.ucp.shopping.checkout', true, $older),
        ]);

        $this->assertSame(
            ['dev.ucp.shopping.checkout' => [self::CHECKOUT, $older]],
            $pool->getEnabledCapabilities()
        );
    }

    /**
     * A disabled or refused provider is skipped, not the end of the list.
     *
     * @return void
     */
    public function testAProviderLeftOutDoesNotStopTheOnesAfterIt(): void
    {
        $fulfillment = [
            'version' => '2026-08-25',
            'schema'  => 'https://ucp.dev/2026-08-25/schemas/shopping/fulfillment.json',
            'extends' => 'dev.ucp.shopping.checkout',
        ];

        $pool = $this->pool([
            $this->provider('dev.ucp.shopping.cart', false, self::CHECKOUT),
            $this->provider('dev.ucp.shopping.discount', true, ['version' => '2026-08-25']),
            $this->provider('dev.ucp.shopping.checkout', true, self::CHECKOUT),
            $this->provider('dev.ucp.shopping.fulfillment', true, $fulfillment),
        ]);

        $this->assertSame(
            ['dev.ucp.shopping.checkout' => [self::CHECKOUT], 'dev.ucp.shopping.fulfillment' => [$fulfillment]],
            $pool->getEnabledCapabilities()
        );
    }

    /**
     * @return void
     */
    public function testAnEntryOutsideTheSchemaIsLoggedAndLeftOut(): void
    {
        $provider = $this->provider('dev.ucp.shopping.checkout', true, ['version' => '2026-08-25']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->logicalAnd(
            $this->stringContains('dev.ucp.shopping.checkout'),
            $this->stringContains($provider::class),
            $this->stringContains('schema')
        ));

        $this->assertSame([], $this->pool([$provider], $logger)->getEnabledCapabilities());
    }

    /**
     * @param UcpCapabilityProviderInterface[] $providers
     * @param LoggerInterface|null $logger
     * @return CapabilityPool
     */
    private function pool(array $providers, ?LoggerInterface $logger = null): CapabilityPool
    {
        return new CapabilityPool(
            new EntryValidator(),
            $logger ?? $this->createStub(LoggerInterface::class),
            $providers
        );
    }

    /**
     * @param string $name
     * @param bool $enabled
     * @param array<string, mixed> $entry
     * @return UcpCapabilityProviderInterface
     */
    private function provider(string $name, bool $enabled, array $entry): UcpCapabilityProviderInterface
    {
        $provider = $this->createStub(UcpCapabilityProviderInterface::class);
        $provider->method('getCapabilityKey')->willReturn($name);
        $provider->method('isEnabled')->willReturn($enabled);
        $provider->method('getCapabilityData')->willReturn($entry);

        return $provider;
    }
}
