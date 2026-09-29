<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Ucp;

use MageOS\Seo\Api\UcpServiceProviderInterface;
use MageOS\Seo\Model\Ucp\EntryValidator;
use MageOS\Seo\Model\Ucp\ServicePool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ServicePoolTest extends TestCase
{
    private const REST = [
        'version'   => '2026-08-25',
        'transport' => 'rest',
        'endpoint'  => 'https://shop.test/ucp/v1',
    ];

    private const MCP = [
        'version'   => '2026-08-25',
        'transport' => 'mcp',
        'endpoint'  => 'https://shop.test/ucp/mcp',
    ];

    /**
     * @return void
     */
    public function testWithNoProvidersThereAreNoServices(): void
    {
        $this->assertSame([], $this->pool([])->getEnabledServices());
    }

    /**
     * Each transport binding is its own entry in the service's list.
     *
     * @return void
     */
    public function testEnabledBindingsAreListedUnderTheirService(): void
    {
        $pool = $this->pool([
            $this->provider('dev.ucp.shopping', true, self::REST),
            $this->provider('dev.ucp.shopping', true, self::MCP),
            $this->provider('com.example.loyalty', false, self::REST),
        ]);

        $this->assertSame(['dev.ucp.shopping' => [self::REST, self::MCP]], $pool->getEnabledServices());
    }

    /**
     * A disabled binding is skipped, not the end of the list, and every service is kept.
     *
     * @return void
     */
    public function testADisabledBindingDoesNotStopTheOnesAfterIt(): void
    {
        $loyalty = ['version' => '2026-08-25', 'transport' => 'rest', 'endpoint' => 'https://shop.test/loyalty/v1'];

        $pool = $this->pool([
            $this->provider('dev.ucp.shopping', false, self::MCP),
            $this->provider('dev.ucp.shopping', true, self::REST),
            $this->provider('com.example.loyalty', true, $loyalty),
        ]);

        $this->assertSame(
            ['dev.ucp.shopping' => [self::REST], 'com.example.loyalty' => [$loyalty]],
            $pool->getEnabledServices()
        );
    }

    /**
     * @return void
     */
    public function testABindingOutsideTheSchemaIsLoggedAndLeftOut(): void
    {
        $noEndpoint = ['version' => '2026-08-25', 'transport' => 'rest'];
        $provider = $this->provider('dev.ucp.shopping', true, $noEndpoint);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->logicalAnd(
            $this->stringContains('dev.ucp.shopping'),
            $this->stringContains($provider::class),
            $this->stringContains('endpoint')
        ));

        $pool = $this->pool([$provider, $this->provider('dev.ucp.shopping', true, self::MCP)], $logger);

        $this->assertSame(['dev.ucp.shopping' => [self::MCP]], $pool->getEnabledServices());
    }

    /**
     * @param UcpServiceProviderInterface[] $providers
     * @param LoggerInterface|null $logger
     * @return ServicePool
     */
    private function pool(array $providers, ?LoggerInterface $logger = null): ServicePool
    {
        return new ServicePool(
            new EntryValidator(),
            $logger ?? $this->createStub(LoggerInterface::class),
            $providers
        );
    }

    /**
     * @param string $name
     * @param bool $enabled
     * @param array<string, mixed> $entry
     * @return UcpServiceProviderInterface
     */
    private function provider(string $name, bool $enabled, array $entry): UcpServiceProviderInterface
    {
        $provider = $this->createStub(UcpServiceProviderInterface::class);
        $provider->method('getServiceKey')->willReturn($name);
        $provider->method('isEnabled')->willReturn($enabled);
        $provider->method('getServiceData')->willReturn($entry);

        return $provider;
    }
}
