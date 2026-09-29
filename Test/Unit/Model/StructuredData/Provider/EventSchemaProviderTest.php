<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\StructuredData\Provider;

use Magento\Framework\View\Layout;
use Magento\Framework\View\Layout\ProcessorInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\EventDataProviderInterface;
use MageOS\Seo\Model\Pool\HandleMatcher;
use MageOS\Seo\Model\StructuredData\OrganizationId;
use MageOS\Seo\Model\StructuredData\Provider\EventSchemaProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EventSchemaProviderTest extends TestCase
{
    /**
     * @var ProcessorInterface&MockObject
     */
    private ProcessorInterface&MockObject $layoutUpdate;

    /**
     * @var Layout&MockObject
     */
    private Layout&MockObject $layout;

    /**
     * @var OrganizationId&MockObject
     */
    private OrganizationId&MockObject $organizationId;

    protected function setUp(): void
    {
        $this->layout       = $this->createMock(Layout::class);
        $this->layoutUpdate = $this->createMock(ProcessorInterface::class);
        $this->layout->method('getUpdate')->willReturn($this->layoutUpdate);
        $this->layoutUpdate->method('getHandles')->willReturn(['events_view']);
        $this->organizationId = $this->createMock(OrganizationId::class);
        $this->organizationId->method('getId')->willReturn('https://acme.com/#organization');
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @param string[] $handles
     */
    private function makeDataProvider(
        array $events,
        array $handles = ['events_view']
    ): EventDataProviderInterface&MockObject {
        $provider = $this->createMock(EventDataProviderInterface::class);
        $provider->method('getHandles')->willReturn($handles);
        $provider->method('getEvents')->willReturn($events);
        return $provider;
    }

    /**
     * @param array<int, EventDataProviderInterface&MockObject> $providers
     */
    private function provider(array $providers): EventSchemaProvider
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $store        = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager->method('getStore')->willReturn($store);

        return new EventSchemaProvider(
            $this->layout,
            $storeManager,
            $this->organizationId,
            new HandleMatcher(),
            $providers
        );
    }

    public function testEmptyPoolReturnsNoSchemas(): void
    {
        $this->assertSame([], $this->provider([])->getSchemas());
    }

    public function testNonMatchingHandleIsSkipped(): void
    {
        $events = [['name' => 'Market', 'startDate' => '2026-04-18T10:00', 'location' => []]];
        $this->assertSame([], $this->provider([$this->makeDataProvider($events, ['cms_page_view'])])->getSchemas());
    }

    public function testEventMissingNameOrStartDateIsSkipped(): void
    {
        $events = [['name' => 'No date', 'location' => []], ['startDate' => '2026-01-01', 'location' => []]];
        $this->assertSame([], $this->provider([$this->makeDataProvider($events)])->getSchemas());
    }

    public function testBuildsEventNodesWithOrganizer(): void
    {
        $events = [
            ['name' => 'Spring Market', 'startDate' => '2026-04-18T10:00', 'location' => ['@type' => 'Place']],
            ['name' => 'Autumn Fair', 'startDate' => '2026-09-12T10:00', 'location' => ['@type' => 'Place']],
        ];
        $schemas = $this->provider([$this->makeDataProvider($events)])->getSchemas();

        $this->assertCount(2, $schemas);
        $this->assertSame('Event', $schemas[0]['@type']);
        $this->assertSame('Spring Market', $schemas[0]['name']);
        $this->assertSame(['@id' => 'https://acme.com/#organization'], $schemas[0]['organizer']);
    }
}
