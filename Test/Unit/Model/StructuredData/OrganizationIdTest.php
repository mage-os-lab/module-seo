<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\StructuredData;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\StructuredData\OrganizationId;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrganizationIdTest extends TestCase
{
    /**
     * @var OrganizationRepositoryInterface&MockObject
     */
    private OrganizationRepositoryInterface&MockObject $repository;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private StoreManagerInterface&MockObject $storeManager;

    /**
     * @var OrganizationId
     */
    private OrganizationId $organizationId;

    protected function setUp(): void
    {
        $this->repository     = $this->createMock(OrganizationRepositoryInterface::class);
        $this->storeManager   = $this->createMock(StoreManagerInterface::class);
        $this->organizationId = new OrganizationId($this->repository, $this->storeManager);
    }

    public function testFromUrlAppendsOrganizationFragment(): void
    {
        $this->assertSame('https://acme.com/#organization', $this->organizationId->fromUrl('https://acme.com'));
    }

    public function testFromUrlStripsTrailingSlash(): void
    {
        $this->assertSame('https://acme.com/#organization', $this->organizationId->fromUrl('https://acme.com/'));
    }

    public function testGetIdWithExplicitScopeUsesRepository(): void
    {
        $org = $this->createMock(OrganizationInterface::class);
        $org->method('getUrl')->willReturn('https://acme.com/');
        $this->repository->expects($this->once())
            ->method('getForScope')->with(2, 3)->willReturn($org);

        $this->assertSame('https://acme.com/#organization', $this->organizationId->getId(2, 3));
    }

    public function testGetIdResolvesScopeFromStoreManagerWhenNotGiven(): void
    {
        $store   = $this->createMock(StoreInterface::class);
        $website = $this->createMock(WebsiteInterface::class);
        $store->method('getId')->willReturn(5);
        $website->method('getId')->willReturn(7);
        $this->storeManager->method('getStore')->willReturn($store);
        $this->storeManager->method('getWebsite')->willReturn($website);

        $org = $this->createMock(OrganizationInterface::class);
        $org->method('getUrl')->willReturn('https://acme.com');
        $this->repository->expects($this->once())
            ->method('getForScope')->with(5, 7)->willReturn($org);

        $this->assertSame('https://acme.com/#organization', $this->organizationId->getId());
    }
}
